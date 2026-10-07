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
 * What belongs here is only what the HOST owns: where that file is, or the service that picks it per request. The mount
 * is catalogue configuration, `bootstrap.base_url` in the file itself.
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('styleguide');

        $tree->getRootNode()
            ->validate()
                ->ifTrue(static fn(array $v): bool => isset($v['config'], $v['config_resolver']))
                ->thenInvalid('"styleguide.config" and "styleguide.config_resolver" are mutually exclusive: set one.')
            ->end()
            ->validate()
                ->ifTrue(static fn(array $v): bool => !isset($v['config']) && !isset($v['config_resolver']))
                ->thenInvalid('Set either "styleguide.config" or "styleguide.config_resolver".')
            ->end()
            ->children()
                ->scalarNode('config')
                    ->cannotBeEmpty()
                    ->info('Absolute path to the project styleguide.yaml, the same file Styleguide::fromYaml() reads. Exclusive with "config_resolver".')
                ->end()
                ->scalarNode('config_resolver')
                    ->cannotBeEmpty()
                    ->info('Id of a service that implements StyleguideConfigResolverInterface. It picks the styleguide.yaml per request. Exclusive with "config".')
                ->end()
                ->arrayNode('twig_extensions')
                    ->validate()
                        ->ifTrue(static fn(mixed $value): bool => is_array($value) && !array_is_list($value))
                        ->thenInvalid('"styleguide.twig_extensions" must be a list of service ids, not a map. Write "- app.url_extension", one id per line.')
                    ->end()
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->info('Ids of services (Twig extensions) added to the catalogue\'s own Twig, for functions the templates call, such as url().')
                ->end()
            ->end();

        return $tree;
    }
}
