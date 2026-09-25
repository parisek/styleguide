<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Bridge\Symfony;

use Parisek\Styleguide\Bridge\Symfony\StyleguideKernel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The micro-kernel, through real requests.
 *
 * A kernel that boots and a router that dumps have both reported a healthy
 * application while every page answered 500, so every assertion here goes
 * through `Kernel::handle()`.
 */
final class StyleguideKernelTest extends TestCase
{
    private const STATIC_DIR = __DIR__ . '/../../fixtures/bundle';

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
    public function it_serves_the_catalogue_in_production(): void
    {
        $kernel = $this->kernel('prod', false);

        self::assertSame(200, $kernel->handle(Request::create('/styleguide'))->getStatusCode());
        self::assertSame(200, $kernel->handle(Request::create('/styleguide/'))->getStatusCode());
        self::assertSame(200, $kernel->handle(Request::create('/styleguide/component/sample'))->getStatusCode());
        self::assertSame(200, $kernel->handle(Request::create('/styleguide/api/components'))->getStatusCode());
    }

    #[Test]
    public function production_has_no_profiler_and_no_toolbar(): void
    {
        $kernel = $this->kernel('prod', false);

        self::assertStringNotContainsString('sfToolbar', (string) $kernel->handle(Request::create('/styleguide/'))->getContent());
        self::assertSame(404, $kernel->handle(Request::create('/_profiler/'))->getStatusCode());
        self::assertFalse($kernel->getContainer()->has('profiler'));
    }

    #[Test]
    public function debug_puts_the_toolbar_on_the_catalogue(): void
    {
        $response = $this->kernel('dev', true)->handle(Request::create('/styleguide/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('sfToolbar', (string) $response->getContent());
    }

    #[Test]
    public function debug_keeps_the_toolbar_out_of_a_preview_and_keeps_the_profile(): void
    {
        $kernel = $this->kernel('dev', true);
        $response = $kernel->handle(Request::create('/styleguide/render/component/sample'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('sfToolbar', (string) $response->getContent());
        self::assertFalse($response->headers->has('X-Debug-Token'));
        self::assertFalse($response->headers->has('X-Debug-Token-Link'));

        // The profile is what the listener exists to keep. Profiles are saved
        // on terminate.
        $kernel->terminate(Request::create('/styleguide/render/component/sample'), $response);
        $profiler = $kernel->getContainer()->get('profiler');
        self::assertInstanceOf(Profiler::class, $profiler);
        self::assertNotSame([], $profiler->find(null, '/styleguide/render/component/sample', 10, null, null, null));
    }

    #[Test]
    public function the_cache_lives_outside_the_project_and_follows_the_kernel_class(): void
    {
        $base = $this->kernel('prod', false);
        $sub = $this->kernel('prod', false, ProjectKernel::class);

        self::assertStringStartsWith(sys_get_temp_dir(), $base->getCacheDir());
        self::assertStringNotContainsString(realpath(self::STATIC_DIR . '/..') ?: 'x', $base->getCacheDir());
        self::assertNotSame($base->getCacheDir(), $sub->getCacheDir());
    }

    #[Test]
    public function debug_and_non_debug_never_share_a_cache(): void
    {
        // A non-debug kernel never checks cached routes for freshness, so a
        // shared directory would keep the profiler's routes after APP_DEBUG=0.
        self::assertNotSame($this->kernel('dev', true)->getCacheDir(), $this->kernel('dev', false)->getCacheDir());
    }

    #[Test]
    public function the_cache_is_under_a_directory_private_to_the_user(): void
    {
        $private = \dirname($this->kernel('prod', false)->getCacheDir(), 2);

        self::assertSame('styleguide-kernel-' . posix_geteuid(), basename($private));
        self::assertSame(0o700, fileperms($private) & 0o777);
    }

    #[Test]
    public function a_cache_directory_open_to_others_is_refused(): void
    {
        $temp = sys_get_temp_dir() . '/sg-kernel-temp-' . bin2hex(random_bytes(4));
        $this->cacheRoots[] = $temp;
        mkdir($temp . '/styleguide-kernel-' . posix_geteuid(), 0o777, true);
        chmod($temp . '/styleguide-kernel-' . posix_geteuid(), 0o777);

        $kernel = new TempDirKernel('prod', false, self::STATIC_DIR);
        $kernel->temp = $temp;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('refusing the cache directory');
        $kernel->getCacheDir();
    }

    #[Test]
    public function a_symlinked_cache_directory_is_refused(): void
    {
        $temp = sys_get_temp_dir() . '/sg-kernel-temp-' . bin2hex(random_bytes(4));
        $this->cacheRoots[] = $temp;
        mkdir($temp . '/elsewhere', 0o700, true);
        symlink($temp . '/elsewhere', $temp . '/styleguide-kernel-' . posix_geteuid());

        $kernel = new TempDirKernel('prod', false, self::STATIC_DIR);
        $kernel->temp = $temp;

        $this->expectException(\RuntimeException::class);
        $kernel->getCacheDir();
    }

    #[Test]
    public function cache_version_changes_the_cache(): void
    {
        $a = new TempDirKernel('prod', false, self::STATIC_DIR);
        $a->temp = sys_get_temp_dir();
        $b = new TempDirKernel('prod', false, self::STATIC_DIR);
        $b->temp = sys_get_temp_dir();
        $b->version = 'deploy-2';
        $this->cacheRoots[] = \dirname($a->getCacheDir());
        $this->cacheRoots[] = \dirname($b->getCacheDir());

        self::assertNotSame($a->getCacheDir(), $b->getCacheDir());
    }

    #[Test]
    public function an_environment_that_is_not_a_path_segment_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new StyleguideKernel('../../elsewhere', false, self::STATIC_DIR);
    }

    #[Test]
    public function the_mount_path_is_a_container_parameter(): void
    {
        $kernel = $this->kernel('prod', false);
        $kernel->boot();

        // Everything in the bridge reads it from here, so a configurable
        // mount later changes only where the parameter comes from.
        self::assertSame('/styleguide', $kernel->getContainer()->getParameter('styleguide.base_url'));
    }

    #[Test]
    public function the_project_dir_is_the_parent_of_the_static_dir(): void
    {
        $kernel = $this->kernel('prod', false);

        self::assertSame(\dirname(self::STATIC_DIR), $kernel->getProjectDir());
        self::assertSame(self::STATIC_DIR, $kernel->getStaticDir());
    }

    #[Test]
    public function a_subclass_adds_routes_and_services_through_the_hooks(): void
    {
        $kernel = $this->kernel('prod', false, ProjectKernel::class);

        $response = $kernel->handle(Request::create('/project-hook'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('configured by the project', $response->getContent());

        self::assertInstanceOf(ProjectBundle::class, $kernel->getBundle('ProjectBundle'));

        // And the catalogue is still there.
        self::assertSame(200, $kernel->handle(Request::create('/styleguide/'))->getStatusCode());
    }

    /**
     * @param class-string<StyleguideKernel> $class
     */
    private function kernel(string $environment, bool $debug, string $class = StyleguideKernel::class): StyleguideKernel
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
 * A kernel pointed at a temp directory the test controls.
 */
final class TempDirKernel extends StyleguideKernel
{
    public string $temp = '';

    public string $version = '';

    protected function tempDir(): string
    {
        return $this->temp;
    }

    protected function cacheVersion(): string
    {
        return $this->version;
    }
}

/**
 * A project kernel, the shape a front controller declares when it needs more.
 */
final class ProjectKernel extends StyleguideKernel
{
    protected function projectBundles(): iterable
    {
        yield new ProjectBundle();
    }

    protected function configureProject(ContainerConfigurator $container): void
    {
        $container->parameters()->set('project.greeting', 'configured by the project');
        $container->services()
            ->set(ProjectHookController::class)
            ->args(['%project.greeting%'])
            ->public()
            ->tag('controller.service_arguments');
    }

    protected function configureProjectRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('project_hook', '/project-hook')->controller(ProjectHookController::class);
    }
}

final class ProjectHookController
{
    public function __construct(private readonly string $greeting) {}

    public function __invoke(): Response
    {
        return new Response($this->greeting);
    }
}

final class ProjectBundle extends Bundle {}
