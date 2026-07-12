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
        self::assertSame('', $dark['oklch']);
        self::assertFalse($dark['light']);
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
}
