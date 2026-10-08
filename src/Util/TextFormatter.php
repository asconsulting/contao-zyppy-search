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
 * Turns a rich text field (page teaser, page description, news teaser) into the
 * short plain text snippet the search result template renders.
 *
 * Extracted verbatim from Zyppy\Search\Module\Search::formatText() so the
 * truncation rules can be tested without booting a Contao module.
 */
class TextFormatter
{

	/**
	 * Strip tags and, when a positive limit is given, truncate on a word
	 * boundary and append a horizontal ellipsis.
	 *
	 * The limit is counted in characters with mb_strlen(), so multi byte text
	 * is not cut short (or mid character) the way strlen() would cut it.
	 *
	 * @param string|null $strTextRaw
	 * @param int         $intLength  0 (or less) means "do not truncate".
	 *
	 * @return string
	 */
	public static function format($strTextRaw, $intLength = 100): string
	{
		$strText = strip_tags((string) $strTextRaw);

		if ((int) $intLength <= 0)
		{
			return $strText;
		}

		$arrTextChunks = preg_split('/\b/', $strText);
		$strTrimmed = '';

		foreach ($arrTextChunks as $strChunk)
		{
			if (mb_strlen($strTrimmed . $strChunk) < (int) $intLength)
			{
				$strTrimmed .= $strChunk;
			}
			else
			{
				// The literal character, NOT the &#8230; entity. The single consumer used to
				// be a raw echo from an .html5; search_zyppy.html.twig escapes its output, so
				// an entity here would render as the visible text "&#8230;". Search::compile()
				// already joins context chunks with the literal character.
				$strTrimmed .= "\u{2026}";
				break;
			}
		}

		return $strTrimmed;
	}

}
