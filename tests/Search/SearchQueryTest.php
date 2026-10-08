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
use Zyppy\Search\Search\SearchQuery;


/**
 * The tl_module row -> search settings mapping.
 *
 * This is the boundary where request input meets module configuration, so what
 * it does NOT take from the request matters as much as what it does. It was
 * scattered across the first sixty lines of the fork's compile() and could not
 * be exercised without a container, a ModuleModel and a page.
 */
class SearchQueryTest extends TestCase
{

	private function module(array $arrOverride = array()): array
	{
		return array_merge(array(
			'queryType' => 'and',
			'fuzzy' => '',
			'minKeywordLength' => 4,
			'perPage' => 10,
			'contextLength' => serialize(array(48, 360)),
			'formatPageTeaser' => '1',
			'pageTeaserLimit' => 120,
			'formatPageDescription' => '',
			'pageDescriptionLimit' => 0,
			'formatNewsTeaser' => '1',
			'newsTeaserLimit' => 80,
		), $arrOverride);
	}

	public function testTheKeywordsAreTrimmed(): void
	{
		$this->assertSame('hello world', SearchQuery::fromModuleRow($this->module(), "  hello world \n", null)->strKeywords);
	}

	public function testNullKeywordsBecomeAnEmptyString(): void
	{
		$objQuery = SearchQuery::fromModuleRow($this->module(), null, null);

		$this->assertSame('', $objQuery->strKeywords);
		$this->assertFalse($objQuery->isSearchable());
	}

	/**
	 * A ceiling on an anonymously reachable endpoint. The search itself is
	 * parameterised, so this is not an injection control - it is a bound on how
	 * much work one request can ask for.
	 */
	public function testAnOverlongKeywordStringIsTruncated(): void
	{
		$strLong = str_repeat('a', SearchQuery::MAX_KEYWORD_LENGTH + 50);

		$this->assertSame(
			SearchQuery::MAX_KEYWORD_LENGTH,
			mb_strlen(SearchQuery::fromModuleRow($this->module(), $strLong, null)->strKeywords),
		);
	}

	public function testTruncationCountsCharactersNotBytes(): void
	{
		$strLong = str_repeat("\u{00e4}", SearchQuery::MAX_KEYWORD_LENGTH + 50);

		$this->assertSame(
			SearchQuery::MAX_KEYWORD_LENGTH,
			mb_strlen(SearchQuery::fromModuleRow($this->module(), $strLong, null)->strKeywords),
		);
	}

	/**
	 * THE REFLECTED INSERT TAG SINK. Core reads the keyword through Input::get(),
	 * which encodes `{{` and `}}` as numeric entities; this bundle reads it RAW
	 * off the Request. The fragment then prints it back into the page, and
	 * TemplateInheritance::inherit() runs the insert tag parser over the whole
	 * front end page buffer - so without this encoding a visitor who submits
	 * `?keywords={{env::...}}` has their keyword EVALUATED as an insert tag on
	 * the no-JavaScript path. Encoded here, at the one place the keyword enters,
	 * so the query, the header, the input value and the pager all carry the
	 * entity form - exactly what core hands Search::query() too.
	 */
	public function testInsertTagDelimitersAreEncodedOnTheWayIn(): void
	{
		$objQuery = SearchQuery::fromModuleRow($this->module(), 'TAG4471 {{env::path}} }}', null);

		$this->assertStringContainsString('TAG4471', $objQuery->strKeywords);
		$this->assertStringNotContainsString('{{', $objQuery->strKeywords);
		$this->assertStringNotContainsString('}}', $objQuery->strKeywords);
		$this->assertSame('TAG4471 &#123;&#123;env::path&#125;&#125; &#125;&#125;', $objQuery->strKeywords);
		$this->assertTrue($objQuery->isSearchable());
	}

	public function testTheAsteriskIsNotSearchable(): void
	{
		$this->assertFalse(SearchQuery::fromModuleRow($this->module(), '*', null)->isSearchable());
		$this->assertTrue(SearchQuery::fromModuleRow($this->module(), 'contao', null)->isSearchable());
	}

	public function testTheRequestQueryTypeOverridesTheModuleSetting(): void
	{
		$this->assertTrue(SearchQuery::fromModuleRow($this->module(array('queryType' => 'and')), 'x', 'or')->blnOrSearch);
		$this->assertFalse(SearchQuery::fromModuleRow($this->module(array('queryType' => 'or')), 'x', 'and')->blnOrSearch);
	}

	/**
	 * Anything other than "and"/"or" falls back to the MODULE's setting rather
	 * than silently becoming an "and" search - which would let a junk query
	 * string quietly narrow a module an editor configured as "or".
	 */
	public function testAnUnknownQueryTypeFallsBackToTheModuleSetting(): void
	{
		$this->assertTrue(SearchQuery::fromModuleRow($this->module(array('queryType' => 'or')), 'x', 'nonsense')->blnOrSearch);
		$this->assertTrue(SearchQuery::fromModuleRow($this->module(array('queryType' => 'or')), 'x', null)->blnOrSearch);
		$this->assertTrue(SearchQuery::fromModuleRow($this->module(array('queryType' => 'or')), 'x', '')->blnOrSearch);
	}

	public function testTheContextLengthsComeFromTheSerialisedCorePair(): void
	{
		$objQuery = SearchQuery::fromModuleRow($this->module(array('contextLength' => serialize(array(20, 200)))), 'x', null);

		$this->assertSame(20, $objQuery->intContextLength);
		$this->assertSame(200, $objQuery->intTotalLength);
	}

	/**
	 * `totalLength` never had a field definition of its own - the value lives in
	 * the second element of core's serialised contextLength. An empty or absent
	 * pair has to fall back to core's own defaults.
	 */
	public function testAbsentContextLengthsFallBackToCoreDefaults(): void
	{
		foreach (array(null, '', serialize(array('', '')), serialize(array(0, 0))) as $varValue)
		{
			$objQuery = SearchQuery::fromModuleRow($this->module(array('contextLength' => $varValue)), 'x', null);

			$this->assertSame(SearchQuery::DEFAULT_CONTEXT_LENGTH, $objQuery->intContextLength);
			$this->assertSame(SearchQuery::DEFAULT_TOTAL_LENGTH, $objQuery->intTotalLength);
		}
	}

	/**
	 * The client's minimum keyword length and the server's have to come from one
	 * mapping, or a form that refuses to fire and a server that would have
	 * answered drift apart silently.
	 */
	public function testTheClientMinimumFallsBackWhenTheModuleSetsNone(): void
	{
		$this->assertSame(4, SearchQuery::fromModuleRow($this->module(array('minKeywordLength' => 4)), 'x', null)->clientMinKeywordLength());
		$this->assertSame(SearchQuery::DEFAULT_MIN_KEYWORD_LENGTH, SearchQuery::fromModuleRow($this->module(array('minKeywordLength' => 0)), 'x', null)->clientMinKeywordLength());
	}

	public function testTheRequestedPageIsClampedToAtLeastOne(): void
	{
		$this->assertSame(1, SearchQuery::fromModuleRow($this->module(), 'x', null, 0)->intPage);
		$this->assertSame(1, SearchQuery::fromModuleRow($this->module(), 'x', null, -7)->intPage);
		$this->assertSame(3, SearchQuery::fromModuleRow($this->module(), 'x', null, 3)->intPage);
	}

	/**
	 * perPage, the format flags and every limit come from the MODULE ROW ONLY.
	 * There is deliberately no request parameter for any of them: a client that
	 * could set perPage could ask one request to render the whole index.
	 */
	public function testEveryFormatSettingComesFromTheModuleRow(): void
	{
		$objQuery = SearchQuery::fromModuleRow($this->module(), 'x', null);

		$this->assertSame(10, $objQuery->intPerPage);
		$this->assertTrue($objQuery->blnFormatPageTeaser);
		$this->assertSame(120, $objQuery->intPageTeaserLimit);
		$this->assertFalse($objQuery->blnFormatPageDescription);
		$this->assertSame(0, $objQuery->intPageDescriptionLimit);
		$this->assertTrue($objQuery->blnFormatNewsTeaser);
		$this->assertSame(80, $objQuery->intNewsTeaserLimit);
		$this->assertSame(4, $objQuery->intMinKeywordLength);
		$this->assertFalse($objQuery->blnFuzzy);
	}

	/**
	 * A row missing every optional key - the shape a freshly created module row
	 * has before an editor touches it - must not fatal.
	 */
	public function testAnEmptyModuleRowProducesUsableDefaults(): void
	{
		$objQuery = SearchQuery::fromModuleRow(array(), 'contao', null);

		$this->assertSame('contao', $objQuery->strKeywords);
		$this->assertFalse($objQuery->blnOrSearch);
		$this->assertSame(0, $objQuery->intPerPage);
		$this->assertSame(SearchQuery::DEFAULT_MIN_KEYWORD_LENGTH, $objQuery->clientMinKeywordLength());
		$this->assertSame(SearchQuery::DEFAULT_CONTEXT_LENGTH, $objQuery->intContextLength);
	}

}
