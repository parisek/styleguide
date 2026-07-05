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
     * Parse a request URI to a route descriptor, or null if the URI doesn't belong
     * to the styleguide.
     *
     * @return array{type:string,slug?:string,kind?:string,endpoint?:string,path?:string,theme?:string}|null
     */
    public static function parse(string $uri): ?array
    {
        // Captured before strtok() below discards it — only the `render`
        // branch consumes it (theme only matters for iframe HTML output).
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
            return [
                'type' => 'render',
                'kind' => $parts[1],
                'slug' => $parts[2],
                'theme' => self::whitelistTheme($query['theme'] ?? null),
            ];
        }

        // /styleguide/api/<endpoint>
        if ($parts[0] === 'api' && isset($parts[1])) {
            return ['type' => 'api', 'endpoint' => $parts[1]];
        }

        // /styleguide/component/<slug>, /styleguide/page/<slug>, /styleguide/doc/<slug>
        if (in_array($parts[0], ['component', 'page', 'doc'], true) && isset($parts[1])) {
            return ['type' => $parts[0], 'slug' => $parts[1]];
        }

        // /styleguide/overview, /styleguide/foundations, /styleguide/fields
        if (in_array($parts[0], ['overview', 'foundations', 'fields'], true)) {
            return ['type' => $parts[0]];
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
     * @param array{type:string,slug?:string,kind?:string,endpoint?:string,path?:string,theme?:string} $route
     * @return array{type:string,slug?:string,kind?:string,endpoint?:string,path?:string,theme?:string}
     */
    public static function synthesizeEmbeddedRoute(array $route, string $secFetchDest): array
    {
        if ($secFetchDest !== 'iframe') {
            return $route;
        }
        if (!in_array($route['type'] ?? null, ['component', 'page', 'doc', 'foundations'], true)) {
            return $route;
        }
        return [
            'type' => 'render',
            'kind' => $route['type'],
            // Foundations carries no slug; `dispatchRender()` ignores the slug
            // for the foundations branch, but the shape contract still expects
            // a string. `'index'` mirrors the public render-endpoint convention.
            'slug' => $route['slug'] ?? 'index',
            // No query-string signal survives past Router::parse() by this point
            // (the SPA-shell route it's swapping from never carried a `theme` —
            // only `render`-type routes read the query string). Default to
            // 'light', matching Renderer's own default for an absent theme.
            'theme' => 'light',
        ];
    }
}
