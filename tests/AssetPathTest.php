<?php

/**
 * @copyright 	Andrew Stevens
 * @author 		Andrew Stevens
 * @license 	AGPL-3.0-only
 * @package 	Zyppy Search
 * @link 		https://github.com/asconsulting/contao-zyppy-search
 */



namespace Zyppy\Search\Tests;

use PHPUnit\Framework\TestCase;
use Zyppy\Search\Controller\FrontendModule\ZyppySearchController;
use Zyppy\Search\SearchBundle;


/**
 * The published asset prefix is derived from the BUNDLE CLASS NAME, so renaming
 * the bundle silently changes the URL the fragment emits — which is how the live
 * search feature came to point at a path that had not existed since Contao 3.
 *
 * Since Step 7 the script is no longer pushed into $GLOBALS['TL_JAVASCRIPT']:
 * a fragment renders after the layout's script section has been built, so
 * TL_JAVASCRIPT is effectively backend-only for fragment output. The URL now
 * comes from ZyppySearchController::SCRIPT_PATH, resolved through Symfony's
 * Packages service and written into a <script> by the template. This test moved
 * with it rather than being deleted — it would otherwise have gone on asserting
 * a path nothing emits, which is exactly the vacuous structural check this repo
 * has been bitten by before.
 */
class AssetPathTest extends TestCase
{

	private function assetPrefix(): string
	{
		$strShortName = (new \ReflectionClass(SearchBundle::class))->getShortName();

		return preg_replace('/bundle$/', '', strtolower($strShortName));
	}

	public function testTheAssetPrefixFollowsTheBundleClassName(): void
	{
		$this->assertSame('search', $this->assetPrefix());
	}

	public function testTheFragmentAdvertisesTheScriptAtItsPublishedPath(): void
	{
		$this->assertSame(
			1,
			preg_match('#^bundles/([A-Za-z0-9_-]+)/js/search\.js$#', ZyppySearchController::SCRIPT_PATH, $arrMatch),
			'ZyppySearchController::SCRIPT_PATH is no longer a bundles/<prefix>/js/search.js path'
		);

		$this->assertSame($this->assetPrefix(), $arrMatch[1]);
	}

	public function testTheScriptItAdvertisesActuallyExists(): void
	{
		$this->assertFileExists(__DIR__ . '/../public/js/search.js');

		// SCRIPT_PATH is bundles/<prefix>/js/<file>; the <file> part has to be
		// the one actually shipped in public/.
		$this->assertFileExists(__DIR__ . '/../public/js/' . basename(ZyppySearchController::SCRIPT_PATH));
	}

	/**
	 * Both form-shell templates emit the script themselves, because a fragment
	 * cannot use TL_JAVASCRIPT. If neither did, the live search would simply
	 * never initialise and nothing would report an error.
	 */
	public function testBothFormShellTemplatesEmitTheScriptTag(): void
	{
		$strBase = __DIR__ . '/../contao/templates';

		foreach (array($strBase . '/modules/mod_search_zyppy.html.twig', $strBase . '/twig/frontend_module/zyppy_search.html.twig') as $strTemplate)
		{
			$this->assertMatchesRegularExpression(
				'#<script src="\{\{\s*scriptSrc#',
				file_get_contents($strTemplate),
				basename($strTemplate) . ' must emit its own <script>'
			);
		}
	}

	public function testTheScriptDoesNotPullInJQuery(): void
	{
		// A bundle must never load its own jQuery: a second copy breaks every
		// plugin bound to the first one.
		$strScript = file_get_contents(__DIR__ . '/../public/js/search.js');

		$this->assertDoesNotMatchRegularExpression('#\bjQuery\s*\(#', $strScript);
		$this->assertDoesNotMatchRegularExpression('#(^|[^\w.$])\$\s*\(#m', $strScript);
	}

	/**
	 * The client talks to the route, not to the current page URL. That is the
	 * Step 3 item that was deferred until a route existed, so it is pinned:
	 * a regression to window.location.href would silently reintroduce the
	 * page-truncation transport.
	 */
	public function testTheClientTargetsTheRouteRatherThanTheCurrentPage(): void
	{
		$strScript = file_get_contents(__DIR__ . '/../public/js/search.js');

		$this->assertStringContainsString("getAttribute('data-endpoint')", $strScript);
		$this->assertStringNotContainsString('IS_AJAX', $strScript);
		$this->assertDoesNotMatchRegularExpression('#fetch\(\s*window\.location#', $strScript);
	}

}
