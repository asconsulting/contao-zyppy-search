<?php

/**
 * @copyright 	Andrew Stevens
 * @author 		Andrew Stevens
 * @license 	AGPL-3.0-only
 * @package 	Zyppy Search
 * @link 		https://github.com/asconsulting/contao-zyppy-search
 */



namespace Zyppy\Search\Search;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FrontendTemplate;


/**
 * Renders a SearchResults into the markup that goes inside div.results.
 *
 * THIS IS THE ONE RENDERER. Both paths go through it:
 *
 *   - ZyppySearchController server-renders the first page into the fragment,
 *     so results exist before any JavaScript runs;
 *   - SearchController returns the identical string as the `html` field of the
 *     JSON the live search fetches.
 *
 * WHY ONE RENDERER RATHER THAN TWO THAT AGREE. Step 7 had the client assemble
 * results in JavaScript. That made tl_module.searchTpl apply to the first paint
 * and silently NOT to any update after it - a site that customised its result
 * template would have watched the customisation vanish on the first keystroke.
 * Rendering both paths from the same template makes them identical BY
 * CONSTRUCTION, which is a stronger guarantee than a test that two
 * implementations agree - and no test in this repo can execute search.js
 * anyway, so that test was never buildable.
 *
 * Two templates, both flat legacy identifiers with no `.twig-root` marker:
 *
 *   search_zyppy          one result row. Selectable per module through
 *                         tl_module.searchTpl, which is what makes that field
 *                         mean something again.
 *   search_zyppy_results  the wrapper: header, keyword hint, the rows, pager.
 *                         Not selectable; override it by shipping a project
 *                         template of the same name, the ordinary Contao way.
 *
 * PAGER LINKS ARE QUERY-STRING ONLY - `?keywords=...&page=2`. That is
 * deliberate and load bearing: a bare `?query` href resolves against the
 * CURRENT DOCUMENT, so the same markup points at the right place whether it was
 * rendered into the page or fetched from /_zyppy/search/{moduleId}. The route
 * does not know the page URL and must never be told it by the client.
 */
final class ResultRenderer
{

	public const DEFAULT_RESULT_TEMPLATE = 'search_zyppy';
	public const WRAPPER_TEMPLATE = 'search_zyppy_results';

	public function __construct(
		private readonly ContaoFramework $objFramework,
	)
	{
	}

	/**
	 * @param string $strResultTemplate tl_module.searchTpl, or '' for the default.
	 */
	public function render(SearchResults $objResults, SearchQuery $objQuery, string $strResultTemplate = ''): string
	{
		$this->objFramework->initialize();

		$strRows = '';
		$arrItems = array_values($objResults->arrItems);
		$intTotal = \count($arrItems);

		foreach ($arrItems as $intIndex => $objItem)
		{
			$objRow = $this->objFramework->createInstance(FrontendTemplate::class, array($strResultTemplate ?: self::DEFAULT_RESULT_TEMPLATE));
			$objRow->setData($objItem->toTemplateData($intIndex, $intTotal));

			$strRows .= $objRow->parse();
		}

		$objWrapper = $this->objFramework->createInstance(FrontendTemplate::class, array(self::WRAPPER_TEMPLATE));
		$objWrapper->setData($this->wrapperData($objResults, $objQuery, $strRows));

		return $objWrapper->parse();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function wrapperData(SearchResults $objResults, SearchQuery $objQuery, string $strRows): array
	{
		return array(
			// Carries markup from the translation itself; see SearchResults.
			'header' => $objResults->strHeader,
			'hint' => $objResults->strKeywordHint,
			'results' => $strRows,
			'count' => $objResults->intCount,
			'from' => $objResults->intFrom,
			'to' => $objResults->intTo,
			'page' => $objResults->intPage,
			'totalPages' => $objResults->intTotalPages,
			'previousHref' => $objResults->intPage > 1 ? $this->pageHref($objQuery, $objResults->intPage - 1) : '',
			'nextHref' => $objResults->intPage < $objResults->intTotalPages ? $this->pageHref($objQuery, $objResults->intPage + 1) : '',
			'previousPage' => $objResults->intPage - 1,
			'nextPage' => $objResults->intPage + 1,
			'previousLabel' => (string) ($GLOBALS['TL_LANG']['MSC']['previous'] ?? 'Previous'),
			'nextLabel' => (string) ($GLOBALS['TL_LANG']['MSC']['next'] ?? 'Next'),
		);
	}

	/**
	 * A query-string-only href, so it resolves against whatever document the
	 * markup ends up in. Unescaped: the template escapes it exactly once.
	 */
	private function pageHref(SearchQuery $objQuery, int $intPage): string
	{
		$arrParams = array('keywords' => $objQuery->strKeywords);

		// Only carry query_type when the module actually ran an "or" search, so
		// a simple module's pager links stay clean.
		if ($objQuery->blnOrSearch)
		{
			$arrParams['query_type'] = 'or';
		}

		if ($intPage > 1)
		{
			$arrParams['page'] = $intPage;
		}

		return '?' . http_build_query($arrParams, '', '&', PHP_QUERY_RFC3986);
	}

}
