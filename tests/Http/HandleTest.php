<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Http;

use Parisek\Styleguide\Http\Request;
use Parisek\Styleguide\Router;
use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `Styleguide::handle()` — a request in, a response out, nothing written and
 * nothing ended.
 *
 * This is the seam the package was missing. `run()` read superglobals, wrote
 * the response and called `exit`: right for a front controller, unusable from
 * a host application, which has to RETURN a response and cannot have the
 * process ended underneath it.
 *
 * Every assertion here was previously impossible in-process. `tests/SpaConfigTest`
 * says so in its own class comment, and drives a real subprocess to get around
 * that `exit`.
 */
final class HandleTest extends TestCase
{
    private function styleguide(): Styleguide
    {
        return new Styleguide([
            'templates_path' => __DIR__ . '/../fixtures/templates',
            'static_path' => __DIR__ . '/../fixtures',
            'config_yaml' => __DIR__ . '/../fixtures/styleguide.yaml',
        ]);
    }

    #[Test]
    public function a_uri_outside_the_styleguide_is_not_handled(): void
    {
        // null, not a 404: the caller's own routing should carry on. It is the
        // same decision run() used to make by returning early, which a host
        // application could never observe.
        self::assertNull($this->styleguide()->handle(new Request('/about-us')));
    }

    #[Test]
    public function an_api_route_comes_back_as_json(): void
    {
        $result = $this->styleguide()->handle(new Request('/styleguide/api/components'));

        self::assertNotNull($result);
        self::assertSame(200, $result->status);
        self::assertSame('application/json; charset=utf-8', $result->headers['Content-Type']);
        json_decode((string) $result->body, true, flags: \JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function an_unknown_api_endpoint_is_a_404(): void
    {
        $result = $this->styleguide()->handle(new Request('/styleguide/api/nope'));

        self::assertNotNull($result);
        self::assertSame(404, $result->status);
        self::assertStringContainsString('Unknown API endpoint', (string) $result->body);
    }

    #[Test]
    public function a_render_route_comes_back_as_html(): void
    {
        $result = $this->styleguide()->handle(new Request('/styleguide/render/component/sample'));

        self::assertNotNull($result);
        self::assertSame(200, $result->status);
        self::assertSame('text/html; charset=utf-8', $result->headers['Content-Type']);
    }

    #[Test]
    public function a_missing_component_renders_404_with_its_status(): void
    {
        // The status and the body arrive together. Before the seam the body
        // came back from Renderer while the status had been written to a
        // process global several frames deeper.
        $result = $this->styleguide()->handle(new Request('/styleguide/render/component/nope'));

        self::assertNotNull($result);
        self::assertSame(404, $result->status);
        self::assertStringContainsString('404', (string) $result->body);
    }

    #[Test]
    public function an_asset_route_comes_back_as_a_file_and_honours_if_none_match(): void
    {
        $sg = $this->styleguide();

        // index.html is the SPA shell the package ships; it is always in
        // dist/, and the "dist/ is reproducible from frontend/" CI job keeps it
        // that way.
        $first = $sg->handle(new Request('/styleguide/assets/index.html'));

        self::assertNotNull($first);
        self::assertSame(200, $first->status);
        self::assertNotNull($first->file);

        // The conditional-request contract: feed the ETag back and the second
        // answer must be a 304 with nothing to send.
        $second = $sg->handle(new Request(
            '/styleguide/assets/index.html',
            ifNoneMatch: $first->headers['ETag'],
        ));

        self::assertNotNull($second);
        self::assertSame(304, $second->status);
        self::assertNull($second->file);
    }

    #[Test]
    public function an_auth_callable_denies_before_any_branch_runs(): void
    {
        $sg = new Styleguide([
            'templates_path' => __DIR__ . '/../fixtures/templates',
            'static_path' => __DIR__ . '/../fixtures',
            'config_yaml' => __DIR__ . '/../fixtures/styleguide.yaml',
            'auth' => static fn(array $route): bool => false,
        ]);

        $result = $sg->handle(new Request('/styleguide/api/components'));

        self::assertNotNull($result);
        self::assertSame(403, $result->status);
        self::assertSame('403 Forbidden', $result->body);
    }

    #[Test]
    public function sec_fetch_dest_turns_an_spa_route_into_a_render(): void
    {
        // The reason Request carries four values rather than a path. An
        // in-iframe navigation must skip the SPA shell, and the only signal is
        // this header — a path-shaped input would have lost it silently.
        $spa = $this->styleguide()->handle(new Request('/styleguide/component/sample'));
        $embedded = $this->styleguide()->handle(new Request(
            '/styleguide/component/sample',
            secFetchDest: 'iframe',
        ));

        self::assertNotNull($spa);
        self::assertNotNull($embedded);
        self::assertNotSame(
            $spa->body,
            $embedded->body,
            'an iframe request should render the component, not the SPA shell',
        );
    }

    #[Test]
    public function one_instance_does_not_carry_a_locale_into_the_next_request(): void
    {
        // A bug this seam INTRODUCED, found by review rather than by the suite.
        // `?locale=` narrows the locale for one render; before handle() existed
        // that was enforced by run() ending the process. A reusable object has
        // no such luck, and every locale test builds a fresh Styleguide, so
        // nothing was watching the second request on the same instance.
        $sg = new Styleguide([
            'templates_path' => __DIR__ . '/../fixtures/templates',
            'static_path' => __DIR__ . '/../fixtures',
            'config_yaml' => __DIR__ . '/../fixtures/nonexistent.yaml',
            'translations_path' => __DIR__ . '/../fixtures/translations',
            'default_locale' => 'en',
        ]);

        $withLocale = $sg->handle(new Request('/styleguide/render/component/translated-sample?locale=cs_CZ'));
        $after = $sg->handle(new Request('/styleguide/render/component/translated-sample'));
        $fresh = $this->styleguideWithTranslations()->handle(
            new Request('/styleguide/render/component/translated-sample'),
        );

        self::assertNotNull($withLocale);
        self::assertNotNull($after);
        self::assertNotNull($fresh);

        // The second request must render exactly as a first request on a fresh
        // instance would. Comparing against the fresh render rather than a
        // hardcoded string keeps this about isolation, not about the fixture's
        // wording.
        self::assertSame($fresh->body, $after->body, 'the previous request left its locale behind');
        self::assertNotSame($withLocale->body, $after->body);
    }

    private function styleguideWithTranslations(): Styleguide
    {
        return new Styleguide([
            'templates_path' => __DIR__ . '/../fixtures/templates',
            'static_path' => __DIR__ . '/../fixtures',
            'config_yaml' => __DIR__ . '/../fixtures/nonexistent.yaml',
            'translations_path' => __DIR__ . '/../fixtures/translations',
            'default_locale' => 'en',
        ]);
    }

    #[Test]
    public function handle_writes_nothing_even_when_the_build_is_corrupt(): void
    {
        // handle() promises to write nothing, and the corrupt-build path used
        // to break that promise: it set a 500 and then threw, so a framework
        // caller catching the exception inherited a response code from a method
        // that returned no result. That status lives in run() now.
        $sg = $this->styleguide();
        $dist = (new \ReflectionClass($sg))->getProperty('distRoot');

        $broken = sys_get_temp_dir() . '/sg-corrupt-' . bin2hex(random_bytes(4));
        mkdir($broken);
        file_put_contents($broken . '/index.html', '<html>no injection point</html>');
        $dist->setValue($sg, $broken);

        http_response_code(200);

        try {
            $sg->handle(new Request('/styleguide/overview'));
            self::fail('a corrupt build should throw');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('#sg-config', $e->getMessage());
        } finally {
            @unlink($broken . '/index.html');
            @rmdir($broken);
        }

        self::assertSame(200, http_response_code(), 'handle() set a response code');
    }

    #[Test]
    public function the_theme_cookie_reaches_the_render(): void
    {
        // The other reason. An in-iframe navigation's href never carries
        // `?theme=`, so the preference survives only in this cookie.
        $result = $this->styleguide()->handle(new Request(
            '/styleguide/render/component/sample',
            cookies: [Router::IFRAME_THEME_COOKIE => 'dark'],
            secFetchDest: 'iframe',
        ));

        self::assertNotNull($result);
        self::assertStringContainsString('dark', (string) $result->body);
    }
}
