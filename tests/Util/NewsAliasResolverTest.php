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
use Zyppy\Search\Util\NewsAliasResolver;


class NewsAliasResolverTest extends TestCase
{

	public function testStripsTheRootPageUrlSuffix(): void
	{
		$this->assertSame('my-article', NewsAliasResolver::aliasFromUrl('news/my-article.html', '.html'));
	}

	public function testStripsAQueryString(): void
	{
		// The original basename($url, '.html') call kept "?page=2" in the alias.
		$this->assertSame('my-article', NewsAliasResolver::aliasFromUrl('news/my-article.html?page=2', '.html'));
	}

	public function testStripsAFragment(): void
	{
		$this->assertSame('my-article', NewsAliasResolver::aliasFromUrl('news/my-article.html#comments', '.html'));
	}

	public function testHandlesAnAbsoluteUrl(): void
	{
		$this->assertSame('my-article', NewsAliasResolver::aliasFromUrl('https://example.com/en/news/my-article.html', '.html'));
	}

	public function testHandlesANestedFolderUrl(): void
	{
		$this->assertSame('my-article', NewsAliasResolver::aliasFromUrl('en/news/2026/my-article.html', '.html'));
	}

	public function testHandlesAnEmptySuffix(): void
	{
		// A root page configured with no URL suffix at all.
		$this->assertSame('my-article', NewsAliasResolver::aliasFromUrl('news/my-article', ''));
	}

	public function testHandlesANonDefaultSuffix(): void
	{
		$this->assertSame('my-article', NewsAliasResolver::aliasFromUrl('news/my-article.php', '.php'));
	}

	public function testLeavesTheAliasAloneWhenTheSuffixDoesNotMatch(): void
	{
		// Hard-coding ".html" (as the original did) silently mangled every
		// site whose root page uses a different suffix; a mismatch must be a
		// no-op, not a partial strip.
		$this->assertSame('my-article.php', NewsAliasResolver::aliasFromUrl('news/my-article.php', '.html'));
	}

	public function testIgnoresATrailingSlash(): void
	{
		$this->assertSame('my-article', NewsAliasResolver::aliasFromUrl('news/my-article/', ''));
	}

	public function testEmptyUrlYieldsAnEmptyAlias(): void
	{
		$this->assertSame('', NewsAliasResolver::aliasFromUrl('', '.html'));
	}

	public function testNullUrlYieldsAnEmptyAlias(): void
	{
		$this->assertSame('', NewsAliasResolver::aliasFromUrl(null, '.html'));
	}

	public function testNullSuffixIsTreatedAsNoSuffix(): void
	{
		// loadDetails()->urlSuffix is null on a page whose root page has none.
		$this->assertSame('my-article.html', NewsAliasResolver::aliasFromUrl('news/my-article.html', null));
	}

}
