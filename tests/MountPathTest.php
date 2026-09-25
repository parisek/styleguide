<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\MountPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MountPathTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function valid(): iterable
    {
        yield 'default' => ['/styleguide', '/styleguide'];
        yield 'trailing slash' => ['/styleguide/', '/styleguide'];
        yield 'two segments' => ['/tools/ui', '/tools/ui'];
        yield 'two segments, trailing slashes' => ['/tools/ui//', '/tools/ui'];
        yield 'dash, underscore, tilde, dot inside' => ['/ui-kit_v2/~team/v1.2', '/ui-kit_v2/~team/v1.2'];
    }

    #[Test]
    #[DataProvider('valid')]
    public function it_normalises_a_valid_mount(string $input, string $expected): void
    {
        self::assertSame($expected, MountPath::normalise($input));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalid(): iterable
    {
        yield 'root' => ['/'];
        yield 'root, repeated' => ['///'];
        yield 'empty' => [''];
        yield 'not a string' => [['/styleguide']];
        yield 'null' => [null];
        yield 'relative' => ['styleguide'];
        yield 'protocol-relative' => ['//cdn.example/ui'];
        yield 'empty segment' => ['/tools//ui'];
        yield 'dot segment' => ['/./styleguide'];
        yield 'dot-dot segment' => ['/a/../b'];
        yield 'query' => ['/kit?x=1'];
        yield 'fragment' => ['/kit#x'];
        yield 'percent-encoding' => ['/a%2Fb'];
        yield 'non-ASCII' => ['/café'];
        yield 'whitespace' => ['/my kit'];
        yield 'backslash' => ['/a\\b'];
        yield 'scheme' => ['http://example.com/kit'];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function it_refuses_an_invalid_mount(mixed $input): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MountPath::normalise($input);
    }

    #[Test]
    public function relative_is_the_path_below_the_mount(): void
    {
        self::assertSame('', MountPath::relative('/tools/ui', '/tools/ui'));
        self::assertSame('component/card', MountPath::relative('/tools/ui/component/card', '/tools/ui'));
        self::assertNull(MountPath::relative('/tools/uix', '/tools/ui'));
        self::assertNull(MountPath::relative('/tools', '/tools/ui'));
        self::assertNull(MountPath::relative('/styleguides/x', '/styleguide'));
    }
}
