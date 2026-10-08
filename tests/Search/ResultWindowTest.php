<?php

declare(strict_types=1);

/**
 * @copyright 	Andrew Stevens
 * @author 		Andrew Stevens
 * @license 	AGPL-3.0-only
 * @package 	Zyppy Search
 * @link 		https://github.com/asconsulting/contao-zyppy-search
 */



namespace Zyppy\Search\Tests\Search;

use PHPUnit\Framework\TestCase;
use Zyppy\Search\Search\ResultWindow;


/**
 * The pagination arithmetic the ModuleSearch fork carried as a copy-pasted,
 * twice-drifted block and which nothing could test.
 *
 * The offset/limit pair is checked against what SearchResult::getResults()
 * actually does with it - array_slice($results, $intOffset, $intCount) - rather
 * than against the fork's 1-based expression, so an off-by-one shows up as a
 * wrong first result number instead of as a silently skipped row.
 *
 * No data providers: PHPUnit 9.6 reads only @dataProvider annotations and 12.x
 * reads only #[DataProvider], so a provider has to carry both forms. Plain
 * assertions avoid the trap entirely - see the note at the top of TODO.md.
 */
class ResultWindowTest extends TestCase
{

	public function testAnEmptyResultSetHasAnEmptyWindow(): void
	{
		$objWindow = ResultWindow::forPage(0, 1, 10);

		$this->assertSame(0, $objWindow->intCount);
		$this->assertSame(0, $objWindow->intFrom);
		$this->assertSame(0, $objWindow->intTo);
		$this->assertSame(0, $objWindow->intTotalPages);
		$this->assertFalse($objWindow->blnOutOfRange, 'page 1 of nothing is not an error');
	}

	public function testAskingForPageTwoOfAnEmptyResultSetIsOutOfRange(): void
	{
		$this->assertTrue(ResultWindow::forPage(0, 2, 10)->blnOutOfRange);
	}

	/**
	 * perPage 0 is "no pagination configured", which is the default for this
	 * module. Everything comes back on one page, and the limit is PHP_INT_MAX so
	 * getResults() is called the way its own default calls it.
	 */
	public function testWithNoPaginationEverythingIsOnOnePage(): void
	{
		$objWindow = ResultWindow::forPage(37, 1, 0);

		$this->assertSame(1, $objWindow->intFrom);
		$this->assertSame(37, $objWindow->intTo);
		$this->assertSame(0, $objWindow->intOffset);
		$this->assertSame(PHP_INT_MAX, $objWindow->intLimit);
		$this->assertSame(1, $objWindow->intTotalPages);
		$this->assertFalse($objWindow->blnOutOfRange);
	}

	public function testTheFirstPageStartsAtOffsetZero(): void
	{
		$objWindow = ResultWindow::forPage(12, 1, 5);

		$this->assertSame(1, $objWindow->intFrom);
		$this->assertSame(5, $objWindow->intTo);
		$this->assertSame(0, $objWindow->intOffset);
		$this->assertSame(5, $objWindow->intLimit);
		$this->assertSame(3, $objWindow->intTotalPages);
	}

	public function testAMiddlePageIsOffsetByAWholeNumberOfPages(): void
	{
		$objWindow = ResultWindow::forPage(12, 2, 5);

		$this->assertSame(6, $objWindow->intFrom);
		$this->assertSame(10, $objWindow->intTo);
		$this->assertSame(5, $objWindow->intOffset);
		$this->assertSame(5, $objWindow->intLimit);
	}

	/**
	 * The last page is short. `to` must be the real count, not offset+perPage -
	 * that is the number the "Results 11 - 12 of 12" header prints.
	 */
	public function testTheLastPageIsClampedToTheRealCount(): void
	{
		$objWindow = ResultWindow::forPage(12, 3, 5);

		$this->assertSame(11, $objWindow->intFrom);
		$this->assertSame(12, $objWindow->intTo);
		$this->assertSame(10, $objWindow->intOffset);
		$this->assertSame(5, $objWindow->intLimit, 'the limit stays perPage; array_slice simply runs out');
		$this->assertSame(3, $objWindow->intTotalPages);
		$this->assertFalse($objWindow->blnOutOfRange);
	}

	public function testAPageBeyondTheLastOneIsOutOfRange(): void
	{
		$objWindow = ResultWindow::forPage(12, 4, 5);

		$this->assertTrue($objWindow->blnOutOfRange);
		$this->assertSame(3, $objWindow->intTotalPages);
		$this->assertSame(0, $objWindow->intFrom, 'an out of range window reports no results rather than a negative range');
	}

	public function testAnExactlyFullLastPageIsNotOutOfRange(): void
	{
		$objWindow = ResultWindow::forPage(10, 2, 5);

		$this->assertFalse($objWindow->blnOutOfRange);
		$this->assertSame(6, $objWindow->intFrom);
		$this->assertSame(10, $objWindow->intTo);
		$this->assertSame(2, $objWindow->intTotalPages);
	}

	/**
	 * The fork threw PageNotFoundException for page < 1 straight out of
	 * compile(). Negative and zero page numbers are attacker reachable query
	 * string values, so they are clamped rather than made into an exception.
	 */
	public function testANonPositivePageIsClampedToTheFirstPage(): void
	{
		foreach (array(0, -1, -999) as $intPage)
		{
			$objWindow = ResultWindow::forPage(12, $intPage, 5);

			$this->assertSame(1, $objWindow->intPage);
			$this->assertSame(1, $objWindow->intFrom);
			$this->assertFalse($objWindow->blnOutOfRange);
		}
	}

	/**
	 * The offset/limit pair has to slice the array the way the numbers say it
	 * does. This runs the real array_slice that SearchResult::getResults()
	 * performs, so an off-by-one cannot hide behind agreeing from/to numbers.
	 */
	public function testTheOffsetAndLimitSliceExactlyTheRowsFromAndToDescribe(): void
	{
		$arrRows = range(1, 12);

		foreach (array(1, 2, 3) as $intPage)
		{
			$objWindow = ResultWindow::forPage(12, $intPage, 5);
			$arrSlice = \array_slice($arrRows, $objWindow->intOffset, $objWindow->intLimit);

			$this->assertSame($objWindow->intFrom, reset($arrSlice), 'page ' . $intPage . ' must start at `from`');
			$this->assertSame($objWindow->intTo, end($arrSlice), 'page ' . $intPage . ' must end at `to`');
			$this->assertCount($objWindow->intTo - $objWindow->intFrom + 1, $arrSlice);
		}
	}

}
