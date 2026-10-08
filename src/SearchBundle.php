<?php

/**
 * @copyright 	Andrew Stevens
 * @author 		Andrew Stevens
 * @license 	AGPL-3.0-only
 * @package 	Zyppy Search
 * @link 		https://github.com/asconsulting/contao-zyppy-search
 */


namespace Zyppy\Search;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;


class SearchBundle extends AbstractBundle
{

	public function loadExtension(
		array $config,
		ContainerConfigurator $containerConfigurator,
		ContainerBuilder $containerBuilder,
	): void
	{
		$containerConfigurator->import('../config/services.yaml');
	}

	public function getPath(): string
	{
		return \dirname(__DIR__);
	}
}
