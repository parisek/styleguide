<?php

declare(strict_types=1);

namespace Parisek\Styleguide;

/**
 * @internal Shared filesystem-path hardening for yaml-configured asset
 * paths (favicon entries, manifest icon `src`, the OG image path, …).
 *
 * Promoted out of {@see FaviconAudit} (#73) when {@see OgImageAudit} (#74)
 * needed the exact same containment/scheme-rejection behaviour — rather
 * than duplicate ~60 lines of security-sensitive logic across two classes,
 * both now share this one hardened implementation. Every filesystem read
 * in either audit is routed through {@see self::resolvePath()}, which
 * enforces that the resolved real path stays under the project's
 * `static_path` — a yaml-configured path containing `../` cannot walk the
 * audit outside the static root.
 *
 * Pure, static, no I/O beyond the `realpath()`/`is_file()`/`is_readable()`
 * calls needed to answer the containment question.
 */
final class PathGuard
{
    /**
     * Resolves `$staticPath . $path` to its canonical real path, requiring
     * the result to stay under `$staticPath`. Returns null when the target
     * doesn't exist, isn't readable, or resolves outside the static root —
     * callers that need to distinguish "missing" from "escapes" call
     * {@see self::pathEscapesRoot()} first.
     */
    public static function resolvePath(string $staticPath, string $path): ?string
    {
        $realStatic = realpath($staticPath);
        if ($realStatic === false) {
            return null;
        }

        $real = realpath(self::joinPath($staticPath, $path));
        if ($real === false || !self::isContained($real, $realStatic) || !is_file($real) || !is_readable($real)) {
            return null;
        }

        return $real;
    }

    /**
     * True for protocol-relative (`//host/...`), absolute-scheme
     * (`https://...`), or bare-scheme (`data:...`, `javascript:...`) URIs —
     * anything carrying a scheme prefix, with or without the `//` authority
     * marker. Root-relative (`/x`) and relative (`x/y`, `./x`, `../x`)
     * paths carry no scheme and stay accepted. Without the bare-scheme
     * half of this check, a `data:` URI would fall through as a
     * filesystem path — misreported as merely "missing" (yaml key) or
     * dir-joined into a mangled string (manifest `src`) instead of
     * rejected outright — anything that would make the audit (or the
     * template that renders the resolved path into an `<img src>`) fetch
     * or reference a resource outside `$staticPath`.
     */
    public static function isExternalUrl(string $path): bool
    {
        return preg_match('#^(?://|[a-z][a-z0-9+.-]*:)#i', $path) === 1;
    }

    /**
     * True when `$staticPath . $path`, once resolved, lands outside
     * `$staticPath` — checked independent of the target's existence: when
     * the target itself doesn't exist yet, containment is resolved against
     * the nearest existing ancestor directory instead (mirrors how
     * `realpath()` needs an existing node to resolve `..` segments).
     */
    public static function pathEscapesRoot(string $staticPath, string $path): bool
    {
        $realStatic = realpath($staticPath);
        if ($realStatic === false) {
            return false;
        }

        $candidate = self::joinPath($staticPath, $path);
        $real = realpath($candidate);
        if ($real === false) {
            $real = realpath(dirname($candidate));
            if ($real === false) {
                return false;
            }
        }

        return !self::isContained($real, $realStatic);
    }

    /**
     * Joins `$staticPath` and `$path` with exactly one `/` separator,
     * regardless of whether either side already carries one. Both
     * {@see self::resolvePath()} and {@see self::pathEscapesRoot()} used to
     * concatenate the two strings verbatim (`$staticPath . $path`) — a
     * yaml-configured value with no leading slash (`og_image:
     * images/og-image.png`, the natural way to write it) silently glued
     * onto the static path with zero separators (`.../staticimages/...`),
     * so the file was misreported as missing instead of resolved.
     */
    private static function joinPath(string $staticPath, string $path): string
    {
        return rtrim($staticPath, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Normalizes a yaml-configured path into a root-relative web path
     * (leading `/`, regardless of whether the yaml value carried one) —
     * the only shape that's safe to render verbatim into an `<img src>` /
     * `<link href>`. Callers apply this only to paths already proven
     * internal (not {@see self::isExternalUrl()}, not {@see
     * self::pathEscapesRoot()}) — normalizing an external URL or a
     * scheme-prefixed value would corrupt it.
     */
    public static function toWebPath(string $path): string
    {
        return '/' . ltrim($path, '/');
    }

    /**
     * Strips the consumer's asset base (`twig_context.templateUrl` — e.g.
     * `/wp-content/themes/acme/static`) off the front of a path, turning a
     * browser URL back into the static-root-relative path the filesystem
     * side of an audit needs.
     *
     * The inverse of `Renderer::resolveAssetUrl()`, and the reason both
     * directions have to exist: consumer-authored asset values arrive in
     * two shapes that a pure filesystem auditor can't tell apart. The
     * short `/images/touch/favicon.svg` is docroot-agnostic and joins onto
     * `static_path` directly; the full `/wp-content/themes/acme/static/
     * images/touch/favicon.svg` is a real browser URL that would join into
     * `<static_path>/wp-content/themes/acme/static/images/...` — a path
     * that exists nowhere, reported as `missing` for a file that is right
     * there on disk.
     *
     * The full shape is not an authoring mistake to be linted away: a
     * `site.webmanifest` is fetched and parsed by the browser, so its icon
     * `src` values MUST be real URLs. Under WordPress / Drupal that made
     * every manifest icon audit as missing.
     *
     * No-op when the base is empty (standalone consumer), when the path
     * doesn't start with the base, or when the path carries a scheme —
     * so a short path, a relative path, and an external URL all pass
     * through untouched.
     */
    public static function stripAssetBase(string $path, string $base): string
    {
        $base = rtrim($base, '/');
        if ($base === '' || $path === '' || self::isExternalUrl($path)) {
            return $path;
        }

        if ($path === $base) {
            return '/';
        }

        return str_starts_with($path, $base . '/')
            ? substr($path, strlen($base))
            : $path;
    }

    private static function isContained(string $real, string $realStatic): bool
    {
        return $real === $realStatic || str_starts_with($real, $realStatic . DIRECTORY_SEPARATOR);
    }
}
