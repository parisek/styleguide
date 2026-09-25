<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Bridge\Symfony\DependencyInjection;

use Parisek\Styleguide\Bridge\Symfony\Controller\StyleguideController;
use Parisek\Styleguide\Bridge\Symfony\StyleguideFactory;
use Parisek\Styleguide\MountPath;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Yaml\Yaml;

/**
 * Wires the catalogue into the host's container.
 *
 * Two services, one parameter, and the refusals that keep one mount.
 *
 * The `Styleguide` service is built through `fromYaml()`, so the project's
 * `styleguide.yaml` stays the single source of catalogue configuration — the
 * bundle adds no second place to say the same things.
 */
final class StyleguideExtension extends Extension
{
    /**
     * @deprecated since 1.22: the mount comes from bootstrap.base_url; read
     *             the `styleguide.base_url` container parameter. This is only
     *             the default.
     */
    public const SUPPORTED_PREFIX = MountPath::DEFAULT;

    /**
     * Container parameter holding the mount path the catalogue is served at.
     */
    public const BASE_URL_PARAMETER = 'styleguide.base_url';

    public function getAlias(): string
    {
        return 'styleguide';
    }

    /**
     * @param array<array-key, mixed> $config
     */
    public function getConfiguration(array $config, ContainerBuilder $container): ConfigurationInterface
    {
        return new Configuration();
    }

    /**
     * The normalised `bootstrap.base_url` of the catalogue's YAML, or null
     * when it sets none. A file that does not load is left to the runtime,
     * which reports it in its own words; an invalid mount is refused here,
     * because it decides the routes.
     */
    private static function yamlMount(string $path): ?string
    {
        try {
            $data = Yaml::parseFile($path);
        } catch (\Throwable) {
            return null;
        }

        $value = \is_array($data) && \is_array($data['bootstrap'] ?? null)
            ? ($data['bootstrap']['base_url'] ?? null)
            : null;

        if ($value === null) {
            return null;
        }

        try {
            return MountPath::normalise($value);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException(sprintf('%s: bootstrap.base_url: %s', $path, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @param array<array-key, mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{config: string, prefix: string|null} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        // The catalogue's own YAML decides the mount, as it does in library
        // mode: one source, so the router, the Symfony routes and the browser
        // never disagree. It is the path inside the host application; the
        // host's base path (`/subdir`, `/index.php`) comes from the request.
        $mount = self::yamlMount($config['config']) ?? MountPath::DEFAULT;

        if ($config['prefix'] !== null) {
            try {
                $prefix = MountPath::normalise($config['prefix']);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException('styleguide.prefix: ' . $e->getMessage(), 0, $e);
            }
            // Transitional: accepted only when it agrees. Letting either one
            // win would bring back the two-sources drift this avoids.
            if ($prefix !== $mount) {
                throw new \InvalidArgumentException(sprintf(
                    "styleguide.prefix is '%s' and %s mounts the catalogue at '%s'. Remove styleguide.prefix "
                        . 'and set bootstrap.base_url in styleguide.yaml.',
                    $prefix,
                    $config['config'],
                    $mount,
                ));
            }
            trigger_deprecation(
                'parisek/styleguide',
                '1.22',
                'The "styleguide.prefix" option is deprecated; set bootstrap.base_url in styleguide.yaml instead.',
            );
        }

        if (is_file($config['config'])) {
            $container->addResource(new FileResource($config['config']));
        }

        // A factory, not a Styleguide. The catalogue needs this request's
        // asset base (`twig_context.templateUrl`), which is run truth: it is
        // correct for exactly one request, which is why fromYaml() refuses it
        // in the YAML. A service built when the container compiles can only
        // carry an empty base — right for a host at the domain root, silently
        // wrong for one serving a theme through a rewrite or from a
        // subdirectory, with no key to correct it. See StyleguideFactory.
        $factory = new Definition(StyleguideFactory::class);
        $factory->setArguments([$config['config']]);
        $factory->setPublic(false);
        $container->setDefinition('styleguide.factory', $factory);

        // The mount as a parameter: the routes (`%styleguide.base_url%` in
        // their paths) and the toolbar listener read it from here.
        $container->setParameter(self::BASE_URL_PARAMETER, $mount);

        $controller = new Definition(StyleguideController::class);
        $controller->setArguments([new Reference('styleguide.factory')]);
        // Tagged rather than merely public: the host's routing refers to this
        // service by id, and `controller.service_arguments` is what lets Symfony
        // inject the Request argument.
        $controller->addTag('controller.service_arguments');
        $controller->setPublic(true);
        $container->setDefinition(StyleguideController::class, $controller);
    }
}
