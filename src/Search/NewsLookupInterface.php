<?php

/**
 * @copyright 	Andrew Stevens
 * @author 		Andrew Stevens
 * @license 	AGPL-3.0-only
 * @package 	Zyppy Search
 * @link 		https://github.com/asconsulting/contao-zyppy-search
 */



namespace Zyppy\Search\Search;


/**
 * Resolves a news alias to a news row, or to null.
 *
 * The IMPLEMENTATION owes the caller an access control guarantee that the
 * interface cannot express in a type: it must only ever return a currently
 * published, in-window item. That is the Step 2 control, and it stays with the
 * lookup rather than with the caller so there is exactly one place to get it
 * right. See ContaoNewsLookup.
 *
 * contao/news-bundle is a suggest, not a require, so an implementation must
 * also cope with Contao\NewsModel not existing at all.
 */
interface NewsLookupInterface
{

	/**
	 * @param string $strAlias The alias recovered from the indexed result URL.
	 *
	 * @return array<string, mixed>|null The tl_news row, or null.
	 */
	public function findPublishedByAlias(string $strAlias): array|null;

}
