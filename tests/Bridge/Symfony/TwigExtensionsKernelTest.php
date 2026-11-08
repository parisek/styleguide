<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Bridge\Symfony;

use Parisek\Styleguide\Bridge\Symfony\StyleguideKernel;
use Parisek\Styleguide\Tests\Support\UrlExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Request;

/**
 * Host Twig extensions in the micro-kernel.
 *
 * The micro-kernel registers TwigBundle in debug for the profiler. An extension
 * tagged `twig.extension` reaches that `twig` service, which is not the
 * catalogue's environment. `styleguide.twig_extensions` is the way in, set by a
 * project kernel in `configureProject()`. Everything goes through
 * `Kernel::handle()`.
 */
final class TwigExtensionsKernelTest extends TestCase
{
    private const STATIC_DIR = __DIR__ . '/../../fixtures/twig-extensions';

    /** @var list<string> */
    private array $cacheRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->cacheRoots as $dir) {
            self::removeDirectory($dir);
        }

        $this->cacheRoots = [];
    }

    #[Test]
    public function a_host_function_reaches_a_render_in_production(): void
    {
        $response = $this->kernel(HostExtensionKernel::class, 'prod', false)
            ->handle(Request::create('/styleguide/render/component/linked'));
        $html = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode(), $html);
        self::assertStringContainsString('href="/about"', $html);
        self::assertStringContainsString('data-build="/build"', $html);
    }

    #[Test]
    public function a_host_function_reaches_a_render_with_the_profiler_on(): void
    {
        $response = $this->kernel(HostExtensionKernel::class, 'dev', true)
            ->handle(Request::create('/styleguide/render/component/linked'));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString('href="/about"', (string) $response->getContent());
    }

    #[Test]
    public function a_function_tagged_only_for_symfony_twig_does_not_reach_a_render(): void
    {
        $response = $this->kernel(TaggedOnlyKernel::class, 'dev', true)
            ->handle(Request::create('/styleguide/render/component/linked'));

        // The fixture keeps `show_source` off, so the failed render answers
        // with the fixed message (R5.12), not a boot or routing fault.
        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('This entry cannot be shown.', (string) $response->getContent());
    }

    /**
     * @param class-string<StyleguideKernel> $class
     */
    private function kernel(string $class, string $environment, bool $debug): StyleguideKernel
    {
        $kernel = new $class($environment, $debug, self::STATIC_DIR);
        $this->cacheRoots[] = \dirname($kernel->getCacheDir());

        return $kernel;
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
            is_dir($path) && !is_link($path) ? self::removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}

/**
 * A project kernel that hands its `url()` extension to the catalogue.
 */
final class HostExtensionKernel extends StyleguideKernel
{
    protected function configureProject(ContainerConfigurator $container): void
    {
        $container->services()->set('app.url_extension', UrlExtension::class)->tag('twig.extension');
        $container->extension('styleguide', ['twig_extensions' => ['app.url_extension']]);
    }
}

/**
 * A project kernel that only tags its extension, as a Symfony app usually does.
 */
final class TaggedOnlyKernel extends StyleguideKernel
{
    protected function configureProject(ContainerConfigurator $container): void
    {
        $container->services()->set('app.url_extension', UrlExtension::class)->tag('twig.extension');
    }
}
