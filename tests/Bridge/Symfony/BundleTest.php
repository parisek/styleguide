<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Bridge\Symfony;

use Parisek\Styleguide\Bridge\Symfony\Controller\StyleguideController;
use Parisek\Styleguide\Bridge\Symfony\StyleguideBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * The bundle, exercised through a real kernel and real requests.
 *
 * Booting a container proves almost nothing here — the whole point of the
 * bundle is what comes back over HTTP, and the design it implements was
 * repeatedly wrong in ways that a successful boot hid. So every assertion below
 * goes through `Kernel::handle()`.
 */
final class BundleTest extends TestCase
{
    private function kernel(string $prefix = '/styleguide'): Kernel
    {
        // MicroKernelTrait is what supplies the `kernel::loadRoutes` loader the
        // routing needs; a bare Kernel has no such loader and every request
        // dies in DelegatingLoader. This is a TEST kernel, not the public
        // "micro-kernel mode" the design deliberately dropped — that would have
        // been a third documented consumer path with no consumer.
        return new class ($prefix) extends Kernel {
            use MicroKernelTrait;

            public function __construct(private readonly string $stylguidePrefix)
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
                $container->extension('styleguide', [
                    'config' => __DIR__ . '/../../fixtures/bundle/styleguide.yaml',
                    'prefix' => $this->stylguidePrefix,
                ]);
            }

            protected function configureRoutes(RoutingConfigurator $routes): void
            {
                // Exactly how a host imports them, so the routes file is the
                // thing under test rather than a copy of it.
                $routes->import(
                    __DIR__ . '/../../../src/Bridge/Symfony/Resources/config/routes.php',
                    'php',
                );
            }

            public function getProjectDir(): string
            {
                return sys_get_temp_dir() . '/sg-bundle-' . md5($this->stylguidePrefix);
            }

            public function getCacheDir(): string
            {
                return $this->getProjectDir() . '/cache';
            }

            public function getLogDir(): string
            {
                return $this->getProjectDir() . '/log';
            }
        };
    }

    #[Test]
    public function the_controller_is_a_service_the_router_can_reach(): void
    {
        $kernel = $this->kernel();
        $kernel->boot();

        // Public on purpose: the routing refers to it by service id.
        self::assertTrue($kernel->getContainer()->has(StyleguideController::class));
    }

    #[Test]
    public function an_api_route_comes_back_as_json_over_http(): void
    {
        $response = $this->kernel()->handle(Request::create('/styleguide/api/components'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->headers->get('Content-Type'));
        json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        // A real difference between the two modes, asserted rather than
        // papered over. The library sends `no-cache`; Symfony normalises
        // Cache-Control and adds `private`, so the bundle sends
        // `no-cache, private`.
        //
        // Left alone. `private` only forbids shared-cache storage, which for a
        // developer catalogue is stricter than what the package asked for and
        // never looser — and overriding it would mean fighting
        // ResponseHeaderBag on every response to win a cosmetic match. The
        // assertion is here so the difference is documented where someone
        // comparing the two modes will find it.
        self::assertSame('no-cache, private', $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function the_bare_prefix_is_a_route_of_its_own(): void
    {
        // Two routes rather than one optional segment: `/styleguide` is a real
        // URL that Router::parse() answers with the SPA landing, and a single
        // pattern does not match it cleanly.
        $response = $this->kernel()->handle(Request::create('/styleguide'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function an_spa_deep_link_survives_a_direct_refresh(): void
    {
        // The reason `{path}` is a catch-all. The SPA routes with the history
        // API, so a bookmarked or pasted deep link arrives as a real request and
        // must come back as the shell — a segment-limited pattern would 404 on
        // exactly the URLs people share.
        $response = $this->kernel()->handle(Request::create('/styleguide/component/sample'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function a_render_route_comes_back_as_html(): void
    {
        $response = $this->kernel()->handle(Request::create('/styleguide/render/component/sample'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function an_asset_is_streamed_as_a_file(): void
    {
        // BinaryFileResponse, not a string body — the reason Result carries a
        // path. A host with X-Sendfile configured can hand the file to the web
        // server instead of reading it through PHP.
        $response = $this->kernel()->handle(Request::create('/styleguide/assets/index.html'));

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $response);
    }

    #[Test]
    public function a_conditional_asset_request_gets_a_304(): void
    {
        $first = $this->kernel()->handle(Request::create('/styleguide/assets/index.html'));
        $etag = (string) $first->headers->get('ETag');

        self::assertNotSame('', $etag);

        $second = $this->kernel()->handle(Request::create(
            '/styleguide/assets/index.html',
            server: ['HTTP_IF_NONE_MATCH' => $etag],
        ));

        self::assertSame(304, $second->getStatusCode());
    }

    #[Test]
    public function an_unrecognised_path_under_the_prefix_is_a_404(): void
    {
        // The route is a catch-all; Router::parse() is stricter. A 404 from the
        // host is the honest answer — an empty 200 would let a CI smoke test
        // pass on a typo'd URL.
        $response = $this->kernel()->handle(Request::create('/styleguide/api/nope'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function a_path_outside_the_prefix_never_reaches_the_controller(): void
    {
        $response = $this->kernel()->handle(Request::create('/about-us'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function a_prefix_other_than_styleguide_is_refused_at_container_build(): void
    {
        // Refused rather than half-honoured. `/styleguide` is hardcoded from the
        // PHP router through the Vue history base and the asset URLs baked into
        // the committed dist/index.html, so mounting elsewhere would route
        // correctly and then serve a shell that still asks for /styleguide/...
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('only "/styleguide" is supported');

        $this->kernel('/kit')->boot();
    }

    #[Test]
    public function the_bundle_cannot_be_given_an_auth_callable(): void
    {
        // Not a check this bundle adds — `auth` is in RUN_TRUTH_KEYS, so
        // fromYaml() refuses it, and the DI extension builds the service through
        // fromYaml(). Security is the host's firewall, with no second gate that
        // could disagree with it.
        $this->expectException(\InvalidArgumentException::class);

        \Parisek\Styleguide\Styleguide::fromYaml(__DIR__ . '/../../fixtures/bundle/styleguide-with-auth.yaml');
    }
}
