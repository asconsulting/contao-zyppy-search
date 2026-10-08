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
use Zyppy\Search\Search\ResultEnricher;
use Zyppy\Search\Search\ResultImageResolverInterface;
use Zyppy\Search\Search\SearchQuery;


/**
 * The enrichment that is this bundle's entire reason to exist: the
 * tl_page.page_image / page_teaser fields contributed by
 * asconsulting/contao-zyppy-page, and the per-page News Reader override.
 *
 * None of it was reachable by a test while it lived in the ModuleSearch fork's
 * compile() loop. It takes ROWS now, so the rules are ordinary function
 * behaviour.
 */
class ResultEnricherTest extends TestCase
{

	protected function setUp(): void
	{
		parent::setUp();

		$GLOBALS['TL_LANG']['MSC']['relevance'] = '%s relevance';
	}

	protected function tearDown(): void
	{
		unset($GLOBALS['TL_LANG']['MSC']['relevance']);

		parent::tearDown();
	}

	/**
	 * A fake image pipeline: uuid "u-<name>" resolves, anything else does not.
	 * It records the SIZE it was handed, because passing tl_module.imgSize
	 * through is the entire point of the resolver seam - the field sat in the
	 * palette doing nothing until Step 10.
	 */
	private function images(): ResultImageResolverInterface
	{
		return new class() implements ResultImageResolverInterface {
			public array $arrSeenSizes = array();

			public function resolve(mixed $varUuid, mixed $varSize = null, string|null $strFallbackAlt = null): array|null
			{
				$this->arrSeenSizes[] = $varSize;

				$strUuid = (string) $varUuid;

				if (!str_starts_with($strUuid, 'u-'))
				{
					return null;
				}

				return array(
					'src' => 'assets/' . substr($strUuid, 2),
					'srcset' => '',
					'sizes' => '',
					'width' => 100,
					'height' => 80,
					'alt' => (string) $strFallbackAlt,
				);
			}
		};
	}

	private function query(array $arrOverride = array()): SearchQuery
	{
		return SearchQuery::fromModuleRow(array_merge(array(
			'formatPageTeaser' => '',
			'pageTeaserLimit' => 0,
			'formatPageDescription' => '',
			'pageDescriptionLimit' => 0,
			'formatNewsTeaser' => '',
			'newsTeaserLimit' => 0,
		), $arrOverride), 'x', null);
	}

	private function searchRow(array $arrOverride = array()): array
	{
		return array_merge(array(
			'id' => 1,
			'pid' => 5,
			'title' => 'A page',
			'url' => 'a-page.html',
			'relevance' => 5.0,
		), $arrOverride);
	}

	public function testThePageTeaserAndImageReachTheItem(): void
	{
		$objItem = (new ResultEnricher($this->images()))->enrich(
			$this->searchRow(),
			array('id' => 5, 'page_teaser' => 'TEASER1122', 'page_image' => 'u-pic.jpg', 'description' => 'DESC3344'),
			null,
			$this->query(),
			'',
			5.0,
		);

		$this->assertSame('TEASER1122', $objItem->strTeaser);
		$this->assertSame('assets/pic.jpg', $objItem->arrImage['src']);
		$this->assertSame('DESC3344', $objItem->strDescription);
		$this->assertFalse($objItem->blnIsNews);
	}

	/**
	 * tl_module.imgSize has to actually reach the image pipeline. It reached
	 * nothing at all before Step 10, which is why the field appeared to do
	 * nothing: the old resolver returned a bare DBAFS path and never resized.
	 */
	public function testTheModuleImageSizeIsHandedToTheImagePipeline(): void
	{
		$objImages = $this->images();

		(new ResultEnricher($objImages))->enrich(
			$this->searchRow(),
			array('id' => 5, 'page_image' => 'u-pic.jpg'),
			null,
			$this->query(array('imgSize' => serialize(array(320, 200, 'proportional')))),
			'',
			5.0,
		);

		$this->assertSame(array(array(320, 200, 'proportional')), $objImages->arrSeenSizes);
	}

	/**
	 * THE DEGRADED PATH: contao-zyppy-page is not installed, so tl_page has no
	 * page_teaser / page_image columns and Model::__get() returns null for them.
	 * That must produce empty strings, not a TypeError and not a PHP 8.1
	 * "passing null to strip_tags()" deprecation.
	 */
	public function testAPageWithoutTheZyppyPageColumnsDegradesToEmptyStrings(): void
	{
		$objItem = (new ResultEnricher($this->images()))->enrich(
			$this->searchRow(),
			array('id' => 5, 'page_teaser' => null, 'page_image' => null, 'description' => null),
			null,
			$this->query(array('formatPageTeaser' => '1', 'pageTeaserLimit' => 50)),
			'',
			5.0,
		);

		$this->assertSame('', $objItem->strTeaser);
		$this->assertNull($objItem->arrImage);
		$this->assertSame('', $objItem->strDescription);
	}

	public function testAMissingPageRowLeavesTheEnrichmentEmptyWithoutFailing(): void
	{
		$objItem = (new ResultEnricher($this->images()))->enrich($this->searchRow(), null, null, $this->query(), '', 5.0);

		$this->assertSame('', $objItem->strTeaser);
		$this->assertNull($objItem->arrImage);
		$this->assertSame('A page', $objItem->strTitle, 'the indexed row itself still has to render');
	}

	public function testAnOrphanedImageUuidProducesNoImageRatherThanABrokenPath(): void
	{
		$objItem = (new ResultEnricher($this->images()))->enrich(
			$this->searchRow(),
			array('id' => 5, 'page_image' => 'gone'),
			null,
			$this->query(),
			'',
			5.0,
		);

		$this->assertNull($objItem->arrImage);
	}

	public function testTheTeaserIsTruncatedOnlyWhenTheModuleAsksForIt(): void
	{
		$strLong = 'The quick brown fox jumps over the lazy dog and keeps on running for a while';

		$objRaw = (new ResultEnricher($this->images()))->enrich(
			$this->searchRow(),
			array('id' => 5, 'page_teaser' => $strLong),
			null,
			$this->query(),
			'',
			5.0,
		);

		$objTrimmed = (new ResultEnricher($this->images()))->enrich(
			$this->searchRow(),
			array('id' => 5, 'page_teaser' => $strLong),
			null,
			$this->query(array('formatPageTeaser' => '1', 'pageTeaserLimit' => 20)),
			'',
			5.0,
		);

		$this->assertSame($strLong, $objRaw->strTeaser);
		$this->assertLessThan(mb_strlen($strLong), mb_strlen($objTrimmed->strTeaser));
		$this->assertStringEndsWith("\u{2026}", $objTrimmed->strTeaser);
	}

	/**
	 * THE BEHAVIOUR CHANGE, asserted rather than assumed. The ModuleSearch fork
	 * computed $newsTeaser and $newsImage on every result and then rendered
	 * neither - search_zyppy.html5 only ever read pageImage/pageTeaser - so the
	 * documented "News Reader override" feature had never once been visible.
	 */
	public function testAMatchingNewsItemOverridesThePageTeaserAndImage(): void
	{
		$objItem = (new ResultEnricher($this->images()))->enrich(
			$this->searchRow(),
			array('id' => 5, 'zyppy_news' => '1', 'page_teaser' => 'PAGE4455', 'page_image' => 'u-page.jpg'),
			array('teaser' => 'NEWS6677', 'addImage' => '1', 'singleSRC' => 'u-news.jpg'),
			$this->query(),
			'',
			5.0,
		);

		$this->assertTrue($objItem->blnIsNews);
		$this->assertSame('NEWS6677', $objItem->strTeaser);
		$this->assertSame('assets/news.jpg', $objItem->arrImage['src']);
	}

	public function testANewsItemWithoutAnImageLeavesThePageImageInPlace(): void
	{
		$objItem = (new ResultEnricher($this->images()))->enrich(
			$this->searchRow(),
			array('id' => 5, 'page_teaser' => 'PAGE4455', 'page_image' => 'u-page.jpg'),
			array('teaser' => 'NEWS6677', 'addImage' => '', 'singleSRC' => 'u-news.jpg'),
			$this->query(),
			'',
			5.0,
		);

		$this->assertSame('assets/page.jpg', $objItem->arrImage['src'], 'addImage off means the news image is not used');
		$this->assertSame('NEWS6677', $objItem->strTeaser);
	}

	/**
	 * A news item whose own image is orphaned must not blank out the page image
	 * it was meant to replace.
	 */
	public function testAnOrphanedNewsImageLeavesThePageImageInPlace(): void
	{
		$objItem = (new ResultEnricher($this->images()))->enrich(
			$this->searchRow(),
			array('id' => 5, 'page_image' => 'u-page.jpg'),
			array('teaser' => '', 'addImage' => '1', 'singleSRC' => 'gone'),
			$this->query(),
			'',
			5.0,
		);

		$this->assertSame('assets/page.jpg', $objItem->arrImage['src']);
	}

	public function testANewsItemWithoutATeaserLeavesThePageTeaserInPlace(): void
	{
		$objItem = (new ResultEnricher($this->images()))->enrich(
			$this->searchRow(),
			array('id' => 5, 'page_teaser' => 'PAGE4455'),
			array('teaser' => '', 'addImage' => ''),
			$this->query(),
			'',
			5.0,
		);

		$this->assertSame('PAGE4455', $objItem->strTeaser);
		$this->assertTrue($objItem->blnIsNews, 'it is still a news result even with nothing to contribute');
	}

	public function testTheRelevanceIsExpressedAsAPercentageOfTheTopResult(): void
	{
		$objEnricher = new ResultEnricher($this->images());

		$this->assertSame('100.00% relevance', $objEnricher->enrich($this->searchRow(array('relevance' => 5.0)), null, null, $this->query(), '', 5.0)->strRelevance);
		$this->assertSame('50.00% relevance', $objEnricher->enrich($this->searchRow(array('relevance' => 2.5)), null, null, $this->query(), '', 5.0)->strRelevance);
	}

	public function testAZeroTopRelevanceDoesNotDivideByZero(): void
	{
		$this->assertSame('', (new ResultEnricher($this->images()))->enrich($this->searchRow(), null, null, $this->query(), '', 0.0)->strRelevance);
	}

	/**
	 * The title attribute is the one place an insert tag in an indexed title
	 * would be re-evaluated by Contao, so it is stripped. It is NOT escaped -
	 * the result template escapes it exactly once.
	 */
	public function testInsertTagsAreStrippedFromTheTitleAttribute(): void
	{
		$objItem = (new ResultEnricher($this->images()))->enrich(
			$this->searchRow(array('title' => 'TITLE8899 {{env::path}}')),
			null,
			null,
			$this->query(),
			'',
			5.0,
		);

		$this->assertStringContainsString('TITLE8899', $objItem->strTitleAttribute);
		$this->assertStringNotContainsString('{{env::path}}', $objItem->strTitleAttribute);
	}

	/**
	 * The visible link text goes into the page buffer too, and that buffer is
	 * insert-tag parsed (TemplateInheritance::inherit()). Core strips insert tags
	 * from the title attribute only; the link text gets the same treatment here
	 * so the two halves of one title cannot disagree about it.
	 */
	public function testInsertTagsAreStrippedFromTheLinkTextAsWell(): void
	{
		$objItem = (new ResultEnricher($this->images()))->enrich(
			$this->searchRow(array('title' => 'LINK7711 {{env::path}}')),
			null,
			null,
			$this->query(),
			'',
			5.0,
		);

		$this->assertStringContainsString('LINK7711', $objItem->strTitle);
		$this->assertStringNotContainsString('{{', $objItem->strTitle);
		$this->assertStringNotContainsString('{{', $objItem->toTemplateData(0, 1)['link']);
	}

	/**
	 * THE SCHEME GUARD, at the boundary it now lives on. Twig escaping does not
	 * stop a `javascript:` href, so the check has to happen before the DTO is
	 * built - and it has to happen HERE rather than in the template, so it
	 * covers the server-rendered page and every live update at once.
	 */
	public function testAHostileResultUrlIsRefusedOnTheWayIntoTheItem(): void
	{
		$objEnricher = new ResultEnricher($this->images());

		$this->assertSame('', $objEnricher->enrich($this->searchRow(array('url' => 'javascript:alert(1)')), null, null, $this->query(), '', 5.0)->strUrl);
		$this->assertSame('', $objEnricher->enrich($this->searchRow(array('url' => "java\tscript:alert(1)")), null, null, $this->query(), '', 5.0)->strUrl);
		$this->assertSame('a-page.html', $objEnricher->enrich($this->searchRow(), null, null, $this->query(), '', 5.0)->strUrl);
	}

	/**
	 * The pre-built context is passed through untouched: it is already escaped
	 * and already carries its <mark> markup, and it is the result template's
	 * single |raw value.
	 */
	public function testTheContextIsPassedThroughUntouched(): void
	{
		$strContext = 'text with <mark class="highlight">CTX2244</mark> in it';

		$objItem = (new ResultEnricher($this->images()))->enrich($this->searchRow(), null, null, $this->query(), $strContext, 5.0);

		$this->assertSame($strContext, $objItem->strContext);
	}

	/**
	 * The per-result template context. `pageImage` is kept alongside the richer
	 * `image` array so a site-specific search_* template written against the old
	 * bare-path shape does not silently lose its image.
	 */
	public function testTheItemExposesBothTheRichImageAndTheLegacyPagePath(): void
	{
		$arrData = (new ResultEnricher($this->images()))->enrich(
			$this->searchRow(),
			array('id' => 5, 'page_teaser' => 'T', 'page_image' => 'u-pic.jpg', 'description' => 'D'),
			null,
			$this->query(),
			'CTX',
			5.0,
		)->toTemplateData(0, 1);

		$this->assertSame('assets/pic.jpg', $arrData['pageImage']);
		$this->assertSame('assets/pic.jpg', $arrData['image']['src']);
		$this->assertSame('a-page.html', $arrData['href']);
		$this->assertSame('CTX', $arrData['context']);
		$this->assertTrue($arrData['hasContext']);
		$this->assertSame('first last even', $arrData['class']);
	}

	public function testAResultWithNoImageExposesNeitherShape(): void
	{
		$arrData = (new ResultEnricher($this->images()))->enrich($this->searchRow(), null, null, $this->query(), '', 5.0)->toTemplateData(1, 3);

		$this->assertSame('', $arrData['pageImage']);
		$this->assertNull($arrData['image']);
		$this->assertFalse($arrData['hasContext']);
		$this->assertSame('odd', $arrData['class']);
	}

}
