<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Bridge\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * `config/packages/styleguide.yaml`.
 *
 * Deliberately small. The catalogue's own configuration lives in the project's
 * `styleguide.yaml`, which `Styleguide::fromYaml()` already reads — duplicating
 * it here would create a second place to change the same thing.
 *
 * What belongs here is only what the HOST owns: where that file is, and where
 * the catalogue is mounted.
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('styleguide');

        $tree->getRootNode()
            ->children()
                ->scalarNode('config')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->info('Absolute path to the project styleguide.yaml, the same file Styleguide::fromYaml() reads.')
                ->end()
                ->scalarNode('prefix')
                    ->defaultNull()
                    ->info(
                        'Deprecated since 1.22: set bootstrap.base_url in styleguide.yaml instead. '
                        . 'When given, it must name the same mount as bootstrap.base_url '
                        . '(default /styleguide); it never overrides it.',
                    )
                ->end()
            ->end();

        return $tree;
    }
}
