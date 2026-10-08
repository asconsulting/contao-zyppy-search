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
 * The whole answer to one search: the window numbers the client needs to draw
 * its own pager, plus the result items.
 *
 * $strHeader is the ONE field here that carries markup, and it is rendered by
 * search_zyppy_results.html.twig rather than serialised into the JSON payload. MSC.sResults is
 * "Results %s - %s of %s for <strong>%s</strong>" and MSC.sEmpty is
 * "No matches for <strong>%s</strong>" - both ship <strong> in the translation
 * itself, so the string cannot be escaped as a whole. The keyword interpolated
 * into it is therefore escaped in PHP at sprintf() time (see
 * ZyppySearchRunner::buildHeader()), exactly as it was for the Twig port, and
 * the client renders this one string with innerHTML. Everything else is plain
 * text.
 */
final class SearchResults
{

	/**
	 * @param array<SearchResultItem> $arrItems
	 */
	public function __construct(
		public readonly int $intCount,
		public readonly int $intFrom,
		public readonly int $intTo,
		public readonly int $intPage,
		public readonly int $intPerPage,
		public readonly int $intTotalPages,
		public readonly array $arrItems,
		public readonly string $strHeader,
		public readonly string $strKeywordHint,
		public readonly bool $blnOutOfRange = false,
	)
	{
	}

	public static function empty(string $strHeader = '', string $strKeywordHint = ''): self
	{
		return new self(0, 0, 0, 1, 0, 0, array(), $strHeader, $strKeywordHint);
	}

	/**
	 * The window METADATA only - the numbers a client needs to reason about a
	 * result set without parsing markup.
	 *
	 * STEP 10 removed three keys from here. `results` (the per-item array),
	 * `header` and `hint` are all gone, because SearchController now returns a
	 * single `html` string rendered by ResultRenderer and that string already
	 * contains the header, the hint and the rows. Leaving them alongside would
	 * be two representations of one thing, only one of which is consumed - and
	 * the unconsumed one is exactly where drift starts.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return array(
			'count' => $this->intCount,
			'from' => $this->intFrom,
			'to' => $this->intTo,
			'page' => $this->intPage,
			'perPage' => $this->intPerPage,
			'totalPages' => $this->intTotalPages,
		);
	}

}
