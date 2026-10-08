<?php

/**
 * @copyright 	Andrew Stevens
 * @author 		Andrew Stevens
 * @license 	AGPL-3.0-only
 * @package 	Zyppy Search
 * @link 		https://github.com/asconsulting/contao-zyppy-search
 */



namespace Zyppy\Search\Tests\Dca;

use PHPUnit\Framework\TestCase;


/**
 * Executes contao/dca/tl_page.php against the real Contao 5.7 palette strings.
 *
 * The palette strings below are copied verbatim from
 * vendor/contao/core-bundle/contao/dca/tl_page.php (5.7.13). `{meta_legend}`
 * appears in all of them, which is why the previous stristr()-based rewrite
 * added the News Reader checkbox to every one.
 */
class TlPageDcaTest extends TestCase
{

	private const PALETTE_REGULAR = '{title_legend},title,type;{routing_legend},alias,requireItem,routePath,routePriority,routeConflicts;{meta_legend},pageTitle,robots,description,serpPreview;{canonical_legend:hide},canonicalLink,canonicalKeepParams;{protected_legend:hide},protected;{layout_legend:hide},includeLayout;{cache_legend:hide},includeCache;{chmod_legend:hide},includeChmod;{expert_legend:hide},cssClass,sitemap,searchIndexer,hide,guests;{tabnav_legend:hide},accesskey;{publish_legend},published,start,stop';
	private const PALETTE_FORWARD = '{title_legend},title,type;{routing_legend},alias,routePath,routePriority,routeConflicts;{meta_legend},pageTitle,robots;{redirect_legend},jumpTo,redirect,alwaysForward;{protected_legend:hide},protected;{publish_legend},published,start,stop';
	private const PALETTE_REDIRECT = '{title_legend},title,type;{routing_legend},alias,routePath,routePriority,routeConflicts;{meta_legend},pageTitle,robots;{redirect_legend},redirect,url,target;{publish_legend},published,start,stop';
	private const PALETTE_ROOT = '{title_legend},title,type;{routing_legend},alias;{meta_legend},pageTitle;{url_legend},dns,useSSL,urlPrefix,urlSuffix;{publish_legend},published,start,stop';
	private const PALETTE_ERROR_404 = '{title_legend},title,type;{meta_legend},pageTitle,robots,description;{forward_legend},autoforward;{publish_legend},published,start,stop';

	private array $arrBackupDca;
	private array $arrBackupLang;

	protected function setUp(): void
	{
		$this->arrBackupDca = $GLOBALS['TL_DCA'] ?? array();
		$this->arrBackupLang = $GLOBALS['TL_LANG'] ?? array();
		$GLOBALS['TL_LANG'] = array('tl_page' => array());
	}

	protected function tearDown(): void
	{
		$GLOBALS['TL_DCA'] = $this->arrBackupDca;
		$GLOBALS['TL_LANG'] = $this->arrBackupLang;
	}

	private function loadDca(array $arrPalettes): void
	{
		$GLOBALS['TL_DCA'] = array('tl_page' => array('palettes' => $arrPalettes, 'fields' => array()));

		include __DIR__ . '/../../contao/dca/tl_page.php';
	}

	private function corePalettes(): array
	{
		return array(
			'__selector__' => array('type', 'protected'),
			'default'      => '{title_legend},title,type',
			'regular'      => self::PALETTE_REGULAR,
			'forward'      => self::PALETTE_FORWARD,
			'redirect'     => self::PALETTE_REDIRECT,
			'root'         => self::PALETTE_ROOT,
			'error_404'    => self::PALETTE_ERROR_404,
		);
	}

	public function testTheFieldIsAddedToTheRegularPalette(): void
	{
		$this->loadDca($this->corePalettes());

		$this->assertStringContainsString('zyppy_news', $GLOBALS['TL_DCA']['tl_page']['palettes']['regular']);
	}

	public function testTheFieldLandsInsideTheMetaLegend(): void
	{
		$this->loadDca($this->corePalettes());

		$this->assertStringContainsString(
			'{meta_legend},pageTitle,robots,description,serpPreview,zyppy_news;',
			$GLOBALS['TL_DCA']['tl_page']['palettes']['regular']
		);
	}

	/**
	 * A forward, a redirect, a root and an error page can never render a news
	 * reader, so the checkbox must not appear on them. The previous
	 * stristr()-based rewrite added it to every palette carrying a
	 * {meta_legend}, which is all of these.
	 */
	public function testPageTypesThatCannotBeANewsReaderAreLeftAlone(): void
	{
		$this->loadDca($this->corePalettes());

		$this->assertSame(self::PALETTE_FORWARD, $GLOBALS['TL_DCA']['tl_page']['palettes']['forward']);
		$this->assertSame(self::PALETTE_REDIRECT, $GLOBALS['TL_DCA']['tl_page']['palettes']['redirect']);
		$this->assertSame(self::PALETTE_ROOT, $GLOBALS['TL_DCA']['tl_page']['palettes']['root']);
		$this->assertSame(self::PALETTE_ERROR_404, $GLOBALS['TL_DCA']['tl_page']['palettes']['error_404']);
	}

	public function testArrayPalettesAreNotTouched(): void
	{
		$this->loadDca($this->corePalettes());

		$this->assertSame(array('type', 'protected'), $GLOBALS['TL_DCA']['tl_page']['palettes']['__selector__']);
	}

	public function testItIsANoOpWhenTheRegularPaletteIsMissing(): void
	{
		$arrPalettes = $this->corePalettes();
		unset($arrPalettes['regular']);

		$this->loadDca($arrPalettes);

		$this->assertArrayNotHasKey('regular', $GLOBALS['TL_DCA']['tl_page']['palettes']);
	}

	public function testItIsANoOpWhenTheRegularPaletteHasNoMetaLegend(): void
	{
		// PaletteManipulator throws PalettePositionException when the legend it
		// is told to append to is absent; the guard has to catch that first.
		$arrPalettes = $this->corePalettes();
		$arrPalettes['regular'] = '{title_legend},title,type;{publish_legend},published';

		$this->loadDca($arrPalettes);

		$this->assertSame('{title_legend},title,type;{publish_legend},published', $GLOBALS['TL_DCA']['tl_page']['palettes']['regular']);
	}

	public function testTheFieldDefinitionIsRegistered(): void
	{
		$this->loadDca($this->corePalettes());

		$arrField = $GLOBALS['TL_DCA']['tl_page']['fields']['zyppy_news'];

		$this->assertSame('checkbox', $arrField['inputType']);
		$this->assertTrue($arrField['exclude']);
		$this->assertSame("char(1) NOT NULL default ''", $arrField['sql']);
	}

	public function testTheLanguageFileLabelsTheField(): void
	{
		$this->loadDca($this->corePalettes());

		include __DIR__ . '/../../contao/languages/en/tl_page.php';

		$this->assertIsArray($GLOBALS['TL_LANG']['tl_page']['zyppy_news']);
		$this->assertNotSame('', (string) $GLOBALS['TL_LANG']['tl_page']['zyppy_news'][0]);
	}

}
