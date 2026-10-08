<?php

/**
 * @copyright 	Andrew Stevens
 * @author 		Andrew Stevens
 * @license 	AGPL-3.0-only
 * @package 	Zyppy Search
 * @link 		https://github.com/asconsulting/contao-zyppy-search
 */



namespace Zyppy\Search\Controller\FrontendModule;

use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\ModuleModel;
use Contao\PageModel;
use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Zyppy\Search\Search\ResultRenderer;
use Zyppy\Search\Search\SearchQuery;
use Zyppy\Search\Search\ZyppySearchRunner;


/**
 * The zyppy_search front end module.
 *
 * STEP 10 CHANGED WHAT THIS DOES. Step 7 made it the form shell ONLY, with all
 * rendering pushed to the client. That removed something real: a visitor with
 * JavaScript disabled got a form that did nothing, because nothing ran a search
 * during a page render any more.
 *
 * So this fragment now renders the FIRST PAGE of results server side, whenever
 * the request carries `keywords`. Live/typeahead updates still go over
 * /_zyppy/search/{moduleId}; that route is unchanged and is not being undone.
 * Both paths render through the same ResultRenderer, so the markup is identical
 * by construction rather than by two implementations agreeing.
 *
 * That also restores the `?keywords=...` submit path, the pager as real links,
 * and tl_module.searchTpl as a setting that means something.
 *
 * CACHING. This output is now request-dependent, which is exactly what core's
 * own ModuleSearch has always been: `?keywords=x` is a distinct URL and gets a
 * distinct cache entry, and Contao's MakeResponsePrivateListener already marks
 * the whole page response private once a member is logged in. Nothing extra is
 * done here on purpose - reimplementing that listener's judgement would be both
 * duplication and a good way to get it subtly wrong.
 *
 * THIS REPLACES the legacy $GLOBALS['FE_MOD'] registration and the
 * Contao\ModuleSearch subclass, under the SAME type name `zyppy_search`, so
 * existing tl_module rows keep working with no data migration.
 *
 * The `application` category matches $GLOBALS['TL_LANG']['FMD']['zyppy_search']
 * living under an `application` group - a frontend module label is FMD, not
 * MOD, and the group key must equal the AsFrontendModule category.
 *
 * ASSETS. A fragment cannot use $GLOBALS['TL_JAVASCRIPT'] - by the time a
 * fragment renders, the layout's script section is already built - so the
 * template emits its own <script>.
 */
#[AsFrontendModule(type: 'zyppy_search', category: 'application', template: 'frontend_module/zyppy_search')]
class ZyppySearchController extends AbstractFrontendModuleController
{

	/**
	 * The published asset path. The prefix follows the BUNDLE CLASS NAME
	 * (SearchBundle -> bundles/search), not the composer package name - getting
	 * that wrong is how this bundle's live search pointed at a 404 for years.
	 * tests/AssetPathTest.php ties the two together.
	 */
	public const SCRIPT_PATH = 'bundles/search/js/search.js';

	public function __construct(
		private readonly UrlGeneratorInterface $objUrlGenerator,
		private readonly ContentUrlGenerator $objContentUrlGenerator,
		private readonly Packages $objPackages,
		private readonly ZyppySearchRunner $objRunner,
		private readonly ResultRenderer $objRenderer,
	)
	{
	}

	protected function getResponse(FragmentTemplate $objTemplate, ModuleModel $objModel, Request $objRequest): Response
	{
		$arrModule = $objModel->row();

		$objQuery = SearchQuery::fromModuleRow(
			$arrModule,
			$objRequest->query->get('keywords'),
			$objRequest->query->get('query_type'),
			(int) $objRequest->query->get('page', 1),
		);

		$objTemplate->set('uniqueId', (int) $objModel->id);
		$objTemplate->set('endpoint', $this->objUrlGenerator->generate('zyppy_search_query', array('moduleId' => (int) $objModel->id)));
		$objTemplate->set('minLength', $objQuery->clientMinKeywordLength());

		// Resolved here rather than with Twig's asset() in the template, so the
		// templates stay renderable by a bare Twig Environment - which is what
		// makes their escaping contract testable without booting Contao.
		$objTemplate->set('scriptSrc', $this->objPackages->getUrl(self::SCRIPT_PATH));
		$objTemplate->set('advanced', 'advanced' === $objModel->searchType);
		$objTemplate->set('queryType', $objQuery->blnOrSearch ? 'or' : 'and');

		// Handed over RAW. Twig escapes it exactly once; pre-escaping here would
		// double-encode, and a search for `don't` would render as `don&#39;t`.
		$objTemplate->set('keyword', $objQuery->strKeywords);

		// The non-AJAX submit target. With no jumpTo the form posts back to the
		// current URL, which is the page this fragment is on - so the server
		// rendered branch below picks the keywords up on the next request.
		$objTemplate->set('action', $this->jumpToUrl($objModel));

		$objTemplate->set('results', $this->renderResults($arrModule, $objQuery, $objRequest));

		$objTemplate->set('keywordLabel', (string) ($GLOBALS['TL_LANG']['MSC']['keywords'] ?? ''));
		$objTemplate->set('optionsLabel', (string) ($GLOBALS['TL_LANG']['MSC']['options'] ?? ''));
		$objTemplate->set('search', (string) ($GLOBALS['TL_LANG']['MSC']['searchLabel'] ?? ''));
		$objTemplate->set('matchAll', (string) ($GLOBALS['TL_LANG']['MSC']['matchAll'] ?? ''));
		$objTemplate->set('matchAny', (string) ($GLOBALS['TL_LANG']['MSC']['matchAny'] ?? ''));
		$objTemplate->set('placeholder', (string) ($GLOBALS['TL_LANG']['MSC']['zyppySearchPlaceholder'] ?? ''));
		$objTemplate->set('errorText', (string) ($GLOBALS['TL_LANG']['MSC']['zyppySearchError'] ?? ''));

		// zyppy-popup's popup.js reads div.results.popup_clear.
		$objTemplate->set('popupClear', (bool) ($arrModule['popupClear'] ?? false));

		return $objTemplate->getResponse();
	}

	/**
	 * The no-JavaScript path.
	 *
	 * Returns '' - a genuinely empty div.results - for a visitor who has not
	 * searched yet, so a page carrying the module looks exactly as it did before
	 * this change until someone actually submits something.
	 *
	 * An out-of-range page number renders an empty result block rather than
	 * throwing: the route answers that case with a 404 JSON body, but a fragment
	 * throwing PageNotFoundException would take down the whole page around it
	 * over a hand-edited query string.
	 *
	 * @param array<string, mixed> $arrModule
	 */
	private function renderResults(array $arrModule, SearchQuery $objQuery, Request $objRequest): string
	{
		if (!$objQuery->isSearchable())
		{
			return '';
		}

		$objResults = $this->objRunner->run($arrModule, $objQuery, $objRequest);

		if ($objResults->blnOutOfRange)
		{
			return '';
		}

		return $this->objRenderer->render($objResults, $objQuery, (string) ($arrModule['searchTpl'] ?? ''));
	}

	private function jumpToUrl(ModuleModel $objModel): string
	{
		if (!$objModel->jumpTo)
		{
			return '';
		}

		$objTarget = PageModel::findByPk((int) $objModel->jumpTo);

		if (null === $objTarget)
		{
			return '';
		}

		try
		{
			return $this->objContentUrlGenerator->generate($objTarget);
		}
		catch (\Exception)
		{
			return '';
		}
	}

}
