<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    #[Test]
    public function parses_landing(): void
    {
        self::assertSame(['type' => 'landing'], Router::parse('/styleguide'));
        self::assertSame(['type' => 'landing'], Router::parse('/styleguide/'));
    }

    #[Test]
    public function parses_component_deep_link(): void
    {
        self::assertSame(
            ['type' => 'component', 'slug' => 'hero'],
            Router::parse('/styleguide/component/hero'),
        );
    }

    #[Test]
    public function parses_page_deep_link(): void
    {
        self::assertSame(
            ['type' => 'page', 'slug' => 'homepage'],
            Router::parse('/styleguide/page/homepage'),
        );
    }

    #[Test]
    public function parses_overview_foundations_and_fields(): void
    {
        // /overview is the SPA-only Components & Pages index (kind=overview is
        // never dispatched to Renderer — Styleguide::dispatchSpa handles it).
        self::assertSame(['type' => 'overview'], Router::parse('/styleguide/overview'));
        // /foundations renders the logo/colors/typography page in the iframe
        // (renamed from /overview in the slug swap).
        self::assertSame(['type' => 'foundations'], Router::parse('/styleguide/foundations'));
        self::assertSame(['type' => 'fields'], Router::parse('/styleguide/fields'));
    }

    #[Test]
    public function parses_render_endpoint(): void
    {
        self::assertSame(
            ['type' => 'render', 'kind' => 'component', 'slug' => 'hero', 'theme' => 'light'],
            Router::parse('/styleguide/render/component/hero'),
        );
        self::assertSame(
            ['type' => 'render', 'kind' => 'page', 'slug' => 'homepage', 'theme' => 'light'],
            Router::parse('/styleguide/render/page/homepage'),
        );
    }

    #[Test]
    public function whitelist_theme_accepts_dark_and_defaults_everything_else_to_light(): void
    {
        self::assertSame('dark', Router::whitelistTheme('dark'));
        self::assertSame('light', Router::whitelistTheme('light'));
        self::assertSame('light', Router::whitelistTheme(null));
        self::assertSame('light', Router::whitelistTheme(''));
        self::assertSame('light', Router::whitelistTheme('DARK')); // case-sensitive, no normalisation guesswork
        self::assertSame('light', Router::whitelistTheme(['dark'])); // never trust raw — non-string is rejected
    }

    #[Test]
    public function render_route_carries_whitelisted_theme_from_query_string(): void
    {
        self::assertSame(
            ['type' => 'render', 'kind' => 'component', 'slug' => 'hero', 'theme' => 'dark'],
            Router::parse('/styleguide/render/component/hero?theme=dark'),
        );
        self::assertSame(
            ['type' => 'render', 'kind' => 'component', 'slug' => 'hero', 'theme' => 'light'],
            Router::parse('/styleguide/render/component/hero?theme=neon'), // invalid → default
        );
        self::assertSame(
            ['type' => 'render', 'kind' => 'component', 'slug' => 'hero', 'theme' => 'light'],
            Router::parse('/styleguide/render/component/hero'), // absent → default
        );
    }

    #[Test]
    public function parses_api_endpoints(): void
    {
        self::assertSame(['type' => 'api', 'endpoint' => 'components'], Router::parse('/styleguide/api/components'));
        self::assertSame(['type' => 'api', 'endpoint' => 'pages'], Router::parse('/styleguide/api/pages'));
        self::assertSame(['type' => 'api', 'endpoint' => 'fields'], Router::parse('/styleguide/api/fields'));
        self::assertSame(['type' => 'api', 'endpoint' => 'docs'], Router::parse('/styleguide/api/docs'));
    }

    #[Test]
    public function parses_asset_paths(): void
    {
        self::assertSame(
            ['type' => 'asset', 'path' => 'styleguide.abc.css'],
            Router::parse('/styleguide/assets/styleguide.abc.css'),
        );
        self::assertSame(
            ['type' => 'asset', 'path' => 'locales/cs.json'],
            Router::parse('/styleguide/assets/locales/cs.json'),
        );
    }

    #[Test]
    public function strips_query_string(): void
    {
        self::assertSame(
            ['type' => 'component', 'slug' => 'hero'],
            Router::parse('/styleguide/component/hero?lang=cs'),
        );
    }

    #[Test]
    public function returns_null_for_non_styleguide_urls(): void
    {
        self::assertNull(Router::parse('/about'));
        self::assertNull(Router::parse('/'));
        self::assertNull(Router::parse('/styleguidedark')); // not a real /styleguide/ prefix
    }

    #[Test]
    public function synthesize_embedded_swaps_component_route_for_render(): void
    {
        // Iframe-context request on /styleguide/component/hero → render endpoint.
        // Without the swap the SPA shell would load inside the parent iframe,
        // producing nested chrome.
        self::assertSame(
            ['type' => 'render', 'kind' => 'component', 'slug' => 'hero', 'theme' => 'light'],
            Router::synthesizeEmbeddedRoute(
                ['type' => 'component', 'slug' => 'hero'],
                'iframe',
            ),
        );
    }

    #[Test]
    public function synthesize_embedded_swaps_page_route_for_render(): void
    {
        self::assertSame(
            ['type' => 'render', 'kind' => 'page', 'slug' => 'homepage', 'theme' => 'light'],
            Router::synthesizeEmbeddedRoute(
                ['type' => 'page', 'slug' => 'homepage'],
                'iframe',
            ),
        );
    }

    #[Test]
    public function synthesize_embedded_swaps_foundations_with_index_slug(): void
    {
        // Foundations carries no slug in the SPA route; the render dispatcher
        // ignores the slug for that branch, but the route shape contract still
        // expects one. 'index' mirrors the public render-endpoint convention.
        self::assertSame(
            ['type' => 'render', 'kind' => 'foundations', 'slug' => 'index', 'theme' => 'light'],
            Router::synthesizeEmbeddedRoute(
                ['type' => 'foundations'],
                'iframe',
            ),
        );
    }

    #[Test]
    public function synthesize_embedded_passes_through_when_not_iframe(): void
    {
        // Top-level navigation — `Sec-Fetch-Dest: document` (or empty on older
        // browsers) means the user is browsing the SPA directly. Route stays
        // unchanged so dispatchSpa() serves the chrome.
        $route = ['type' => 'page', 'slug' => 'homepage'];
        self::assertSame($route, Router::synthesizeEmbeddedRoute($route, 'document'));
        self::assertSame($route, Router::synthesizeEmbeddedRoute($route, ''));
        // Other Sec-Fetch-Dest values (image, script, …) shouldn't trigger the
        // synthesis either — only the iframe value swaps the route.
        self::assertSame($route, Router::synthesizeEmbeddedRoute($route, 'image'));
    }

    #[Test]
    public function synthesize_embedded_leaves_render_route_unchanged(): void
    {
        // Direct /styleguide/render/... requests already hit the isolated
        // dispatch path — no synthesis needed, no double-wrapping.
        $route = ['type' => 'render', 'kind' => 'component', 'slug' => 'hero'];
        self::assertSame($route, Router::synthesizeEmbeddedRoute($route, 'iframe'));
    }

    #[Test]
    public function synthesize_embedded_leaves_other_routes_unchanged(): void
    {
        // Routes outside the SPA-shell set don't have an iframe-nesting problem.
        // `asset` / `api` / `overview` / `fields` / `landing` pass through.
        foreach (
            [
                ['type' => 'asset', 'path' => 'styleguide.css'],
                ['type' => 'api', 'endpoint' => 'components'],
                ['type' => 'overview'],
                ['type' => 'fields'],
                ['type' => 'landing'],
            ] as $route
        ) {
            self::assertSame(
                $route,
                Router::synthesizeEmbeddedRoute($route, 'iframe'),
                'Route type ' . $route['type'] . ' must pass through',
            );
        }
    }

    #[Test]
    public function parses_doc_deep_link(): void
    {
        self::assertSame(
            ['type' => 'doc', 'slug' => 'changelog'],
            Router::parse('/styleguide/doc/changelog'),
        );
    }

    #[Test]
    public function synthesize_embedded_swaps_doc_route_for_render(): void
    {
        self::assertSame(
            ['type' => 'render', 'kind' => 'doc', 'slug' => 'changelog', 'theme' => 'light'],
            Router::synthesizeEmbeddedRoute(
                ['type' => 'doc', 'slug' => 'changelog'],
                'iframe',
            ),
        );
    }
}
