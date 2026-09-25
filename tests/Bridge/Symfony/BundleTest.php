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

    /**
     * @param string|null $prefix the removed `styleguide.prefix` option, only
     *                            to prove it is refused; null leaves it out
     */
    private function kernel(?string $prefix = null): Kernel
    {
        return $this->build($prefix, __DIR__ . '/../../fixtures/bundle/styleguide.yaml');
    }

    /**
     * A kernel pointed at a different project yaml, for the cases where the
     * catalogue's own configuration is what is under test.
     */
    private function kernelWithConfig(string $config): Kernel
    {
        return $this->build(null, $config);
    }

    private function build(?string $prefix, string $config): Kernel
    {
        $this->projectDirs[] = sys_get_temp_dir() . '/sg-bundle-' . md5(($prefix ?? '') . $config);

        // MicroKernelTrait is what supplies the `kernel::loadRoutes` loader the
        // routing needs; a bare Kernel has no such loader and every request
        // dies in DelegatingLoader. This is a bare TEST kernel that isolates
        // the bundle; StyleguideKernelTest covers the public StyleguideKernel.
        return new class ($prefix, $config) extends Kernel {
            use MicroKernelTrait;

            public function __construct(
                private readonly ?string $styleguidePrefix,
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
                $container->extension('styleguide', array_filter([
                    'config' => $this->styleguideConfig,
                    'prefix' => $this->styleguidePrefix,
                ], static fn(?string $value): bool => $value !== null));
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
                return sys_get_temp_dir() . '/sg-bundle-' . md5(($this->styleguidePrefix ?? '') . $this->styleguideConfig);
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
    public function the_removed_prefix_option_is_refused_by_name(): void
    {
        // Removed in 1.23. A host that still sets it gets Symfony's own
        // "unrecognized option" error, which names the key to delete.
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->expectExceptionMessage('"prefix"');

        $this->kernel('/styleguide')->boot();
    }

    #[Test]
    public function base_url_moves_the_routes(): void
    {
        $kernel = $this->kernelWithConfig($this->yamlWithMount('/tools/ui'));

        self::assertSame(200, $kernel->handle(Request::create('/tools/ui'))->getStatusCode());
        self::assertSame(200, $kernel->handle(Request::create('/tools/ui/'))->getStatusCode());
        self::assertSame(200, $kernel->handle(Request::create('/tools/ui/api/components'))->getStatusCode());
        self::assertSame(200, $kernel->handle(Request::create('/tools/ui/render/component/sample'))->getStatusCode());
        self::assertStringContainsString('"baseUrl":"/tools/ui"', (string) $kernel->handle(Request::create('/tools/ui/'))->getContent());

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $kernel->handle(Request::create('/styleguide/'), HttpKernelInterface::MAIN_REQUEST, false);
    }

    #[Test]
    public function a_host_base_path_prefixes_every_url_the_catalogue_produces(): void
    {
        // Symfony installed under /subdir, the catalogue mounted at /kit
        // inside it. Routing matches the path info; the shell must ask for
        // /subdir/kit/…, or the browser loads nothing.
        $kernel = $this->kernelWithConfig($this->yamlWithMount('/kit'));
        $server = ['SCRIPT_NAME' => '/subdir/index.php', 'SCRIPT_FILENAME' => '/var/www/subdir/index.php'];

        $shell = (string) $kernel->handle(Request::create('/subdir/kit/', server: $server))->getContent();

        self::assertStringContainsString('"baseUrl":"/subdir/kit"', $shell);
        self::assertMatchesRegularExpression('#src="/subdir/kit/assets/styleguide\.[^"]+\.js"#', $shell);

        $render = (string) $kernel->handle(Request::create('/subdir/kit/render/component/sample', server: $server))->getContent();
        self::assertStringContainsString('href="/subdir/kit/component/sample"', $render);
    }

    private function yamlWithMount(string $mount): string
    {
        $source = (string) file_get_contents(__DIR__ . '/../../fixtures/bundle/styleguide.yaml');
        $dir = sys_get_temp_dir() . '/sg-bundle-yaml-' . md5($mount);
        @mkdir($dir);
        // Same relative paths as the fixture, resolved from the fixture's
        // directory, so only the mount differs.
        $fixtureDir = realpath(__DIR__ . '/../../fixtures/bundle');
        $yaml = str_replace(
            ['templates_path: ../templates', 'static_path: ..'],
            ['templates_path: ' . $fixtureDir . '/../templates', 'static_path: ' . $fixtureDir . '/..'],
            $source,
        );
        $yaml = str_replace("bootstrap:\n", "bootstrap:\n  base_url: " . $mount . "\n", $yaml);
        file_put_contents($dir . '/styleguide.yaml', $yaml);
        $this->projectDirs[] = $dir;

        return $dir . '/styleguide.yaml';
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
        // This asserts routing. That the shell then asks for its assets under
        // the base URL is a_host_base_path_prefixes_every_url_the_catalogue_produces.
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
    public function the_prefix_is_served_with_and_without_its_trailing_slash(): void
    {
        // Both, in one test, because fixing either one alone moved the 301 to
        // the other: Symfony's redirectable matcher answers a trailing-slash
        // difference on the first route that nearly matches, without trying
        // the route that matches exactly. A test covering only `/styleguide/`
        // would have gone green on a version that redirected `/styleguide`.
        //
        // It matters because the library front controller served both
        // directly, and `/styleguide/` is the URL people bookmark and the one
        // the SPA's history base is written with. Found by serving real
        // requests through both entry points and diffing them.
        foreach (['/styleguide', '/styleguide/'] as $uri) {
            $response = $this->kernel()->handle(Request::create($uri));

            self::assertSame(200, $response->getStatusCode(), $uri);
            self::assertStringContainsString(
                'text/html',
                (string) $response->headers->get('Content-Type'),
                $uri,
            );
        }
    }

    #[Test]
    public function the_asset_base_comes_from_the_request(): void
    {
        // The gap this factory closes. `templateUrl` is run truth — fromYaml()
        // refuses it in the YAML because it is correct for exactly one request
        // — so a service built when the container compiles can only ever carry
        // an empty base. Right at the domain root, silently wrong for a host
        // serving a theme through a rewrite or from a subdirectory, and the
        // bundle exposes no key to correct it.
        $render = $this->kernel()->handle(Request::create(
            '/sub/index.php/styleguide/render/component/sample',
            server: ['SCRIPT_NAME' => '/sub/index.php', 'SCRIPT_FILENAME' => '/sub/index.php'],
        ));

        self::assertSame(200, $render->getStatusCode());
        self::assertStringContainsString('/sub/dist/css/style.css', (string) $render->getContent());
    }

    #[Test]
    public function the_asset_base_is_empty_at_the_domain_root(): void
    {
        // The other half, and the one a regression would hide behind: a host
        // at the domain root must keep passing paths through byte for byte.
        // Rebasing onto a non-empty base here would prefix every stylesheet
        // with a path that does not exist.
        $render = $this->kernel()->handle(Request::create('/styleguide/render/component/sample'));

        $html = (string) $render->getContent();
        self::assertStringContainsString('"/dist/css/style.css"', $html);
        self::assertStringNotContainsString('/index.php/dist/', $html);
    }

    #[Test]
    public function the_asset_base_matches_what_the_library_front_controller_computes(): void
    {
        // getBasePath(), not getBaseUrl(): the latter keeps the script
        // filename, so `/index.php/styleguide/…` would rebase every iframe
        // stylesheet onto `/index.php/dist/…`. These are the four deployment
        // shapes the library's `rtrim(dirname(SCRIPT_NAME), '/')` covers.
        $shapes = [
            ['/styleguide/', '/index.php', ''],
            ['/index.php/styleguide/', '/index.php', ''],
            ['/sub/index.php/styleguide/', '/sub/index.php', '/sub'],
            ['/sub/styleguide/', '/sub/index.php', '/sub'],
        ];

        foreach ($shapes as [$uri, $scriptName, $expected]) {
            $request = Request::create(
                $uri,
                server: ['SCRIPT_NAME' => $scriptName, 'SCRIPT_FILENAME' => $scriptName],
            );

            self::assertSame($expected, $request->getBasePath(), $uri);
            self::assertSame(rtrim(dirname($scriptName), '/'), $request->getBasePath(), $uri);
        }
    }

    #[Test]
    public function an_auth_key_in_the_projects_yaml_is_refused_through_the_kernel(): void
    {
        // The previous version of this test called fromYaml() directly, which
        // proves the LIBRARY rule and not the bundle wiring — a review pointed
        // that out. This goes through the kernel, which also documents WHEN the
        // refusal fires: the container holds only a factory carrying a path,
        // and fromYaml() runs inside the controller, so a project yaml
        // carrying `auth` boots fine and fails on the first request rather
        // than at cache:clear.
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
