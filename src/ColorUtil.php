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
}
