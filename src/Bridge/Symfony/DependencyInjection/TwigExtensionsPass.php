<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Bridge\Symfony\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Reference;
use Twig\Extension\ExtensionInterface;

/**
 * Checks `styleguide.twig_extensions` while the container compiles, so a typo
 * in a service id stops the boot and names the id, instead of failing on the
 * first request, or never, when no request reads the catalogue.
 *
 * It runs before the container is optimised: the services the ids point at are
 * all registered by then, and none is inlined or removed yet.
 *
 * @internal
 */
final class TwigExtensionsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('styleguide.factory')) {
            return;
        }

        // By name: the position of the argument changes when the factory gains
        // a constructor argument in front of it.
        $arguments = $container->getDefinition('styleguide.factory')->getArguments();
        $references = $arguments['$twigExtensions'] ?? [];

        foreach (is_array($references) ? $references : [] as $reference) {
            if (!$reference instanceof Reference) {
                continue;
            }

            $id = (string) $reference;

            try {
                $class = $container->findDefinition($id)->getClass();
            } catch (ServiceNotFoundException $e) {
                throw new \LogicException(
                    sprintf('The "styleguide.twig_extensions" service "%s" does not exist.', $id),
                    0,
                    $e,
                );
            }

            // A service built by a factory may declare no class. The factory
            // checks the object itself at the first request.
            if ($class === null) {
                continue;
            }

            $class = $container->getParameterBag()->resolveValue($class);
            if (!is_string($class) || !is_a($class, ExtensionInterface::class, true)) {
                throw new \LogicException(sprintf(
                    'The "styleguide.twig_extensions" service "%s" must implement %s%s.',
                    $id,
                    ExtensionInterface::class,
                    is_string($class) ? sprintf(', "%s" does not', $class) : '',
                ));
            }
        }
    }
}
