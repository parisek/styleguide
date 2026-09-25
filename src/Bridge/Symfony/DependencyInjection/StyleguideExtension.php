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
 * Two services and one refusal.
 *
 * The `Styleguide` service is built through `fromYaml()`, so the project's
 * `styleguide.yaml` stays the single source of catalogue configuration — the
 * bundle adds no second place to say the same things.
 */
final class StyleguideExtension extends Extension
{
    /**
     * The one prefix that works. Kept as a constant so the check below and the
     * bundle's routing file cannot drift apart.
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
     * when it sets none. A file that does not load, or an invalid value, is
     * left to the runtime, which reports it in its own words.
     */
    private static function yamlMount(string $path): ?string
    {
        try {
            $data = Yaml::parseFile($path);
            $value = \is_array($data) && \is_array($data['bootstrap'] ?? null)
                ? ($data['bootstrap']['base_url'] ?? null)
                : null;

            return $value === null ? null : MountPath::normalise($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<array-key, mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{config: string, prefix: string} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        if ($config['prefix'] !== self::SUPPORTED_PREFIX) {
            // Refused rather than half-honoured. `/styleguide` is hardcoded from
            // Router::parse() through the Vue router's history base, the SPA's
            // API and locale fetches, the iframe URLs, the theme cookie path and
            // the asset URLs already baked into the committed dist/index.html.
            // Accepting another value would route the controller correctly and
            // then serve a shell that asks for /styleguide/... anyway — broken
            // in a way that looks like a caching problem.
            throw new \InvalidArgumentException(sprintf(
                'styleguide.prefix: only "%s" is supported, got "%s". The prefix is hardcoded '
                . 'through the PHP router, the built SPA bundle and its asset URLs; making it '
                . 'configurable is a frontend build change, tracked separately. Mounting the '
                . 'catalogue elsewhere would serve a shell that still requests "%s/...".',
                self::SUPPORTED_PREFIX,
                $config['prefix'],
                self::SUPPORTED_PREFIX,
            ));
        }

        // The library honours bootstrap.base_url; this bridge's routes do not
        // yet. A YAML mount other than the prefix would make the catalogue
        // answer a path no route reaches — refused here, at container build,
        // not discovered as a 404.
        $yamlMount = self::yamlMount($config['config']);
        if ($yamlMount !== null && $yamlMount !== $config['prefix']) {
            throw new \InvalidArgumentException(sprintf(
                "%s sets bootstrap.base_url to '%s', and the Symfony bundle serves the catalogue at '%s' "
                    . 'only. Remove base_url, or set it to %s, until the bundle follows it.',
                $config['config'],
                $yamlMount,
                $config['prefix'],
                $config['prefix'],
            ));
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

        // The mount path as a parameter, so everything else in the bridge
        // reads it from the container rather than from SUPPORTED_PREFIX. Today
        // it can only be that constant; when the mount becomes configurable,
        // only this line's source changes.
        $container->setParameter(self::BASE_URL_PARAMETER, $config['prefix']);

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
