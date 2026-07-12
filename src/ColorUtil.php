<?php

declare(strict_types=1);

namespace Parisek\Styleguide;

/**
 * @internal Color math for the overview screen (lightness, later contrast).
 *
 * Pure static helpers — WCAG 2.x relative luminance over sRGB hex. Used by
 * {@see ColorPalettes} to precompute the `light` flag each swatch carries
 * into foundations.twig, replacing the old shade-name heuristic
 * (`['50','100','200','300']`).
 */
final class ColorUtil
{
    /**
     * Luminance above which black text yields a higher WCAG contrast ratio
     * than white text: (L+0.05)/0.05 > 1.05/(L+0.05) ⇒ L > 0.179.
     */
    private const LIGHT_THRESHOLD = 0.179;

    /**
     * OKLab lightness equivalent of {@see LIGHT_THRESHOLD}: cbrt(0.179) ≈
     * 0.5635. OKLab's L channel is (roughly) a cube-root-compressed
     * luminance, so the WCAG black/white-text crossover maps onto it via a
     * cube root rather than a 1:1 copy.
     */
    private const LIGHT_THRESHOLD_OKLCH = 0.5635;

    /** @return array{int, int, int}|null */
    public static function parseHex(string $hex): ?array
    {
        $hex = ltrim(trim($hex), '#');
        if (preg_match('/^[0-9a-fA-F]{3}$/', $hex) === 1) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            return null;
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    public static function relativeLuminance(string $hex): ?float
    {
        $rgb = self::parseHex($hex);
        if ($rgb === null) {
            return null;
        }
        $linear = array_map(static function (int $channel): float {
            $c = $channel / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    public static function isLight(string $hex): bool
    {
        $luminance = self::relativeLuminance($hex);

        return $luminance !== null && $luminance > self::LIGHT_THRESHOLD;
    }

    /**
     * sRGB hex → OKLCH string, e.g. `oklch(66.61% 0.218 27.16)`.
     *
     * Björn Ottosson's reference sRGB → OKLab → OKLCH matrices. Null when
     * the hex is unparseable. White/black round-trip exactly by
     * construction (the linear-sRGB→LMS matrix rows sum to 1, so white's
     * l/m/s are all 1 and black's are all 0); near-zero chroma is snapped
     * to `0 0` to avoid a meaningless hue on greys.
     */
    public static function hexToOklch(string $hex): ?string
    {
        $rgb = self::parseHex($hex);
        if ($rgb === null) {
            return null;
        }

        $linear = array_map(static function (int $channel): float {
            $c = $channel / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $rgb);
        [$r, $g, $b] = $linear;

        $l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
        $m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
        $s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;

        $cbrt = static fn(float $x): float => $x <= 0 ? 0.0 : $x ** (1 / 3);
        $l_ = $cbrt($l);
        $m_ = $cbrt($m);
        $s_ = $cbrt($s);

        $bigL = 0.2104542553 * $l_ + 0.7936177850 * $m_ - 0.0040720468 * $s_;
        $a    = 1.9779984951 * $l_ - 2.4285922050 * $m_ + 0.4505937099 * $s_;
        $bLab = 0.0259040371 * $l_ + 0.7827717662 * $m_ - 0.8086757660 * $s_;

        $c = sqrt($a * $a + $bLab * $bLab);
        $h = fmod(rad2deg(atan2($bLab, $a)) + 360, 360);
        if ($c < 0.0001) {
            $c = 0.0;
            $h = 0.0;
        }

        $lPercent = round($bigL * 100, 2);
        $cRounded = round($c, 3);
        $hRounded = round($h, 2);

        return "oklch({$lPercent}% {$cRounded} {$hRounded})";
    }

    /**
     * Parses an `oklch(<L> …)` string and returns its lightness as a 0–1
     * fraction. Accepts `L%` (percent) or bare `L` (already 0–1). Null when
     * unparseable — callers fall back to hex-based lightness.
     */
    public static function oklchLightness(string $oklch): ?float
    {
        if (preg_match('/oklch\(\s*([0-9.]+)(%?)/i', $oklch, $matches) !== 1) {
            return null;
        }

        $value = (float) $matches[1];

        return $matches[2] === '%' ? $value / 100 : $value;
    }

    /**
     * OKLab-lightness counterpart of {@see isLight()} — same crossover
     * concept, expressed on the OKLCH `L` channel via
     * {@see LIGHT_THRESHOLD_OKLCH}.
     */
    public static function isLightOklch(float $l): bool
    {
        return $l > self::LIGHT_THRESHOLD_OKLCH;
    }

    /**
     * WCAG 2.x contrast ratio between two colors: (Lmax + 0.05) / (Lmin + 0.05).
     * Symmetric; 1.0 (identical) … 21.0 (black on white). Null when either
     * hex is unparseable. Unrounded — presentation layers round for display.
     */
    public static function contrastRatio(string $hexA, string $hexB): ?float
    {
        $la = self::relativeLuminance($hexA);
        $lb = self::relativeLuminance($hexB);
        if ($la === null || $lb === null) {
            return null;
        }

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }
}
