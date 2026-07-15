<?php

declare(strict_types=1);

namespace Parisek\Styleguide;

/**
 * @internal Implementation detail of `Styleguide::run()`. Signature and
 *           behaviour can change in any minor release. Consumers must not
 *           depend on this class directly. The observable URL surface
 *           (which the router parses) IS part of the public contract —
 *           see `docs/API.md` § URL surface.
 *
 * Parses /styleguide/* request URIs into structured route descriptors.
 *
 * All share-able URLs (`/styleguide/component/<slug>`, `/page/<slug>`, `/overview`, `/fields`)
 * map to the SPA — server returns the same `dist/index.html` for each, and the SPA
 * router (`frontend/src/router.js`) reads `location.pathname` and renders the right view.
 *
 * Internal endpoints (`/render/*`, `/api/*`, `/assets/*`) bypass the SPA and dispatch
 * to dedicated handlers in {@see Styleguide::run()}.
 */
final class Router
{
    /**
     * Cookie name the SPA writes to when the visitor toggles the iframe's
     * in-content theme (see `frontend/src/stores/ui.js` `setIframeTheme()`).
     * Gives `synthesizeEmbeddedRoute()` a channel to recover the preference
     * on in-iframe navigations, which never carry the SPA's own `?theme=`
     * query param — the clicked link's href is authored by the rendered
     * content, not the SPA.
     */
    public const IFRAME_THEME_COOKIE = 'sg-iframe-theme';

    /**
     * Parse a request URI to a route descriptor, or null if the URI doesn't belong
     * to the styleguide.
     *
     * @param array<string,mixed> $cookies Raw `$_COOKIE` (or a test double). Only
     *        consulted for the `render` route's theme fallback; SPA-shell routes
     *        (`component`/`page`/`doc`/`foundations`) only ever carry an explicit
     *        `theme` key when the query string itself asked for one — see
     *        {@see self::resolveTheme()} for why cookie fallback lives in
     *        `synthesizeEmbeddedRoute()` instead.
     * @return array{type:string,slug?:string,kind?:string,endpoint?:string,path?:string,theme?:string,variant?:string}|null
     */
    public static function parse(string $uri, array $cookies = []): ?array
    {
        // Captured before strtok() below discards it — only the `render`
        // and SPA-shell (component/page/doc) branches consume it (theme for
        // iframe HTML output, variant for which styleguide.<variant>.twig
        // sibling to resolve).
        $queryString = (string) (strpos($uri, '?') !== false ? substr($uri, strpos($uri, '?') + 1) : '');

        // Strip query string and trailing slash
        $uri = (string) strtok($uri, '?');
        $uri = rtrim($uri, '/');

        if ($uri === '/styleguide') {
            return ['type' => 'landing'];
        }

        if (!str_starts_with($uri, '/styleguide/')) {
            return null;
        }

        $path = substr($uri, strlen('/styleguide/'));
        $parts = explode('/', $path);

        // /styleguide/assets/<path...>
        if ($parts[0] === 'assets') {
            return ['type' => 'asset', 'path' => implode('/', array_slice($parts, 1))];
        }

        // /styleguide/render/<kind>/<slug>
        if ($parts[0] === 'render' && count($parts) >= 3) {
            parse_str($queryString, $query);
            $route = [
                'type' => 'render',
                'kind' => $parts[1],
                'slug' => $parts[2],
                'theme' => self::resolveTheme($query['theme'] ?? null, $cookies),
            ];
            $variant = self::whitelistVariant($query['variant'] ?? null);
            if ($variant !== null) {
                $route['variant'] = $variant;
            }
            return $route;
        }

        // /styleguide/api/<endpoint>
        if ($parts[0] === 'api' && isset($parts[1])) {
            return ['type' => 'api', 'endpoint' => $parts[1]];
        }

        // /styleguide/component/<slug>, /styleguide/page/<slug>, /styleguide/doc/<slug>
        if (in_array($parts[0], ['component', 'page', 'doc'], true) && isset($parts[1])) {
            $route = self::withExplicitThemeIfPresent(['type' => $parts[0], 'slug' => $parts[1]], $queryString);
            return self::withExplicitVariantIfPresent($route, $queryString);
        }

        // /styleguide/overview, /styleguide/foundations, /styleguide/icons, /styleguide/fields
        if (in_array($parts[0], ['overview', 'foundations', 'icons', 'fields'], true)) {
            return self::withExplicitThemeIfPresent(['type' => $parts[0]], $queryString);
        }

        // Unknown path under /styleguide/ — default to landing (SPA handles it)
        return ['type' => 'landing'];
    }

    /**
     * Whitelist an arbitrary (query-string-sourced, therefore untrusted) theme
     * value down to one of the two values `render-cell.twig` understands.
     * Anything else — missing, wrong case, an array from a malformed query
     * string — falls back to `'light'`, the historical (pre-feature) render
     * output, so a bad/absent `?theme=` never surfaces as broken markup.
     */
    public static function whitelistTheme(mixed $raw): string
    {
        return $raw === 'dark' ? 'dark' : 'light';
    }

    /**
     * Whitelist an arbitrary (query-string-sourced, therefore untrusted)
     * variant id syntactically. Unlike {@see self::whitelistTheme()}, there
     * is no fixed enum of valid values and no default to fall back to —
     * `null` means "no variant" (the caller omits the `variant` key
     * entirely), which is indistinguishable from an absent `?variant=` on
     * purpose: a deep link with a deleted/renamed variant file must degrade
     * to the same default-chain rendering as one with none, not 404.
     * Existence of a matching `styleguide.<variant>.twig` file is resolved
     * downstream by `Renderer`/`ComponentParser`, never here — this is a
     * syntax check only.
     */
    public static function whitelistVariant(mixed $raw): ?string
    {
        return is_string($raw) && preg_match('/^[a-z0-9-]+$/', $raw) === 1 ? $raw : null;
    }

    /**
     * Resolve the effective theme for a request, preferring an explicit
     * query-string value over the cookie fallback over the hardcoded default.
     * Both inputs are untrusted (query string / cookie jar) — either one is
     * routed through {@see self::whitelistTheme()} rather than trusted raw,
     * same as the historical query-only path.
     *
     * @param array<string,mixed> $cookies
     */
    private static function resolveTheme(mixed $queryTheme, array $cookies): string
    {
        if ($queryTheme !== null) {
            return self::whitelistTheme($queryTheme);
        }
        if (isset($cookies[self::IFRAME_THEME_COOKIE])) {
            return self::whitelistTheme($cookies[self::IFRAME_THEME_COOKIE]);
        }
        return 'light';
    }

    /**
     * Attach a `theme` key to an SPA-shell route only when the request URL
     * explicitly asked for one. Absent here on purpose: SPA-shell routes are
     * served by `dispatchSpa()`, which ignores `theme` entirely — the SPA
     * chrome owns its own theme client-side. The key only matters downstream,
     * in `synthesizeEmbeddedRoute()`, as the "query param wins over cookie"
     * signal for an in-iframe navigation that swaps this route for a `render`
     * one. Omitting the key (rather than always setting it, e.g. to 'light')
     * keeps `parse()`'s existing exact-array assertions in RouterTest valid
     * for every URL that never mentioned `?theme=`.
     *
     * @param array{type:string,slug?:string} $route
     * @return array{type:string,slug?:string,theme?:string}
     */
    private static function withExplicitThemeIfPresent(array $route, string $queryString): array
    {
        parse_str($queryString, $query);
        if (isset($query['theme'])) {
            $route['theme'] = self::whitelistTheme($query['theme']);
        }
        return $route;
    }

    /**
     * Attach a `variant` key to an SPA-shell route (`component`/`page`/
     * `doc`) only when the request URL carried a syntactically valid
     * `?variant=`. No cookie fallback and no default value — unlike theme,
     * a variant is a per-entry choice tied to one deep link, not a global
     * visitor preference — there's nothing meaningful to persist across
     * requests. Existence of the actual `styleguide.<variant>.twig`
     * sibling is checked downstream by `Renderer`, never here.
     *
     * @param array{type:string,slug?:string,theme?:string} $route
     * @return array{type:string,slug?:string,theme?:string,variant?:string}
     */
    private static function withExplicitVariantIfPresent(array $route, string $queryString): array
    {
        parse_str($queryString, $query);
        $variant = self::whitelistVariant($query['variant'] ?? null);
        if ($variant !== null) {
            $route['variant'] = $variant;
        }
        return $route;
    }

    /**
     * Swap an SPA route (`component`, `page`, `foundations`) for its `render`
     * equivalent when the request was issued from inside an iframe.
     *
     * The SPA shell (sidebar + toolbar + iframe) is meant to load only as the
     * top-level document. When a component / page in an iframe links to another
     * styleguide URL — e.g. a header nav's "Projects" link emitting
     * `/styleguide/page/projects` — the browser would otherwise load the full
     * SPA shell INSIDE the iframe, producing a confusing chrome-in-chrome
     * layout (a second sidebar and another nested iframe).
     *
     * `Sec-Fetch-Dest: iframe` is the browser's authoritative signal for any
     * sub-frame request — both the initial iframe SRC and every same-target
     * link click within it. Synthesising the matching `render` route lets the
     * existing dispatch path serve the response (raw render-cell document, no
     * chrome) while the link's href stays semantically correct as the SPA URL.
     *
     * Routes outside the SPA-shell set (`asset`, `render`, `api`, `overview`,
     * `fields`, `landing`) pass through unchanged — they have no iframe-nesting
     * problem to solve.
     *
     * Theme precedence for the synthesized route: an explicit `?theme=` on
     * the original SPA-shell URL wins (rare — nothing the SPA generates adds
     * one to these routes, but a hand-typed URL might); otherwise the
     * `sg-iframe-theme` cookie the SPA writes on toggle (see
     * `frontend/src/stores/ui.js` `setIframeTheme()`) supplies the visitor's
     * last choice; otherwise `'light'`. Without the cookie fallback, a native
     * link click inside dark-toggled iframe content — which carries no
     * `?theme=` of its own — would silently reset the rendered page to light,
     * because this synthesis path never touches the SPA's in-memory state.
     *
     * Variant precedence: query-param only, no cookie channel (see
     * {@see self::withExplicitVariantIfPresent()} for why) — the original
     * SPA-shell route only ever carries a `variant` key when the request's
     * own `?variant=` set one, so forwarding it here is a straight copy, not
     * a resolve. A native in-iframe navigation whose target link carries no
     * `?variant=` of its own therefore loses it on the swap — an accepted,
     * documented gap (unlike theme, a variant is scoped to one deep link, not
     * a visitor preference worth persisting via cookie).
     *
     * @param array{type:string,slug?:string,kind?:string,endpoint?:string,path?:string,theme?:string,variant?:string} $route
     * @param array<string,mixed> $cookies Raw `$_COOKIE` (or a test double).
     * @return array{type:string,slug?:string,kind?:string,endpoint?:string,path?:string,theme?:string,variant?:string}
     */
    public static function synthesizeEmbeddedRoute(array $route, string $secFetchDest, array $cookies = []): array
    {
        if ($secFetchDest !== 'iframe') {
            return $route;
        }
        if (!in_array($route['type'] ?? null, ['component', 'page', 'doc', 'foundations', 'icons'], true)) {
            return $route;
        }
        $synthesized = [
            'type' => 'render',
            'kind' => $route['type'],
            // Foundations carries no slug; `dispatchRender()` ignores the slug
            // for the foundations branch, but the shape contract still expects
            // a string. `'index'` mirrors the public render-endpoint convention.
            'slug' => $route['slug'] ?? 'index',
            'theme' => $route['theme'] ?? self::resolveTheme(null, $cookies),
        ];
        if (isset($route['variant'])) {
            $synthesized['variant'] = $route['variant'];
        }
        return $synthesized;
    }
}
