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
use Zyppy\Search\Search\SearchResultItem;
use Zyppy\Search\Search\SearchResults;


/**
 * The JSON envelope's METADATA half.
 *
 * STEP 10 SHRANK THIS ON PURPOSE. Step 7 serialised every result item into a
 * `results` array plus `header` and `hint`, and the client assembled the markup.
 * Now SearchController returns a single `html` string rendered by
 * ResultRenderer - the same renderer that produces the server-rendered first
 * page - and that string already contains the header, the hint and the rows.
 *
 * So `toArray()` carries only the window numbers a client needs in order to
 * reason about a result set without parsing markup. Keeping the old keys
 * alongside would be two representations of one thing with only one consumed,
 * which is where drift starts.
 */
class SearchResultsTest extends TestCase
{

	private function item(string $strTitle): SearchResultItem
	{
		return new SearchResultItem($strTitle, '/a.html', $strTitle, '100.00%', '', '', '', null, false);
	}

	public function testTheEnvelopeCarriesExactlyTheWindowMetadata(): void
	{
		$arrData = (new SearchResults(12, 6, 10, 2, 5, 3, array($this->item('A')), 'HEADER', 'HINT'))->toArray();

		$this->assertSame(
			array('count', 'from', 'to', 'page', 'perPage', 'totalPages'),
			array_keys($arrData),
		);

		$this->assertSame(12, $arrData['count']);
		$this->assertSame(6, $arrData['from']);
		$this->assertSame(10, $arrData['to']);
		$this->assertSame(2, $arrData['page']);
		$this->assertSame(5, $arrData['perPage']);
		$this->assertSame(3, $arrData['totalPages']);
	}

	/**
	 * The three keys Step 10 removed. Asserting their ABSENCE is the point: a
	 * half-reverted payload carrying both shapes is the failure mode this
	 * guards against, and it would otherwise pass every other test here.
	 */
	public function testTheRenderedHalfIsNotDuplicatedIntoTheMetadata(): void
	{
		$arrData = (new SearchResults(1, 1, 1, 1, 0, 1, array($this->item('A')), 'HEADER', 'HINT'))->toArray();

		$this->assertArrayNotHasKey('results', $arrData);
		$this->assertArrayNotHasKey('header', $arrData);
		$this->assertArrayNotHasKey('hint', $arrData);
	}

	/**
	 * The header and hint are still CARRIED - ResultRenderer reads them off the
	 * object to render them. They are just not serialised.
	 */
	public function testTheHeaderAndHintAreStillCarriedForTheRenderer(): void
	{
		$objResults = SearchResults::empty('No matches for <strong>x</strong>', 'HINT');

		$this->assertSame('No matches for <strong>x</strong>', $objResults->strHeader);
		$this->assertSame('HINT', $objResults->strKeywordHint);
		$this->assertSame(0, $objResults->intCount);
		$this->assertSame(array(), $objResults->arrItems);
	}

	public function testTheItemsAreCarriedInOrderForTheRenderer(): void
	{
		$objResults = new SearchResults(2, 1, 2, 1, 0, 1, array($this->item('A'), $this->item('B')), '', '');

		$this->assertCount(2, $objResults->arrItems);
		$this->assertSame('A', $objResults->arrItems[0]->strTitle);
		$this->assertSame('B', $objResults->arrItems[1]->strTitle);
	}

	/**
	 * The metadata has to survive json_encode - a SearchResultItem that leaked
	 * into it would encode as {} and tell the client nothing.
	 */
	public function testTheMetadataRoundTripsThroughJson(): void
	{
		$arrDecoded = json_decode(json_encode((new SearchResults(7, 1, 7, 1, 0, 1, array($this->item('ROUND5511')), '', ''))->toArray()), true);

		$this->assertSame(JSON_ERROR_NONE, json_last_error());
		$this->assertSame(7, $arrDecoded['count']);
		$this->assertSame(1, $arrDecoded['totalPages']);
	}

	public function testAnOutOfRangeResultIsFlaggedForTheController(): void
	{
		$objResults = new SearchResults(12, 0, 0, 9, 5, 3, array(), '', '', true);

		$this->assertTrue($objResults->blnOutOfRange);
		$this->assertFalse(SearchResults::empty()->blnOutOfRange);
	}

}
