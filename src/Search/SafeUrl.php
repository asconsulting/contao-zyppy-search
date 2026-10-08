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
 * Refuses any URL scheme that is not http(s).
 *
 * THIS MOVED SERVER SIDE IN STEP 10 AND MUST NOT BE DROPPED. Step 7 put the same
 * check in public/js/search.js, because the client was assembling every result
 * with setAttribute. Step 10 moved rendering back into a Twig template for both
 * the server-rendered first page and the live updates, so the client no longer
 * touches a URL at all - and Twig's autoescaping does NOT stop a
 * `javascript:` href. Escaping and scheme checking are different jobs:
 * `javascript:alert(1)` contains no character that htmlspecialchars() touches.
 *
 * So the check lives here, on the way into the DTO, where it covers both render
 * paths at once. tl_search.url is written by Contao's own crawler and should
 * never be hostile, but "should never be" is not a control.
 */
final class SafeUrl
{

	/**
	 * @return string The URL, or '' if it carries a scheme other than http(s).
	 */
	public static function sanitise(string|null $strUrl): string
	{
		// Control characters FIRST: browsers strip them before resolving a URL,
		// so `java<TAB>script:alert(1)` executes as `javascript:` while sailing
		// straight past a scheme pattern.
		$strUrl = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', (string) $strUrl));

		if ('' === $strUrl)
		{
			return '';
		}

		// A scheme-relative //host/path and any ordinary relative path are fine.
		if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $strUrl))
		{
			return preg_match('#^https?:#i', $strUrl) ? $strUrl : '';
		}

		return $strUrl;
	}

}
