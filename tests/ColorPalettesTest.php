<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\ColorPalettes;
use PHPUnit\Framework\TestCase;

final class ColorPalettesTest extends TestCase
{
    public function testLegacyShadesShapeNormalizes(): void
    {
        $result = ColorPalettes::normalize([
            'primary' => [
                'name'         => 'Primary',
                'css_variable' => 'primary',
                'default'      => 500,
                'shades'       => [
                    100 => ['hex' => '#fedbda', 'oklch' => 'oklch(92.33% 0.039 19.83)'],
                    500 => ['hex' => '#FE4942', 'oklch' => 'oklch(66.78% 0.219 27.17)'],
                    900 => ['hex' => '#410200'],
                ],
            ],
        ]);

        self::assertCount(1, $result);
        $palette = $result[0];
        self::assertSame('primary', $palette['key']);
        self::assertSame('Primary', $palette['name']);
        self::assertSame('500', $palette['default']);
        self::assertCount(3, $palette['swatches']);

        $mid = $palette['swatches'][1];
        self::assertSame('500', $mid['key']);
        self::assertSame('#FE4942', $mid['hex']);
        self::assertSame('oklch(66.78% 0.219 27.17)', $mid['oklch']);
        self::assertSame('primary-500', $mid['label']);
        self::assertSame('var(--color-primary-500, #FE4942)', $mid['bg']);
        self::assertTrue($mid['light']);

        $dark = $palette['swatches'][2];
        // #410200 has no yaml oklch — computed from hex (Björn Ottosson's
        // sRGB → OKLab → OKLCH reference matrices; see ColorUtilTest).
        self::assertSame('oklch(23.81% 0.094 30.39)', $dark['oklch']);
        self::assertFalse($dark['light']);
    }

    public function testMalformedProvidedOklchFallsBackToHexLightnessButDisplaysVerbatim(): void
    {
        $result = ColorPalettes::normalize([
            'brand' => [
                'swatches' => [
                    ['name' => 'oops', 'hex' => '#FE4942', 'oklch' => 'oklch(broken)'],
                ],
            ],
        ]);

        $swatch = $result[0]['swatches'][0];
        // Author's string is displayed as-is — never rewritten — even
        // though it can't be parsed for a lightness value.
        self::assertSame('oklch(broken)', $swatch['oklch']);
        // oklchLightness() can't parse it → falls back to hex-based
        // WCAG luminance, same as the pre-OKLCH behaviour (#FE4942 is light).
        self::assertTrue($swatch['light']);
    }

    public function testDefaultFallsBackToFirstSwatchWhenMissingOrUnknown(): void
    {
        $result = ColorPalettes::normalize([
            'accent' => [
                'shades' => [
                    50  => ['hex' => '#FFFFFF'],
                    900 => ['hex' => '#000000'],
                ],
                'default' => 500, // not among shades
            ],
        ]);

        self::assertSame('50', $result[0]['default']);
        self::assertSame('Accent', $result[0]['name']); // ucfirst(key) fallback
    }

    public function testUnparseableHexEntriesAreSkipped(): void
    {
        $result = ColorPalettes::normalize([
            'primary' => [
                'css_variable' => 'primary',
                'shades'       => [
                    100 => ['hex' => 'garbage'],
                    500 => ['hex' => '#FE4942'],
                ],
            ],
        ]);

        self::assertCount(1, $result[0]['swatches']);
        self::assertSame('500', $result[0]['swatches'][0]['key']);
    }

    public function testEmptyAndNonArrayInputYieldsEmptyList(): void
    {
        self::assertSame([], ColorPalettes::normalize(null));
        self::assertSame([], ColorPalettes::normalize('nope'));
        self::assertSame([], ColorPalettes::normalize([]));
        self::assertSame([], ColorPalettes::normalize(['broken' => 'not-a-map']));
    }

    public function testMissingCssVariableFallsBackToPaletteKeyPrefix(): void
    {
        $result = ColorPalettes::normalize([
            'brand' => [
                'shades' => [500 => ['hex' => '#123456']],
            ],
        ]);

        self::assertSame('brand-500', $result[0]['swatches'][0]['label']);
        self::assertSame('var(--color-brand-500, #123456)', $result[0]['swatches'][0]['bg']);
    }

    public function testNamedSwatchesListNormalizes(): void
    {
        $result = ColorPalettes::normalize([
            'brand' => [
                'name'     => 'Brand',
                'swatches' => [
                    ['name' => 'red', 'hex' => '#E63946', 'css_variable' => 'brand-red'],
                    ['name' => 'cream', 'hex' => '#F1FAEE'],
                    ['name' => 'navy', 'hex' => '#1D3557', 'oklch' => 'oklch(32.1% 0.078 258.4)'],
                ],
            ],
        ]);

        $palette = $result[0];
        self::assertSame(['red', 'cream', 'navy'], array_column($palette['swatches'], 'key'));
        self::assertSame('red', $palette['default']); // first swatch — no default: given

        $red = $palette['swatches'][0];
        self::assertSame('brand-red', $red['label']);
        self::assertSame('var(--color-brand-red, #E63946)', $red['bg']);

        $cream = $palette['swatches'][1];
        self::assertSame('cream', $cream['label']);     // no css_variable → bare name
        self::assertSame('#F1FAEE', $cream['bg']);      // no css_variable → plain hex background
        self::assertTrue($cream['light']);

        self::assertSame('oklch(32.1% 0.078 258.4)', $palette['swatches'][2]['oklch']);
        self::assertFalse($palette['swatches'][2]['light']);
    }

    public function testNamedSwatchDefaultByName(): void
    {
        $result = ColorPalettes::normalize([
            'brand' => [
                'default'  => 'cream',
                'swatches' => [
                    ['name' => 'red', 'hex' => '#E63946'],
                    ['name' => 'cream', 'hex' => '#F1FAEE'],
                ],
            ],
        ]);

        self::assertSame('cream', $result[0]['default']);
    }

    public function testNamedSwatchWithoutNameOrHexIsSkipped(): void
    {
        $result = ColorPalettes::normalize([
            'brand' => [
                'swatches' => [
                    ['hex' => '#E63946'],                  // no name
                    ['name' => 'ghost'],                   // no hex
                    ['name' => 'ok', 'hex' => '#123456'],
                ],
            ],
        ]);

        self::assertSame(['ok'], array_column($result[0]['swatches'], 'key'));
    }

    public function testSwatchesWinsOverShadesWhenBothPresent(): void
    {
        $result = ColorPalettes::normalize([
            'mixed' => [
                'shades'   => [500 => ['hex' => '#111111']],
                'swatches' => [['name' => 'only', 'hex' => '#222222']],
            ],
        ]);

        self::assertSame(['only'], array_column($result[0]['swatches'], 'key'));
    }

    public function testSwatchCarriesTextContrastData(): void
    {
        $result = ColorPalettes::normalize([
            'primary' => ['shades' => [500 => ['hex' => '#FE4942']]],
        ]);
        $swatch = $result[0]['swatches'][0];

        self::assertEqualsWithDelta(3.36, $swatch['contrast_white'], 0.01);
        self::assertEqualsWithDelta(6.25, $swatch['contrast_black'], 0.01);
        self::assertFalse($swatch['aa_white']);
        self::assertTrue($swatch['aa_black']);
    }

    public function testDarkSwatchPassesWhiteFailsBlack(): void
    {
        $result = ColorPalettes::normalize([
            'x' => ['shades' => [900 => ['hex' => '#19191E']]],
        ]);
        $swatch = $result[0]['swatches'][0];

        self::assertTrue($swatch['aa_white']);
        self::assertFalse($swatch['aa_black']);
    }
}
