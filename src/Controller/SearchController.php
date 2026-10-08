<?php

/**
 * @copyright 	Andrew Stevens
 * @author 		Andrew Stevens
 * @license 	AGPL-3.0-only
 * @package 	Zyppy Search
 * @link 		https://github.com/asconsulting/contao-zyppy-search
 */



namespace Zyppy\Search\Controller;

use Contao\Controller as ContaoController;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\PageFinder;
use Contao\ModuleModel;
use Contao\System;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Zyppy\Search\Search\ResultRenderer;
use Zyppy\Search\Search\SearchQuery;
use Zyppy\Search\Search\ZyppySearchRunner;


/**
 * The live search endpoint: GET /_zyppy/search/{moduleId} -> JSON.
 *
 * WHY THIS ROUTE EXISTS AT ALL. The thing it replaces was not an endpoint. It
 * was an ordinary Contao page render that the front end module hijacked partway
 * through with `echo` + `exit()`. Three of the problems in the audit were
 * properties of that transport rather than bugs inside it, and all three are
 * gone here because the transport is gone:
 *
 *   - Page truncation. A request that reached the exit() without matching the
 *     module id guard would emit a half rendered page. There is no page here.
 *   - Cache headers. exit() bypassed the kernel, so the response inherited the
 *     page's headers - potentially a SHARED cache entry holding member scoped
 *     results. This response is explicitly private + no-store.
 *   - No place to attach a rate limiter. Step 3 deferred the server side
 *     limiter for exactly one reason: there was no route to attach it to. It is
 *     attached below.
 *
 * ACCESS CONTROL. Steps 1 and 2 are preserved and extended, not weakened:
 *
 *   1. The module must exist AND be of type zyppy_search. Without the type
 *      assertion the route would be a config oracle for any tl_module row.
 *   2. Contao\Controller::isVisibleElement() is applied to the module row -
 *      the framework's own check, not a reimplementation of it. On a page
 *      render Controller::getFrontendModule() runs this for us; on a standalone
 *      route nothing does, so a protected module would otherwise have become
 *      readable by anyone who knew its id. This is a NEW control that the
 *      route made necessary.
 *   3. Per result access control (the protected-page filter) and the news
 *      published/in-window filter both live in ZyppySearchRunner's injected
 *      collaborators and run on every request through here.
 *
 * STEP 10: the response body changed. It still returns JSON, and the route,
 * the rate limiter, the access control and the cache headers are all untouched
 * - but the per-item 'results' array has been replaced by a single 'html'
 * string rendered by ResultRenderer. See the comment at the return statement.
 *
 * NOT final, deliberately: Symfony's controller resolver may hand back a lazy
 * or ghost proxy for a controller service depending on how the container is
 * configured, and a final class cannot be proxied. Contao's own fragment
 * controllers are not final for the same reason.
 */
class SearchController
{

	public function __construct(
		private readonly ContaoFramework $objFramework,
		private readonly ZyppySearchRunner $objRunner,
		private readonly ResultRenderer $objRenderer,
		private readonly PageFinder $objPageFinder,
		private readonly RateLimiterFactory $objLimiterFactory,
		private readonly LoggerInterface|null $objLogger = null,
	)
	{
	}

	/**
	 * The $moduleId parameter name is fixed by the route placeholder, so it does
	 * not carry the usual int prefix - Symfony's argument resolver matches route
	 * parameters to method parameters BY NAME.
	 */
	public function __invoke(Request $objRequest, int $moduleId): JsonResponse
	{
		// Server side throttling, keyed by client IP. Client debouncing is a
		// UX control, not a security one: the amplification vector is that one
		// keystroke can become one full text search over the whole site.
		$objLimit = $this->objLimiterFactory->create($objRequest->getClientIp() ?? 'anonymous')->consume(1);

		if (!$objLimit->isAccepted())
		{
			return $this->json(array('error' => 'rate_limited'), 429, array('Retry-After' => (string) max(1, $objLimit->getRetryAfter()->getTimestamp() - time())));
		}

		$this->objFramework->initialize();

		$objModule = $this->objFramework->getAdapter(ModuleModel::class)->findByPk($moduleId);

		if (null === $objModule || 'zyppy_search' !== $objModule->type)
		{
			return $this->json(array('error' => 'not_found'), 404);
		}

		if (!$this->objFramework->getAdapter(ContaoController::class)->isVisibleElement($objModule))
		{
			return $this->json(array('error' => 'forbidden'), 403);
		}

		$this->loadLanguage($objRequest);

		$arrModule = $objModule->row();

		$objQuery = SearchQuery::fromModuleRow(
			$arrModule,
			$objRequest->query->get('keywords'),
			$objRequest->query->get('query_type'),
			(int) $objRequest->query->get('page', 1),
		);

		$objResults = $this->objRunner->run($arrModule, $objQuery, $objRequest);

		if ($objResults->blnOutOfRange)
		{
			return $this->json(array('error' => 'page_out_of_range', 'count' => $objResults->intCount), 404);
		}

		// STEP 10 PAYLOAD CHANGE, called out because it is a contract change:
		// the per-item 'results' array is gone and 'html' replaces it. Results
		// are rendered by ResultRenderer - the SAME renderer the fragment uses
		// for the server-rendered first page - so what the client drops into
		// div.results is byte-identical to what a no-JavaScript visitor gets.
		// Two renderers would have made tl_module.searchTpl apply to the first
		// paint and silently not to any update after it.
		return $this->json($objResults->toArray() + array(
			'html' => $this->objRenderer->render($objResults, $objQuery, (string) ($arrModule['searchTpl'] ?? '')),
		));
	}

	/**
	 * $GLOBALS['TL_LANG']['MSC'] carries the result header, the relevance label
	 * and the minimum-keyword hint. A page render loads those on the way in; a
	 * standalone route does not, so it is done explicitly here, in the language
	 * of the root page the request host resolves to.
	 */
	private function loadLanguage(Request $objRequest): void
	{
		try
		{
			$objRoot = $this->objPageFinder->findRootPageForRequest($objRequest);

			if ($objRoot && $objRoot->language)
			{
				$GLOBALS['TL_LANGUAGE'] = $objRoot->language;
			}
		}
		catch (\Exception $objException)
		{
			$this->objLogger?->warning('Zyppy Search could not resolve a root page language: ' . $objException->getMessage());
		}

		$this->objFramework->getAdapter(System::class)->loadLanguageFile('default');
	}

	/**
	 * @param array<string, mixed>  $arrData
	 * @param array<string, string> $arrHeaders
	 */
	private function json(array $arrData, int $intStatus = 200, array $arrHeaders = array()): JsonResponse
	{
		$objResponse = new JsonResponse($arrData, $intStatus, $arrHeaders);

		// Results are member scoped: a shared cache must never hold them, and
		// neither must the browser's back/forward cache. The old transport
		// inherited whatever headers the hijacked page had.
		$objResponse->setPrivate();
		$objResponse->headers->addCacheControlDirective('no-store');
		$objResponse->headers->addCacheControlDirective('must-revalidate');
		$objResponse->headers->set('X-Robots-Tag', 'noindex');

		return $objResponse;
	}

}
