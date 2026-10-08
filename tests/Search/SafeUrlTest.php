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
use Zyppy\Search\Search\SafeUrl;


/**
 * The URL scheme guard.
 *
 * THIS CHECK MOVED, IT WAS NOT DROPPED. Step 7 had it in
 * public/js/search.js, because the client assembled every result with
 * setAttribute. Step 10 moved rendering back into a Twig template for both
 * paths, so the client never touches a URL - and Twig's autoescaping does NOT
 * stop a `javascript:` href, because htmlspecialchars() touches no character in
 * `javascript:alert(1)`. Escaping and scheme checking are different jobs.
 *
 * So the guard is server side now, on the way into the DTO, covering the
 * server-rendered first page and every live update at once.
 */
class SafeUrlTest extends TestCase
{

	public function testAnOrdinaryRelativeUrlIsKept(): void
	{
		$this->assertSame('a-page.html', SafeUrl::sanitise('a-page.html'));
		$this->assertSame('/en/a-page.html', SafeUrl::sanitise('/en/a-page.html'));
		$this->assertSame('/en/a-page.html?x=1#frag', SafeUrl::sanitise('/en/a-page.html?x=1#frag'));
	}

	public function testHttpAndHttpsAreKept(): void
	{
		$this->assertSame('http://example.com/a', SafeUrl::sanitise('http://example.com/a'));
		$this->assertSame('https://example.com/a', SafeUrl::sanitise('https://example.com/a'));
		$this->assertSame('HTTPS://example.com/a', SafeUrl::sanitise('HTTPS://example.com/a'));
	}

	public function testASchemeRelativeUrlIsKept(): void
	{
		$this->assertSame('//cdn.example.com/a.html', SafeUrl::sanitise('//cdn.example.com/a.html'));
	}

	public function testJavascriptIsRefused(): void
	{
		$this->assertSame('', SafeUrl::sanitise('javascript:alert(1)'));
		$this->assertSame('', SafeUrl::sanitise('JaVaScRiPt:alert(1)'));
		$this->assertSame('', SafeUrl::sanitise('  javascript:alert(1)  '));
	}

	/**
	 * THE CASE A NAIVE SCHEME PATTERN MISSES. Browsers strip control characters
	 * before resolving a URL, so `java<TAB>script:` executes as `javascript:`
	 * while sailing straight past `^[a-z][a-z0-9+.-]*:`. Stripping has to happen
	 * FIRST, which is why the order in SafeUrl::sanitise() is load bearing.
	 */
	public function testControlCharactersCannotSmuggleAScheme(): void
	{
		$this->assertSame('', SafeUrl::sanitise("java\tscript:alert(1)"));
		$this->assertSame('', SafeUrl::sanitise("java\nscript:alert(1)"));
		$this->assertSame('', SafeUrl::sanitise("java\x00script:alert(1)"));
		$this->assertSame('', SafeUrl::sanitise("\x01javascript:alert(1)"));
	}

	public function testOtherHostileSchemesAreRefused(): void
	{
		$this->assertSame('', SafeUrl::sanitise('data:text/html;base64,PHNjcmlwdD4='));
		$this->assertSame('', SafeUrl::sanitise('vbscript:msgbox(1)'));
		$this->assertSame('', SafeUrl::sanitise('file:///etc/passwd'));
	}

	public function testEmptyAndNullBecomeAnEmptyString(): void
	{
		$this->assertSame('', SafeUrl::sanitise(null));
		$this->assertSame('', SafeUrl::sanitise(''));
		$this->assertSame('', SafeUrl::sanitise('   '));
		$this->assertSame('', SafeUrl::sanitise("\t\n"));
	}

	/**
	 * A colon in a path or query is not a scheme, and must not be mistaken for
	 * one - that would silently blank ordinary links.
	 */
	public function testAColonThatIsNotASchemeDoesNotTriggerARefusal(): void
	{
		$this->assertSame('/notes/10:30-meeting.html', SafeUrl::sanitise('/notes/10:30-meeting.html'));
		$this->assertSame('a-page.html?t=10:30', SafeUrl::sanitise('a-page.html?t=10:30'));
	}

}
