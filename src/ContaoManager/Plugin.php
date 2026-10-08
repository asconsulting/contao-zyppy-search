<?php

/**
 * @copyright 	Andrew Stevens
 * @author 		Andrew Stevens
 * @license 	AGPL-3.0-only
 * @package 	Zyppy Search
 * @link 		https://github.com/asconsulting/contao-zyppy-search
 */


namespace Zyppy\Search\ContaoManager;

use Zyppy\Search\SearchBundle;
use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Contao\ManagerPlugin\Routing\RoutingPluginInterface;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouteCollection;

class Plugin implements BundlePluginInterface, RoutingPluginInterface
{
    /**
     * {@inheritdoc}
     */
    public function getBundles(ParserInterface $parser)
    {
        return [
            BundleConfig::create(SearchBundle::class)->setLoadAfter([ContaoCoreBundle::class]),
        ];
    }

    /**
     * Registers config/routes.yaml.
     *
     * Nothing else loads it: a bundle's routes are not discovered automatically,
     * so without this method the /_zyppy/search/{moduleId} endpoint simply would
     * not exist and the live search would 404 with no error anywhere.
     *
     * {@inheritdoc}
     */
    public function getRouteCollection(LoaderResolverInterface $resolver, KernelInterface $kernel): RouteCollection|null
    {
        $file = __DIR__ . '/../../config/routes.yaml';

        return $resolver->resolve($file)->load($file);
    }
}
