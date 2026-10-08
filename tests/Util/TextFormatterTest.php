<?php

/**
 * @copyright 	Andrew Stevens
 * @author 		Andrew Stevens
 * @license 	AGPL-3.0-only
 * @package 	Zyppy Search
 * @link 		https://github.com/asconsulting/contao-zyppy-search
 */



namespace Zyppy\Search\Tests\Util;

use PHPUnit\Framework\TestCase;
use Zyppy\Search\Util\TextFormatter;


class TextFormatterTest extends TestCase
{

	public function testStripsTags(): void
	{
		$this->assertSame('Hello world', TextFormatter::format('<p>Hello <b>world</b></p>', 0));
	}

	public function testZeroLengthMeansNoTruncation(): void
	{
		$strLong = str_repeat('word ', 200);

		$this->assertSame($strLong, TextFormatter::format($strLong, 0));
	}

	public function testNegativeLengthMeansNoTruncation(): void
	{
		$this->assertSame('Hello world', TextFormatter::format('Hello world', -1));
	}

	public function testNullDegradesToAnEmptyString(): void
	{
		// The zyppy-page columns this is fed from do not exist when that
		// bundle is absent, so Model::__get() hands us null.
		$this->assertSame('', TextFormatter::format(null, 100));
	}

	public function testShortTextIsReturnedWithoutAnEllipsis(): void
	{
		$this->assertSame('The quick brown fox jumps', TextFormatter::format('The quick brown fox jumps', 100));
	}

	public function testTruncatesOnAWordBoundaryAndAppendsAnEllipsis(): void
	{
		$this->assertSame("The quick\u{2026}", TextFormatter::format('The quick brown fox jumps', 10));
	}

	public function testTruncationCountsCharactersNotBytes(): void
	{
		// strlen() would cut "café" mid character here and emit a broken UTF-8
		// sequence; mb_strlen() keeps the accent intact.
		$strResult = TextFormatter::format('Le café est très chaud', 12);

		$this->assertSame("Le café est\u{2026}", $strResult);
		$this->assertTrue(mb_check_encoding($strResult, 'UTF-8'));
	}

	public function testEmptyStringStaysEmpty(): void
	{
		$this->assertSame('', TextFormatter::format('', 100));
	}

	public function testTagsAreStrippedBeforeTheLengthIsCounted(): void
	{
		// The markup must not eat the character budget.
		$this->assertSame("The quick\u{2026}", TextFormatter::format('<em>The</em> <strong>quick</strong> brown fox', 10));
	}

}
