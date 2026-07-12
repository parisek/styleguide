<?php

declare(strict_types=1);

namespace Parisek\Styleguide;

/**
 * @internal Server-side favicon audit backing the `#favicon` foundations
 * section (#73).
 *
 * Reads the yaml `favicon:` block plus the consuming project's `static_path`
 * and reports, per entry: whether it's configured, whether the file exists,
 * real pixel dimensions (PNG via `getimagesize()`, ICO via a tiny custom
 * header parser — no `gd`/`exif` dependency beyond what's already in use),
 * and a status/note pair the template renders verbatim (escaped). Also
 * validates an optional `manifest` (web app manifest JSON) and resolves the
 * best icon for each of the three mockup contexts (browser tab, iOS home
 * screen, Android/PWA maskable).
 *
 * Every filesystem read (`is_file`/`getimagesize`/`file_get_contents`) is
 * routed through {@see self::resolvePath()}, which enforces that the
 * resolved real path stays under `$staticPath` — a yaml-configured path or
 * manifest icon `src` containing `../` cannot walk the audit outside the
 * static root.
 *
 * Pure, static, no I/O beyond reading the files it's told about — no network,
 * no image decoding beyond header bytes.
 *
 * @phpstan-type FaviconEntry array{key: string, label: string, configured: bool, path: string, exists: bool, width: int|null, height: int|null, expected: string, status: string, note: string}
 * @phpstan-type FaviconManifestIcon array{src: string, sizes: string, purpose: string, exists: bool}
 * @phpstan-type FaviconManifest array{valid: bool, icons: list<FaviconManifestIcon>, notes: list<string>}
 * @phpstan-type FaviconThemeColor array{configured: bool, valid: bool, value: string|null}
 * @phpstan-type ResolvedEntry array{configured: bool, path: string, exists: bool, absolute: string|null, status: string, note: string}
 */
final class FaviconAudit
{
    private const PNG_96_EXPECTED = 96;

    private const APPLE_TOUCH_EXPECTED = 180;

    private const PATH_ESCAPES_NOTE = 'path escapes the static root';

    private const EXTERNAL_URL_NOTE = 'external URLs are not allowed';

    /**
     * @param array<string, mixed> $favicon
     *
     * @return array{
     *   entries: list<FaviconEntry>,
     *   manifest: FaviconManifest|null,
     *   theme_color: FaviconThemeColor,
     *   tab_icon: string|null,
     *   touch_icon: string|null,
     *   maskable_icon: string|null,
     * }
     */
    public static function run(string $staticPath, array $favicon): array
    {
        $staticPath = rtrim($staticPath, '/');

        /** @var list<FaviconEntry> $entries */
        $entries = [
            self::auditSvg($staticPath, $favicon),
            self::auditPng($staticPath, $favicon, 'png_96', 'PNG 96×96', self::PNG_96_EXPECTED, self::PNG_96_EXPECTED),
            self::auditIco($staticPath, $favicon),
            self::auditPng($staticPath, $favicon, 'apple_touch', 'Apple Touch Icon', self::APPLE_TOUCH_EXPECTED, self::APPLE_TOUCH_EXPECTED),
            self::auditManifestEntry($staticPath, $favicon),
        ];

        /** @var array<string, FaviconEntry> $entriesByKey */
        $entriesByKey = [];
        foreach ($entries as $entry) {
            $entriesByKey[$entry['key']] = $entry;
        }

        $manifest = self::auditManifest($staticPath, $favicon);

        $themeColor = self::auditThemeColor($favicon);

        $tabIcon = self::firstExisting($entriesByKey, ['svg', 'png_96']);
        $touchIcon = self::firstExisting($entriesByKey, ['apple_touch']);
        $maskableIcon = self::resolveMaskableIcon($manifest);

        return [
            'entries' => $entries,
            'manifest' => $manifest,
            'theme_color' => $themeColor,
            'tab_icon' => $tabIcon,
            'touch_icon' => $touchIcon,
            'maskable_icon' => $maskableIcon,
        ];
    }

    /**
     * Resolves a yaml-configured `$key` into its containment-checked state:
     * unconfigured, escaping the static root, missing on disk, or resolved
     * to a real, contained absolute path. Shared prelude for every entry
     * auditor below — each adds its own format-specific checks on top of
     * `status === 'ok'`.
     *
     * @param array<string, mixed> $favicon
     *
     * @return ResolvedEntry
     */
    private static function resolveEntry(string $staticPath, array $favicon, string $key): array
    {
        $path = self::stringConfig($favicon, $key);
        if ($path === null) {
            return ['configured' => false, 'path' => '', 'exists' => false, 'absolute' => null, 'status' => 'unconfigured', 'note' => ''];
        }

        if (self::isExternalUrl($path)) {
            return ['configured' => true, 'path' => $path, 'exists' => false, 'absolute' => null, 'status' => 'error', 'note' => self::EXTERNAL_URL_NOTE];
        }

        if (self::pathEscapesRoot($staticPath, $path)) {
            return ['configured' => true, 'path' => $path, 'exists' => false, 'absolute' => null, 'status' => 'error', 'note' => self::PATH_ESCAPES_NOTE];
        }

        $absolute = self::resolvePath($staticPath, $path);
        if ($absolute === null) {
            return ['configured' => true, 'path' => $path, 'exists' => false, 'absolute' => null, 'status' => 'missing', 'note' => ''];
        }

        return ['configured' => true, 'path' => $path, 'exists' => true, 'absolute' => $absolute, 'status' => 'ok', 'note' => ''];
    }

    /**
     * Resolves `$staticPath . $path` to its canonical real path, requiring
     * the result to stay under `$staticPath`. Returns null when the target
     * doesn't exist, isn't readable, or resolves outside the static root —
     * callers that need to distinguish "missing" from "escapes" call
     * {@see self::pathEscapesRoot()} first.
     */
    private static function resolvePath(string $staticPath, string $path): ?string
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
     * True for protocol-relative (`//host/...`) or absolute-scheme
     * (`https://...`, `data:...`) URLs — anything that would make the
     * audit (or the template that renders `tab_icon`/`touch_icon`/
     * `maskable_icon` into an `<img src>`) fetch or reference a resource
     * outside `$staticPath`.
     */
    private static function isExternalUrl(string $path): bool
    {
        return str_starts_with($path, '//') || preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1;
    }

    /**
     * True when `$staticPath . $path`, once resolved, lands outside
     * `$staticPath` — checked independent of the target's existence: when
     * the target itself doesn't exist yet, containment is resolved against
     * the nearest existing ancestor directory instead (mirrors how
     * `realpath()` needs an existing node to resolve `..` segments).
     */
    private static function pathEscapesRoot(string $staticPath, string $path): bool
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

    /**
     * @param array<string, mixed> $favicon
     *
     * @return FaviconEntry
     */
    private static function auditSvg(string $staticPath, array $favicon): array
    {
        $resolved = self::resolveEntry($staticPath, $favicon, 'svg');
        $status = $resolved['status'];
        $note = $resolved['note'];

        if ($status === 'ok') {
            $contents = (string) file_get_contents((string) $resolved['absolute']);
            if (stripos($contents, '<svg') === false) {
                $status = 'error';
                $note = 'file does not contain an <svg> root element';
            }
        }

        return [
            'key' => 'svg',
            'label' => 'SVG',
            'configured' => $resolved['configured'],
            'path' => $resolved['path'],
            'exists' => $resolved['exists'],
            'width' => null,
            'height' => null,
            'expected' => '',
            'status' => $status,
            'note' => $note,
        ];
    }

    /**
     * @param array<string, mixed> $favicon
     *
     * @return FaviconEntry
     */
    private static function auditPng(
        string $staticPath,
        array $favicon,
        string $key,
        string $label,
        int $expectedWidth,
        int $expectedHeight,
    ): array {
        $resolved = self::resolveEntry($staticPath, $favicon, $key);
        $expected = "{$expectedWidth}×{$expectedHeight}";
        $status = $resolved['status'];
        $note = $resolved['note'];
        $width = null;
        $height = null;

        if ($status === 'ok') {
            $size = @getimagesize((string) $resolved['absolute']);
            if ($size === false) {
                $status = 'error';
                $note = 'file is not a readable image';
            } else {
                $width = (int) $size[0];
                $height = (int) $size[1];
                if ($width === $expectedWidth && $height === $expectedHeight) {
                    $status = 'ok';
                } else {
                    $status = 'warning';
                    $note = "expected {$expected}, got {$width}×{$height}";
                }
            }
        }

        return [
            'key' => $key,
            'label' => $label,
            'configured' => $resolved['configured'],
            'path' => $resolved['path'],
            'exists' => $resolved['exists'],
            'width' => $width,
            'height' => $height,
            'expected' => $expected,
            'status' => $status,
            'note' => $note,
        ];
    }

    /**
     * @param array<string, mixed> $favicon
     *
     * @return FaviconEntry
     */
    private static function auditIco(string $staticPath, array $favicon): array
    {
        $resolved = self::resolveEntry($staticPath, $favicon, 'ico');
        $status = $resolved['status'];
        $note = $resolved['note'];

        if ($status === 'ok') {
            $sizes = self::parseIcoSizes((string) file_get_contents((string) $resolved['absolute']));
            if ($sizes === null || $sizes === []) {
                $status = 'error';
                $note = 'file is not a parseable ICO';
            } else {
                $note = implode(', ', $sizes);
            }
        }

        return [
            'key' => 'ico',
            'label' => 'ICO',
            'configured' => $resolved['configured'],
            'path' => $resolved['path'],
            'exists' => $resolved['exists'],
            'width' => null,
            'height' => null,
            'expected' => '',
            'status' => $status,
            'note' => $note,
        ];
    }

    /**
     * Parses an ICO's directory: bytes 0-1 reserved=0, 2-3 type=1, 4-5
     * count; per 16-byte entry byte0=width (0→256), byte1=height (0→256),
     * bytes8-11=dwBytesInRes, bytes12-15=dwImageOffset (both DWORD,
     * little-endian). Returns a list of "W×H" strings, or null when the
     * header doesn't match a valid ICO container (garbage bytes, wrong
     * signature, a header/directory truncated shorter than it claims, or
     * any entry's `dwImageOffset + dwBytesInRes` pointing past the end of
     * `$data` — a truncated/malformed image payload).
     *
     * @return list<string>|null
     */
    private static function parseIcoSizes(string $data): ?array
    {
        if (strlen($data) < 6) {
            return null;
        }

        $header = unpack('vreserved/vtype/vcount', substr($data, 0, 6));
        if ($header === false || $header['reserved'] !== 0 || $header['type'] !== 1 || $header['count'] < 1) {
            return null;
        }

        $count = $header['count'];
        $sizes = [];
        $dataLength = strlen($data);

        for ($i = 0; $i < $count; $i++) {
            $offset = 6 + $i * 16;
            if ($dataLength < $offset + 16) {
                return null;
            }
            $entry = substr($data, $offset, 16);

            $imageData = unpack('VbytesInRes/VimageOffset', substr($entry, 8, 8));
            if ($imageData === false || $imageData['imageOffset'] + $imageData['bytesInRes'] > $dataLength) {
                return null;
            }

            $width = ord($entry[0]);
            $height = ord($entry[1]);
            $width = $width === 0 ? 256 : $width;
            $height = $height === 0 ? 256 : $height;
            $sizes[] = "{$width}×{$height}";
        }

        return $sizes;
    }

    /**
     * @param array<string, mixed> $favicon
     *
     * @return FaviconEntry
     */
    private static function auditManifestEntry(string $staticPath, array $favicon): array
    {
        $resolved = self::resolveEntry($staticPath, $favicon, 'manifest');
        $status = $resolved['status'];
        $note = $resolved['note'];

        if ($status === 'ok') {
            $decoded = json_decode((string) file_get_contents((string) $resolved['absolute']), true);
            if (!is_array($decoded)) {
                $status = 'error';
                $note = 'file is not valid JSON';
            }
        }

        return [
            'key' => 'manifest',
            'label' => 'Web App Manifest',
            'configured' => $resolved['configured'],
            'path' => $resolved['path'],
            'exists' => $resolved['exists'],
            'width' => null,
            'height' => null,
            'expected' => '',
            'status' => $status,
            'note' => $note,
        ];
    }

    /**
     * @param array<string, mixed> $favicon
     *
     * @return FaviconManifest|null
     */
    private static function auditManifest(string $staticPath, array $favicon): ?array
    {
        $path = self::stringConfig($favicon, 'manifest');
        if ($path === null || self::pathEscapesRoot($staticPath, $path)) {
            return null;
        }

        $absolute = self::resolvePath($staticPath, $path);
        if ($absolute === null) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($absolute), true);
        if (!is_array($decoded)) {
            return [
                'valid' => false,
                'icons' => [],
                'notes' => [],
            ];
        }

        $manifestDir = dirname($path);
        $rawIcons = $decoded['icons'] ?? [];
        $icons = [];
        $sizesSeen = [];
        $hasMaskable = false;
        $notes = [];

        if (is_array($rawIcons)) {
            foreach ($rawIcons as $rawIcon) {
                if (!is_array($rawIcon)) {
                    continue;
                }
                $src = (string) ($rawIcon['src'] ?? '');
                $sizes = (string) ($rawIcon['sizes'] ?? '');
                $purpose = (string) ($rawIcon['purpose'] ?? '');

                if (self::isExternalUrl($src)) {
                    // Never normalize/resolve an external URL against the
                    // static root — output it verbatim (it's never
                    // selected as an icon since exists stays false; the
                    // template only ever prints it escaped).
                    $iconExists = false;
                    $iconOutputSrc = $src;
                    $notes[] = "icon '{$src}': " . self::EXTERNAL_URL_NOTE;
                } else {
                    // Output src is the web path normalized against the
                    // manifest's own directory — callers (the styleguide
                    // template) render this verbatim into an `<img src>`
                    // for the maskable-icon mockup, so a manifest-relative
                    // src like `icon-192.png` must not leak through
                    // unresolved.
                    $iconOutputSrc = str_starts_with($src, '/')
                        ? $src
                        : rtrim($manifestDir, '/') . '/' . $src;

                    if (self::pathEscapesRoot($staticPath, $iconOutputSrc)) {
                        $iconExists = false;
                        $notes[] = "icon '{$src}' escapes the static root";
                    } else {
                        $iconExists = self::resolvePath($staticPath, $iconOutputSrc) !== null;
                    }
                }

                $icons[] = [
                    'src' => $iconOutputSrc,
                    'sizes' => $sizes,
                    'purpose' => $purpose,
                    'exists' => $iconExists,
                ];

                $sizesSeen[] = $sizes;
                if (stripos($purpose, 'maskable') !== false) {
                    $hasMaskable = true;
                }
            }
        }

        if (!self::hasSize($sizesSeen, 192)) {
            $notes[] = 'no 192×192 icon';
        }
        if (!self::hasSize($sizesSeen, 512)) {
            $notes[] = 'no 512×512 icon';
        }
        if (!$hasMaskable) {
            $notes[] = 'no maskable purpose icon';
        }

        return [
            'valid' => true,
            'icons' => $icons,
            'notes' => $notes,
        ];
    }

    /**
     * True when any whitespace-separated token in `$sizesSeen` equals
     * `{$dimension}x{$dimension}` (case-insensitive — spec allows both
     * `x` and `X` as the separator letter), e.g. `"96x96 192x192"`
     * satisfies dimension 192 even though it isn't the first token.
     *
     * @param list<string> $sizesSeen
     */
    private static function hasSize(array $sizesSeen, int $dimension): bool
    {
        $needle = "{$dimension}x{$dimension}";

        foreach ($sizesSeen as $sizes) {
            $tokens = preg_split('/\s+/', trim($sizes));
            if ($tokens === false) {
                continue;
            }
            foreach ($tokens as $token) {
                if ($token !== '' && strcasecmp($token, $needle) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $favicon
     *
     * @return FaviconThemeColor
     */
    private static function auditThemeColor(array $favicon): array
    {
        $themeColor = self::stringConfig($favicon, 'theme_color');
        if ($themeColor === null) {
            return ['configured' => false, 'valid' => false, 'value' => null];
        }

        $rgb = ColorUtil::parseHex($themeColor);
        if ($rgb === null) {
            return ['configured' => true, 'valid' => false, 'value' => null];
        }

        $normalized = sprintf('#%02X%02X%02X', $rgb[0], $rgb[1], $rgb[2]);

        return ['configured' => true, 'valid' => true, 'value' => $normalized];
    }

    /**
     * @param array<string, FaviconEntry> $entriesByKey
     * @param list<string>                $keysInPreferenceOrder
     */
    private static function firstExisting(array $entriesByKey, array $keysInPreferenceOrder): ?string
    {
        foreach ($keysInPreferenceOrder as $key) {
            $entry = $entriesByKey[$key] ?? null;
            if ($entry !== null && $entry['exists']) {
                return $entry['path'];
            }
        }

        return null;
    }

    /**
     * Deterministic preference among existing manifest icons: an icon that
     * is both maskable and declares 192×192 wins outright; failing that,
     * the first existing maskable icon; failing that, the first existing
     * icon of any purpose; failing that, null.
     *
     * @param FaviconManifest|null $manifest
     */
    private static function resolveMaskableIcon(?array $manifest): ?string
    {
        if ($manifest === null) {
            return null;
        }

        foreach ($manifest['icons'] as $icon) {
            if ($icon['exists'] && stripos($icon['purpose'], 'maskable') !== false && self::hasSize([$icon['sizes']], 192)) {
                return $icon['src'];
            }
        }

        foreach ($manifest['icons'] as $icon) {
            if ($icon['exists'] && stripos($icon['purpose'], 'maskable') !== false) {
                return $icon['src'];
            }
        }

        foreach ($manifest['icons'] as $icon) {
            if ($icon['exists']) {
                return $icon['src'];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $favicon */
    private static function stringConfig(array $favicon, string $key): ?string
    {
        $value = $favicon[$key] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
