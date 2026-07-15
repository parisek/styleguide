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
        // /icons renders the standalone icon catalog in the iframe (#87).
        self::assertSame(['type' => 'icons'], Router::parse('/styleguide/icons'));
        self::assertSame(['type' => 'fields'], Router::parse('/styleguide/fields'));
    }

    #[Test]
    public function synthesize_embedded_swaps_icons_with_index_slug(): void
    {
        // Same sectionless-route contract as foundations (#87): no slug in
        // the SPA route, 'index' synthesized for the render dispatch shape.
        self::assertSame(
            ['type' => 'render', 'kind' => 'icons', 'slug' => 'index', 'theme' => 'light'],
            Router::synthesizeEmbeddedRoute(
                ['type' => 'icons'],
                'iframe',
            ),
        );
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
        self::assertSame(['type' => 'api', 'endpoint' => 'health'], Router::parse('/styleguide/api/health'));
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
    public function synthesize_embedded_falls_back_to_dark_iframe_theme_cookie(): void
    {
        // The scenario this whole cookie channel exists for: a native link
        // click inside dark-toggled iframe content re-issues a `Sec-Fetch-
        // Dest: iframe` request to an SPA-shell URL that carries no `?theme=`
        // of its own. Without the cookie fallback this synthesizes to
        // 'light', silently resetting the visitor's choice.
        self::assertSame(
            ['type' => 'render', 'kind' => 'page', 'slug' => 'homepage', 'theme' => 'dark'],
            Router::synthesizeEmbeddedRoute(
                ['type' => 'page', 'slug' => 'homepage'],
                'iframe',
                ['sg-iframe-theme' => 'dark'],
            ),
        );
    }

    #[Test]
    public function synthesize_embedded_whitelists_garbage_cookie_value_to_light(): void
    {
        // Cookie is client-writable, therefore untrusted input — same trust
        // boundary as the query string. A corrupted/forged value must never
        // reach the renderer unwhitelisted.
        self::assertSame(
            ['type' => 'render', 'kind' => 'page', 'slug' => 'homepage', 'theme' => 'light'],
            Router::synthesizeEmbeddedRoute(
                ['type' => 'page', 'slug' => 'homepage'],
                'iframe',
                ['sg-iframe-theme' => 'DROP TABLE'],
            ),
        );
    }

    #[Test]
    public function synthesize_embedded_prefers_explicit_query_theme_over_cookie(): void
    {
        // A hand-typed `?theme=` on the original SPA-shell URL is a more
        // specific signal than the visitor's last toggle — it wins even when
        // the cookie disagrees.
        self::assertSame(
            ['type' => 'render', 'kind' => 'page', 'slug' => 'homepage', 'theme' => 'light'],
            Router::synthesizeEmbeddedRoute(
                ['type' => 'page', 'slug' => 'homepage', 'theme' => 'light'],
                'iframe',
                ['sg-iframe-theme' => 'dark'],
            ),
        );
    }

    #[Test]
    public function parse_carries_explicit_theme_query_on_spa_shell_routes_for_later_synthesis(): void
    {
        // parse() itself never dispatches on this key (dispatchSpa() ignores
        // it) — it only exists to survive to synthesizeEmbeddedRoute()'s
        // "query beats cookie" precedence check.
        self::assertSame(
            ['type' => 'page', 'slug' => 'homepage', 'theme' => 'dark'],
            Router::parse('/styleguide/page/homepage?theme=dark'),
        );
        self::assertSame(
            ['type' => 'foundations', 'theme' => 'light'],
            Router::parse('/styleguide/foundations?theme=nope'), // invalid → whitelisted, not omitted
        );
    }

    #[Test]
    public function render_route_falls_back_to_dark_iframe_theme_cookie_when_query_absent(): void
    {
        self::assertSame(
            ['type' => 'render', 'kind' => 'component', 'slug' => 'hero', 'theme' => 'dark'],
            Router::parse('/styleguide/render/component/hero', ['sg-iframe-theme' => 'dark']),
        );
        // Explicit query still wins over the cookie.
        self::assertSame(
            ['type' => 'render', 'kind' => 'component', 'slug' => 'hero', 'theme' => 'light'],
            Router::parse('/styleguide/render/component/hero?theme=light', ['sg-iframe-theme' => 'dark']),
        );
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

    #[Test]
    public function whitelists_variant_query_param_on_render_route(): void
    {
        // Reconciled against P2's shipped theme default: a render route
        // always carries a `theme` key (defaults to 'light' absent a query
        // param or cookie) — variant has no such default, so it's the only
        // additive key here.
        self::assertSame(
            ['type' => 'render', 'kind' => 'component', 'slug' => 'multi', 'theme' => 'light', 'variant' => 'secondary'],
            Router::parse('/styleguide/render/component/multi?variant=secondary'),
        );
    }

    #[Test]
    public function whitelists_variant_query_param_on_spa_shell_routes(): void
    {
        // component/page/doc SPA routes also carry it — needed so
        // synthesizeEmbeddedRoute() can forward it when a consumer embeds
        // one of these URLs directly in an iframe. Unlike the render route,
        // these never default a `theme` key absent an explicit `?theme=`
        // (see withExplicitThemeIfPresent()), so `variant` is the only key
        // added here.
        self::assertSame(
            ['type' => 'component', 'slug' => 'multi', 'variant' => 'secondary'],
            Router::parse('/styleguide/component/multi?variant=secondary'),
        );
        self::assertSame(
            ['type' => 'page', 'slug' => 'homepage', 'variant' => 'secondary'],
            Router::parse('/styleguide/page/homepage?variant=secondary'),
        );
        self::assertSame(
            ['type' => 'doc', 'slug' => 'changelog', 'variant' => 'secondary'],
            Router::parse('/styleguide/doc/changelog?variant=secondary'),
        );
    }

    #[Test]
    public function drops_invalid_variant_query_param(): void
    {
        // Uppercase, whitespace, dot-segments, slashes — none of these can ever
        // be a real filename ComponentParser discovers, so they're dropped at
        // parse time rather than reaching Renderer at all.
        foreach (['UPPER', 'has space', '../../etc', 'a/b', ''] as $bad) {
            self::assertSame(
                ['type' => 'component', 'slug' => 'multi'],
                Router::parse('/styleguide/component/multi?variant=' . rawurlencode($bad)),
                "variant='$bad' must be dropped",
            );
        }
    }

    #[Test]
    public function variant_and_theme_query_params_coexist(): void
    {
        // Both whitelists are independent regexes over the same query string.
        self::assertSame(
            ['type' => 'render', 'kind' => 'component', 'slug' => 'multi', 'theme' => 'dark', 'variant' => 'secondary'],
            Router::parse('/styleguide/render/component/multi?theme=dark&variant=secondary'),
        );
    }

    #[Test]
    public function unrelated_query_params_are_still_ignored(): void
    {
        // Existing BC proof (mirrors strips_query_string above) extended to
        // confirm an unrelated param never leaks a stray key onto the route.
        self::assertSame(
            ['type' => 'component', 'slug' => 'hero'],
            Router::parse('/styleguide/component/hero?lang=cs'),
        );
    }

    #[Test]
    public function synthesize_embedded_carries_variant_and_theme_through(): void
    {
        self::assertSame(
            ['type' => 'render', 'kind' => 'component', 'slug' => 'multi', 'theme' => 'dark', 'variant' => 'secondary'],
            Router::synthesizeEmbeddedRoute(
                ['type' => 'component', 'slug' => 'multi', 'theme' => 'dark', 'variant' => 'secondary'],
                'iframe',
            ),
        );
    }

    #[Test]
    public function synthesize_embedded_omits_variant_when_absent(): void
    {
        // Reconciled against P2's shipped behaviour: synthesizeEmbeddedRoute()
        // always resolves a `theme` key (query > cookie > 'light' default —
        // see resolveTheme()), so it stays present even with none requested.
        // `variant` has no such fallback and is a straight copy-if-present,
        // so it's simply absent here — this is the "variant is lost across
        // an in-iframe navigation whose link carries no ?variant=" case
        // documented on synthesizeEmbeddedRoute().
        self::assertSame(
            ['type' => 'render', 'kind' => 'component', 'slug' => 'hero', 'theme' => 'light'],
            Router::synthesizeEmbeddedRoute(['type' => 'component', 'slug' => 'hero'], 'iframe'),
        );
    }

    #[Test]
    public function whitelist_variant_accepts_lowercase_alnum_and_dashes_and_rejects_everything_else(): void
    {
        self::assertSame('secondary', Router::whitelistVariant('secondary'));
        self::assertSame('dark-bg', Router::whitelistVariant('dark-bg'));
        self::assertSame('v2', Router::whitelistVariant('v2'));
        self::assertNull(Router::whitelistVariant(null));
        self::assertNull(Router::whitelistVariant(''));
        self::assertNull(Router::whitelistVariant('UPPER'));
        self::assertNull(Router::whitelistVariant('has space'));
        self::assertNull(Router::whitelistVariant('../../etc'));
        self::assertNull(Router::whitelistVariant('a/b'));
        self::assertNull(Router::whitelistVariant(['secondary'])); // never trust raw — non-string is rejected
    }
}
