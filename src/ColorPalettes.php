<?php

declare(strict_types=1);

namespace Parisek\Styleguide;

/**
 * @internal Normalizes the `colors:` block of styleguide.yaml for the
 *           overview screen (foundations.twig).
 *
 * Two accepted input shapes per palette:
 *
 *   Legacy (Tailwind scale) — `shades:` map keyed by shade number, palette
 *   `css_variable` used as the `--color-<var>-<shade>` prefix:
 *
 *     primary:
 *       name: Primary
 *       css_variable: primary
 *       default: 500
 *       shades:
 *         500: { hex: "#FE4942", oklch: "oklch(66.78% 0.219 27.17)" }
 *
 *   Named swatches (v1.3) — ordered `swatches:` list, keys are free-form
 *   names, optional per-swatch `css_variable` (full name, no `--color-`):
 *
 *     brand:
 *       name: Brand
 *       swatches:
 *         - { name: red, hex: "#E63946", css_variable: brand-red }
 *         - { name: cream, hex: "#F1FAEE" }
 *
 * Both normalize to one canonical list shape (see normalize() return doc).
 * Swatches without a parseable hex are skipped — resilience over failure,
 * consistent with ComponentParser's handling of broken metadata.
 */
final class ColorPalettes
{
    /**
     * @return list<array{
     *     key: string,
     *     name: string,
     *     default: string,
     *     swatches: list<array{key: string, hex: string, oklch: string, label: string, bg: string, light: bool}>
     * }>
     */
    public static function normalize(mixed $colors): array
    {
        if (!is_array($colors)) {
            return [];
        }

        $palettes = [];
        foreach ($colors as $key => $palette) {
            if (!is_array($palette)) {
                continue;
            }
            $key = (string) $key;
            $swatches = self::normalizeSwatches($key, $palette);
            if ($swatches === []) {
                continue;
            }

            $swatchKeys = array_column($swatches, 'key');
            $default = (string) ($palette['default'] ?? '');
            if (!in_array($default, $swatchKeys, true)) {
                $default = $swatchKeys[0];
            }

            $palettes[] = [
                'key'      => $key,
                'name'     => (string) ($palette['name'] ?? ucfirst($key)),
                'default'  => $default,
                'swatches' => $swatches,
            ];
        }

        return $palettes;
    }

    /**
     * @param array<mixed> $palette
     *
     * @return list<array{key: string, hex: string, oklch: string, label: string, bg: string, light: bool}>
     */
    private static function normalizeSwatches(string $paletteKey, array $palette): array
    {
        $swatches = [];

        if (isset($palette['swatches']) && is_array($palette['swatches'])) {
            foreach ($palette['swatches'] as $color) {
                if (!is_array($color)) {
                    continue;
                }
                $name = (string) ($color['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $swatch = self::buildSwatch(
                    key: $name,
                    hex: (string) ($color['hex'] ?? ''),
                    oklch: (string) ($color['oklch'] ?? ''),
                    cssVariable: (string) ($color['css_variable'] ?? ''),
                );
                if ($swatch !== null) {
                    $swatches[] = $swatch;
                }
            }

            return $swatches;
        }

        if (isset($palette['shades']) && is_array($palette['shades'])) {
            $prefix = (string) ($palette['css_variable'] ?? $paletteKey);
            foreach ($palette['shades'] as $shade => $color) {
                if (!is_array($color)) {
                    continue;
                }
                $swatch = self::buildSwatch(
                    key: (string) $shade,
                    hex: (string) ($color['hex'] ?? ''),
                    oklch: (string) ($color['oklch'] ?? ''),
                    cssVariable: $prefix . '-' . $shade,
                );
                if ($swatch !== null) {
                    $swatches[] = $swatch;
                }
            }
        }

        return $swatches;
    }

    /**
     * @return array{key: string, hex: string, oklch: string, label: string, bg: string, light: bool}|null
     */
    private static function buildSwatch(string $key, string $hex, string $oklch, string $cssVariable): ?array
    {
        $rgb = ColorUtil::parseHex($hex);
        if ($rgb === null) {
            return null;
        }
        $hex = '#' . strtoupper(ltrim(trim($hex), '#'));
        if (strlen($hex) === 4) { // #RGB → #RRGGBB so copy-to-clipboard is canonical
            $hex = '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
        }

        return [
            'key'   => $key,
            'hex'   => $hex,
            'oklch' => $oklch,
            'label' => $cssVariable !== '' ? $cssVariable : $key,
            'bg'    => $cssVariable !== '' ? sprintf('var(--color-%s, %s)', $cssVariable, $hex) : $hex,
            'light' => ColorUtil::isLight($hex),
        ];
    }
}
