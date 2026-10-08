<?php

/**
 * @copyright 	Andrew Stevens
 * @author 		Andrew Stevens
 * @license 	AGPL-3.0-only
 * @package 	Zyppy Search
 * @link 		https://github.com/asconsulting/contao-zyppy-search
 */



namespace Zyppy\Search\Search;

use Contao\Input;
use Contao\StringUtil;


/**
 * Everything ZyppySearchRunner needs to answer one search, folded out of a
 * tl_module row and the request's query string.
 *
 * Deliberately free of Contao services: the only Contao calls are the static,
 * container free StringUtil::deserialize() and Input::encodeInsertTags(). That is what makes the whole
 * request-to-settings mapping unit testable without booting a site, which the
 * ModuleSearch fork's compile() never was.
 */
final class SearchQuery
{

	/**
	 * Upper bound on the keyword string. The search itself is parameterised, so
	 * this is not an injection control - it is a cheap ceiling on the work one
	 * request can ask for, on an endpoint that is reachable anonymously.
	 */
	public const MAX_KEYWORD_LENGTH = 200;

	/**
	 * Core's own defaults from ModuleSearch::compile().
	 */
	public const DEFAULT_CONTEXT_LENGTH = 48;
	public const DEFAULT_TOTAL_LENGTH = 360;

	/**
	 * Fallback minimum keyword length handed to the live search client when the
	 * module itself does not set one.
	 */
	public const DEFAULT_MIN_KEYWORD_LENGTH = 3;

	private function __construct(
		public readonly string $strKeywords,
		public readonly bool $blnOrSearch,
		public readonly bool $blnFuzzy,
		public readonly int $intMinKeywordLength,
		public readonly int $intPage,
		public readonly int $intPerPage,
		public readonly int $intContextLength,
		public readonly int $intTotalLength,
		public readonly bool $blnFormatPageTeaser,
		public readonly int $intPageTeaserLimit,
		public readonly bool $blnFormatPageDescription,
		public readonly int $intPageDescriptionLimit,
		public readonly bool $blnFormatNewsTeaser,
		public readonly int $intNewsTeaserLimit,
		public readonly mixed $varImgSize = null,
	)
	{
	}

	/**
	 * Build a query from a tl_module row plus the three request inputs the
	 * client is allowed to influence.
	 *
	 * NOTE what is deliberately NOT taken from the client: the page scope, the
	 * per-page size and every format/limit setting. Those come from the module
	 * row only. A client that could widen its own page scope could search past
	 * the module's configured reference pages; see PageScopeResolver.
	 *
	 * @param array<string, mixed> $arrModule    A tl_module row.
	 * @param string|null          $strKeywords  Raw keywords from the query string.
	 * @param string|null          $strQueryType Raw query_type from the query string.
	 * @param int                  $intPage      Requested result page, 1 based.
	 */
	public static function fromModuleRow(array $arrModule, string|null $strKeywords, string|null $strQueryType, int $intPage = 1): self
	{
		// Core reads the keyword through Input::get(), which encodes `{{` and `}}`
		// as numeric entities. This bundle reads it raw off the Request, and the
		// fragment reflects it into a page buffer that TemplateInheritance runs
		// the insert tag parser over - so the same encoding is applied here, at
		// the one place the keyword enters. Search::query() then receives exactly
		// what core hands it.
		$strKeywords = Input::encodeInsertTags(trim((string) $strKeywords));

		if (mb_strlen($strKeywords) > self::MAX_KEYWORD_LENGTH)
		{
			$strKeywords = mb_substr($strKeywords, 0, self::MAX_KEYWORD_LENGTH);
		}

		// Only "and" and "or" are meaningful; anything else falls back to the
		// module's own setting rather than silently becoming an "and" search.
		$strQueryType = \in_array($strQueryType, array('and', 'or'), true)
			? $strQueryType
			: (string) ($arrModule['queryType'] ?? 'and');

		$arrLengths = StringUtil::deserialize($arrModule['contextLength'] ?? null, true);

		$intContextLength = ((int) ($arrLengths[0] ?? 0)) > 0 ? (int) $arrLengths[0] : self::DEFAULT_CONTEXT_LENGTH;
		$intTotalLength = ((int) ($arrLengths[1] ?? 0)) > 0 ? (int) $arrLengths[1] : self::DEFAULT_TOTAL_LENGTH;

		return new self(
			$strKeywords,
			'or' === $strQueryType,
			(bool) ($arrModule['fuzzy'] ?? false),
			max(0, (int) ($arrModule['minKeywordLength'] ?? 0)),
			max(1, $intPage),
			max(0, (int) ($arrModule['perPage'] ?? 0)),
			$intContextLength,
			$intTotalLength,
			(bool) ($arrModule['formatPageTeaser'] ?? false),
			max(0, (int) ($arrModule['pageTeaserLimit'] ?? 0)),
			(bool) ($arrModule['formatPageDescription'] ?? false),
			max(0, (int) ($arrModule['pageDescriptionLimit'] ?? 0)),
			(bool) ($arrModule['formatNewsTeaser'] ?? false),
			max(0, (int) ($arrModule['newsTeaserLimit'] ?? 0)),
			// Core's own imgSize field, deserialised. It is either a [width,
			// height, mode] array or a tl_image_size id, and FigureBuilder's
			// setSize() accepts both shapes as-is - so it is passed straight
			// through rather than interpreted here. Until Step 10 nothing read
			// this field at all, which is why it sat in the palette doing
			// nothing.
			StringUtil::deserialize($arrModule['imgSize'] ?? null),
		);
	}

	/**
	 * The minimum length the live search client should enforce before it fires
	 * a request at all.
	 */
	public function clientMinKeywordLength(): int
	{
		return $this->intMinKeywordLength > 0 ? $this->intMinKeywordLength : self::DEFAULT_MIN_KEYWORD_LENGTH;
	}

	/**
	 * Whether these keywords are worth running a search for at all. "*" is
	 * core's own "match everything" placeholder and is refused here exactly as
	 * ModuleSearch refuses it.
	 */
	public function isSearchable(): bool
	{
		return '' !== $this->strKeywords && '*' !== $this->strKeywords;
	}

}
