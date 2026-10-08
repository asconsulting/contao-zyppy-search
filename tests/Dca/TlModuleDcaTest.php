<?php

/**
 * @copyright 	Andrew Stevens
 * @author 		Andrew Stevens
 * @license 	AGPL-3.0-only
 * @package 	Zyppy Search
 * @link 		https://github.com/asconsulting/contao-zyppy-search
 */



namespace Zyppy\Search\Tests\Dca;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;


/**
 * Executes contao/dca/tl_module.php the way Contao does — php -l passes on a
 * DCA whose class references do not resolve, which is exactly how this bundle
 * shipped a backend fatal for years.
 */
class TlModuleDcaTest extends TestCase
{

	private array $arrBackupDca;
	private array $arrBackupLang;

	protected function setUp(): void
	{
		$this->arrBackupDca = $GLOBALS['TL_DCA'] ?? array();
		$this->arrBackupLang = $GLOBALS['TL_LANG'] ?? array();

		$GLOBALS['TL_DCA'] = array('tl_module' => array('palettes' => array('__selector__' => array()), 'subpalettes' => array(), 'fields' => array()));
		$GLOBALS['TL_LANG'] = array('tl_module' => array());

		include __DIR__ . '/../../contao/dca/tl_module.php';
	}

	protected function tearDown(): void
	{
		$GLOBALS['TL_DCA'] = $this->arrBackupDca;
		$GLOBALS['TL_LANG'] = $this->arrBackupLang;
	}

	private function palette(): string
	{
		return $GLOBALS['TL_DCA']['tl_module']['palettes']['zyppy_search'];
	}

	public function testThePaletteIsRegistered(): void
	{
		$this->assertArrayHasKey('zyppy_search', $GLOBALS['TL_DCA']['tl_module']['palettes']);
		$this->assertIsString($this->palette());
	}

	/**
	 * Both metadata forms are deliberate and neither is redundant. This suite
	 * has to run on PHPUnit 9.6 (the version contao/test-case 5.3 pulls in,
	 * which is how the declared Contao 5.3 floor is proved) as well as on 12.x.
	 * 9.6 reads only the `@dataProvider` annotation and never looks at
	 * attributes; 12.x dropped doc-comment metadata entirely and reads only the
	 * attribute. Deleting either one drops the data sets on that version, which
	 * surfaces as an ArgumentCountError rather than as a skipped test.
	 *
	 * @dataProvider deadFieldProvider
	 */
	#[DataProvider('deadFieldProvider')]
	public function testDeadFieldsAreGoneFromThePalette(string $strField): void
	{
		$this->assertStringNotContainsString($strField, $this->palette());
	}

	public static function deadFieldProvider(): array
	{
		return array(
			'ajaxTpl was never read by any code'      => array('ajaxTpl'),
			'disableAjax was never read by any code'  => array('disableAjax'),
			'totalLength has no field definition'     => array('totalLength'),
			'guests was removed from core in Contao 5' => array('guests'),
		);
	}

	/**
	 * See testDeadFieldsAreGoneFromThePalette for why both forms are present.
	 *
	 * @dataProvider deadFieldDefinitionProvider
	 */
	#[DataProvider('deadFieldDefinitionProvider')]
	public function testDeadFieldDefinitionsAreGone(string $strField): void
	{
		$this->assertArrayNotHasKey($strField, $GLOBALS['TL_DCA']['tl_module']['fields']);
	}

	public static function deadFieldDefinitionProvider(): array
	{
		return array(
			array('ajaxTpl'),
			array('disableAjax'),
		);
	}

	/**
	 * See testDeadFieldsAreGoneFromThePalette for why both forms are present.
	 *
	 * @dataProvider survivingFieldProvider
	 */
	#[DataProvider('survivingFieldProvider')]
	public function testTheWorkingFieldsSurvived(string $strField): void
	{
		$this->assertStringContainsString($strField, $this->palette());
	}

	public static function survivingFieldProvider(): array
	{
		return array(
			array('queryType'),
			array('fuzzy'),
			array('contextLength'),
			array('minKeywordLength'),
			array('perPage'),
			array('searchType'),
			array('formatPageTeaser'),
			array('formatPageDescription'),
			array('formatNewsTeaser'),
			array('jumpTo'),
			array('pages'),
			array('searchTpl'),
			array('customTpl'),
			array('protected'),
			array('imgSize'),
			array('cssID'),
		);
	}

	public function testEveryFieldInThePaletteEitherComesFromCoreOrIsDefinedHere(): void
	{
		// Core 5.7 tl_module fields the search palette relies on. Anything in
		// the palette that is neither here nor defined by this DCA is a dead
		// entry that silently renders as nothing in the backend.
		$arrCoreFields = array('name', 'headline', 'type', 'queryType', 'fuzzy', 'contextLength', 'minKeywordLength', 'perPage', 'searchType', 'jumpTo', 'pages', 'searchTpl', 'customTpl', 'protected', 'imgSize', 'cssID');
		$arrOwnFields = array_keys($GLOBALS['TL_DCA']['tl_module']['fields']);

		$arrPaletteFields = array_filter(array_map(
			static fn (string $strField): string => trim($strField),
			preg_split('/[;,]/', preg_replace('/\{[^}]*\}/', '', $this->palette()))
		));

		$arrUnknown = array_diff($arrPaletteFields, $arrCoreFields, $arrOwnFields);

		$this->assertSame(array(), array_values($arrUnknown));
	}

	public function testTheSubpalettesStillPointAtTheirLimitFields(): void
	{
		$this->assertSame('pageTeaserLimit', $GLOBALS['TL_DCA']['tl_module']['subpalettes']['formatPageTeaser']);
		$this->assertSame('pageDescriptionLimit', $GLOBALS['TL_DCA']['tl_module']['subpalettes']['formatPageDescription']);
		$this->assertSame('newsTeaserLimit', $GLOBALS['TL_DCA']['tl_module']['subpalettes']['formatNewsTeaser']);

		foreach (array('formatPageTeaser', 'formatPageDescription', 'formatNewsTeaser') as $strSelector)
		{
			$this->assertContains($strSelector, $GLOBALS['TL_DCA']['tl_module']['palettes']['__selector__']);
		}
	}

	public function testTheLanguageFileDefinesALabelForEveryFieldThisDcaAdds(): void
	{
		// The DCA's `&$GLOBALS['TL_LANG'][...]` references already created the
		// keys (as null), so the value has to be asserted, not the key.
		include __DIR__ . '/../../contao/languages/en/tl_module.php';

		foreach (array_keys($GLOBALS['TL_DCA']['tl_module']['fields']) as $strField)
		{
			$this->assertIsArray($GLOBALS['TL_LANG']['tl_module'][$strField] ?? null, 'Missing label for ' . $strField);
			$this->assertNotSame('', (string) ($GLOBALS['TL_LANG']['tl_module'][$strField][0] ?? ''), 'Empty label for ' . $strField);
		}
	}

	public function testTheLanguageFileHasNoLabelsLeftOverForDeletedFields(): void
	{
		include __DIR__ . '/../../contao/languages/en/tl_module.php';

		$this->assertArrayNotHasKey('ajaxTpl', $GLOBALS['TL_LANG']['tl_module']);
		$this->assertArrayNotHasKey('disableAjax', $GLOBALS['TL_LANG']['tl_module']);
	}

}
