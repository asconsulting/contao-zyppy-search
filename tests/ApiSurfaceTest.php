<?php

declare(strict_types=1);

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
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\StorageInterface;


/**
 * Every framework class and method this bundle calls, checked against whatever
 * is actually installed.
 *
 * WHY THIS EXISTS. `composer.json` declares `contao/core-bundle: ">=5.3"` with
 * NO upper bound - a deliberate decision recorded in TODO.md - so the suite runs
 * against 5.3.0, 5.7.x and 6.0.0. A class or method removed in a later major is
 * invisible to every other test in this repo: nothing here boots a container,
 * renders a fragment or dispatches a route, and `php -l` cannot see an
 * unresolved class name at all. That is exactly the failure mode that left this
 * bundle non-functional on Contao 5 for years, and the Step 7 rearchitecture
 * added a fresh crop of framework touch points - PageFinder,
 * AbstractFrontendModuleController, ContentUrlGenerator, Packages,
 * RateLimiterFactory - none of which existed here before.
 *
 * Two layers:
 *
 *   1. A GENERIC sweep of every `use` statement under src/, so a new import
 *      cannot be added without being covered.
 *   2. EXPLICIT method and signature checks, because a class surviving a major
 *      release says nothing about the methods on it.
 */
class ApiSurfaceTest extends TestCase
{

	/**
	 * The ONE import that is legitimately absent.
	 *
	 * contao/news-bundle is a `suggest` plus a class_exists() guard, not a
	 * `require` - a decision that predates Step 7 and is unchanged by it - so
	 * Contao\NewsModel is installed on none of the three legs. ContaoNewsLookup
	 * guards every use of it. Anything else missing is a real break.
	 */
	private const OPTIONAL_CLASSES = array(
		'Contao\NewsModel',
	);

	/**
	 * @return array<string> Every fully qualified name imported anywhere in src/.
	 */
	private function importedClasses(): array
	{
		$objFiles = new \RegexIterator(
			new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__) . '/src')),
			'/\.php$/',
		);

		$arrClasses = array();

		foreach ($objFiles as $objFile)
		{
			preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+\w+)?;/m', file_get_contents($objFile->getPathname()), $arrMatches);

			foreach ($arrMatches[1] as $strClass)
			{
				$arrClasses[$strClass] = true;
			}
		}

		return array_keys($arrClasses);
	}

	public function testEveryClassImportedBySourceResolvesOnThisContaoVersion(): void
	{
		$arrClasses = $this->importedClasses();

		// Non-vacuity guard: a broken scan returning nothing would otherwise
		// pass this test in silence.
		$this->assertGreaterThan(20, \count($arrClasses), 'the import scan found suspiciously few classes');

		foreach ($arrClasses as $strClass)
		{
			if (\in_array($strClass, self::OPTIONAL_CLASSES, true))
			{
				continue;
			}

			$this->assertTrue(
				class_exists($strClass) || interface_exists($strClass) || trait_exists($strClass),
				$strClass . ' is imported by src/ but does not exist on this Contao version',
			);
		}
	}

	/**
	 * The static Contao calls, which no import check can see the shape of.
	 */
	public function testTheStaticContaoMethodsThisBundleCallsStillExist(): void
	{
		$arrCalls = array(
			'Contao\Controller' => array('isVisibleElement'),
			'Contao\Model' => array('findByPk', 'findOneBy'),
			'Contao\Search' => array('query', 'getMatchVariants'),
			'Contao\SearchResult' => array('applyFilter', 'getCount', 'getResults'),
			'Contao\StringUtil' => array('specialchars', 'substrHtml', 'stripInsertTags', 'deserialize', 'binToUuid', 'trimsplit'),
			'Contao\System' => array('loadLanguageFile'),
			'Contao\Input' => array('encodeInsertTags'),
			'Contao\Config' => array('get'),
			'Contao\Database' => array('getInstance', 'getChildRecords'),
			'Contao\CoreBundle\Framework\ContaoFramework' => array('initialize', 'getAdapter', 'createInstance'),
			'Contao\CoreBundle\Routing\PageFinder' => array('findRootPageForRequest'),
			'Contao\CoreBundle\Routing\ContentUrlGenerator' => array('generate'),
			'Contao\CoreBundle\Twig\FragmentTemplate' => array('set', 'getResponse', 'getName'),
			'Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController' => array('getResponse'),
			'Contao\CoreBundle\Twig\Loader\TemplateLocator' => array('findTemplates'),
			'Contao\ManagerPlugin\Routing\RoutingPluginInterface' => array('getRouteCollection'),
			'Symfony\Component\Asset\Packages' => array('getUrl'),
			'Symfony\Bundle\SecurityBundle\Security' => array('isGranted'),
			// Added in Step 10: the template rendering path and the image
			// pipeline that finally makes tl_module.imgSize do something.
			'Contao\FrontendTemplate' => array('setData', 'parse'),
			'Contao\CoreBundle\Image\Studio\Studio' => array('createFigureBuilder'),
			'Contao\CoreBundle\Image\Studio\FigureBuilder' => array('fromUuid', 'setSize', 'buildIfResourceExists'),
			'Contao\CoreBundle\Image\Studio\Figure' => array('getImage', 'hasMetadata', 'getMetadata'),
			'Contao\CoreBundle\Image\Studio\ImageResult' => array('getImg'),
		);

		foreach ($arrCalls as $strClass => $arrMethods)
		{
			$this->assertTrue(
				class_exists($strClass) || interface_exists($strClass),
				$strClass . ' does not exist on this Contao version',
			);

			foreach ($arrMethods as $strMethod)
			{
				$this->assertTrue(method_exists($strClass, $strMethod), $strClass . '::' . $strMethod . '() does not exist on this version');
			}
		}
	}

	public function testTheMemberInGroupsPermissionConstantStillExists(): void
	{
		$this->assertTrue(\defined('Contao\CoreBundle\Security\ContaoCorePermissions::MEMBER_IN_GROUPS'));
	}

	/**
	 * config/services.yaml builds a RateLimiterFactory positionally, so its
	 * constructor SHAPE is a hard dependency - not just the class name. The
	 * concrete class is used rather than RateLimiterFactoryInterface, which only
	 * arrived in Symfony 7.3 and so does not exist on the 6.4 line the Contao 5.3
	 * floor resolves to.
	 *
	 * A signature change here would be a container compile error on a site, and
	 * nothing else in this suite would notice.
	 */
	public function testTheRateLimiterFactoryStillTakesConfigThenStorage(): void
	{
		$arrParameters = (new \ReflectionMethod(RateLimiterFactory::class, '__construct'))->getParameters();

		$this->assertGreaterThanOrEqual(2, \count($arrParameters));
		$this->assertSame('array', (string) $arrParameters[0]->getType(), 'the first argument must still be the config array');
		$this->assertSame(StorageInterface::class, (string) $arrParameters[1]->getType(), 'the second argument must still be the storage');

		// The lock factory is optional; symfony/lock is only a dev dependency of
		// the component, so services.yaml must be able to leave it out.
		if (isset($arrParameters[2]))
		{
			$this->assertTrue($arrParameters[2]->isOptional(), 'the lock factory must stay optional');
		}
	}

	/**
	 * ContaoImageResolver reads src/srcset/sizes/width/height off getImg(). The
	 * keys come from Contao\Image\PictureGenerator, and a rename there would
	 * render <img src=""> silently on every result rather than failing loudly -
	 * so the CONTRACT is pinned even though the values need a real image
	 * service to produce.
	 */
	public function testTheImageAttributeContractIsDocumentedWhereItIsConsumed(): void
	{
		$objMethod = new \ReflectionMethod('Contao\CoreBundle\Image\Studio\ImageResult', 'getImg');

		$this->assertSame('array', (string) $objMethod->getReturnType(), 'getImg() must still return the img attribute array');
		$this->assertSame(0, $objMethod->getNumberOfRequiredParameters(), 'getImg() must still be callable with no arguments');

		// buildIfResourceExists() returning null is the degrade-do-not-fatal
		// guard for a deleted or non-image file. A non-nullable return would
		// mean that guard silently stopped guarding.
		$objBuild = new \ReflectionMethod('Contao\CoreBundle\Image\Studio\FigureBuilder', 'buildIfResourceExists');

		$this->assertTrue($objBuild->getReturnType()?->allowsNull() ?? false, 'buildIfResourceExists() must still be able to return null');
	}

	public function testTheRateLimiterStorageWeWireStillExists(): void
	{
		$this->assertTrue(class_exists('Symfony\Component\RateLimiter\Storage\CacheStorage'));
		$this->assertTrue(is_a('Symfony\Component\RateLimiter\Storage\CacheStorage', StorageInterface::class, true));
	}

	/**
	 * The #[AsFrontendModule] attribute is what registers the module type at all.
	 * The three named arguments the controller passes have to keep existing.
	 */
	public function testTheFrontendModuleAttributeStillAcceptsTypeCategoryAndTemplate(): void
	{
		$arrNames = array_map(
			static fn (\ReflectionParameter $objParameter): string => $objParameter->getName(),
			(new \ReflectionMethod('Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule', '__construct'))->getParameters(),
		);

		foreach (array('type', 'category', 'template') as $strName)
		{
			$this->assertContains($strName, $arrNames, 'AsFrontendModule no longer accepts a $' . $strName . ' argument');
		}
	}

	/**
	 * `template: 'frontend_module/zyppy_search'` only takes the modern rendering
	 * path because the name contains a slash. That rule is Contao's, and it is
	 * what the .twig-root marker exists to satisfy - so it is pinned rather than
	 * trusted.
	 */
	public function testASlashIsStillWhatMakesAFragmentTemplateModern(): void
	{
		// No setAccessible(): it has been a no-op since PHP 8.1 and is deprecated
		// on 8.5, which the Contao 6 leg runs on.
		$objMethod = new \ReflectionMethod('Contao\CoreBundle\Controller\AbstractFragmentController', 'isLegacyTemplate');

		// A concrete subclass: the abstract base cannot be instantiated, and this
		// bundle's own controller is the one whose template name has to be judged.
		$objController = (new \ReflectionClass(ZyppySearchController::class))->newInstanceWithoutConstructor();

		$this->assertFalse($objMethod->invoke($objController, 'frontend_module/zyppy_search'), 'a slashed name must be a MODERN template');
		$this->assertTrue($objMethod->invoke($objController, 'mod_search_zyppy'), 'a flat name must stay a LEGACY template');
	}

}
