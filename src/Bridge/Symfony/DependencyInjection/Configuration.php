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
 * What belongs here is only what the HOST owns: where that file is. The mount
 * is catalogue configuration, `bootstrap.base_url` in the file itself.
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
