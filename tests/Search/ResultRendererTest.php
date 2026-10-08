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

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Zyppy\Search\Search\ResultRenderer;
use Zyppy\Search\Search\SearchQuery;
use Zyppy\Search\Search\SearchResultItem;
use Zyppy\Search\Search\SearchResults;


/**
 * The SINGLE renderer, exercised end to end against this bundle's REAL template
 * files.
 *
 * This is the closest mechanical equivalent of "prove the server-rendered and
 * client-rendered results are the same markup" - and it is stronger than that
 * test would have been, because there is only one renderer to check. Step 7 had
 * two: a Twig template for nothing and a JavaScript assembler for everything,
 * which meant tl_module.searchTpl applied to the first paint and silently not
 * to any update after it. No test in this repo can execute search.js, so an
 * equivalence test between two renderers was never buildable anyway.
 *
 * ContaoFramework::createInstance() is stubbed to hand back a template object
 * that renders through a plain Twig Environment over contao/templates/. Contao's
 * own FrontendTemplate would resolve the identifier through
 * ContaoFilesystemLoader and then render exactly this way - neither template
 * uses a Contao function, filter or tag - so what comes out here is the real
 * markup, minus the loader. The loader half is pinned separately, against core's
 * own TemplateLocator, in tests/Template/TemplateIdentifierTest.php.
 */
class ResultRendererTest extends TestCase
{

	protected function setUp(): void
	{
		parent::setUp();

		$GLOBALS['TL_LANG']['MSC']['previous'] = 'Previous';
		$GLOBALS['TL_LANG']['MSC']['next'] = 'Next';
	}

	protected function tearDown(): void
	{
		unset($GLOBALS['TL_LANG']['MSC']['previous'], $GLOBALS['TL_LANG']['MSC']['next']);

		parent::tearDown();
	}

	private function renderer(): ResultRenderer
	{
		$strBase = \dirname(__DIR__, 2) . '/contao/templates';

		$objTwig = new Environment(
			new FilesystemLoader(array($strBase . '/modules', $strBase . '/search')),
			array('autoescape' => 'html', 'cache' => false),
		);

		$objFramework = $this->createStub(ContaoFramework::class);
		$objFramework
			->method('createInstance')
			->willReturnCallback(
				static fn (string $strClass, array $arrArgs = array()): object => new class($objTwig, (string) ($arrArgs[0] ?? '')) {
					private array $arrData = array();

					public function __construct(private readonly Environment $objTwig, private readonly string $strName)
					{
					}

					public function setData(array $arrData): void
					{
						$this->arrData = $arrData;
					}

					public function parse(): string
					{
						return $this->objTwig->render($this->strName . '.html.twig', $this->arrData);
					}
				},
			)
		;

		return new ResultRenderer($objFramework);
	}

	private function query(array $arrOverride = array(), string $strKeywords = 'contao'): SearchQuery
	{
		return SearchQuery::fromModuleRow($arrOverride, $strKeywords, $arrOverride['queryType'] ?? null);
	}

	private function item(string $strTitle, array $arrOverride = array()): SearchResultItem
	{
		return new SearchResultItem(
			$strTitle,
			$arrOverride['url'] ?? '/a-page.html',
			$arrOverride['titleAttribute'] ?? $strTitle,
			$arrOverride['relevance'] ?? '100.00%',
			$arrOverride['context'] ?? '',
			$arrOverride['teaser'] ?? '',
			$arrOverride['description'] ?? '',
			$arrOverride['image'] ?? null,
			$arrOverride['isNews'] ?? false,
		);
	}

	private function results(array $arrItems, array $arrOverride = array()): SearchResults
	{
		return new SearchResults(
			$arrOverride['count'] ?? \count($arrItems),
			$arrOverride['from'] ?? 1,
			$arrOverride['to'] ?? \count($arrItems),
			$arrOverride['page'] ?? 1,
			$arrOverride['perPage'] ?? 0,
			$arrOverride['totalPages'] ?? 1,
			$arrItems,
			$arrOverride['header'] ?? '',
			$arrOverride['hint'] ?? '',
		);
	}

	public function testEveryResultRowReachesTheOutput(): void
	{
		$strHtml = $this->renderer()->render(
			$this->results(array($this->item('ROW1188'), $this->item('ROW2299'))),
			$this->query(),
		);

		$this->assertStringContainsString('ROW1188', $strHtml);
		$this->assertStringContainsString('ROW2299', $strHtml);
	}

	/**
	 * The row classes come from SearchResultItem::toTemplateData(), so an
	 * off-by-one in the first/last/even/odd arithmetic shows up here rather
	 * than in a stylesheet on a live site.
	 */
	public function testTheRowClassesMarkFirstLastAndAlternation(): void
	{
		$strHtml = $this->renderer()->render(
			$this->results(array($this->item('A'), $this->item('B'), $this->item('C'))),
			$this->query(),
		);

		$this->assertStringContainsString('<div class="first even">', $strHtml);
		$this->assertStringContainsString('<div class="odd">', $strHtml);
		$this->assertStringContainsString('<div class="last even">', $strHtml);
	}

	public function testASingleResultIsBothFirstAndLast(): void
	{
		$strHtml = $this->renderer()->render($this->results(array($this->item('ONLY'))), $this->query());

		$this->assertStringContainsString('<div class="first last even">', $strHtml);
	}

	/**
	 * tl_module.searchTpl selecting a different per-result template is the whole
	 * reason the field stopped being inert. `mod_search_zyppy` is not a sensible
	 * result template, but it IS a real template in this bundle, which makes it
	 * a usable stand-in for "not the default".
	 */
	public function testTheSearchTplOverridesTheDefaultResultTemplate(): void
	{
		$strDefault = $this->renderer()->render($this->results(array($this->item('PICK4411'))), $this->query());
		$strCustom = $this->renderer()->render($this->results(array($this->item('PICK4411'))), $this->query(), 'mod_search_zyppy');

		$this->assertStringContainsString('<div class="first last even">', $strDefault);
		$this->assertStringNotContainsString('name="keywords"', $strDefault);

		// The override really was used: the stand-in template renders a form.
		$this->assertStringContainsString('name="keywords"', $strCustom);
	}

	public function testTheHeaderKeepsItsTranslationMarkupWhileTheHintEscapes(): void
	{
		$strHtml = $this->renderer()->render(
			$this->results(array($this->item('A')), array(
				'header' => 'Results 1 - 1 of 1 for <strong>HEAD5511</strong>',
				'hint' => 'HINT6622 <script>alert(1)</script>',
			)),
			$this->query(),
		);

		$this->assertStringContainsString('<strong>HEAD5511</strong>', $strHtml);
		$this->assertStringContainsString('HINT6622', $strHtml);
		$this->assertStringContainsString('&lt;script&gt;', $strHtml);
		$this->assertStringNotContainsString('<script>alert', $strHtml);
	}

	public function testThereIsNoPagerWhenEverythingFitsOnOnePage(): void
	{
		$strHtml = $this->renderer()->render($this->results(array($this->item('A'))), $this->query());

		$this->assertStringNotContainsString('class="pagination"', $strHtml);
	}

	/**
	 * THE NO-JAVASCRIPT PAGER. Real links, and query-string-only so the same
	 * markup resolves against the page whether it was rendered into it or
	 * fetched from /_zyppy/search/{moduleId} - the route does not know the page
	 * URL and must never be told it by the client.
	 */
	public function testThePagerRendersRealQueryStringOnlyLinks(): void
	{
		$strHtml = $this->renderer()->render(
			$this->results(array($this->item('A')), array('count' => 12, 'page' => 2, 'perPage' => 5, 'from' => 6, 'to' => 10, 'totalPages' => 3)),
			$this->query(array('perPage' => 5), 'contao'),
		);

		$this->assertStringContainsString('class="pagination"', $strHtml);
		$this->assertStringContainsString('href="?keywords=contao"', $strHtml, 'page 1 carries no page parameter');
		$this->assertStringContainsString('href="?keywords=contao&amp;page=3"', $strHtml);
		$this->assertStringContainsString('data-zyppy-page="1"', $strHtml);
		$this->assertStringContainsString('data-zyppy-page="3"', $strHtml);
		$this->assertStringContainsString('Previous', $strHtml);
		$this->assertStringContainsString('Next', $strHtml);
		$this->assertStringContainsString('2 / 3', $strHtml);

		// A relative, query-string-only href - never an absolute one, and never
		// one pointing at the route.
		$this->assertStringNotContainsString('href="/_zyppy', $strHtml);
		$this->assertStringNotContainsString('href="http', $strHtml);
	}

	public function testTheFirstPageHasNoPreviousLinkAndTheLastNoNext(): void
	{
		$strFirst = $this->renderer()->render(
			$this->results(array($this->item('A')), array('count' => 12, 'page' => 1, 'perPage' => 5, 'totalPages' => 3)),
			$this->query(array('perPage' => 5)),
		);

		$this->assertStringNotContainsString('rel="prev"', $strFirst);
		$this->assertStringContainsString('rel="next"', $strFirst);

		$strLast = $this->renderer()->render(
			$this->results(array($this->item('A')), array('count' => 12, 'page' => 3, 'perPage' => 5, 'totalPages' => 3)),
			$this->query(array('perPage' => 5)),
		);

		$this->assertStringContainsString('rel="prev"', $strLast);
		$this->assertStringNotContainsString('rel="next"', $strLast);
	}

	/**
	 * The keyword goes into a pager href, so it is URL-encoded on the way in and
	 * HTML-escaped on the way out. Getting either wrong breaks the link or opens
	 * an attribute.
	 */
	public function testAHostileKeywordCannotEscapeAPagerHref(): void
	{
		$strHtml = $this->renderer()->render(
			$this->results(array($this->item('A')), array('count' => 12, 'page' => 1, 'perPage' => 5, 'totalPages' => 3)),
			$this->query(array('perPage' => 5), 'BREAK7733" onclick="alert(1)'),
		);

		$this->assertStringContainsString('BREAK7733', $strHtml, 'the fixture must reach the output');
		$this->assertStringNotContainsString('onclick="alert(1)"', $strHtml);
		$this->assertStringNotContainsString('BREAK7733" onclick', $strHtml);
	}

	/**
	 * The Step 7 escaping guarantees have to survive the move back to a
	 * template: the indexed title escapes, and the pre-escaped context keeps its
	 * generated <mark> markup.
	 */
	public function testTheEscapingContractSurvivesTheWholeRenderPath(): void
	{
		$strHtml = $this->renderer()->render(
			$this->results(array($this->item('TITLE9911 <script>alert(1)</script>', array(
				'teaser' => 'TEASER8822 <script>alert(1)</script>',
				'context' => 'text with <mark class="highlight">CTX7733</mark> in it',
			)))),
			$this->query(),
		);

		$this->assertStringContainsString('TITLE9911', $strHtml);
		$this->assertStringContainsString('TEASER8822', $strHtml);
		$this->assertStringContainsString('&lt;script&gt;', $strHtml);
		$this->assertStringNotContainsString('<script>alert', $strHtml);
		$this->assertStringContainsString('<mark class="highlight">CTX7733</mark>', $strHtml);
	}

	/**
	 * A URL that SafeUrl refused arrives as '', and must degrade to a non-link
	 * rather than to `href=""`.
	 */
	public function testARefusedUrlRendersAsPlainTextRatherThanAnEmptyLink(): void
	{
		$strHtml = $this->renderer()->render(
			$this->results(array($this->item('NOLINK5544', array('url' => '')))),
			$this->query(),
		);

		$this->assertStringContainsString('NOLINK5544', $strHtml);
		$this->assertStringNotContainsString('href=""', $strHtml);
		$this->assertStringNotContainsString('<a ', $strHtml);
	}

	/**
	 * The image attribute set built by contao.image.studio - which is what makes
	 * tl_module.imgSize do anything at all.
	 */
	public function testTheStudioImageAttributesReachTheMarkup(): void
	{
		$strHtml = $this->renderer()->render(
			$this->results(array($this->item('A', array('image' => array(
				'src' => '/assets/images/IMG3311.jpg',
				'srcset' => '/assets/images/IMG3311.jpg 1x, /assets/images/IMG3311@2x.jpg 2x',
				'sizes' => '100vw',
				'width' => 320,
				'height' => 200,
				'alt' => 'ALT4422',
			))))),
			$this->query(),
		);

		$this->assertStringContainsString('src="/assets/images/IMG3311.jpg"', $strHtml);
		$this->assertStringContainsString('srcset="/assets/images/IMG3311.jpg 1x, /assets/images/IMG3311@2x.jpg 2x"', $strHtml);
		$this->assertStringContainsString('sizes="100vw"', $strHtml);
		$this->assertStringContainsString('width="320"', $strHtml);
		$this->assertStringContainsString('height="200"', $strHtml);
		$this->assertStringContainsString('alt="ALT4422"', $strHtml);
	}

	public function testAResultWithNoImageRendersNoImgTag(): void
	{
		$strHtml = $this->renderer()->render($this->results(array($this->item('A'))), $this->query());

		$this->assertStringNotContainsString('<img', $strHtml);
	}

	public function testAnEmptyResultSetStillRendersItsHeader(): void
	{
		$strHtml = $this->renderer()->render(
			SearchResults::empty('No matches for <strong>NONE2211</strong>'),
			$this->query(),
		);

		$this->assertStringContainsString('<strong>NONE2211</strong>', $strHtml);
		$this->assertStringNotContainsString('<div class="', $strHtml, 'there are no result rows to render');
	}

}
