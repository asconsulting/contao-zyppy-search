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
use Contao\NewsModel;


/**
 * The Contao backed NewsLookupInterface.
 *
 * TWO controls live here, and neither may be relaxed:
 *
 * 1. class_exists(NewsModel::class). contao/news-bundle is a suggest plus a
 *    guard, not a require - that pair is deliberate and is not changed by the
 *    Step 7 rearchitecture. Without the bundle installed this simply returns
 *    null and the result keeps its page teaser.
 *
 * 2. The published + start/stop window filter added in Step 2. The front end
 *    would never show an unpublished or out-of-window article, so neither may
 *    the search preview. Archive scoping is still not available: nothing links
 *    a zyppy_search module to specific news archives.
 *
 * KNOWN RESIDUAL GAP, unchanged and not fixable here: an item whose stop date
 * has merely passed leaves its tl_search row behind until the next crawl,
 * because no DataContainer event fires for the passage of time. That row's
 * title and context can still surface. This is core-wide behaviour - Contao's
 * own search module has it identically - and the filter above is what stops
 * the ENRICHMENT (teaser, image) from being added on top of it.
 */
final class ContaoNewsLookup implements NewsLookupInterface
{

	public function __construct(
		private readonly ContaoFramework $objFramework,
	)
	{
	}

	public function findPublishedByAlias(string $strAlias): array|null
	{
		if ('' === $strAlias || !class_exists(NewsModel::class))
		{
			return null;
		}

		$this->objFramework->initialize();

		$intTime = time();

		$objNews = $this->objFramework->getAdapter(NewsModel::class)->findOneBy(
			array("tl_news.alias=?", "tl_news.published='1'", "(tl_news.start='' OR tl_news.start<=?)", "(tl_news.stop='' OR tl_news.stop>?)"),
			array($strAlias, $intTime, $intTime),
		);

		return $objNews ? $objNews->row() : null;
	}

}
