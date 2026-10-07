<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Bridge\Symfony;

use Parisek\Styleguide\Bridge\Symfony\DependencyInjection\TwigExtensionsPass;
use Parisek\Styleguide\Bridge\Symfony\StyleguideBundle;
use Parisek\Styleguide\Tests\Support\NotAnExtension;
use Parisek\Styleguide\Tests\Support\UrlExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * `styleguide.twig_extensions`: service ids the bundle resolves and adds to the
 * catalogue's own Twig. Everything goes through a real kernel and real requests.
 */
final class TwigExtensionsBundleTest extends TestCase
{
    public const CONFIG = __DIR__ . '/../../fixtures/twig-extensions/styleguide.yaml';

    /** @var list<string> */
    private array $projectDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->projectDirs as $dir) {
            self::removeDirectory($dir);
        }

        $this->projectDirs = [];
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    /**
     * @param list<string> $ids        the `styleguide.twig_extensions` value; null leaves the key out
     * @param array<string, class-string> $services extra services, id => class
     */
    private function kernel(?array $ids, array $services = []): Kernel
    {
        $dir = sys_get_temp_dir() . '/sg-twig-ext-' . md5(serialize([$ids, $services]));
        $this->projectDirs[] = $dir;

        return new class ($ids, $services, $dir) extends Kernel {
            use MicroKernelTrait;

            /**
             * @param list<string>|null $ids
             * @param array<string, class-string> $services
             */
            public function __construct(
                private readonly ?array $ids,
                private readonly array $services,
                private readonly string $dir,
            ) {
                parent::__construct('test', true);
            }

            public function registerBundles(): iterable
            {
                return [new FrameworkBundle(), new StyleguideBundle()];
            }

            protected function configureContainer(ContainerConfigurator $container): void
            {
                $container->extension('framework', [
                    'secret' => 'test',
                    'test' => true,
                    'http_method_override' => false,
                    'router' => ['utf8' => true],
                ]);
                $container->extension('styleguide', array_filter([
                    'config' => TwigExtensionsBundleTest::CONFIG,
                    'twig_extensions' => $this->ids,
                ], static fn(mixed $value): bool => $value !== null));

                foreach ($this->services as $id => $class) {
                    $container->services()->set($id, $class);
                }
            }

            protected function configureRoutes(RoutingConfigurator $routes): void
            {
                $routes->import(
                    __DIR__ . '/../../../src/Bridge/Symfony/Resources/config/routes.php',
                    'php',
                );
            }

            public function getProjectDir(): string
            {
                return $this->dir;
            }

            public function getCacheDir(): string
            {
                return $this->dir . '/cache';
            }

            public function getLogDir(): string
            {
                return $this->dir . '/log';
            }
        };
    }

    #[Test]
    public function a_configured_service_supplies_the_function_and_the_global(): void
    {
        $kernel = $this->kernel(['test.url'], ['test.url' => UrlExtension::class]);

        $response = $kernel->handle(Request::create('/styleguide/render/component/linked'));
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode(), $html);
        self::assertStringContainsString('href="/about"', $html);
        self::assertStringContainsString('data-build="/build"', $html);
    }

    #[Test]
    public function without_the_key_the_template_fails_and_the_message_names_the_function(): void
    {
        $kernel = $this->kernel(null);

        $response = $kernel->handle(Request::create('/styleguide/render/component/linked'));

        self::assertGreaterThanOrEqual(500, $response->getStatusCode());
        // The render error page escapes the message.
        self::assertStringContainsString('Unknown &quot;url&quot; function', (string) $response->getContent());
    }

    #[Test]
    public function an_empty_list_changes_nothing(): void
    {
        $kernel = $this->kernel([]);

        $response = $kernel->handle(Request::create('/styleguide/render/component/linked'));

        self::assertGreaterThanOrEqual(500, $response->getStatusCode());
    }

    #[Test]
    public function a_missing_service_fails_at_boot_and_names_the_id(): void
    {
        $kernel = $this->kernel(['test.nowhere']);

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('test.nowhere');
        $kernel->boot();
    }

    #[Test]
    public function a_service_that_is_not_a_twig_extension_fails_at_boot_and_names_the_id(): void
    {
        $kernel = $this->kernel(['test.plain'], ['test.plain' => NotAnExtension::class]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('test.plain');
        $kernel->boot();
    }

    #[Test]
    public function a_non_extension_message_names_the_required_interface(): void
    {
        $kernel = $this->kernel(['test.plain'], ['test.plain' => NotAnExtension::class]);

        $this->expectExceptionMessage('Twig\Extension\ExtensionInterface');
        $kernel->boot();
    }

    #[Test]
    public function the_pass_reads_the_extensions_by_name_whatever_the_position(): void
    {
        $container = new ContainerBuilder();
        $container->register('test.plain', NotAnExtension::class);
        // Other arguments stand in front of the extensions, as when the
        // factory gains a config resolver.
        $container->setDefinition('styleguide.factory', new Definition('stdClass', [
            '/path/to/styleguide.yaml',
            new Reference('test.resolver'),
            '$twigExtensions' => [new Reference('test.plain')],
        ]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('test.plain');
        (new TwigExtensionsPass())->process($container);
    }

    #[Test]
    public function the_pass_ignores_a_factory_without_the_extensions_argument(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('styleguide.factory', new Definition('stdClass', ['/path/to/styleguide.yaml']));

        (new TwigExtensionsPass())->process($container);

        $this->addToAssertionCount(1);
    }
}
