<?php

declare(strict_types=1);

namespace Parisek\Styleguide;

/**
 * Parses /styleguide/* request URIs into structured route descriptors.
 *
 * All share-able URLs (`/styleguide/component/<slug>`, `/page/<slug>`, `/overview`, `/fields`)
 * map to the SPA — server returns the same `dist/index.html` for each, and the SPA
 * router (`frontend/router.js`) reads `location.pathname` and renders the right view.
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
     * @return array{type:string,slug?:string,kind?:string,endpoint?:string,path?:string}|null
     */
    public static function parse(string $uri): ?array
    {
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
            return [
                'type' => 'render',
                'kind' => $parts[1],
                'slug' => $parts[2],
            ];
        }

        // /styleguide/api/<endpoint>
        if ($parts[0] === 'api' && isset($parts[1])) {
            return ['type' => 'api', 'endpoint' => $parts[1]];
        }

        // /styleguide/component/<slug>
        // /styleguide/page/<slug>
        if (in_array($parts[0], ['component', 'page'], true) && isset($parts[1])) {
            return ['type' => $parts[0], 'slug' => $parts[1]];
        }

        // /styleguide/overview, /styleguide/foundations, /styleguide/fields
        if (in_array($parts[0], ['overview', 'foundations', 'fields'], true)) {
            return ['type' => $parts[0]];
        }

        // Unknown path under /styleguide/ — default to landing (SPA handles it)
        return ['type' => 'landing'];
    }
}
