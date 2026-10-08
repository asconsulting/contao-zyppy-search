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


/**
 * Builds the keyword-highlighted excerpt shown under a search result.
 *
 * Lifted verbatim (behaviour wise) out of the ModuleSearch fork's compile()
 * loop. It takes the match variants as a plain array rather than calling
 * Contao\Search::getMatchVariants() itself, which is what keeps it pure: no
 * container, no database, no framework boot - so the regex and the escaping
 * contract below are unit testable.
 *
 * OUTPUT CONTRACT - read this before changing anything here.
 *
 * The returned string is ALREADY ESCAPED text carrying GENERATED markup: the
 * excerpt is run through StringUtil::specialchars() first, and only then does a
 * second pass inject <mark class="highlight">...</mark> around each matched
 * keyword variant. So the caller must NOT escape it again (that would show the
 * reader `&lt;mark ...&gt;`), and the live search client sets it with innerHTML
 * on a plain element rather than textContent. That is the single documented
 * innerHTML sink for per-result data, and it is safe for the same reason the
 * old server rendered path was: the attacker controlled halves - the indexed
 * page text and the keyword - are both escaped before the <mark> is added.
 */
final class ContextBuilder
{

	/**
	 * A character class of "not a letter, or a script that does not use word
	 * boundaries". Core uses exactly this list either side of a match.
	 */
	private const BOUNDARY = '\PL|\p{Hiragana}|\p{Katakana}|\p{Han}|\p{Myanmar}|\p{Khmer}|\p{Lao}|\p{Thai}|\p{Tibetan}';

	/**
	 * @param string        $strText          The indexed page text, insert tags already stripped.
	 * @param array<string> $arrMatchVariants The output of Contao\Search::getMatchVariants().
	 * @param int           $intContextLength Characters of context either side of a match.
	 * @param int           $intTotalLength   Maximum length of the joined excerpt.
	 *
	 * @return string Escaped text with <mark> markup, or '' when nothing matched.
	 */
	public function build(string $strText, array $arrMatchVariants, int $intContextLength, int $intTotalLength): string
	{
		$arrMatchVariants = array_values(array_filter($arrMatchVariants, static fn ($v): bool => '' !== (string) $v));

		// implode() on an empty list would produce `(?:)` and match everywhere,
		// highlighting the entire excerpt. Bail out instead.
		if (!$arrMatchVariants || '' === $strText)
		{
			return '';
		}

		$strAlternation = implode('|', array_map('preg_quote', $arrMatchVariants));
		$intContextLength = max(0, $intContextLength);
		$intTotalLength = max(1, $intTotalLength);

		$arrChunks = array();

		preg_match_all(
			'((^|(?:\b|^).{0,' . $intContextLength . '}(?:' . self::BOUNDARY . '))(?:' . $strAlternation . ')((?:' . self::BOUNDARY . ').{0,' . $intContextLength . '}(?:\b|$)|$))ui',
			$strText,
			$arrChunks,
		);

		if (empty($arrChunks[0]))
		{
			return '';
		}

		$arrContext = array();

		foreach ($arrChunks[0] as $strChunk)
		{
			$arrContext[] = ' ' . $strChunk . ' ';
		}

		// The double-encode flag is passed explicitly: its default flipped to true
		// in Contao 6, and the indexed text is decoded plain text that must render
		// the same on every supported version.
		$strContext = StringUtil::specialchars(trim(StringUtil::substrHtml(implode("\u{2026}", $arrContext), $intTotalLength)), false, false);

		return (string) preg_replace(
			'((?<=^|' . self::BOUNDARY . ')(' . $strAlternation . ')(?=' . self::BOUNDARY . '|$))ui',
			'<mark class="highlight">$1</mark>',
			$strContext,
		);
	}

}
