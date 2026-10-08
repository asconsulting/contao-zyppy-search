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
use Contao\CoreBundle\Routing\PageFinder;
use Contao\Database;
use Contao\StringUtil;
use Symfony\Component\HttpFoundation\Request;


/**
 * Works out which tl_page ids one zyppy_search module may search.
 *
 * THIS IS A SECURITY BOUNDARY, and it is the one thing the move to a standalone
 * route genuinely changed rather than merely relocated.
 *
 * The fork read `global $objPage` for the "no reference pages configured" case
 * and searched everything under $objPage->rootId. On /_zyppy/search/{moduleId}
 * there is no $objPage, so the scope has to come from somewhere else - and the
 * tempting "somewhere else" is a page id posted by the client, which would let
 * any visitor widen their own search scope to a root they were never shown.
 *
 * So the scope is resolved SERVER SIDE ONLY, from two sources, neither of which
 * the client can influence:
 *
 *   1. tl_module.pages - the module's own configured reference pages.
 *   2. Failing that, PageFinder::findRootPageForRequest(), which matches the
 *      request host (and Accept-Language) through Contao's own router. On a
 *      request that does carry a pageModel it uses that page's rootId, so it
 *      degrades to exactly the old `$objPage->rootId` behaviour.
 *
 * No request parameter reaches this class. If you are ever tempted to add one,
 * that is the change that turns a search box into a scope oracle.
 */
final class PageScopeResolver
{

	public function __construct(
		private readonly ContaoFramework $objFramework,
		private readonly PageFinder $objPageFinder,
	)
	{
	}

	/**
	 * @param array<string, mixed> $arrModule A tl_module row.
	 *
	 * @return array<int> tl_page ids, or an empty array when nothing is in scope.
	 */
	public function resolve(array $arrModule, Request $objRequest): array
	{
		$this->objFramework->initialize();

		$objDatabase = $this->objFramework->getAdapter(Database::class)->getInstance();

		$arrRoots = array_values(array_filter(array_map(
			static fn ($v): int => (int) $v,
			StringUtil::deserialize($arrModule['pages'] ?? null, true),
		)));

		if ($arrRoots)
		{
			$arrIds = array();

			foreach ($arrRoots as $intPageId)
			{
				// The configured page itself is searchable, its subtree too.
				$arrIds[] = array($intPageId);
				$arrIds[] = $objDatabase->getChildRecords($intPageId, 'tl_page');
			}

			$arrIds = array_merge(...$arrIds);
		}
		else
		{
			$objRoot = $this->objPageFinder->findRootPageForRequest($objRequest);

			if (null === $objRoot)
			{
				return array();
			}

			// Note: the root page itself is NOT added, matching the fork and
			// core - a root page is not indexed.
			$arrIds = $objDatabase->getChildRecords((int) $objRoot->id, 'tl_page');
		}

		return array_values(array_unique(array_map(static fn ($v): int => (int) $v, $arrIds)));
	}

}
