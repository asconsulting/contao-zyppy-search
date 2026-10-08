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
use Zyppy\Search\Search\ContextBuilder;


/**
 * The keyword-highlighted excerpt, and its escaping contract.
 *
 * `context` is the ONE per-result field the live search client writes with
 * innerHTML rather than textContent, so the guarantee that everything in it is
 * escaped BEFORE the <mark> markup is added is load bearing rather than
 * cosmetic. It was untestable while it lived inside compile().
 *
 * Every assertion carries a sentinel so a builder that silently returned '' -
 * a broken regex, a dropped variant list - cannot pass the "no live script"
 * half vacuously.
 */
class ContextBuilderTest extends TestCase
{

	private function builder(): ContextBuilder
	{
		return new ContextBuilder();
	}

	public function testTheMatchIsWrappedInAHighlight(): void
	{
		$strContext = $this->builder()->build('Alpha beta CTX7734 gamma delta', array('CTX7734'), 48, 360);

		$this->assertStringContainsString('<mark class="highlight">CTX7734</mark>', $strContext);
		$this->assertStringContainsString('Alpha beta', $strContext, 'the surrounding context must come along');
	}

	/**
	 * The empty variant list is the dangerous case: implode('|', array()) makes
	 * the alternation `(?:)`, which matches at every position and would wrap the
	 * whole excerpt - or, worse, be read as "matched everything".
	 */
	public function testAnEmptyVariantListProducesNoContextRatherThanMatchingEverything(): void
	{
		$this->assertSame('', $this->builder()->build('Alpha beta gamma', array(), 48, 360));
		$this->assertSame('', $this->builder()->build('Alpha beta gamma', array('', ''), 48, 360));
	}

	public function testTextWithNoMatchProducesNoContext(): void
	{
		$this->assertSame('', $this->builder()->build('Alpha beta gamma', array('NOTHERE'), 48, 360));
	}

	public function testEmptyTextProducesNoContext(): void
	{
		$this->assertSame('', $this->builder()->build('', array('CTX7734'), 48, 360));
	}

	/**
	 * THE ESCAPING CONTRACT. The indexed page text is attacker influenced (it is
	 * whatever the crawler found on the page), so it must be escaped before the
	 * generated <mark> is layered on top - the client sets this string with
	 * innerHTML.
	 */
	public function testTheSurroundingTextIsEscapedBeforeTheHighlightIsAdded(): void
	{
		$strContext = $this->builder()->build('quote "CTX8811" here', array('CTX8811'), 48, 360);

		// Sentinel: the fixture really did reach the output.
		$this->assertStringContainsString('CTX8811', $strContext);
		$this->assertStringContainsString('&quot;', $strContext, 'the quotes around the match must be entity encoded');
		$this->assertStringNotContainsString('"CTX8811"', $strContext, 'raw quotes must not survive');
		$this->assertStringContainsString('<mark class="highlight">CTX8811</mark>', $strContext);
	}

	public function testAScriptTagInTheIndexedTextNeverBecomesLiveMarkup(): void
	{
		$strContext = $this->builder()->build('lead in <script>alert(1)</script> CTX9922 trailing', array('CTX9922'), 48, 360);

		$this->assertStringContainsString('CTX9922', $strContext, 'the fixture must reach the output');
		$this->assertStringNotContainsString('<script>', $strContext);
		$this->assertStringNotContainsString('</script>', $strContext);
	}

	/**
	 * The only tag the builder is allowed to emit is its own <mark>.
	 */
	public function testTheOnlyMarkupEmittedIsTheHighlight(): void
	{
		$strContext = $this->builder()->build('a <b>bold</b> CTX3355 word', array('CTX3355'), 48, 360);

		$this->assertStringContainsString('CTX3355', $strContext, 'the fixture must reach the output');
		$this->assertSame(
			0,
			preg_match_all('#<(?!/?mark\b)#i', $strContext),
			'nothing but <mark> may reach the output: ' . $strContext,
		);
	}

	public function testEveryOccurrenceOfTheKeywordIsHighlighted(): void
	{
		$strContext = $this->builder()->build('CTX4466 in the middle CTX4466 again', array('CTX4466'), 48, 360);

		$this->assertSame(2, substr_count($strContext, '<mark class="highlight">'));
	}

	public function testAllSuppliedVariantsAreHighlighted(): void
	{
		$strContext = $this->builder()->build('one CTX1111 two CTX2222 three', array('CTX1111', 'CTX2222'), 48, 360);

		$this->assertStringContainsString('<mark class="highlight">CTX1111</mark>', $strContext);
		$this->assertStringContainsString('<mark class="highlight">CTX2222</mark>', $strContext);
	}

	/**
	 * A regex metacharacter in a match variant must be quoted, not compiled.
	 * Contao\Search::getMatchVariants() returns literal words, but the variant
	 * list ultimately derives from the user's keywords.
	 */
	public function testARegexMetacharacterInAVariantIsTreatedAsALiteral(): void
	{
		$strContext = $this->builder()->build('a CTX(5577) here', array('CTX(5577)'), 48, 360);

		$this->assertStringContainsString('CTX(5577)', $strContext);
	}

	/**
	 * The total length caps the excerpt. Contao's substrHtml is what does the
	 * cutting; this checks the cap is actually applied rather than ignored.
	 */
	/**
	 * StringUtil::specialchars() double-encodes by default on Contao 6 and does
	 * not on 5.x. The indexed text is DECODED plain text, so an entity that is
	 * literally part of a page must render the same on every supported version -
	 * which means the double-encode flag has to be passed explicitly rather than
	 * left to a default that changed under us.
	 */
	public function testAnEntityInTheIndexedTextIsNotDoubleEncoded(): void
	{
		$strContext = $this->builder()->build('Tom &amp; Jerry meet CTX6161 today', array('CTX6161'), 48, 360);

		$this->assertStringContainsString('CTX6161', $strContext);
		$this->assertStringContainsString('Tom &amp; Jerry', $strContext);
		$this->assertStringNotContainsString('&amp;amp;', $strContext);
	}

	public function testTheTotalLengthCapsTheExcerpt(): void
	{
		$strText = str_repeat('padding words here ', 40) . 'CTX6688 ' . str_repeat('trailing words ', 40);

		$strLong = $this->builder()->build($strText, array('CTX6688'), 48, 360);
		$strShort = $this->builder()->build($strText, array('CTX6688'), 48, 40);

		$this->assertStringContainsString('CTX6688', $strLong);
		$this->assertLessThan(mb_strlen($strLong), mb_strlen($strShort), 'a smaller total length must produce a shorter excerpt');
	}

}
