<?php

declare(strict_types=1);

namespace Parisek\Styleguide;

/**
 * @internal Implementation detail of `Styleguide::run()`. Signature can change
 *           in any minor release. The asset URL surface
 *           (`/styleguide/assets/<path>`) is part of the public contract — see
 *           `docs/API.md` § URL surface.
 *
 * Serves static assets from the package's dist/ directory.
 *
 * - Path-traversal guard via realpath() + str_starts_with()
 * - Detects hashed filenames (styleguide.abc12345.js) and applies immutable cache
 * - ETag support for conditional requests
 */
final class AssetServer
{
    private string $distRoot;

    public function __construct(string $distRoot)
    {
        $resolved = realpath($distRoot);
        if ($resolved === false) {
            throw new \RuntimeException("AssetServer: distRoot does not exist: {$distRoot}");
        }
        $this->distRoot = $resolved;
    }

    public function serve(string $path): void
    {
        $file = realpath($this->distRoot . '/' . ltrim($path, '/'));

        if ($file === false || !str_starts_with($file, $this->distRoot) || !is_file($file)) {
            http_response_code(404);
            return;
        }

        $etag = '"' . md5_file($file) . '"';
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            return;
        }

        $cacheControl = $this->isHashedFilename(basename($file))
            ? 'public, max-age=31536000, immutable'
            : 'public, max-age=3600';

        header('Content-Type: ' . $this->mimeType($file));
        header('ETag: ' . $etag);
        header('Cache-Control: ' . $cacheControl);
        readfile($file);
    }

    /**
     * True when filename matches `<name>.<8-or-more-base64url>.<ext>` pattern produced by Vite.
     *
     * Vite's default hash alphabet is base64url-ish (A–Z, a–z, 0–9, plus `_` and `-`),
     * not pure hex — e.g. `styleguide.CWEjyLdQ.css` is a real Vite hash. The regex
     * therefore accepts the full base64url character set, not just `[a-f0-9]`.
     */
    public function isHashedFilename(string $basename): bool
    {
        return (bool) preg_match('/\.[A-Za-z0-9_-]{8,}\.[a-z0-9]+$/', $basename);
    }

    private function mimeType(string $file): string
    {
        return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'css' => 'text/css; charset=utf-8',
            'js', 'mjs' => 'application/javascript; charset=utf-8',
            'json', 'map' => 'application/json; charset=utf-8',
            'svg' => 'image/svg+xml',
            'html' => 'text/html; charset=utf-8',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            default => mime_content_type($file) ?: 'application/octet-stream',
        };
    }
}
