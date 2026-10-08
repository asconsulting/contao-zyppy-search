<?php

/**
 * @copyright 	Andrew Stevens
 * @author 		Andrew Stevens
 * @license 	AGPL-3.0-only
 * @package 	Zyppy Search
 * @link 		https://github.com/asconsulting/contao-zyppy-search
 */



namespace Zyppy\Search\Search;

use Contao\StringUtil;
use Zyppy\Search\Util\TextFormatter;


/**
 * Turns one tl_search row - plus the tl_page row behind it and, optionally, the
 * tl_news row behind THAT - into a SearchResultItem.
 *
 * This is the bundle's whole reason to exist: the page_image / page_teaser
 * enrichment that asconsulting/contao-zyppy-page adds to tl_page, and the
 * per-page News Reader override.
 *
 * It takes ROWS, not models, so it is pure apart from the injected image
 * resolver - which is why the enrichment rules are unit testable.
 *
 * BEHAVIOUR CHANGE from the ModuleSearch fork, called out rather than slipped
 * in: the news teaser and news image now actually reach the output. The fork
 * computed both on every result and rendered neither, so the documented "News
 * Reader override" had never once been visible.
 */
final class ResultEnricher
{

	public function __construct(
		private readonly ResultImageResolverInterface $objImages,
	)
	{
	}

	/**
	 * @param array<string, mixed>      $arrSearchRow A tl_search row (id, pid, title, url, relevance, ...).
	 * @param array<string, mixed>|null $arrPageRow   The tl_page row for $arrSearchRow['pid'], or null.
	 * @param array<string, mixed>|null $arrNewsRow   A published tl_news row, or null.
	 * @param string                    $strContext   Pre-built, already escaped context markup.
	 * @param float                     $fltTop       The relevance of the most relevant result on this page.
	 */
	public function enrich(array $arrSearchRow, array|null $arrPageRow, array|null $arrNewsRow, SearchQuery $objQuery, string $strContext, float $fltTop): SearchResultItem
	{
		// Insert tags are stripped from the title once, for the link text AND the
		// title attribute: the rendered result ends up in a page buffer that is
		// insert-tag parsed, so neither half may carry a live `{{...}}`.
		$strTitle = StringUtil::stripInsertTags((string) ($arrSearchRow['title'] ?? ''));

		$strTeaser = '';
		$strDescription = '';
		$arrImage = null;
		$blnIsNews = false;

		if (null !== $arrPageRow)
		{
			// Model::__get() returns null for an unknown column, so a site
			// WITHOUT contao-zyppy-page installed degrades to empty strings
			// here rather than fatalling. That is why TextFormatter coerces.
			$strTeaser = $objQuery->blnFormatPageTeaser
				? TextFormatter::format($arrPageRow['page_teaser'] ?? null, $objQuery->intPageTeaserLimit)
				: (string) ($arrPageRow['page_teaser'] ?? '');

			$strDescription = $objQuery->blnFormatPageDescription
				? TextFormatter::format($arrPageRow['description'] ?? null, $objQuery->intPageDescriptionLimit)
				: (string) ($arrPageRow['description'] ?? '');

			$arrImage = $this->objImages->resolve($arrPageRow['page_image'] ?? null, $objQuery->varImgSize, $strTitle);
		}

		if (null !== $arrNewsRow)
		{
			$blnIsNews = true;

			$strNewsTeaser = $objQuery->blnFormatNewsTeaser
				? TextFormatter::format($arrNewsRow['teaser'] ?? null, $objQuery->intNewsTeaserLimit)
				: (string) ($arrNewsRow['teaser'] ?? '');

			if ('' !== $strNewsTeaser)
			{
				$strTeaser = $strNewsTeaser;
			}

			if (!empty($arrNewsRow['addImage']))
			{
				$arrNewsImage = $this->objImages->resolve($arrNewsRow['singleSRC'] ?? null, $objQuery->varImgSize, $strTitle);

				if (null !== $arrNewsImage)
				{
					$arrImage = $arrNewsImage;
				}
			}
		}

		return new SearchResultItem(
			// The indexed title. tl_search.title comes from DomCrawler::text(),
			// i.e. decoded plain text; it is handed over UNESCAPED because the
			// result template escapes it exactly once.
			$strTitle,
			// Scheme checked here, not in the template: Twig escaping does not
			// stop a `javascript:` href. See SafeUrl.
			SafeUrl::sanitise($arrSearchRow['url'] ?? null),
			$strTitle,
			$this->formatRelevance($arrSearchRow, $fltTop),
			$strContext,
			$strTeaser,
			$strDescription,
			$arrImage,
			$blnIsNews,
		);
	}

	/**
	 * @param array<string, mixed> $arrSearchRow
	 */
	private function formatRelevance(array $arrSearchRow, float $fltTop): string
	{
		if ($fltTop <= 0)
		{
			return '';
		}

		$strLabel = (string) ($GLOBALS['TL_LANG']['MSC']['relevance'] ?? '%s');

		return \sprintf($strLabel, number_format(((float) ($arrSearchRow['relevance'] ?? 0)) / $fltTop * 100, 2) . '%');
	}

}
