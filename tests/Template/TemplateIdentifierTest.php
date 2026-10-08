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

use Contao\CoreBundle\Config\ResourceFinder;
use Contao\CoreBundle\Twig\Loader\TemplateLocator;
use Contao\CoreBundle\Twig\Loader\ThemeNamespace;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;


/**
 * Proves - against Contao's OWN TemplateLocator, not against a description of
 * it - that this bundle's two template placements really do produce the two
 * different identifier shapes it needs.
 *
 * This is the one structural claim in the Step 7 change that is expensive to get
 * wrong and impossible to see locally: a fragment declaring
 * template: 'frontend_module/zyppy_search' 500s on every render if the file
 * registers under the bare basename instead, and the tl_module template
 * dropdowns throw an InvalidArgumentException if the legacy files ever register
 * under a prefixed one. Both failures need a booted site to observe. Here they
 * need two assertions.
 *
 * The mechanism, from TemplateLocator itself:
 *
 *   findTemplates($path) scans RECURSIVELY when $path is a namespace root -
 *   giving relative pathnames like `frontend_module/zyppy_search.html.twig` -
 *   and at depth < 1 otherwise, giving bare basenames. A directory is a
 *   namespace root exactly when it contains a `.twig-root` marker file
 *   (TemplateLocator::FILE_MARKER_NAMESPACE_ROOT).
 *
 * The locator is built through reflection because its constructor CHANGED
 * across the supported range - Contao 5.3 takes (projectDir, bundles,
 * bundlesMetadata, ThemeNamespace, Connection) while 5.7 takes (projectDir,
 * ResourceFinder, ThemeNamespace, Connection). Matching on parameter type keeps
 * one test valid on every leg instead of pinning it to one Contao line.
 */
class TemplateIdentifierTest extends TestCase
{

	private function templateBase(): string
	{
		return \dirname(__DIR__, 2) . '/contao/templates';
	}

	private function locator(): TemplateLocator
	{
		// A stub rather than a mock: findThemeDirectories() only needs an empty
		// theme list, and PHPUnit 12 raises a notice for a mock with no
		// configured expectation.
		$objConnection = $this->createStub(Connection::class);
		$objConnection->method('fetchFirstColumn')->willReturn(array());

		$objConstructor = new \ReflectionMethod(TemplateLocator::class, '__construct');
		$arrArguments = array();

		foreach ($objConstructor->getParameters() as $objParameter)
		{
			$strType = (string) $objParameter->getType();

			$arrArguments[] = match (true)
			{
				'string' === $strType => \dirname(__DIR__, 2),
				'array' === $strType => array(),
				str_contains($strType, 'ThemeNamespace') => new ThemeNamespace(),
				str_contains($strType, 'ResourceFinder') => new ResourceFinder(array()),
				str_contains($strType, 'Connection') => $objConnection,
				default => throw new \RuntimeException('Unhandled TemplateLocator constructor parameter of type ' . $strType),
			};
		}

		return new TemplateLocator(...$arrArguments);
	}

	/**
	 * WITHOUT a marker, a bundle template directory is scanned at depth < 1 and
	 * every file registers under its bare basename. That is what the flat
	 * legacy identifiers `mod_search_zyppy` and `search_zyppy` need, because
	 * Controller::getTemplateGroup() - which builds the customTpl and searchTpl
	 * dropdowns - throws for any identifier containing a slash.
	 */
	public function testTheLegacyTemplatesRegisterUnderTheirBareBasenames(): void
	{
		$objLocator = $this->locator();

		$this->assertSame(
			array('mod_search_zyppy.html.twig'),
			array_keys($objLocator->findTemplates($this->templateBase() . '/modules')),
		);

		// Both result templates live here, and both must stay flat: search_zyppy
		// is reached through the tl_module.searchTpl dropdown, and
		// search_zyppy_results sits beside it for the same no-marker reason.
		$this->assertSame(
			array('search_zyppy.html.twig', 'search_zyppy_results.html.twig'),
			array_keys($objLocator->findTemplates($this->templateBase() . '/search')),
		);
	}

	/**
	 * WITH the marker, contao/templates/twig is a namespace root and is scanned
	 * recursively, so the fragment template registers under the PREFIXED
	 * identifier its #[AsFrontendModule] attribute declares.
	 *
	 * This assertion genuinely discriminates: delete the marker and the scan
	 * drops to depth < 1, which finds nothing at all in that directory because
	 * the only file lives one level down - the returned array is empty and the
	 * fragment's declared template name resolves to nothing.
	 */
	public function testTheFragmentTemplateRegistersUnderItsPrefixedIdentifier(): void
	{
		$arrTemplates = $this->locator()->findTemplates($this->templateBase() . '/twig');

		$this->assertSame(array('frontend_module/zyppy_search.html.twig'), array_keys($arrTemplates));
		$this->assertFileExists($arrTemplates['frontend_module/zyppy_search.html.twig']);
	}

	/**
	 * And the marker really is the only thing making the difference: the same
	 * locator, pointed at a directory without one, is not recursive.
	 */
	public function testTheMarkerIsWhatMakesTheDifference(): void
	{
		$this->assertFileExists($this->templateBase() . '/twig/' . TemplateLocator::FILE_MARKER_NAMESPACE_ROOT);
		$this->assertFileDoesNotExist($this->templateBase() . '/' . TemplateLocator::FILE_MARKER_NAMESPACE_ROOT);

		// contao/templates itself has no marker, so a scan of it sees only the
		// files sitting directly in it - which is none - and NOT the three
		// templates one level down.
		$this->assertSame(array(), $this->locator()->findTemplates($this->templateBase()));
	}

}
