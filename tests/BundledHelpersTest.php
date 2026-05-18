<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\Placeholder;
use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class BundledHelpersTest extends TestCase
{
    private static function newStyleguide(): Styleguide
    {
        // Minimal config — the package builds its own pristine Twig env
        // since `twig` is omitted, sufficient for asserting helper
        // registration on it.
        return new Styleguide([
            'templates_path' => __DIR__ . '/fixtures/templates',
            'static_path' => __DIR__ . '/fixtures',
            'config_yaml' => __DIR__ . '/fixtures/styleguide.yaml',
        ]);
    }

    private static function twigOf(Styleguide $sg): Environment
    {
        // PHP 8.1+ allows reading private props without setAccessible();
        // setAccessible() itself is deprecated in 8.5.
        return (new \ReflectionClass($sg))->getProperty('twig')->getValue($sg);
    }

    #[Test]
    public function registers_component_and_page_functions(): void
    {
        $twig = self::twigOf(self::newStyleguide());

        self::assertNotNull($twig->getFunction('component_*'));
        self::assertNotNull($twig->getFunction('page_*'));
        self::assertNotNull($twig->getFunction('merge_resizer'));
        self::assertNotNull($twig->getFunction('placeholder'));
    }

    #[Test]
    public function registers_translation_stubs_with_wp_compatible_signatures(): void
    {
        $twig = self::twigOf(self::newStyleguide());

        // The stubs are identity functions — verify they survive the
        // optional context / domain arguments WP consumers pass through.
        $tpl = $twig->createTemplate('{{ __("hello", "domain") }}|{{ _x("text", "ctx", "domain") }}|{{ _n("one", "many", 1, "domain") }}|{{ _n("one", "many", 5, "domain") }}|{{ _nx("one", "many", 2, "ctx", "domain") }}');

        self::assertSame('hello|text|one|many|many', $tpl->render());
    }

    #[Test]
    public function registers_format_date_and_custom_price_filters(): void
    {
        $twig = self::twigOf(self::newStyleguide());

        self::assertNotNull($twig->getFilter('format_date'));
        self::assertNotNull($twig->getFilter('custom_price_format'));
        self::assertNotNull($twig->getFilter('resizer'));
    }

    #[Test]
    public function format_date_returns_original_string_on_parse_failure(): void
    {
        $twig = self::twigOf(self::newStyleguide());
        // Unparseable input must not collapse to "1. 1. 1970".
        $tpl = $twig->createTemplate('{{ "not-a-date"|format_date }}');

        self::assertSame('not-a-date', $tpl->render());
    }

    #[Test]
    public function merge_resizer_tolerates_null_args(): void
    {
        $twig = self::twigOf(self::newStyleguide());
        // Two valid lists with a null between them — old typed-variadic
        // would TypeError; the mixed-variadic + is_array filter drops it.
        $tpl = $twig->createTemplate(
            '{% set out = merge_resizer('
            . '[{src: "a.avif", media: "(min-width: 1024px)"}], '
            . 'null, '
            . '[{src: "b.avif"}]'
            . ') %}{{ out|length }}|{{ out[0].media|default("") }}|{{ out[1].src }}'
        );

        // First list contributes its media-queried entry. Second list is null (dropped).
        // Third list is the "last real" — contributes the fallback (no media).
        self::assertSame('2|(min-width: 1024px)|b.avif', $tpl->render());
    }

    #[Test]
    public function custom_price_format_emits_czk_and_eur_shapes(): void
    {
        $twig = self::twigOf(self::newStyleguide());
        $tpl = $twig->createTemplate(
            '{{ {number: 1234, currency_code: "CZK"}|custom_price_format }}'
            . '|'
            . '{{ {number: 1234.5, currency_code: "EUR"}|custom_price_format }}'
        );

        self::assertSame('1 234 Kč|€ 1 234,50', $tpl->render());
    }

    #[Test]
    public function placeholder_generate_returns_data_url(): void
    {
        $r = Placeholder::generate(['subject' => 'landscape', 'width' => 800, 'height' => 533, 'seed' => 'stable']);

        self::assertArrayHasKey(0, $r);
        self::assertSame(800, $r[0]['width']);
        self::assertSame(533, $r[0]['height']);
        self::assertSame('image/svg+xml', $r[0]['type']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $r[0]['src']);
    }

    #[Test]
    public function placeholder_generate_falls_back_to_abstract_subject(): void
    {
        // Same seed + dimensions, two different unknown subjects → both
        // should produce identical output (both fall back to subjectAbstract).
        $r1 = Placeholder::generate(['subject' => 'bogus_one', 'width' => 400, 'height' => 300, 'seed' => 'stable']);
        $r2 = Placeholder::generate(['subject' => 'bogus_two', 'width' => 400, 'height' => 300, 'seed' => 'stable']);

        self::assertSame($r1[0]['src'], $r2[0]['src']);
    }

    #[Test]
    public function placeholder_generate_is_deterministic_for_same_seed(): void
    {
        $r1 = Placeholder::generate(['subject' => 'food', 'mood' => 'warm', 'width' => 600, 'height' => 400, 'seed' => 'fixed-seed-123']);
        $r2 = Placeholder::generate(['subject' => 'food', 'mood' => 'warm', 'width' => 600, 'height' => 400, 'seed' => 'fixed-seed-123']);

        self::assertSame($r1[0]['src'], $r2[0]['src']);
    }
}
