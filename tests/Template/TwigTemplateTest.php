<?php

declare(strict_types=1);

/**
 * @copyright 	Andrew Stevens
 * @author 		Andrew Stevens
 * @license 	AGPL-3.0-only
 * @package 	Zyppy Search
 * @link 		https://github.com/asconsulting/contao-zyppy-search
 */



namespace Zyppy\Search\Tests\Template;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;


/**
 * Renders the three Twig templates for real.
 *
 * All three use only core Twig - default, the ternary, comparison and |raw -
 * and no Contao function, filter or tag, so a plain Environment with HTML
 * autoescaping renders them exactly as Contao's does. That makes the escaping
 * contract testable with no site and on every supported Contao version.
 *
 * The `asset()` Twig function is deliberately NOT used by any of them: the
 * script URL is resolved in ZyppySearchController through Symfony's Packages
 * service and handed over as a plain string, precisely so this bare Environment
 * stays a faithful stand-in for Contao's.
 *
 * Every escaping assertion carries a SENTINEL: a unique token in the same
 * fixture value. Asserting only "no <script> in the output" passes vacuously
 * when the template never printed the variable at all (a typo in the name, a
 * dropped line). The sentinel is what catches that.
 *
 * Three of the four templates are rendered here: the two form shells
 * (mod_search_zyppy, frontend_module/zyppy_search) and the per-result template
 * (search_zyppy). Since Step 10 both shells render `results` and reflect
 * `keyword` again, and both are pinned below. The results wrapper
 * (search_zyppy_results) is rendered end to end, through ResultRenderer, in
 * tests/Search/ResultRendererTest.php.
 *
 * One caveat about the stand-in: Twig's stock `html` strategy double-encodes
 * existing entities, whereas Contao's `contao_html` escaper does not when the
 * value carries no quote or angle bracket. Every fixture below contains one of
 * those characters or carries no entity at all, so the two strategies agree
 * on everything asserted here.
 */
class TwigTemplateTest extends TestCase
{

	private function twig(): Environment
	{
		$strBase = \dirname(__DIR__, 2) . '/contao/templates';

		$objLoader = new FilesystemLoader(array(
			$strBase . '/modules',
			$strBase . '/search',
			$strBase . '/twig/frontend_module',
		));

		// Neither template uses a Contao function, filter or tag, so a plain
		// Environment renders them exactly as Contao's does.

		// Contao renders with HTML autoescaping; match it exactly.
		return new Environment($objLoader, array('autoescape' => 'html', 'cache' => false));
	}

	/**
	 * The context ZyppySearchController sets on the LEGACY (customTpl) variant,
	 * i.e. Contao's legacy fragment context plus the controller's own keys.
	 */
	private function moduleContext(array $arrOverride = array()): array
	{
		return array_merge(array(
			'class'        => 'mod_zyppy_search',
			'cssID'        => '',
			'style'        => '',
			'headline'     => '',
			'hl'           => 'h2',
			'id'           => 42,
			'uniqueId'     => 42,
			'action'       => '',
			'keyword'      => '',
			'results'      => '',
			'endpoint'     => '/_zyppy/search/42',
			'scriptSrc'    => '/bundles/search/js/search.js',
			'keywordLabel' => 'Keywords',
			'optionsLabel' => 'Options',
			'placeholder'  => 'Search the site',
			'errorText'    => 'The search failed',
			'previousText' => 'Previous',
			'nextText'     => 'Next',
			'minLength'    => 3,
			'search'       => 'Search',
			'matchAll'     => 'Match all',
			'matchAny'     => 'Match any',
			'queryType'    => 'and',
			'advanced'     => false,
		), $arrOverride);
	}

	/**
	 * The context AbstractFrontendModuleController builds for a MODERN fragment
	 * template - element_css_classes / element_html_id / headline.text rather
	 * than class / cssID / headline - plus the controller's own keys.
	 */
	private function fragmentContext(array $arrOverride = array()): array
	{
		return array_merge($this->moduleContext(), array(
			'element_css_classes' => '',
			'element_html_id'     => '',
			'headline'            => array('text' => '', 'tag_name' => 'h2'),
		), $arrOverride);
	}

	private function resultContext(array $arrOverride = array()): array
	{
		return array_merge(array(
			'class'      => 'first even',
			'href'       => 'a-page.html',
			'title'      => 'A page',
			'link'       => 'A page',
			'relevance'  => '100.00% relevance',
			'pageImage'  => '',
			'pageTeaser' => '',
			'hasContext' => false,
			'context'    => '',
		), $arrOverride);
	}

	private function renderModule(array $arrOverride = array()): string
	{
		return $this->twig()->render('mod_search_zyppy.html.twig', $this->moduleContext($arrOverride));
	}

	private function renderFragment(array $arrOverride = array()): string
	{
		return $this->twig()->render('zyppy_search.html.twig', $this->fragmentContext($arrOverride));
	}

	private function renderResult(array $arrOverride = array()): string
	{
		return $this->twig()->render('search_zyppy.html.twig', $this->resultContext($arrOverride));
	}

	/**
	 * THE REFLECTED SINK. Step 10 put `keyword` back into the form shell,
	 * because the server renders the first page of results again and a
	 * no-JavaScript visitor has to see what they searched for. It is whatever
	 * was in the query string, handed over RAW so Twig escapes it exactly once -
	 * so this is the classic reflected-XSS sink and it is tested on both shells.
	 */
	public function testTheSearchKeywordIsEscaped(): void
	{
		$arrHostile = array('keyword' => 'KEYWORD5417 <script>alert(1)</script>');

		foreach (array($this->renderModule($arrHostile), $this->renderFragment($arrHostile)) as $strHtml)
		{
			// Positive control: the fixture actually reached the output.
			$this->assertStringContainsString('KEYWORD5417', $strHtml, 'the keyword must be echoed back into the field');
			$this->assertStringContainsString('&lt;script&gt;', $strHtml, 'the keyword must be entity encoded');
			$this->assertStringNotContainsString('<script>alert', $strHtml, 'the keyword must not emit a live script tag');
		}
	}

	/**
	 * A double quote is the character that actually breaks OUT of value="...".
	 */
	public function testTheSearchKeywordCannotEscapeItsAttribute(): void
	{
		$arrHostile = array('keyword' => 'QUOTE8823" onfocus="alert(1)');

		foreach (array($this->renderModule($arrHostile), $this->renderFragment($arrHostile)) as $strHtml)
		{
			$this->assertStringContainsString('QUOTE8823', $strHtml);
			$this->assertStringContainsString('&quot;', $strHtml);
			$this->assertStringNotContainsString('onfocus="alert(1)"', $strHtml);
			$this->assertStringNotContainsString('QUOTE8823" onfocus', $strHtml);
		}
	}

	/**
	 * `results` is already-rendered, already-escaped markup from ResultRenderer,
	 * so it is one of the deliberate |raw values. Escaping it would turn every
	 * search result on the page into visible entity soup.
	 *
	 * It going INSIDE div.results matters: the header lives there too now, so
	 * contao-zyppy-popup's popup_clear clears both together rather than leaving
	 * an orphaned header on screen.
	 */
	public function testTheServerRenderedResultsAreNotEscapedAndSitInsideTheResultsContainer(): void
	{
		$arrRendered = array('results' => '<div class="first even">RESULT2288</div>');

		foreach (array($this->renderModule($arrRendered), $this->renderFragment($arrRendered)) as $strHtml)
		{
			$this->assertStringContainsString('<div class="results"><div class="first even">RESULT2288</div></div>', $strHtml);
			$this->assertStringNotContainsString('&lt;div class=&quot;first even&quot;&gt;', $strHtml);
		}
	}

	/**
	 * And with nothing searched for, div.results is genuinely empty - a page
	 * carrying the module looks exactly as it did before Step 10 until someone
	 * actually submits something.
	 */
	public function testTheResultsContainerIsEmptyUntilSomethingIsSearchedFor(): void
	{
		foreach (array($this->renderModule(), $this->renderFragment()) as $strHtml)
		{
			$this->assertStringContainsString('<div class="results"></div>', $strHtml);
		}
	}

	/**
	 * The user controlled strings the form shell still echoes back.
	 */
	public function testTheModuleLabelsAndActionAreEscaped(): void
	{
		$arrHostile = array(
			'action'       => '/search.html" onload="alert(1)',
			'placeholder'  => 'PLACE3311 <script>alert(1)</script>',
			'errorText'    => 'ERROR4422 <script>alert(1)</script>',
			'keywordLabel' => 'LABEL7788 <script>alert(1)</script>',
		);

		foreach (array($this->renderModule($arrHostile), $this->renderFragment($arrHostile)) as $strHtml)
		{
			foreach (array('PLACE3311', 'ERROR4422', 'LABEL7788') as $strSentinel)
			{
				$this->assertStringContainsString($strSentinel, $strHtml, $strSentinel . ' must reach the output');
			}

			$this->assertStringNotContainsString('<script>alert', $strHtml);
			$this->assertStringNotContainsString('onload="alert(1)"', $strHtml);
		}
	}

	/**
	 * The endpoint is a generated URL, but it is still written into an attribute
	 * and still escapes - there is no reason for a data attribute to be the one
	 * place a quote gets through.
	 */
	public function testTheEndpointCannotEscapeItsAttribute(): void
	{
		$arrHostile = array('endpoint' => '/_zyppy/search/ENDPOINT9911" onfocus="alert(1)');

		foreach (array($this->renderModule($arrHostile), $this->renderFragment($arrHostile)) as $strHtml)
		{
			$this->assertStringContainsString('ENDPOINT9911', $strHtml);
			$this->assertStringContainsString('&quot;', $strHtml);
			$this->assertStringNotContainsString('onfocus="alert(1)"', $strHtml);
		}
	}

	/**
	 * public/js/search.js selects `div.mod_zyppy_search`, reads `data-endpoint`,
	 * `data-min-length` and `data-error-text` off the keywords input, and writes
	 * into `div.results`. All four are template contracts, so they are pinned
	 * here - for BOTH shells, because the modern one has to write
	 * `mod_zyppy_search` out literally (element_css_classes carries only the
	 * editor's own classes, never Contao's `mod_<type>`).
	 */
	public function testTheLiveSearchClientContractIsIntact(): void
	{
		foreach (array($this->renderModule(), $this->renderFragment()) as $strHtml)
		{
			$this->assertStringContainsString('mod_zyppy_search', $strHtml);
			$this->assertStringContainsString('class="mod_zyppy_search block"', $strHtml);
			$this->assertStringContainsString('<div class="results">', $strHtml);
			$this->assertStringContainsString('name="keywords"', $strHtml);
			$this->assertStringContainsString('data-endpoint="/_zyppy/search/42"', $strHtml);
			$this->assertStringContainsString('data-min-length="3"', $strHtml);
			$this->assertStringContainsString('data-error-text="The search failed"', $strHtml);
			$this->assertStringContainsString('<script src="/bundles/search/js/search.js" defer></script>', $strHtml);
		}
	}

	/**
	 * The discriminator that guarded the old echo+exit transport. It has no
	 * server side counterpart any more - the module id is in the route path - so
	 * it must be gone rather than left behind as dead markup someone later
	 * mistakes for a control.
	 */
	public function testTheOldAjaxDiscriminatorIsGone(): void
	{
		foreach (array($this->renderModule(), $this->renderFragment()) as $strHtml)
		{
			$this->assertStringNotContainsString('name="zyppy_search"', $strHtml);
			$this->assertStringNotContainsString('zyppy_search_42', $strHtml);
			$this->assertStringNotContainsString('IS_AJAX', $strHtml);
		}
	}

	public function testTheAdvancedOptionsRenderOnlyWhenEnabledAndPreselectTheQueryType(): void
	{
		$strOff = $this->renderModule();
		$this->assertStringNotContainsString('name="query_type"', $strOff);

		$strAnd = $this->renderModule(array('advanced' => true, 'queryType' => 'and'));
		$this->assertStringContainsString('id="matchAll_42" class="radio" value="and" checked="checked"', $strAnd);
		$this->assertStringNotContainsString('value="or" checked="checked"', $strAnd);

		$strOr = $this->renderFragment(array('advanced' => true, 'queryType' => 'or'));
		$this->assertStringContainsString('id="matchAny_42" class="radio" value="or" checked="checked"', $strOr);
		$this->assertStringNotContainsString('value="and" checked="checked"', $strOr);
	}

	/**
	 * cssID arrives as a complete ` id="..."` attribute string (Module.php:220)
	 * in the LEGACY context, so it is one of the deliberate |raw values; the
	 * modern context supplies element_html_id as a bare id instead. popupClear is
	 * owned by contao-zyppy-popup and is absent whenever that bundle is not
	 * installed - the |default() is what keeps both templates rendering without
	 * it.
	 */
	public function testTheWrapperAttributesAndThePopupClearFlag(): void
	{
		$strHtml = $this->renderModule(array(
			'cssID' => ' id="my-search"',
			'style' => 'display:none',
		));

		$this->assertStringContainsString(' id="my-search"', $strHtml);
		$this->assertStringContainsString(' style="display:none"', $strHtml);
		$this->assertStringContainsString('<div class="results">', $strHtml);

		$strFragment = $this->renderFragment(array(
			'element_html_id'     => 'my-search',
			'element_css_classes' => 'highlighted',
		));

		$this->assertStringContainsString('class="mod_zyppy_search block highlighted"', $strFragment);
		$this->assertStringContainsString('id="my-search"', $strFragment);

		$this->assertStringContainsString('<div class="results popup_clear">', $this->renderModule(array('popupClear' => true)));
		$this->assertStringContainsString('<div class="results popup_clear">', $this->renderFragment(array('popupClear' => true)));
	}

	/**
	 * Both shells must render with only what Contao itself guarantees.
	 * Everything the controller sets conditionally (action, popupClear, and the
	 * whole zyppy-popup contribution) has to be |default()ed.
	 */
	public function testBothFormShellsRenderWithTheBareMinimumContext(): void
	{
		$strLegacy = $this->twig()->render('mod_search_zyppy.html.twig', array(
			'class'    => 'mod_zyppy_search',
			'hl'       => 'h2',
			'uniqueId' => 7,
		));

		$this->assertStringContainsString('<form method="get">', $strLegacy, 'no action means no action attribute');
		$this->assertStringContainsString('data-min-length="3"', $strLegacy, 'minLength falls back to 3');
		$this->assertStringContainsString('<!-- indexer::stop -->', $strLegacy);
		$this->assertStringContainsString('<!-- indexer::continue -->', $strLegacy);

		$strModern = $this->twig()->render('zyppy_search.html.twig', array(
			'uniqueId' => 7,
			'endpoint' => '/_zyppy/search/7',
		));

		$this->assertStringContainsString('class="mod_zyppy_search block"', $strModern);
		$this->assertStringContainsString('<form method="get">', $strModern);
		$this->assertStringContainsString('data-min-length="3"', $strModern);
		$this->assertStringContainsString('<!-- indexer::stop -->', $strModern);
		$this->assertStringContainsString('<!-- indexer::continue -->', $strModern);
	}

	/**
	 * The modern context nests the headline; the legacy one does not. Getting
	 * this wrong renders nothing and raises no error.
	 */
	public function testTheHeadlineRendersFromBothContextShapes(): void
	{
		$strLegacy = $this->renderModule(array('headline' => 'LEGACY4471', 'hl' => 'h3'));
		$this->assertStringContainsString('<h3>LEGACY4471</h3>', $strLegacy);

		$strModern = $this->renderFragment(array('headline' => array('text' => 'MODERN8825', 'tag_name' => 'h4')));
		$this->assertStringContainsString('<h4>MODERN8825</h4>', $strModern);
	}

	/**
	 * The result template. `link` is the indexed page title - core escapes it at
	 * ModuleSearch.php:248 and this fork had dropped that call, so before the port
	 * it reached the markup raw.
	 */
	public function testTheResultPageTitleIsEscaped(): void
	{
		$strHtml = $this->renderResult(array(
			'link'  => 'TITLE3956 <script>alert(1)</script>',
			'title' => 'TITLE3956 <script>alert(1)</script>',
		));

		$this->assertStringContainsString('TITLE3956', $strHtml, 'the page title must reach the output');
		$this->assertStringContainsString('&lt;script&gt;', $strHtml);
		$this->assertStringNotContainsString('<script>alert', $strHtml);
	}

	public function testTheResultUrlIsEscapedAndCannotEscapeItsAttribute(): void
	{
		$strHtml = $this->renderResult(array(
			'href' => 'HREF7734.html" onmouseover="alert(1)',
		));

		$this->assertStringContainsString('HREF7734', $strHtml);
		$this->assertStringContainsString('&quot;', $strHtml);
		$this->assertStringNotContainsString('onmouseover="alert(1)"', $strHtml);
	}

	/**
	 * tl_page.page_teaser is a plain textarea (decodeEntities, no rte), i.e. data.
	 */
	public function testTheResultTeaserIsEscaped(): void
	{
		$strHtml = $this->renderResult(array(
			'pageTeaser' => 'TEASER7391 <script>alert(1)</script>',
		));

		$this->assertStringContainsString('TEASER7391', $strHtml, 'the teaser must reach the output');
		$this->assertStringContainsString('<p class="page_teaser">', $strHtml);
		$this->assertStringContainsString('&lt;script&gt;', $strHtml);
		$this->assertStringNotContainsString('<script>alert', $strHtml);
	}

	public function testTheResultImagePathIsEscaped(): void
	{
		$strHtml = $this->renderResult(array(
			'pageImage' => 'files/IMG2648.jpg" onerror="alert(1)',
		));

		$this->assertStringContainsString('IMG2648', $strHtml);
		$this->assertStringNotContainsString('onerror="alert(1)"', $strHtml);
	}

	/**
	 * THE OTHER HALF OF THE PAIR. ContextBuilder builds `context` as
	 * StringUtil::specialchars() followed by a preg_replace that injects
	 * <mark class="highlight"> around each matched keyword variant. It is
	 * pre escaped text carrying generated markup, so it stays |raw - otherwise
	 * every highlight renders as visible &lt;mark&gt;.
	 */
	public function testTheHighlightedMatchMarkupSurvivesUnescaped(): void
	{
		$strHtml = $this->renderResult(array(
			'hasContext' => true,
			'context'    => 'some text with <mark class="highlight">CONTEXT5043</mark> in it',
		));

		$this->assertStringContainsString('<mark class="highlight">CONTEXT5043</mark>', $strHtml);
		$this->assertStringNotContainsString('&lt;mark', $strHtml);

		// And the pre escaped half of the same string must not be double encoded.
		$strEscaped = $this->renderResult(array(
			'hasContext' => true,
			'context'    => 'Tom &amp; Jerry &lt;b&gt; <mark class="highlight">match</mark>',
		));

		$this->assertStringContainsString('Tom &amp; Jerry &lt;b&gt;', $strEscaped);
		$this->assertStringNotContainsString('&amp;amp;', $strEscaped);
	}

	public function testTheContextParagraphIsOmittedWithoutAContext(): void
	{
		$strHtml = $this->renderResult(array('context' => 'never rendered'));

		$this->assertStringNotContainsString('class="context"', $strHtml);
		$this->assertStringNotContainsString('never rendered', $strHtml);
	}

	/**
	 * The result template must render with only the keys core's ModuleSearch
	 * always sets; pageImage, pageTeaser and hasContext are contributed by
	 * zyppy-page and are absent when a result page has none of them.
	 */
	public function testTheResultTemplateRendersWithTheBareMinimumContext(): void
	{
		$strHtml = $this->twig()->render('search_zyppy.html.twig', array(
			'class'     => 'first even',
			'href'      => 'a-page.html',
			'title'     => 'A page',
			'link'      => 'A page',
			'relevance' => '100.00% relevance',
		));

		$this->assertStringContainsString('<div class="first even">', $strHtml);
		$this->assertStringContainsString('<a href="a-page.html" title="A page">A page</a>', $strHtml);
		$this->assertStringContainsString('<span class="relevance">[100.00% relevance]</span>', $strHtml);
		$this->assertStringNotContainsString('page_image', $strHtml);
		$this->assertStringNotContainsString('page_teaser', $strHtml);
		$this->assertStringNotContainsString('class="context"', $strHtml);
	}

	/**
	 * The port is worthless if the files are not where Contao looks for them, and
	 * a leftover .html5 would silently win: on 5.3/5.7 the Twig surrogate only
	 * fires when the highest priority file for an identifier is NOT an .html5.
	 */
	public function testTheLegacyHtml5TemplatesAreGone(): void
	{
		$strBase = \dirname(__DIR__, 2) . '/contao/templates';

		$this->assertFileExists($strBase . '/modules/mod_search_zyppy.html.twig');
		$this->assertFileExists($strBase . '/search/search_zyppy.html.twig');
		$this->assertFileExists($strBase . '/search/search_zyppy_results.html.twig');
		$this->assertFileExists($strBase . '/twig/frontend_module/zyppy_search.html.twig');
		$this->assertFileDoesNotExist($strBase . '/modules/mod_search_zyppy.html5');
		$this->assertFileDoesNotExist($strBase . '/search/search_zyppy.html5');
	}

	/**
	 * The `.twig-root` marker must sit at contao/templates/twig/ and NOWHERE
	 * else. It is what gives the fragment template the prefixed identifier
	 * `frontend_module/zyppy_search` it declares; a marker one level up would
	 * ALSO prefix the two legacy identifiers, which are reached through
	 * Controller::getTemplateGroup() dropdowns (customTpl and searchTpl) that
	 * throw an InvalidArgumentException for any identifier containing a slash -
	 * and existing tl_module rows carry the flat values.
	 *
	 * TemplateIdentifierTest proves the CONSEQUENCE of this layout against the
	 * real core TemplateLocator; this test just pins the layout itself.
	 */
	public function testTheTwigRootMarkerIsPresentOnlyWhereItIsNeeded(): void
	{
		// Enumerated, not globbed: glob() returning false on an unreadable directory
		// would collapse to an empty array and pass vacuously. These are the only
		// locations where a marker could change a registered identifier.
		$strBase = \dirname(__DIR__, 2) . '/contao/templates';

		$this->assertFileExists($strBase . '/twig/.twig-root');
		$this->assertFileDoesNotExist($strBase . '/.twig-root');
		$this->assertFileDoesNotExist($strBase . '/modules/.twig-root');
		$this->assertFileDoesNotExist($strBase . '/search/.twig-root');
		$this->assertFileDoesNotExist($strBase . '/twig/frontend_module/.twig-root');
	}

}
