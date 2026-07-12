<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\ColorUtil;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColorUtilTest extends TestCase
{
    #[DataProvider('hexProvider')]
    public function testParseHex(string $input, ?array $expected): void
    {
        self::assertSame($expected, ColorUtil::parseHex($input));
    }

    public static function hexProvider(): array
    {
        return [
            'six digit'        => ['#FE4942', [254, 73, 66]],
            'no hash'          => ['FE4942', [254, 73, 66]],
            'lowercase'        => ['#fe4942', [254, 73, 66]],
            'three digit'      => ['#F00', [255, 0, 0]],
            'garbage'          => ['not-a-color', null],
            'empty'            => ['', null],
            'wrong length'     => ['#FE49', null],
        ];
    }

    public function testRelativeLuminanceWhiteIsOne(): void
    {
        self::assertEqualsWithDelta(1.0, ColorUtil::relativeLuminance('#FFFFFF'), 0.001);
    }

    public function testRelativeLuminanceBlackIsZero(): void
    {
        self::assertEqualsWithDelta(0.0, ColorUtil::relativeLuminance('#000000'), 0.001);
    }

    public function testRelativeLuminanceMidRed(): void
    {
        // #FE4942: R 254 G 73 B 66 → L ≈ 0.267 (WCAG sRGB linearization)
        self::assertEqualsWithDelta(0.267, ColorUtil::relativeLuminance('#FE4942'), 0.01);
    }

    public function testRelativeLuminanceGarbageIsNull(): void
    {
        self::assertNull(ColorUtil::relativeLuminance('oops'));
    }

    #[DataProvider('lightProvider')]
    public function testIsLight(string $hex, bool $expected): void
    {
        self::assertSame($expected, ColorUtil::isLight($hex));
    }

    public static function lightProvider(): array
    {
        return [
            'white'                => ['#FFFFFF', true],
            'black'                => ['#000000', false],
            'tw primary-100 pale'  => ['#FEDBDA', true],
            'tw primary-500 mid'   => ['#FE4942', true],  // L≈0.267 > 0.179 — black text wins on this red
            'tw primary-700 dark'  => ['#C10600', false],
            'garbage → dark'       => ['nope', false],
        ];
    }

    #[DataProvider('hexToOklchProvider')]
    public function testHexToOklch(string $hex, ?string $expected): void
    {
        self::assertSame($expected, ColorUtil::hexToOklch($hex));
    }

    public static function hexToOklchProvider(): array
    {
        return [
            // Oracle values from this project's own sRGB → OKLab → OKLCH
            // implementation (Björn Ottosson's reference matrices), verified
            // independently in Python. White/black are exact by construction
            // (matrix rows sum to 1 / all-zero input); the colored oracles
            // below were recomputed from the spec's own math and cross-checked
            // in a second language rather than trusted blind — see task report.
            'mid red'    => ['#FE4942', 'oklch(66.61% 0.218 27.16)'],
            'white'      => ['#FFFFFF', 'oklch(100% 0 0)'],
            'black'      => ['#000000', 'oklch(0% 0 0)'],
            'grey-ish'   => ['#7E7E92', 'oklch(60% 0.03 285.46)'],
            'garbage'    => ['not-a-color', null],
        ];
    }

    #[DataProvider('oklchLightnessProvider')]
    public function testOklchLightness(string $oklch, ?float $expected): void
    {
        $result = ColorUtil::oklchLightness($oklch);
        if ($expected === null) {
            self::assertNull($result);
        } else {
            self::assertEqualsWithDelta($expected, $result, 0.0001);
        }
    }

    public static function oklchLightnessProvider(): array
    {
        return [
            'percent form'  => ['oklch(66.78% 0.219 27.17)', 0.6678],
            'fraction form' => ['oklch(0.65 0.2 30)', 0.65],
            'garbage'       => ['nonsense', null],
            'empty'         => ['', null],
        ];
    }

    #[DataProvider('isLightOklchProvider')]
    public function testIsLightOklch(float $l, bool $expected): void
    {
        self::assertSame($expected, ColorUtil::isLightOklch($l));
    }

    public static function isLightOklchProvider(): array
    {
        return [
            'above threshold' => [0.6678, true],
            'below threshold' => [0.321, false],
            'just above'      => [0.5636, true],
            'just below'      => [0.5634, false],
        ];
    }

    public function testContrastRatioWhiteBlackIsTwentyOne(): void
    {
        self::assertEqualsWithDelta(21.0, ColorUtil::contrastRatio('#FFFFFF', '#000000'), 0.001);
    }

    public function testContrastRatioIsSymmetric(): void
    {
        self::assertSame(
            ColorUtil::contrastRatio('#FE4942', '#FFFFFF'),
            ColorUtil::contrastRatio('#FFFFFF', '#FE4942'),
        );
    }

    public function testContrastRatioSameColorIsOne(): void
    {
        self::assertEqualsWithDelta(1.0, ColorUtil::contrastRatio('#7E7E92', '#7E7E92'), 0.001);
    }

    public function testContrastRatioMidRedOnWhite(): void
    {
        // L(#FE4942) ≈ 0.2623 (exact ColorUtil::relativeLuminance() value) →
        // (1.0 + 0.05) / (0.2623 + 0.05) ≈ 3.36. The brief's worked comment
        // used the rounded 0.267 approximation from the OKLCH test fixture,
        // which put the derived ratio (3.31) outside a sane delta of the
        // actual value — corrected here against the real computation.
        self::assertEqualsWithDelta(3.36, ColorUtil::contrastRatio('#FE4942', '#FFFFFF'), 0.01);
    }

    public function testContrastRatioGarbageIsNull(): void
    {
        self::assertNull(ColorUtil::contrastRatio('nope', '#FFFFFF'));
        self::assertNull(ColorUtil::contrastRatio('#FFFFFF', ''));
    }
}
