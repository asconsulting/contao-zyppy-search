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
 * Which slice of a result set one request should return.
 *
 * This exists because the ModuleSearch fork carried a pre-5.7 pagination block
 * that had already drifted from core twice. Rather than porting that drift
 * forward - or adopting core's contao.pagination.factory, which builds a
 * server rendered pagination MENU that a JSON endpoint has no use for - the
 * arithmetic is derived here from the one thing that is actually stable:
 * SearchResult::getResults(int $intCount, int $intOffset), which is a plain
 * array_slice($results, $intOffset, $intCount).
 *
 * So this class emits an offset/limit pair for that call, plus the 1 based
 * from/to/page numbers the client needs to draw its own pager. It is pure, so
 * the off-by-one that a copy-pasted pagination block hides is testable.
 */
final class ResultWindow
{

	private function __construct(
		public readonly int $intCount,
		public readonly int $intPage,
		public readonly int $intPerPage,
		public readonly int $intFrom,
		public readonly int $intTo,
		public readonly int $intOffset,
		public readonly int $intLimit,
		public readonly int $intTotalPages,
		public readonly bool $blnOutOfRange,
	)
	{
	}

	/**
	 * @param int $intCount   Total number of results AFTER access control filtering.
	 * @param int $intPage    Requested page, 1 based. Values below 1 are clamped.
	 * @param int $intPerPage 0 (or less) means "no pagination - return everything".
	 */
	public static function forPage(int $intCount, int $intPage, int $intPerPage): self
	{
		$intCount = max(0, $intCount);
		$intPerPage = max(0, $intPerPage);
		$intPage = max(1, $intPage);

		// No pagination configured: one page holding the whole result set. The
		// limit is PHP_INT_MAX rather than $intCount so that getResults() is
		// called exactly the way its own default calls it.
		if ($intPerPage < 1)
		{
			return new self(
				$intCount,
				1,
				0,
				$intCount > 0 ? 1 : 0,
				$intCount,
				0,
				PHP_INT_MAX,
				$intCount > 0 ? 1 : 0,
				$intPage > 1,
			);
		}

		$intTotalPages = (int) ceil($intCount / $intPerPage);
		$intOffset = ($intPage - 1) * $intPerPage;

		// An out of range page returns an empty window rather than throwing.
		// The fork threw PageNotFoundException from inside compile(); on a JSON
		// endpoint that would render an HTML error page into a fetch() body, so
		// the decision is handed to the caller through blnOutOfRange instead.
		if ($intCount < 1 || $intPage > $intTotalPages)
		{
			return new self($intCount, $intPage, $intPerPage, 0, 0, $intOffset, $intPerPage, $intTotalPages, $intCount < 1 ? $intPage > 1 : true);
		}

		return new self(
			$intCount,
			$intPage,
			$intPerPage,
			$intOffset + 1,
			min($intOffset + $intPerPage, $intCount),
			$intOffset,
			$intPerPage,
			$intTotalPages,
			false,
		);
	}

}
