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
}
