<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Translation;

use Parisek\Styleguide\Translation\PluralForms;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PluralFormsTest extends TestCase
{
    #[Test]
    public function germanic_rule_english(): void
    {
        $select = PluralForms::compile('nplurals=2; plural=(n != 1);');
        self::assertSame(0, $select(1));
        self::assertSame(1, $select(0));
        self::assertSame(1, $select(2));
        self::assertSame(1, $select(5));
    }

    #[Test]
    public function czech_slovak_three_way_rule(): void
    {
        $select = PluralForms::compile('nplurals=3; plural=(n==1) ? 0 : (n>=2 && n<=4) ? 1 : 2;');
        self::assertSame(0, $select(1));
        self::assertSame(1, $select(2));
        self::assertSame(1, $select(4));
        self::assertSame(2, $select(0));
        self::assertSame(2, $select(5));
        self::assertSame(2, $select(11));
    }

    #[Test]
    public function polish_rule_with_modulo_and_nested_conditions(): void
    {
        $select = PluralForms::compile(
            'nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
        );
        self::assertSame(0, $select(1));
        self::assertSame(1, $select(2));
        self::assertSame(1, $select(4));
        self::assertSame(2, $select(5));
        self::assertSame(2, $select(12)); // 12 % 100 = 12, in [10,20) -> excluded from bucket 1
        self::assertSame(1, $select(22)); // 22 % 100 = 22 -> in bucket 1
    }

    #[Test]
    public function missing_header_falls_back_to_the_germanic_default(): void
    {
        $select = PluralForms::compile(null);
        self::assertSame(0, $select(1));
        self::assertSame(1, $select(2));
    }

    #[Test]
    public function malformed_expression_falls_back_to_the_germanic_default_instead_of_throwing(): void
    {
        $select = PluralForms::compile('nplurals=2; plural=(this is not valid C at all!!);');
        self::assertSame(0, $select(1));
        self::assertSame(1, $select(2));
    }
}
