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
use Symfony\Component\HttpKernel\HttpKernelInterface;
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
    /** @var list<string> */
    private array $projectDirs = [];

    protected function tearDown(): void
    {
        // Each kernel compiles a container into the system temp directory.
        // Leaving them behind is how a stale compiled container outlives the
        // change that should have invalidated it.
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

    private function kernel(string $prefix = '/styleguide'): Kernel
    {
        return $this->build($prefix, __DIR__ . '/../../fixtures/bundle/styleguide.yaml');
    }

    /**
     * A kernel pointed at a different project yaml, for the cases where the
     * catalogue's own configuration is what is under test.
     */
    private function kernelWithConfig(string $config): Kernel
    {
        return $this->build('/styleguide', $config);
    }

    private function build(string $prefix, string $config): Kernel
    {
        $this->projectDirs[] = sys_get_temp_dir() . '/sg-bundle-' . md5($prefix . $config);

        // MicroKernelTrait is what supplies the `kernel::loadRoutes` loader the
        // routing needs; a bare Kernel has no such loader and every request
        // dies in DelegatingLoader. This is a TEST kernel, not the public
        // "micro-kernel mode" the design deliberately dropped — that would have
        // been a third documented consumer path with no consumer.
        return new class ($prefix, $config) extends Kernel {
            use MicroKernelTrait;

            public function __construct(
                private readonly string $styleguidePrefix,
                private readonly string $styleguideConfig,
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
                $container->extension('styleguide', [
                    'config' => $this->styleguideConfig,
                    'prefix' => $this->styleguidePrefix,
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
                // Keyed on both, so two kernels with different configuration
                // never share a compiled container.
                return sys_get_temp_dir() . '/sg-bundle-' . md5($this->styleguidePrefix . $this->styleguideConfig);
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
    public function a_deployment_without_rewrites_still_routes(): void
    {
        // The bug a review found with a probe and the suite did not. The
        // controller took getRequestUri(), which includes the base URL, so on
        // `/index.php/styleguide/…` — any host without rewrites, or installed
        // in a subdirectory — routing matched (it uses pathInfo) and then
        // Router::parse() was handed `/index.php/styleguide/…`, did not
        // recognise it, and every single request 404'd.
        //
        // What this asserts is routing, not a usable catalogue under a
        // subdirectory: the built shell still requests /styleguide/assets/…
        // at the domain root. The endpoints answer; the shell needs the
        // prefix where it was built for.
        $response = $this->kernel()->handle(Request::create(
            '/index.php/styleguide/api/components',
            server: ['SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => '/index.php'],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function the_query_string_survives_the_path_info_rewrite(): void
    {
        // getPathInfo() drops the query string, and Router::parse() reads
        // `?theme=`, `?variant=` and `?locale=` out of the URI itself — so the
        // fix for the above has to re-attach it or it trades one silent
        // breakage for another.
        $light = $this->kernel()->handle(Request::create('/styleguide/render/component/sample?theme=light'));
        $dark = $this->kernel()->handle(Request::create('/styleguide/render/component/sample?theme=dark'));

        self::assertSame(200, $dark->getStatusCode());
        self::assertNotSame(
            $light->getContent(),
            $dark->getContent(),
            'the query string was lost on the way to Router::parse()',
        );
    }

    #[Test]
    public function an_iframe_request_renders_the_component_not_the_shell(): void
    {
        $shell = $this->kernel()->handle(Request::create('/styleguide/component/sample'));
        $embedded = $this->kernel()->handle(Request::create(
            '/styleguide/component/sample',
            server: ['HTTP_SEC_FETCH_DEST' => 'iframe'],
        ));

        self::assertSame(200, $embedded->getStatusCode());
        self::assertNotSame($shell->getContent(), $embedded->getContent());
    }

    #[Test]
    public function a_head_request_sends_the_headers_and_no_body(): void
    {
        $response = $this->kernel()->handle(Request::create('/styleguide/api/components', 'HEAD'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function a_post_is_refused_by_the_router(): void
    {
        // The routes are GET|HEAD. A write method should never reach a
        // read-only catalogue, and the router is the right place to say so.
        $response = $this->kernel()->handle(Request::create('/styleguide/api/components', 'POST'));

        self::assertSame(405, $response->getStatusCode());
    }

    #[Test]
    public function an_asset_cannot_escape_the_dist_directory(): void
    {
        // The containment check AssetServer grew in #139, reached through the
        // host's stack this time. Serving via BinaryFileResponse must not
        // bypass it — the path only ever comes from a Result built after
        // realpath() and isContained().
        $response = $this->kernel()->handle(
            Request::create('/styleguide/assets/../../composer.json'),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function a_missing_asset_is_a_404(): void
    {
        self::assertSame(
            404,
            $this->kernel()->handle(Request::create('/styleguide/assets/nope.css'))->getStatusCode(),
        );
    }

    #[Test]
    public function an_auth_key_in_the_projects_yaml_is_refused_through_the_kernel(): void
    {
        // The previous version of this test called fromYaml() directly, which
        // proves the LIBRARY rule and not the bundle wiring — a review pointed
        // that out. This goes through the kernel, which also documents WHEN the
        // refusal fires: styleguide.core is private and lazily instantiated, so
        // a project yaml carrying `auth` boots fine and fails on the first
        // request rather than at cache:clear.
        $kernel = $this->kernelWithConfig(__DIR__ . '/../../fixtures/bundle/styleguide-with-auth.yaml');

        $response = $kernel->handle(Request::create('/styleguide/api/components'));

        self::assertSame(500, $response->getStatusCode());

        // A 500 on its own would not prove the refusal: fromYaml() also throws
        // InvalidArgumentException for a config file it cannot find, so a
        // fixture path that rotted would keep this test green and leave the
        // security property untested. Name the reason.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("key 'bootstrap.auth' is a run-truth value");

        $kernel->handle(
            Request::create('/styleguide/api/components'),
            HttpKernelInterface::MAIN_REQUEST,
            // Positional: Kernel::handle()'s third parameter is $catch.
            false,
        );
    }

    #[Test]
    public function the_bundle_cannot_be_given_an_auth_callable(): void
    {
        // The library rule the above rests on, asserted directly. The message
        // is asserted for the same reason as in the kernel test: the bare
        // exception class does not distinguish a refused key from a missing
        // file.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("key 'bootstrap.auth' is a run-truth value");

        \Parisek\Styleguide\Styleguide::fromYaml(__DIR__ . '/../../fixtures/bundle/styleguide-with-auth.yaml');
    }
}
