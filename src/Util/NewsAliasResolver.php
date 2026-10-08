<?php

/**
 * @copyright 	Andrew Stevens
 * @author 		Andrew Stevens
 * @license 	AGPL-3.0-only
 * @package 	Zyppy Search
 * @link 		https://github.com/asconsulting/contao-zyppy-search
 */



namespace Zyppy\Search\Util;


/**
 * Recovers a news alias from a search result URL.
 *
 * The search index (tl_search) stores no relation back to the news item that
 * produced a result, so the alias has to be derived from the indexed URL. That
 * is inherently a guess; this class isolates the guess so its edge cases (query
 * strings, fragments, folder URLs, a per root page URL suffix) are testable.
 *
 * It deliberately performs no lookup and no access control of its own — the
 * caller is responsible for resolving the alias against a published, in window
 * news item.
 */
class NewsAliasResolver
{

	/**
	 * Derive the news alias from an indexed result URL.
	 *
	 * @param string|null $strUrl       The URL as stored in tl_search.
	 * @param string|null $strUrlSuffix The root page URL suffix, e.g. ".html".
	 *
	 * @return string The alias, or an empty string if none can be derived.
	 */
	public static function aliasFromUrl($strUrl, $strUrlSuffix = ''): string
	{
		$strPath = (string) parse_url((string) $strUrl, PHP_URL_PATH);
		$strAlias = basename($strPath);
		$strUrlSuffix = (string) $strUrlSuffix;

		if ($strUrlSuffix !== '' && str_ends_with($strAlias, $strUrlSuffix))
		{
			$strAlias = substr($strAlias, 0, -\strlen($strUrlSuffix));
		}

		return $strAlias;
	}

}
