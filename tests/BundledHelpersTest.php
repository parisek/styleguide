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
    public function registers_bundled_extensions_including_dump(): void
    {
        $twig = self::twigOf(self::newStyleguide());

        // The five Twig extensions that every consuming project previously
        // had to register itself, plus DumpExtension (added in 0.2.0 — same
        // rationale as the others: enables `{{ dump(var) }}` in templates,
        // production leak risk caught by the DumpRule twig-cs-fixer lint
        // instead of by withholding the extension).
        self::assertTrue($twig->hasExtension(\Twig\Extra\Intl\IntlExtension::class));
        self::assertTrue($twig->hasExtension(\Twig\Extra\String\StringExtension::class));
        self::assertTrue($twig->hasExtension(\Parisek\Twig\CommonExtension::class));
        self::assertTrue($twig->hasExtension(\Parisek\Twig\AttributeExtension::class));
        self::assertTrue($twig->hasExtension(\Parisek\Twig\TypographyExtension::class));
        self::assertTrue($twig->hasExtension(\Symfony\Bridge\Twig\Extension\DumpExtension::class));
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

    /**
     * Direct access to the private `classifyAspect()` helper. The helper is
     * private on purpose (consumers should use the Twig filter), but covering
     * the classification logic through Twig alone would require ~9 separate
     * placeholder() fixtures per assertion. Reflection lets the unit tests
     * exercise the band boundaries directly and keeps the integration tests
     * below focused on filter dispatch.
     */
    private static function classifyAspect(array $image, ?float $tolerance = null): string
    {
        $m = (new \ReflectionClass(Styleguide::class))->getMethod('classifyAspect');
        return $m->invoke(null, $image, $tolerance ?? 0.1);
    }

    #[Test]
    public function classify_aspect_picks_landscape_portrait_square(): void
    {
        // Landscape: aspect = 1.5 → > 1 + outside ±0.1 band → landscape
        self::assertSame('landscape', self::classifyAspect([['width' => 1200, 'height' => 800]]));
        // Portrait: aspect ≈ 0.67 → < 1 + outside band → portrait
        self::assertSame('portrait', self::classifyAspect([['width' => 800, 'height' => 1200]]));
        // Exactly 1:1
        self::assertSame('square', self::classifyAspect([['width' => 1000, 'height' => 1000]]));
    }

    #[Test]
    public function classify_aspect_square_band_boundaries(): void
    {
        // ±0.1 around 1.0 means: aspect ∈ [0.9, 1.1] is square. Anything
        // outside classifies as landscape (> 1.1) or portrait (< 0.9). The
        // boundary itself is inclusive — see classifyAspect's cross-mult
        // comment for why this needs care under IEEE 754.

        // Exact upper edge: 1100×1000 → aspect = 1.1 → square (boundary inclusive)
        self::assertSame('square', self::classifyAspect([['width' => 1100, 'height' => 1000]]));
        // Just-outside upper edge: 1101×1000 → aspect 1.101 → landscape
        self::assertSame('landscape', self::classifyAspect([['width' => 1101, 'height' => 1000]]));
        // Exact lower edge: 900×1000 → aspect = 0.9 → square (boundary inclusive)
        self::assertSame('square', self::classifyAspect([['width' => 900, 'height' => 1000]]));
        // Just-outside lower edge: 899×1000 → aspect 0.899 → portrait
        self::assertSame('portrait', self::classifyAspect([['width' => 899, 'height' => 1000]]));
    }

    #[Test]
    public function classify_aspect_tolerance_override_tightens_and_loosens_band(): void
    {
        // Tighten to 0 → only exact 1:1 counts as square.
        self::assertSame('landscape', self::classifyAspect([['width' => 1001, 'height' => 1000]], 0.0));
        self::assertSame('square',    self::classifyAspect([['width' => 1000, 'height' => 1000]], 0.0));
        // Loosen to 0.5 → 1.5 / 1 aspect is still square.
        self::assertSame('square', self::classifyAspect([['width' => 1500, 'height' => 1000]], 0.5));
    }

    #[Test]
    public function classify_aspect_falls_back_to_landscape_for_invalid_metadata(): void
    {
        // Missing first entry
        self::assertSame('landscape', self::classifyAspect([]));
        // First entry not an array
        self::assertSame('landscape', self::classifyAspect(['not-an-array']));
        // Missing width / height keys
        self::assertSame('landscape', self::classifyAspect([['src' => 'x.svg']]));
        // Zero dimensions
        self::assertSame('landscape', self::classifyAspect([['width' => 0, 'height' => 0]]));
        // Negative dimensions (corrupt metadata)
        self::assertSame('landscape', self::classifyAspect([['width' => -100, 'height' => 50]]));
        // Non-numeric strings (SVG without intrinsic px dimensions)
        self::assertSame('landscape', self::classifyAspect([['width' => 'auto', 'height' => 'auto']]));
    }

    #[Test]
    public function resizer_dispatches_orientation_map_to_matching_bucket(): void
    {
        $twig = self::twigOf(self::newStyleguide());

        // Landscape source (1200 × 800 placeholder) + orientation-keyed map
        // as the filter's single arg → resizer picks the `landscape` tuples
        // (one breakpoint variant 960 × 720 + a 480 × 360 fallback). The
        // shape carries the landscape dimensions, distinct from the
        // portrait/square alternatives.
        $tpl = $twig->createTemplate(
            "{% set src = placeholder({width: 1200, height: 800, seed: 'r'}) %}"
            . "{% set out = src|resizer({"
            . "  landscape: [['960', '720', '1024', 'crop'], ['480', '360', '', 'crop']],"
            . "  portrait:  [['720', '960', '1024', 'crop'], ['360', '480', '', 'crop']],"
            . "  square:    [['800', '800', '1024', 'crop'], ['400', '400', '', 'crop']],"
            . "}) %}"
            . '{{ out|length }}|{{ out[0].width }}x{{ out[0].height }}|{{ out[1].width }}x{{ out[1].height }}'
        );

        self::assertSame('2|960x720|480x360', $tpl->render());
    }

    #[Test]
    public function resizer_picks_portrait_bucket_for_tall_sources(): void
    {
        $twig = self::twigOf(self::newStyleguide());

        $tpl = $twig->createTemplate(
            "{% set src = placeholder({width: 800, height: 1200, seed: 'r'}) %}"
            . "{% set out = src|resizer({"
            . "  landscape: [['960', '720', '', 'crop']],"
            . "  portrait:  [['720', '960', '', 'crop']],"
            . "}) %}"
            . '{{ out[0].width }}x{{ out[0].height }}'
        );

        self::assertSame('720x960', $tpl->render());
    }

    #[Test]
    public function resizer_picks_square_bucket_inside_tolerance(): void
    {
        $twig = self::twigOf(self::newStyleguide());

        $tpl = $twig->createTemplate(
            "{% set src = placeholder({width: 1000, height: 1000, seed: 'r'}) %}"
            . "{% set out = src|resizer({"
            . "  landscape: [['960', '720', '', 'crop']],"
            . "  square:    [['800', '800', '', 'crop']],"
            . "}) %}"
            . '{{ out[0].width }}x{{ out[0].height }}'
        );

        self::assertSame('800x800', $tpl->render());
    }

    #[Test]
    public function resizer_falls_back_to_landscape_bucket_when_match_missing(): void
    {
        $twig = self::twigOf(self::newStyleguide());

        // Square source but only landscape tuples provided → should use
        // landscape (documented fallback semantics).
        $tpl = $twig->createTemplate(
            "{% set src = placeholder({width: 1000, height: 1000, seed: 'r'}) %}"
            . "{% set out = src|resizer({"
            . "  landscape: [['640', '480', '', 'crop']],"
            . "}) %}"
            . '{{ out[0].width }}x{{ out[0].height }}'
        );

        self::assertSame('640x480', $tpl->render());
    }

    #[Test]
    public function resizer_falls_back_to_landscape_when_matched_bucket_is_empty(): void
    {
        $twig = self::twigOf(self::newStyleguide());

        // Square source + empty `square` bucket + landscape tuples present
        // → empty matched bucket should still trigger the landscape fallback
        // (a `?? landscape` chain on null-coalescing alone would NOT, since
        // `square => []` is a defined-but-empty value).
        $tpl = $twig->createTemplate(
            "{% set src = placeholder({width: 1000, height: 1000, seed: 'r'}) %}"
            . "{% set out = src|resizer({"
            . "  landscape: [['640', '480', '', 'crop']],"
            . "  square:    [],"
            . "}) %}"
            . '{{ out[0].width }}x{{ out[0].height }}'
        );

        self::assertSame('640x480', $tpl->render());
    }

    #[Test]
    public function resizer_passes_through_when_orientation_map_has_no_tuples(): void
    {
        $twig = self::twigOf(self::newStyleguide());

        // Map carries a recognised key (`landscape`) so the detector DOES
        // enter orientation mode — but every bucket is empty, including the
        // `landscape` fallback. With no tuples available anywhere, the
        // filter returns the source unchanged rather than inventing a
        // variant. Asserting the source's own dimensions makes an
        // accidental transform visible in the output.
        $tpl = $twig->createTemplate(
            "{% set src = placeholder({width: 1200, height: 800, seed: 'r'}) %}"
            . "{% set out = src|resizer({landscape: []}) %}"
            . '{{ out[0].width }}x{{ out[0].height }}'
        );

        self::assertSame('1200x800', $tpl->render());
    }

    #[Test]
    public function resizer_passes_through_matched_empty_bucket_without_landscape_fallback(): void
    {
        $twig = self::twigOf(self::newStyleguide());

        // Square source + empty `square` bucket + no `landscape` fallback →
        // passthrough (the helper refuses to invent a variant).
        $tpl = $twig->createTemplate(
            "{% set src = placeholder({width: 1000, height: 1000, seed: 'r'}) %}"
            . "{% set out = src|resizer({square: []}) %}"
            . '{{ out[0].width }}x{{ out[0].height }}'
        );

        self::assertSame('1000x1000', $tpl->render());
    }

    #[Test]
    public function resizer_tuples_mode_unchanged_by_orientation_detection(): void
    {
        $twig = self::twigOf(self::newStyleguide());

        // Historical tuples shape — variadic tuples after the source. The
        // orientation detector requires (a) single arg AND (b) at least one
        // recognised key, so positional tuples (integer keys, no landscape /
        // portrait / square) flow into the tuples branch unchanged.
        $tpl = $twig->createTemplate(
            "{% set src = placeholder({width: 1200, height: 800, seed: 'r'}) %}"
            . "{% set out = src|resizer(['960', '720', '1280', 'crop'], ['480', '360', '', 'crop']) %}"
            . '{{ out|length }}|{{ out[0].width }}x{{ out[0].height }}|{{ out[1].width }}x{{ out[1].height }}'
        );

        self::assertSame('2|960x720|480x360', $tpl->render());
    }
}
