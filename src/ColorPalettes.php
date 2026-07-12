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
 *
 * `oklch` is optional per swatch/shade: when the yaml provides one it's
 * displayed verbatim (even if malformed — the author's string is never
 * rewritten), otherwise it's computed from `hex` via
 * {@see ColorUtil::hexToOklch()} so a value is always present. The `light`
 * flag prefers OKLCH lightness (author-provided or computed) over the older
 * WCAG hex-luminance heuristic; it only falls back to the hex heuristic
 * when the final `oklch` string can't be parsed for a lightness value
 * (e.g. a hand-typed typo in the yaml).
 */
final class ColorPalettes
{
    /**
     * @return list<array{
     *     key: string,
     *     name: string,
     *     default: string,
     *     swatches: list<array{key: string, hex: string, oklch: string, label: string, bg: string, light: bool, contrast_white: float, contrast_black: float, aa_white: bool, aa_black: bool}>
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
     * @return list<array{key: string, hex: string, oklch: string, label: string, bg: string, light: bool, contrast_white: float, contrast_black: float, aa_white: bool, aa_black: bool}>
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
     * @return array{key: string, hex: string, oklch: string, label: string, bg: string, light: bool, contrast_white: float, contrast_black: float, aa_white: bool, aa_black: bool}|null
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

        // OKLCH is always available: the yaml's own value wins verbatim
        // (displayed as-is, even if malformed — see the `light` fallback
        // below), otherwise it's computed from the now-validated hex.
        $oklch = $oklch !== '' ? $oklch : (string) ColorUtil::hexToOklch($hex);

        // `light` prefers OKLCH lightness (author-provided or computed)
        // since OKLCH is the display basis; only a malformed provided
        // string (oklchLightness() returns null) falls back to the
        // WCAG hex-luminance heuristic.
        $lightness = ColorUtil::oklchLightness($oklch);
        $light = $lightness !== null ? ColorUtil::isLightOklch($lightness) : ColorUtil::isLight($hex);

        // (float) cast is safe: parseHex() already validated $hex above, so
        // contrastRatio() cannot return null here.
        $contrastWhite = round((float) ColorUtil::contrastRatio($hex, '#FFFFFF'), 2);
        $contrastBlack = round((float) ColorUtil::contrastRatio($hex, '#000000'), 2);

        return [
            'key'            => $key,
            'hex'            => $hex,
            'oklch'          => $oklch,
            'label'          => $cssVariable !== '' ? $cssVariable : $key,
            'bg'             => $cssVariable !== '' ? sprintf('var(--color-%s, %s)', $cssVariable, $hex) : $hex,
            'light'          => $light,
            'contrast_white' => $contrastWhite,
            'contrast_black' => $contrastBlack,
            'aa_white'       => $contrastWhite >= 4.5,
            'aa_black'       => $contrastBlack >= 4.5,
        ];
    }

    /**
     * Full pair-contrast grid for the foundations matrix: every swatch across
     * all palettes plus White and Black. cells[row][col] grades text color
     * `row` on background `col` — ratio is symmetric, the orientation only
     * matters for the rendered preview.
     *
     * Levels: aaa >= 7.0, aa >= 4.5, aa-large >= 3.0 (large-text AA), fail.
     * Empty palette list yields ['colors' => [], 'cells' => []] so the
     * template's `if colors_contrast.colors` guard hides the section.
     *
     * @param list<array{key: string, name: string, default: string, swatches: list<array<string, mixed>>}> $palettes
     *
     * @return array{colors: list<array{label: string, hex: string, bg: string}>, cells: list<list<array{ratio: float, level: string}>>}
     */
    public static function contrastMatrix(array $palettes): array
    {
        $colors = [];
        foreach ($palettes as $palette) {
            foreach ($palette['swatches'] as $swatch) {
                $colors[] = ['label' => (string) $swatch['label'], 'hex' => (string) $swatch['hex'], 'bg' => (string) $swatch['bg']];
            }
        }
        if ($colors === []) {
            return ['colors' => [], 'cells' => []];
        }
        $colors[] = ['label' => 'White', 'hex' => '#FFFFFF', 'bg' => '#FFFFFF'];
        $colors[] = ['label' => 'Black', 'hex' => '#000000', 'bg' => '#000000'];

        $cells = [];
        foreach ($colors as $row) {
            $rowCells = [];
            foreach ($colors as $col) {
                $ratio = round((float) ColorUtil::contrastRatio($row['hex'], $col['hex']), 2);
                $rowCells[] = ['ratio' => $ratio, 'level' => self::gradeContrast($ratio)];
            }
            $cells[] = $rowCells;
        }

        return ['colors' => $colors, 'cells' => $cells];
    }

    private static function gradeContrast(float $ratio): string
    {
        return match (true) {
            $ratio >= 7.0 => 'aaa',
            $ratio >= 4.5 => 'aa',
            $ratio >= 3.0 => 'aa-large',
            default => 'fail',
        };
    }
}
