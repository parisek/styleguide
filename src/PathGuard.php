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

        $real = realpath($staticPath . $path);
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

        $candidate = $staticPath . $path;
        $real = realpath($candidate);
        if ($real === false) {
            $real = realpath(dirname($candidate));
            if ($real === false) {
                return false;
            }
        }

        return !self::isContained($real, $realStatic);
    }

    private static function isContained(string $real, string $realStatic): bool
    {
        return $real === $realStatic || str_starts_with($real, $realStatic . DIRECTORY_SEPARATOR);
    }
}
