<?php

/**
 * @copyright 	Andrew Stevens
 * @author 		Andrew Stevens
 * @license 	AGPL-3.0-only
 * @package 	Zyppy Search
 * @link 		https://github.com/asconsulting/contao-zyppy-search
 */



namespace Zyppy\Search\Search;

use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Contao\Search as ContaoSearch;
use Contao\SearchResult;
use Contao\StringUtil;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Zyppy\Search\Util\NewsAliasResolver;


/**
 * Runs one search and returns plain data.
 *
 * THIS CLASS IS THE POINT OF STEP 7. Everything the ModuleSearch fork's
 * compile() did - and only what it did - happens here, with no Contao\Module in
 * sight, no `echo`, no `exit()` and no template. That is what dissolves the
 * fork: the subclass existed to host this logic, so once the logic lives in a
 * service the subclass has nothing left to be.
 *
 * The orchestration below is deliberately thin. Every decision it used to make
 * inline is now a collaborator that can be tested on its own:
 *
 *   PageScopeResolver      which pages may be searched (a security boundary)
 *   ProtectedResultFilter  which rows this visitor may see (a security boundary)
 *   ResultWindow           which slice of them to return
 *   ContextBuilder         the highlighted excerpt
 *   ResultEnricher         the zyppy-page / news enrichment
 *   NewsLookupInterface    the published + in-window news lookup
 *
 * WHAT WAS DELIBERATELY NOT CARRIED FORWARD, each recorded in TODO.md:
 *
 * - The `customizeSearch` HOOK. Its fifth argument is the ModuleSearch
 *   INSTANCE, which no longer exists. Passing null or a ModuleModel would
 *   silently change the contract for any listener that type hints it, so the
 *   hook is dropped outright rather than half kept.
 * - Contao\Pagination. The window arithmetic lives in ResultWindow and the
 *   pager is rendered by ResultRenderer from count/from/to/page/totalPages.
 *   This dissolves the pre-5.7 pagination drift instead of porting it.
 * - addImageToTemplateFromSearchResult(). It was the single inherited method
 *   the fork did not override, and its output was never rendered.
 */
final class ZyppySearchRunner
{

	public function __construct(
		private readonly ContaoFramework $objFramework,
		private readonly PageScopeResolver $objScope,
		private readonly ProtectedResultFilter $objAccess,
		private readonly ContextBuilder $objContext,
		private readonly ResultEnricher $objEnricher,
		private readonly NewsLookupInterface $objNews,
		private readonly LoggerInterface|null $objLogger = null,
	)
	{
	}

	/**
	 * @param array<string, mixed> $arrModule A tl_module row of type zyppy_search.
	 */
	public function run(array $arrModule, SearchQuery $objQuery, Request $objRequest): SearchResults
	{
		$this->objFramework->initialize();

		if (!$objQuery->isSearchable())
		{
			return SearchResults::empty('', $this->buildKeywordHint($objQuery));
		}

		$arrPages = $this->objScope->resolve($arrModule, $objRequest);

		if (!$arrPages)
		{
			return SearchResults::empty($this->buildEmptyHeader($objQuery), $this->buildKeywordHint($objQuery));
		}

		$objResult = $this->query($objQuery, $arrPages);

		// ACCESS CONTROL. Applied unconditionally - see ProtectedResultFilter
		// for why the contao.search.index_protected guard the fork wrapped this
		// in was an optimisation rather than a control.
		$objResult->applyFilter($this->objAccess->asClosure());

		$intCount = $objResult->getCount();

		if ($intCount < 1)
		{
			return SearchResults::empty($this->buildEmptyHeader($objQuery), $this->buildKeywordHint($objQuery));
		}

		$objWindow = ResultWindow::forPage($intCount, $objQuery->intPage, $objQuery->intPerPage);

		if ($objWindow->blnOutOfRange)
		{
			return new SearchResults($intCount, 0, 0, $objWindow->intPage, $objWindow->intPerPage, $objWindow->intTotalPages, array(), '', $this->buildKeywordHint($objQuery), true);
		}

		$arrRows = $objResult->getResults($objWindow->intLimit, $objWindow->intOffset);
		$fltTop = (float) ($arrRows[0]['relevance'] ?? 0);

		$arrItems = array();

		foreach ($arrRows as $arrRow)
		{
			$arrPageRow = $this->findPageRow($arrRow);
			$arrNewsRow = $this->findNewsRow($arrRow, $arrPageRow);

			$arrItems[] = $this->objEnricher->enrich(
				$arrRow,
				$arrPageRow,
				$arrNewsRow,
				$objQuery,
				$this->buildContext($arrRow, $objQuery),
				$fltTop,
			);
		}

		return new SearchResults(
			$intCount,
			$objWindow->intFrom,
			$objWindow->intTo,
			$objWindow->intPage,
			$objWindow->intPerPage,
			$objWindow->intTotalPages,
			$arrItems,
			$this->buildHeader($objQuery, $objWindow),
			$this->buildKeywordHint($objQuery),
		);
	}

	/**
	 * @param array<int> $arrPages
	 */
	private function query(SearchQuery $objQuery, array $arrPages): SearchResult
	{
		try
		{
			return $this->objFramework->getAdapter(ContaoSearch::class)->query(
				$objQuery->strKeywords,
				$objQuery->blnOrSearch,
				$arrPages,
				$objQuery->blnFuzzy,
				$objQuery->intMinKeywordLength,
			);
		}
		catch (\Exception $objException)
		{
			$this->objLogger?->error('Website search failed: ' . $objException->getMessage());

			return $this->objFramework->createInstance(SearchResult::class, array(array()));
		}
	}

	/**
	 * @param array<string, mixed> $arrRow
	 *
	 * @return array<string, mixed>|null
	 */
	private function findPageRow(array $arrRow): array|null
	{
		$objPage = $this->objFramework->getAdapter(PageModel::class)->findByPk((int) ($arrRow['pid'] ?? 0));

		return $objPage ? $objPage->row() : null;
	}

	/**
	 * The tl_search index stores no relation back to the news item that produced
	 * a result, so the alias has to be recovered from the indexed URL. The guess
	 * itself lives in NewsAliasResolver; the access control lives in the lookup.
	 *
	 * @param array<string, mixed>      $arrRow
	 * @param array<string, mixed>|null $arrPageRow
	 *
	 * @return array<string, mixed>|null
	 */
	private function findNewsRow(array $arrRow, array|null $arrPageRow): array|null
	{
		if (null === $arrPageRow || empty($arrPageRow['zyppy_news']))
		{
			return null;
		}

		$strAlias = NewsAliasResolver::aliasFromUrl((string) ($arrRow['url'] ?? ''), $this->urlSuffixFor((int) ($arrPageRow['id'] ?? 0)));

		return $this->objNews->findPublishedByAlias($strAlias);
	}

	/**
	 * loadDetails() can throw NoRootPageFoundException for a page whose ancestry
	 * is broken (a stale or orphaned tl_search row, say). One bad row must not
	 * abort the whole result set, so fall back to the global default suffix.
	 */
	private function urlSuffixFor(int $intPageId): string
	{
		try
		{
			$objPage = $this->objFramework->getAdapter(PageModel::class)->findByPk($intPageId);

			if ($objPage)
			{
				return (string) $objPage->loadDetails()->urlSuffix;
			}
		}
		catch (\Exception)
		{
			// Fall through to the global default.
		}

		return (string) $this->objFramework->getAdapter(Config::class)->get('urlSuffix');
	}

	/**
	 * @param array<string, mixed> $arrRow
	 */
	private function buildContext(array $arrRow, SearchQuery $objQuery): string
	{
		$strText = StringUtil::stripInsertTags(strtok((string) ($arrRow['text'] ?? ''), "\n"));

		$arrVariants = $this->objFramework->getAdapter(ContaoSearch::class)->getMatchVariants(
			StringUtil::trimsplit(',', (string) ($arrRow['matches'] ?? '')),
			$strText,
			(string) ($GLOBALS['TL_LANGUAGE'] ?? 'en'),
		);

		return $this->objContext->build($strText, $arrVariants, $objQuery->intContextLength, $objQuery->intTotalLength);
	}

	/**
	 * MSC.sResults is "Results %s - %s of %s for <strong>%s</strong>". The
	 * translation CARRIES MARKUP, so the string cannot be escaped as a whole and
	 * the keyword interpolated into it is escaped here, at sprintf() time. Same
	 * reasoning as the Twig port's `header|raw`.
	 */
	private function buildHeader(SearchQuery $objQuery, ResultWindow $objWindow): string
	{
		return vsprintf(
			(string) ($GLOBALS['TL_LANG']['MSC']['sResults'] ?? '%s - %s / %s: %s'),
			array($objWindow->intFrom, $objWindow->intTo, $objWindow->intCount, StringUtil::specialchars($objQuery->strKeywords, false, false)),
		);
	}

	/**
	 * MSC.sEmpty is "No matches for <strong>%s</strong>" - markup again.
	 */
	private function buildEmptyHeader(SearchQuery $objQuery): string
	{
		return \sprintf(
			(string) ($GLOBALS['TL_LANG']['MSC']['sEmpty'] ?? '%s'),
			StringUtil::specialchars($objQuery->strKeywords, false, false),
		);
	}

	private function buildKeywordHint(SearchQuery $objQuery): string
	{
		if ($objQuery->intMinKeywordLength < 1)
		{
			return '';
		}

		return \sprintf((string) ($GLOBALS['TL_LANG']['MSC']['sKeywordHint'] ?? '%s'), $objQuery->intMinKeywordLength);
	}

}
