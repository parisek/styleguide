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
 * Pure, static, no I/O beyond reading the files it's told about — no network,
 * no image decoding beyond header bytes.
 *
 * @phpstan-type FaviconEntry array{key: string, label: string, configured: bool, path: string, exists: bool, width: int|null, height: int|null, expected: string, status: string, note: string}
 * @phpstan-type FaviconManifest array{valid: bool, icons: list<array{src: string, sizes: string, purpose: string, exists: bool}>, notes: list<string>}
 */
final class FaviconAudit
{
    private const PNG_96_EXPECTED = 96;

    private const APPLE_TOUCH_EXPECTED = 180;

    /**
     * @param array<string, mixed> $favicon
     *
     * @return array{
     *   entries: list<FaviconEntry>,
     *   manifest: FaviconManifest|null,
     *   theme_color: string|null,
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
     * @param array<string, mixed> $favicon
     *
     * @return FaviconEntry
     */
    private static function auditSvg(string $staticPath, array $favicon): array
    {
        $path = self::stringConfig($favicon, 'svg');
        $configured = $path !== null;
        $absolute = $configured ? $staticPath . $path : null;
        $exists = $configured && $absolute !== null && is_file($absolute);

        $status = 'unconfigured';
        $note = '';

        if ($configured && !$exists) {
            $status = 'missing';
        } elseif ($configured && $exists) {
            $contents = (string) file_get_contents($absolute);
            if (stripos($contents, '<svg') === false) {
                $status = 'error';
                $note = 'file does not contain an <svg> root element';
            } else {
                $status = 'ok';
            }
        }

        return [
            'key' => 'svg',
            'label' => 'SVG',
            'configured' => $configured,
            'path' => $path ?? '',
            'exists' => $exists,
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
        $path = self::stringConfig($favicon, $key);
        $configured = $path !== null;
        $absolute = $configured ? $staticPath . $path : null;
        $exists = $configured && $absolute !== null && is_file($absolute);
        $expected = "{$expectedWidth}×{$expectedHeight}";

        $width = null;
        $height = null;
        $status = 'unconfigured';
        $note = '';

        if ($configured && !$exists) {
            $status = 'missing';
        } elseif ($configured && $exists) {
            $size = @getimagesize($absolute);
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
            'configured' => $configured,
            'path' => $path ?? '',
            'exists' => $exists,
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
        $path = self::stringConfig($favicon, 'ico');
        $configured = $path !== null;
        $absolute = $configured ? $staticPath . $path : null;
        $exists = $configured && $absolute !== null && is_file($absolute);

        $status = 'unconfigured';
        $note = '';

        if ($configured && !$exists) {
            $status = 'missing';
        } elseif ($configured && $exists) {
            $sizes = self::parseIcoSizes((string) file_get_contents($absolute));
            if ($sizes === null || $sizes === []) {
                $status = 'error';
                $note = 'file is not a parseable ICO';
            } else {
                $status = 'ok';
                $note = implode(', ', $sizes);
            }
        }

        return [
            'key' => 'ico',
            'label' => 'ICO',
            'configured' => $configured,
            'path' => $path ?? '',
            'exists' => $exists,
            'width' => null,
            'height' => null,
            'expected' => '',
            'status' => $status,
            'note' => $note,
        ];
    }

    /**
     * Parses an ICO's directory: bytes 0-1 reserved=0, 2-3 type=1, 4-5
     * count; per 16-byte entry byte0=width (0→256), byte1=height (0→256).
     * Returns a list of "W×H" strings, or null when the header doesn't
     * match a valid ICO container.
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

        for ($i = 0; $i < $count; $i++) {
            $offset = 6 + $i * 16;
            if (strlen($data) < $offset + 16) {
                return null;
            }
            $entry = substr($data, $offset, 16);
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
        $path = self::stringConfig($favicon, 'manifest');
        $configured = $path !== null;
        $absolute = $configured ? $staticPath . $path : null;
        $exists = $configured && $absolute !== null && is_file($absolute);

        $status = 'unconfigured';
        $note = '';

        if ($configured && !$exists) {
            $status = 'missing';
        } elseif ($configured && $exists) {
            $decoded = json_decode((string) file_get_contents($absolute), true);
            if (!is_array($decoded)) {
                $status = 'error';
                $note = 'file is not valid JSON';
            } else {
                $status = 'ok';
            }
        }

        return [
            'key' => 'manifest',
            'label' => 'Web App Manifest',
            'configured' => $configured,
            'path' => $path ?? '',
            'exists' => $exists,
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
        if ($path === null) {
            return null;
        }

        $absolute = $staticPath . $path;
        if (!is_file($absolute)) {
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

        if (is_array($rawIcons)) {
            foreach ($rawIcons as $rawIcon) {
                if (!is_array($rawIcon)) {
                    continue;
                }
                $src = (string) ($rawIcon['src'] ?? '');
                $sizes = (string) ($rawIcon['sizes'] ?? '');
                $purpose = (string) ($rawIcon['purpose'] ?? '');

                $iconAbsolute = str_starts_with($src, '/')
                    ? $staticPath . $src
                    : $staticPath . rtrim($manifestDir, '/') . '/' . $src;
                $iconExists = is_file($iconAbsolute);

                $icons[] = [
                    'src' => $src,
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

        $notes = [];
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

    /** @param list<string> $sizesSeen */
    private static function hasSize(array $sizesSeen, int $dimension): bool
    {
        foreach ($sizesSeen as $sizes) {
            if (str_starts_with($sizes, "{$dimension}x{$dimension}")) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $favicon */
    private static function auditThemeColor(array $favicon): ?string
    {
        $themeColor = self::stringConfig($favicon, 'theme_color');
        if ($themeColor === null) {
            return null;
        }

        return ColorUtil::parseHex($themeColor) === null ? null : $themeColor;
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
     * @param array{icons: list<array{src: string, purpose: string, exists: bool}>}|null $manifest
     */
    private static function resolveMaskableIcon(?array $manifest): ?string
    {
        if ($manifest === null) {
            return null;
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
