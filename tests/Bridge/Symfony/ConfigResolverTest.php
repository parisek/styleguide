<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Bridge\Symfony;

use Parisek\Styleguide\Bridge\Symfony\StyleguideBundle;
use Parisek\Styleguide\Bridge\Symfony\StyleguideConfigResolverInterface;
use Parisek\Styleguide\Bridge\Symfony\StyleguideFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * `styleguide.config_resolver`: one catalogue per request, picked by a service.
 *
 * Every assertion that matters goes through `Kernel::handle()`, as in
 * BundleTest. The test resolver maps a host through a fixed allowlist; a host
 * name is a key, never a part of a path.
 */
final class ConfigResolverTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/resolver';

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            self::removeDirectory($dir);
        }

        $this->dirs = [];
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
     * @param array<string, mixed> $styleguide the `styleguide:` bundle configuration
     */
    private function kernel(array $styleguide): Kernel
    {
        $dir = sys_get_temp_dir() . '/sg-resolver-' . md5(serialize($styleguide));
        $this->dirs[] = $dir;
        self::removeDirectory($dir);

        return new class ($styleguide, $dir) extends Kernel {
            use MicroKernelTrait;

            /** @param array<string, mixed> $styleguide */
            public function __construct(private readonly array $styleguide, private readonly string $dir)
            {
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
                $container->extension('styleguide', $this->styleguide);
                $container->services()
                    ->set('test.resolver', AllowlistResolver::class)->public()
                    ->set('test.missing_file_resolver', MissingFileResolver::class)->public();
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

    /**
     * @return list<string>
     */
    private static function ids(Kernel $kernel, string $host): array
    {
        $response = $kernel->handle(Request::create('http://' . $host . '/styleguide/api/components'));
        self::assertSame(200, $response->getStatusCode(), $host);

        $ids = array_column((array) json_decode((string) $response->getContent(), true), 'id');
        sort($ids);

        return $ids;
    }

    #[Test]
    public function two_hosts_in_one_kernel_get_two_catalogues(): void
    {
        $kernel = $this->kernel(['config_resolver' => 'test.resolver']);

        $alpha = self::ids($kernel, 'alpha.ux.test');
        $beta = self::ids($kernel, 'beta.ux.test');
        $alphaAgain = self::ids($kernel, 'alpha.ux.test');

        self::assertSame(['alpha-card'], $alpha);
        self::assertSame(['beta-card'], $beta);
        self::assertSame($alpha, $alphaAgain, 'nothing from the beta request stays behind');
    }

    #[Test]
    public function a_component_renders_from_the_catalogue_of_its_own_host(): void
    {
        $kernel = $this->kernel(['config_resolver' => 'test.resolver']);

        $own = $kernel->handle(Request::create('http://beta.ux.test/styleguide/render/component/beta-card'));
        self::assertSame(200, $own->getStatusCode());
        self::assertStringContainsString('beta-card', (string) $own->getContent());

        $foreign = $kernel->handle(Request::create('http://alpha.ux.test/styleguide/render/component/beta-card'));
        self::assertNotSame(200, $foreign->getStatusCode(), 'a component of another host is not served');
    }

    #[Test]
    public function an_unknown_host_is_a_404_without_the_host_or_a_path_in_the_body(): void
    {
        $kernel = $this->kernel(['config_resolver' => 'test.resolver']);

        // Valid host names that the allowlist does not know.
        foreach (['unknown.ux.test', 'a.ux.test.evil', 'alpha.ux.test.evil', 'ux.test'] as $host) {
            $request = Request::create('/styleguide/api/components');
            $request->headers->set('Host', $host);
            $response = $kernel->handle($request, catch: true);

            self::assertSame(404, $response->getStatusCode(), $host);
            self::assertStringNotContainsString($host, (string) $response->getContent(), $host);
            self::assertStringNotContainsString(self::FIXTURES, (string) $response->getContent(), $host);
        }
    }

    #[Test]
    public function a_host_with_dots_in_a_row_never_reaches_the_resolver_or_a_path(): void
    {
        // Symfony refuses a malformed host (400) before the resolver runs. The
        // resolver would answer 404 too: `a..b.ux.test` is not in the allowlist.
        $kernel = $this->kernel(['config_resolver' => 'test.resolver']);
        $kernel->boot();
        $resolver = $kernel->getContainer()->get('test.resolver');
        self::assertInstanceOf(AllowlistResolver::class, $resolver);

        $request = Request::create('/styleguide/api/components');
        $request->headers->set('Host', 'a..b.ux.test');
        $response = $kernel->handle($request, catch: true);

        // The body is Symfony's own debug page here; the bundle adds nothing to it.
        self::assertContains($response->getStatusCode(), [400, 404]);
        self::assertSame(0, $resolver->calls);

        $direct = Request::create('/styleguide/api/components');
        $direct->headers->set('Host', '../alpha');
        $this->expectException(NotFoundHttpException::class);
        $resolver->resolve($direct);
    }

    #[Test]
    public function the_resolver_is_asked_on_every_request(): void
    {
        $kernel = $this->kernel(['config_resolver' => 'test.resolver']);
        $kernel->boot();
        $resolver = $kernel->getContainer()->get('test.resolver');
        self::assertInstanceOf(AllowlistResolver::class, $resolver);

        self::ids($kernel, 'alpha.ux.test');
        self::ids($kernel, 'alpha.ux.test');
        self::ids($kernel, 'beta.ux.test');

        self::assertSame(3, $resolver->calls);
    }

    #[Test]
    public function the_factory_builds_a_new_catalogue_for_every_request(): void
    {
        $factory = new StyleguideFactory(null, new AllowlistResolver());

        $first = $factory->forRequest('', Request::create('http://alpha.ux.test/styleguide'));
        $second = $factory->forRequest('', Request::create('http://alpha.ux.test/styleguide'));
        $third = $factory->forRequest('', Request::create('http://beta.ux.test/styleguide'));

        self::assertNotSame($first, $second, 'the same host still gets a new object');
        self::assertNotSame($second, $third);
    }

    #[Test]
    public function a_resolver_needs_the_request(): void
    {
        $factory = new StyleguideFactory(null, new AllowlistResolver());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('request');
        $factory->forRequest('');
    }

    #[Test]
    public function config_and_resolver_together_fail_at_compile_and_name_both_keys(): void
    {
        $kernel = $this->kernel([
            'config' => self::FIXTURES . '/alpha/styleguide.yaml',
            'config_resolver' => 'test.resolver',
        ]);

        try {
            $kernel->boot();
            self::fail('The container must not compile with both keys.');
        } catch (\Throwable $e) {
            self::assertStringContainsString('styleguide.config', $e->getMessage());
            self::assertStringContainsString('styleguide.config_resolver', $e->getMessage());
            self::assertStringContainsString('mutually exclusive', $e->getMessage());
        }
    }

    #[Test]
    public function neither_key_fails_at_compile_and_names_both_keys(): void
    {
        $kernel = $this->kernel([]);

        try {
            $kernel->boot();
            self::fail('The container must not compile with neither key.');
        } catch (\Throwable $e) {
            self::assertStringContainsString('styleguide.config', $e->getMessage());
            self::assertStringContainsString('styleguide.config_resolver', $e->getMessage());
        }
    }

    #[Test]
    public function the_fixed_config_works_as_before(): void
    {
        $kernel = $this->kernel(['config' => self::FIXTURES . '/alpha/styleguide.yaml']);

        self::assertSame(['alpha-card'], self::ids($kernel, 'anything.test'));
    }

    #[Test]
    public function a_resolver_that_returns_a_missing_file_fails_with_a_clear_message(): void
    {
        $factory = new StyleguideFactory(null, new MissingFileResolver());

        try {
            $factory->forRequest('', Request::create('http://alpha.ux.test/styleguide'));
            self::fail('A missing file must not pass.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('config_resolver', $e->getMessage());
            self::assertStringContainsString('missing.yaml', $e->getMessage());
            self::assertStringContainsString('MissingFileResolver', $e->getMessage());
        }
    }

    #[Test]
    public function a_missing_file_is_a_server_error_over_http_not_a_404(): void
    {
        $kernel = $this->kernel(['config_resolver' => 'test.missing_file_resolver']);

        $response = $kernel->handle(Request::create('http://alpha.ux.test/styleguide/api/components'), catch: true);

        self::assertSame(500, $response->getStatusCode());
    }
}

/**
 * The test resolver. The host is a key into a fixed map, never a path part.
 * It reads the raw Host header so that a host Symfony itself would refuse
 * still reaches the allowlist.
 */
final class AllowlistResolver implements StyleguideConfigResolverInterface
{
    private const MAP = [
        'alpha.ux.test' => 'alpha',
        'beta.ux.test' => 'beta',
    ];

    public int $calls = 0;

    public function resolve(Request $request): string
    {
        ++$this->calls;

        $folder = self::MAP[(string) $request->headers->get('Host')] ?? null;
        if ($folder === null) {
            throw new NotFoundHttpException('No catalogue for host ' . (string) $request->headers->get('Host') . '.');
        }

        return __DIR__ . '/../../fixtures/resolver/' . $folder . '/styleguide.yaml';
    }
}

final class MissingFileResolver implements StyleguideConfigResolverInterface
{
    public function resolve(Request $request): string
    {
        return __DIR__ . '/../../fixtures/resolver/missing.yaml';
    }
}
