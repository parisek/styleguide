<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Http;

/**
 * @internal Not SemVer-covered while the request/result seam is being built
 *           out. It becomes public with the Symfony bundle.
 *
 * The request values a styleguide route actually depends on.
 *
 * Four, not one. The tracking issue proposed "a method that takes a path",
 * which is too narrow and would have broken the iframe silently:
 *
 * - **uri** selects the route.
 * - **cookies** carry `sg-iframe-theme`, the theme fallback for in-iframe
 *   navigations whose href never had a `?theme=`.
 * - **secFetchDest** turns an SPA-shell route into a render route when the
 *   request came from inside the preview iframe.
 * - **ifNoneMatch** decides an asset's `304`.
 * - **basePath** is where the host application itself is served, e.g.
 *   `/subdir` or `/index.php`. `uri` is relative to it and routing ignores
 *   it; every URL the catalogue produces starts with it. Empty for a front
 *   controller at the domain root, and in library mode, where `base_url`
 *   is already the full public path.
 *
 * `fromGlobals()` is the only place the package reads superglobals for a
 * request. Everything downstream takes this object, which is what lets a
 * controller supply the same four values from a framework request and lets a
 * test supply them from nothing at all.
 */
final class Request
{
    /**
     * @param array<string, mixed> $cookies
     */
    public function __construct(
        public readonly string $uri,
        public readonly array $cookies = [],
        public readonly string $secFetchDest = '',
        public readonly string $ifNoneMatch = '',
        public readonly string $basePath = '',
    ) {}

    public static function fromGlobals(): self
    {
        return new self(
            (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            $_COOKIE,
            (string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''),
            (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''),
        );
    }
}
