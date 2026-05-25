<?php

declare(strict_types=1);

namespace Parisek\Styleguide;

/**
 * @internal Implementation detail of the bundled `placeholder()` Twig
 *           function. The Twig function itself IS public (see `docs/API.md` §
 *           Twig functions); the underlying PHP class shape can change.
 *
 * Deterministic, professional-looking SVG placeholder generator.
 *
 * Returns an image-array shape compatible with `component_picture`. Use
 * exclusively in styleguide.twig files — never in production component
 * templates (those receive `content.image` from the CMS via `|resizer`).
 *
 * Visual approach: muted palette + abstract gradient compositions + blur,
 * vignette, and grain filters. Avoids literal shapes (mountains, faces) so
 * the result reads as "photographic mood" rather than "illustration".
 *
 * Lazy-loaded via the standard PSR-4 autoloader the moment Twig calls the
 * `placeholder()` function or the `|resizer` filter. Projects that need a
 * tuned palette / subject set extend this class and pass the subclass FQCN
 * to {@see Styleguide} (future config key) — until then, override at the
 * Twig-function level: register your own `placeholder` Twig function on the
 * environment before constructing `Styleguide`, and `registerBundledHelpers()`
 * will respect it (its `getFunction('placeholder') === null` check).
 */
final class Placeholder
{
    private static int $counter = 0;

    public static function generate(array $opts = []): array
    {
        // Strip caller-supplied nulls before merging defaults — `$opts += [...]`
        // only adds *missing* keys, so an explicit `mood: null` would otherwise
        // reach `palette(string $mood, …)` and trigger a TypeError. `null`
        // is treated as "unset"; defaults reapply.
        $opts = array_filter($opts, static fn ($v) => $v !== null) + [
            'subject' => 'abstract',
            'mood' => 'pastel',
            'aspect' => '3/2',
            'width' => null,
            'height' => null,
            'seed' => null,
            'label' => false,
            'alt' => null,
            'grain' => true,
            'vignette' => true,
        ];

        [$w, $h] = self::resolveDimensions($opts);
        $seed = (string) ($opts['seed'] ?? 'auto-' . (++self::$counter));
        // Stash resolved seed back so the resizer can regenerate variants at new
        // dimensions while keeping palette + composition stable.
        $opts['seed'] = $seed;

        $palette = self::palette($opts['mood'], $seed);
        $svg = self::svg($w, $h, $opts['subject'], $palette, $seed, $opts);
        // Base64 encoding (vs percent-encoding) — slightly larger but contains no
        // commas, so the same data URL works inside `<source srcset>` where srcset's
        // candidate parser would otherwise split on the data URI separator and reject
        // the URL.
        $dataUrl = 'data:image/svg+xml;base64,' . base64_encode($svg);

        return [[
            'src' => $dataUrl,
            'type' => 'image/svg+xml',
            'width' => $w,
            'height' => $h,
            'alt' => $opts['alt'] ?? "{$opts['subject']} placeholder",
            '_placeholderOpts' => $opts,
        ]];
    }

    private static function resolveDimensions(array $opts): array
    {
        // Normalize width/height up front: non-numeric strings (`'auto'`) or
        // non-positive values are treated as "unset" so the rest of the function
        // can stop juggling truthy checks and unsafe arithmetic operands.
        // `max(1, …)` guards the derived dimension at extreme aspect ratios.
        $w = (is_numeric($opts['width'] ?? null) && $opts['width'] > 0) ? (int) $opts['width'] : null;
        $h = (is_numeric($opts['height'] ?? null) && $opts['height'] > 0) ? (int) $opts['height'] : null;

        if ($w !== null && $h !== null) {
            return [$w, $h];
        }
        [$aw, $ah] = explode('/', (string) $opts['aspect']) + [1 => '1'];
        $aw = max(0.001, (float) $aw);
        $ah = max(0.001, (float) $ah);
        if ($w !== null) {
            return [$w, max(1, (int) round($w * $ah / $aw))];
        }
        if ($h !== null) {
            return [max(1, (int) round($h * $aw / $ah)), $h];
        }
        return [1200, max(1, (int) round(1200 * $ah / $aw))];
    }

    private static function seedHash(string $seed, int $offset = 0): int
    {
        return abs(crc32($seed . ':' . $offset));
    }

    private static function seedRand(string $seed, int $offset, float $min, float $max): float
    {
        $normalized = (self::seedHash($seed, $offset) % 1000) / 1000.0;
        return $min + $normalized * ($max - $min);
    }

    private static function posMod(int $value, int $modulus): int
    {
        $m = $value % $modulus;
        return $m < 0 ? $m + $modulus : $m;
    }

    /**
     * Mood → palette. Saturation/lightness tuned for muted, photographic feel —
     * less playground colours, more editorial.
     */
    private static function palette(string $mood, string $seed): array
    {
        // Tuning derived from analysis of 36 curated real-world palettes
        // (colorhunt.co/palettes/{pastel,sage,vintage}, captured 2026-04).
        // Common pattern: light cream base (lit 78–94) + one dark anchor (lit 25–45).
        $moods = [
            'pastel'     => ['range' => [0, 360],   'sat' => 32, 'lit' => 78],
            'vibrant'    => ['range' => [0, 360],   'sat' => 55, 'lit' => 48],
            'monochrome' => ['range' => [0, 360],   'sat' => 4,  'lit' => 65],
            'warm'       => ['range' => [15, 45],   'sat' => 42, 'lit' => 62],
            'cold'       => ['range' => [195, 230], 'sat' => 25, 'lit' => 60],
            'natural'    => ['range' => [60, 110],  'sat' => 25, 'lit' => 58],
            'vintage'    => ['range' => [20, 60],   'sat' => 38, 'lit' => 55],
        ];
        $cfg = $moods[$mood] ?? $moods['pastel'];

        $rangeStart = $cfg['range'][0];
        $rangeWidth = max(1, $cfg['range'][1] - $cfg['range'][0]);
        $baseHue = self::seedHash($seed, 100) % 360;
        $h1 = $rangeStart + self::posMod($baseHue - $rangeStart, $rangeWidth);

        if ($rangeWidth >= 360) {
            $h2 = ($h1 + 25) % 360;
            $h3 = ($h1 + 200) % 360;
        } else {
            $h2 = $rangeStart + self::posMod($h1 - $rangeStart + (int) round($rangeWidth * 0.4), $rangeWidth);
            $h3 = $rangeStart + self::posMod($h1 - $rangeStart + (int) round($rangeWidth * 0.7), $rangeWidth);
        }

        return [
            // Background — cream-to-paper gradient: bright top, slightly tinted bottom
            'bg1' => sprintf('hsl(%d, %d%%, %d%%)', $h1, $cfg['sat'], min(94, $cfg['lit'] + 12)),
            'bg2' => sprintf('hsl(%d, %d%%, %d%%)', $h2, $cfg['sat'], $cfg['lit'] + 2),
            // Foreground — fg1 acts as the dark anchor (lit 25–45 in real palettes)
            'fg1' => sprintf('hsl(%d, %d%%, %d%%)', $h1, max(8, $cfg['sat'] - 8), max(20, $cfg['lit'] - 45)),
            'fg2' => sprintf('hsl(%d, %d%%, %d%%)', $h3, max(8, $cfg['sat'] - 5), max(25, $cfg['lit'] - 20)),
            'highlight' => sprintf('hsl(%d, %d%%, %d%%)', $h2, min(60, $cfg['sat'] + 15), min(92, $cfg['lit'] + 18)),
        ];
    }

    private static function svg(int $w, int $h, string $subject, array $palette, string $seed, array $opts): string
    {
        $blurAmount = (int) round(min($w, $h) * 0.04);
        $grainSeed = self::seedHash($seed, 999) % 100;

        $defs = sprintf(
            '<defs>' .
                // Background gradient — diagonal, subtle
                '<linearGradient id="b" x1="0%%" y1="0%%" x2="100%%" y2="100%%">' .
                    '<stop offset="0%%" stop-color="%s"/>' .
                    '<stop offset="100%%" stop-color="%s"/>' .
                '</linearGradient>' .
                // Vignette overlay — transparent centre, dark edges
                '<radialGradient id="v" cx="50%%" cy="50%%" r="75%%">' .
                    '<stop offset="40%%" stop-color="black" stop-opacity="0"/>' .
                    '<stop offset="100%%" stop-color="black" stop-opacity="0.30"/>' .
                '</radialGradient>' .
                // Soft blur for organic shapes
                '<filter id="f" x="-15%%" y="-15%%" width="130%%" height="130%%">' .
                    '<feGaussianBlur stdDeviation="%d"/>' .
                '</filter>' .
                // Film grain overlay
                '<filter id="g" x="0%%" y="0%%" width="100%%" height="100%%">' .
                    '<feTurbulence type="fractalNoise" baseFrequency="0.9" numOctaves="2" seed="%d"/>' .
                    '<feColorMatrix values="0 0 0 0 0.5  0 0 0 0 0.5  0 0 0 0 0.5  0 0 0 0.18 0"/>' .
                '</filter>' .
            '</defs>',
            $palette['bg1'], $palette['bg2'], $blurAmount, $grainSeed
        );

        $bg = '<rect width="100%" height="100%" fill="url(#b)"/>';

        // Subject dispatch is by name → method (subject_abstract → subjectAbstract).
        // method_exists()'s second arg auto-resolves on the FQCN string we pass
        // first; matches the previous function_exists() semantics.
        $subjectMethod = 'subject' . ucfirst(strtolower($subject));
        if (!method_exists(self::class, $subjectMethod)) {
            $subjectMethod = 'subjectAbstract';
        }
        $body = self::$subjectMethod($w, $h, $palette, $seed);

        $vignette = $opts['vignette']
            ? '<rect width="100%" height="100%" fill="url(#v)" style="mix-blend-mode:multiply"/>'
            : '';
        $grain = $opts['grain']
            ? '<rect width="100%" height="100%" filter="url(#g)" opacity="0.55"/>'
            : '';

        $labelEl = '';
        if ($opts['label'] !== false) {
            $text = ($opts['label'] === true || $opts['label'] === '') ? "{$w} × {$h}" : (string) $opts['label'];
            $fontSize = max(14, (int) round(min($w, $h) / 14));
            $labelEl = sprintf(
                '<text x="50%%" y="50%%" text-anchor="middle" dominant-baseline="middle" ' .
                'font-family="system-ui, sans-serif" font-size="%d" font-weight="500" letter-spacing="0.05em" ' .
                'fill="white" opacity="0.85">%s</text>',
                $fontSize, htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8')
            );
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" preserveAspectRatio="xMidYMid slice">%s%s%s%s%s%s</svg>',
            $w, $h, $w, $h,
            $defs, $bg, $body, $vignette, $grain, $labelEl
        );
    }

    // ─── Subject generators ──────────────────────────────────────────────────
    // Each returns an SVG fragment string (no <svg> wrapper). Uses #f filter
    // for organic blur, deterministic positioning via self::seedRand().

    /**
     * Three large overlapping ellipses, blurred — generic editorial backdrop.
     */
    private static function subjectAbstract(int $w, int $h, array $palette, string $seed): string
    {
        $colors = [$palette['fg1'], $palette['fg2'], $palette['highlight']];
        $blobs = '';
        for ($i = 0; $i < 3; $i++) {
            $cx = self::seedRand($seed, 1 + $i, $w * 0.10, $w * 0.90);
            $cy = self::seedRand($seed, 10 + $i, $h * 0.10, $h * 0.90);
            $rx = self::seedRand($seed, 20 + $i, min($w, $h) * 0.30, min($w, $h) * 0.55);
            $ry = self::seedRand($seed, 30 + $i, min($w, $h) * 0.25, min($w, $h) * 0.50);
            $rot = self::seedRand($seed, 40 + $i, -45, 45);
            $opacity = 0.45 - $i * 0.05;
            $blobs .= sprintf(
                '<ellipse cx="%F" cy="%F" rx="%F" ry="%F" transform="rotate(%F %F %F)" fill="%s" opacity="%F"/>',
                $cx, $cy, $rx, $ry, $rot, $cx, $cy, $colors[$i], $opacity
            );
        }
        return '<g filter="url(#f)">' . $blobs . '</g>';
    }

    /**
     * Horizontal layered bands with smooth bezier transitions and soft sun glow.
     * Reads as "horizon" without drawing actual mountains.
     */
    private static function subjectLandscape(int $w, int $h, array $palette, string $seed): string
    {
        $horizon = $h * self::seedRand($seed, 1, 0.55, 0.68);
        $sunX = self::seedRand($seed, 5, $w * 0.20, $w * 0.80);
        $sunY = self::seedRand($seed, 6, $h * 0.18, $horizon - $h * 0.08);
        $sunR = min($w, $h) * 0.13;

        $ridgeOffset = $h * 0.05;
        $ridge = sprintf(
            'M0,%F C%F,%F %F,%F %F,%F C%F,%F %F,%F %F,%F L%F,%F L0,%F Z',
            $horizon,
            $w * 0.25, $horizon - $ridgeOffset,
            $w * 0.35, $horizon + $ridgeOffset * 0.5,
            $w * 0.55, $horizon - $ridgeOffset * 0.3,
            $w * 0.75, $horizon - $ridgeOffset,
            $w * 0.85, $horizon + $ridgeOffset * 0.7,
            $w, $horizon,
            $w, $h, $h
        );

        $fgY = $h * 0.82;
        $fg = sprintf(
            'M0,%F C%F,%F %F,%F %F,%F L%F,%F L0,%F Z',
            $fgY,
            $w * 0.40, $fgY - $h * 0.05,
            $w * 0.60, $fgY + $h * 0.03,
            $w, $fgY - $h * 0.02,
            $w, $h, $h
        );

        return sprintf(
            '<g filter="url(#f)">' .
                '<circle cx="%F" cy="%F" r="%F" fill="%s" opacity="0.65"/>' .
                '<rect x="0" y="%F" width="%F" height="%F" fill="white" opacity="0.18"/>' .
                '<path d="%s" fill="%s" opacity="0.55"/>' .
                '<path d="%s" fill="%s" opacity="0.80"/>' .
            '</g>',
            $sunX, $sunY, $sunR * 1.4, $palette['highlight'],
            $horizon - $h * 0.10, $w, $h * 0.20,
            $ridge, $palette['fg2'],
            $fg, $palette['fg1']
        );
    }

    /**
     * Vertical gradient + radial spotlight in upper third — studio portrait
     * lighting. No literal head/shoulders shape.
     */
    private static function subjectPortrait(int $w, int $h, array $palette, string $seed): string
    {
        $bottomBandY = $h * 0.72;

        return sprintf(
            '<defs>' .
                '<radialGradient id="p" cx="50%%" cy="35%%" r="55%%">' .
                    '<stop offset="0%%" stop-color="%s" stop-opacity="0.65"/>' .
                    '<stop offset="100%%" stop-color="%s" stop-opacity="0"/>' .
                '</radialGradient>' .
            '</defs>' .
            '<rect width="100%%" height="100%%" fill="url(#p)"/>' .
            '<g filter="url(#f)">' .
                '<rect x="0" y="%F" width="%F" height="%F" fill="%s" opacity="0.50"/>' .
            '</g>',
            $palette['highlight'], $palette['highlight'],
            $bottomBandY, $w, $h - $bottomBandY, $palette['fg1']
        );
    }

    /**
     * Centred radial spotlight with soft falloff — product photographed against
     * a clean backdrop. Lit-from-above feel.
     */
    private static function subjectProduct(int $w, int $h, array $palette, string $seed): string
    {
        return sprintf(
            '<defs>' .
                '<radialGradient id="pr" cx="50%%" cy="45%%" r="40%%">' .
                    '<stop offset="0%%" stop-color="%s" stop-opacity="0.70"/>' .
                    '<stop offset="60%%" stop-color="%s" stop-opacity="0.20"/>' .
                    '<stop offset="100%%" stop-color="%s" stop-opacity="0"/>' .
                '</radialGradient>' .
            '</defs>' .
            '<rect width="100%%" height="100%%" fill="url(#pr)"/>' .
            '<g filter="url(#f)">' .
                '<ellipse cx="50%%" cy="78%%" rx="35%%" ry="6%%" fill="%s" opacity="0.30"/>' .
            '</g>',
            $palette['highlight'], $palette['highlight'], $palette['highlight'],
            $palette['fg1']
        );
    }

    /**
     * Warm-tinted off-centre radial glow — food-styling lighting cue without
     * drawing a plate.
     */
    private static function subjectFood(int $w, int $h, array $palette, string $seed): string
    {
        $cx = self::seedRand($seed, 1, 40, 60);
        $cy = self::seedRand($seed, 2, 40, 60);

        return sprintf(
            '<defs>' .
                '<radialGradient id="fd" cx="%F%%" cy="%F%%" r="50%%">' .
                    '<stop offset="0%%" stop-color="%s" stop-opacity="0.75"/>' .
                    '<stop offset="50%%" stop-color="%s" stop-opacity="0.30"/>' .
                    '<stop offset="100%%" stop-color="%s" stop-opacity="0"/>' .
                '</radialGradient>' .
            '</defs>' .
            '<rect width="100%%" height="100%%" fill="url(#fd)"/>' .
            '<g filter="url(#f)">' .
                '<circle cx="%F%%" cy="%F%%" r="22%%" fill="%s" opacity="0.30"/>' .
            '</g>',
            $cx, $cy, $palette['highlight'], $palette['highlight'], $palette['highlight'],
            $cx, $cy, $palette['fg2']
        );
    }

    /**
     * Vertical bands of varying lightness — abstract urban "skyline" without
     * window grids.
     */
    private static function subjectArchitecture(int $w, int $h, array $palette, string $seed): string
    {
        $count = 5;
        $bandW = $w / $count;
        $out = '<g filter="url(#f)">';

        for ($i = 0; $i < $count; $i++) {
            $bandH = self::seedRand($seed, 10 + $i, $h * 0.45, $h * 0.85);
            $bandX = $i * $bandW;
            $bandY = $h - $bandH;
            $color = $i % 2 === 0 ? $palette['fg1'] : $palette['fg2'];
            $opacity = 0.45 + ($i % 3) * 0.10;
            $out .= sprintf(
                '<rect x="%F" y="%F" width="%F" height="%F" fill="%s" opacity="%F"/>',
                $bandX, $bandY, $bandW * 1.05, $bandH, $color, $opacity
            );
        }
        $out .= sprintf(
            '<rect x="0" y="%F" width="%F" height="%F" fill="white" opacity="0.12"/>',
            $h * 0.55, $w, $h * 0.10
        );
        $out .= '</g>';
        return $out;
    }

    /**
     * Tight square-ish framing of portrait lighting — for comment / quote tiles.
     */
    private static function subjectAvatar(int $w, int $h, array $palette, string $seed): string
    {
        return sprintf(
            '<defs>' .
                '<radialGradient id="av" cx="50%%" cy="40%%" r="48%%">' .
                    '<stop offset="0%%" stop-color="%s" stop-opacity="0.75"/>' .
                    '<stop offset="80%%" stop-color="%s" stop-opacity="0.05"/>' .
                    '<stop offset="100%%" stop-color="%s" stop-opacity="0"/>' .
                '</radialGradient>' .
            '</defs>' .
            '<rect width="100%%" height="100%%" fill="url(#av)"/>' .
            '<g filter="url(#f)">' .
                '<rect x="0" y="80%%" width="%F" height="20%%" fill="%s" opacity="0.55"/>' .
            '</g>',
            $palette['highlight'], $palette['highlight'], $palette['highlight'],
            $w, $palette['fg1']
        );
    }
}
