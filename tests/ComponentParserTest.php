<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\ComponentParser;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Class-level: the \Throwable-resilience tests below use getMockBuilder()
// purely as a stub (willReturnCallback) to force a controlled exception out
// of parseTwigComment() — never asserting call counts/arguments via
// expects(). PHPUnit 12 nudges towards test stubs for that use case; opting
// out here documents the choice instead of silently swallowing the notice.
#[AllowMockObjectsWithoutExpectations]
final class ComponentParserTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->fixturesPath = __DIR__ . '/fixtures/templates';
    }

    #[Test]
    public function parses_single_component(): void
    {
        $parser = new ComponentParser($this->fixturesPath);
        $sample = $parser->parse('component', 'sample');

        self::assertNotNull($sample);
        self::assertSame('sample', $sample['id']);
        self::assertSame('Sample', $sample['name']);
        self::assertSame('Block', $sample['category']);
        self::assertSame(20, $sample['weight']);
        self::assertSame('bg-secondary-500 body-secondary', $sample['body_class']);
    }

    #[Test]
    public function body_class_defaults_to_empty_when_absent(): void
    {
        $parser = new ComponentParser($this->fixturesPath);
        $another = $parser->parse('component', 'another');

        self::assertNotNull($another);
        self::assertSame('', $another['body_class']);
    }

    #[Test]
    public function parses_all_components_sorted_by_weight(): void
    {
        $parser = new ComponentParser($this->fixturesPath);
        $components = $parser->parseAll('component');

        // Assert the FULL set in weight order rather than just a count — this
        // documents the canonical fixture roster and catches any sort
        // regression precisely. Weights: Another 10, Sample 20, Multi 30
        // (Phase-4 variant-discovery fixture), With fields 50 (no explicit
        // weight -> parser default), then the sidebar-tree cluster
        // widget-one/two/three 51/52/53, gizmo 54, and Broken Sample 999
        // (deliberately last — its Twig body throws, exercised by
        // RendererTest, but its YAML metadata is valid so ComponentParser,
        // which never renders the body, picks it up like any other
        // component).
        self::assertSame(
            ['Another', 'Sample', 'Multi', 'With fields', 'Widget - one', 'Widget - two', 'Widget - three', 'Gizmo', 'Broken Sample'],
            array_column($components, 'name'),
            'parseAll returns the full fixture set sorted by weight',
        );

        // Sort invariant, independent of the concrete roster above: weights are
        // non-decreasing across the whole result.
        $weights = array_column($components, 'weight');
        $sorted = $weights;
        sort($sorted);
        self::assertSame($sorted, $weights, 'components are returned in non-decreasing weight order');
    }

    #[Test]
    public function returns_null_for_missing_component(): void
    {
        $parser = new ComponentParser($this->fixturesPath);
        self::assertNull($parser->parse('component', 'nonexistent'));
    }

    #[Test]
    public function returns_empty_array_for_missing_directory(): void
    {
        // Use a fixtures subtree that genuinely has no page/ subdirectory;
        // tests/fixtures/templates/page/ is now populated for CLI tests.
        $parser = new ComponentParser(__DIR__ . '/fixtures/asset-server');
        self::assertSame([], $parser->parseAll('page'));
    }

    #[Test]
    public function parses_yaml_with_tabs_in_metadata(): void
    {
        $parser = new ComponentParser($this->fixturesPath);
        // YAML normally rejects tabs — parser converts them to 4 spaces first
        $result = $parser->parseTwigComment("{#\n\tname: \"Tabbed\"\n#}\n<div></div>");
        self::assertNotFalse($result);
        self::assertSame('Tabbed', $result['name']);
    }

    #[Test]
    public function normalise_render_accepts_inset(): void
    {
        self::assertSame('inset', ComponentParser::normaliseRender('inset'));
    }

    #[Test]
    public function normalise_render_accepts_bleed(): void
    {
        self::assertSame('bleed', ComponentParser::normaliseRender('bleed'));
    }

    #[Test]
    public function normalise_render_accepts_chrome(): void
    {
        self::assertSame('chrome', ComponentParser::normaliseRender('chrome'));
    }

    #[Test]
    public function normalise_render_accepts_overlay(): void
    {
        self::assertSame('overlay', ComponentParser::normaliseRender('overlay'));
    }

    #[Test]
    public function normalise_render_defaults_inset_for_null(): void
    {
        self::assertSame('inset', ComponentParser::normaliseRender(null));
    }

    #[Test]
    public function normalise_render_falls_back_to_inset_for_unknown(): void
    {
        // YAML typo or stale value — coerce silently instead of throwing.
        self::assertSame('inset', ComponentParser::normaliseRender('hero'));
    }

    #[Test]
    public function normalise_render_falls_back_to_inset_for_non_string(): void
    {
        // YAML like `render: 42` or `render: [inset]` parses to int/array;
        // the helper must not pass those through to the iframe template.
        self::assertSame('inset', ComponentParser::normaliseRender(42));
        self::assertSame('inset', ComponentParser::normaliseRender(['inset']));
    }

    #[Test]
    public function parse_emits_normalised_render_mode(): void
    {
        $parser = new ComponentParser($this->fixturesPath);
        $sample = $parser->parse('component', 'sample');
        self::assertNotNull($sample);
        self::assertSame('inset', $sample['render']);
    }

    #[Test]
    public function normalise_usage_splits_trims_and_drops_empty_tokens(): void
    {
        // Authoring convention: comma-separated, looser whitespace allowed
        // (`"404, article-list"` as well as `"404,article-list"`).
        self::assertSame(['404', 'article-list'], ComponentParser::normaliseUsage('404, article-list'));
        self::assertSame(['a', 'b'], ComponentParser::normaliseUsage('a,,b,'));
    }

    #[Test]
    public function normalise_usage_accepts_an_already_array_yaml_value(): void
    {
        // A block-list `usage:\n  - 404\n  - article-list` parses to a PHP
        // array before it ever reaches normaliseUsage() — must not be
        // punished for using YAML's native list syntax instead of a CSV.
        self::assertSame(['404', 'article-list'], ComponentParser::normaliseUsage(['404', ' article-list ']));
        self::assertSame([], ComponentParser::normaliseUsage([]));
    }

    #[Test]
    public function normalise_usage_defaults_to_empty_array_for_absent_or_non_scalar_non_array(): void
    {
        self::assertSame([], ComponentParser::normaliseUsage(null));
        self::assertSame([], ComponentParser::normaliseUsage(false));
    }

    #[Test]
    public function normalise_usage_stringifies_scalar_entries_in_an_array(): void
    {
        // A malformed YAML list item (e.g. `usage: [404, true]`) must not
        // throw — non-string entries are stringified like the CSV path
        // already stringifies the whole scalar value.
        self::assertSame(['404', '1'], ComponentParser::normaliseUsage([404, true]));
    }

    #[Test]
    public function parse_emits_usage_as_empty_array_when_absent(): void
    {
        $parser = new ComponentParser($this->fixturesPath);
        $sample = $parser->parse('component', 'sample');
        self::assertNotNull($sample);
        self::assertSame([], $sample['usage']);
    }

    #[Test]
    public function parse_emits_has_styleguide_key_not_the_old_camel_case_name(): void
    {
        $parser = new ComponentParser($this->fixturesPath);
        $multi = $parser->parse('component', 'multi');
        self::assertNotNull($multi);
        self::assertArrayHasKey('has_styleguide', $multi);
        self::assertArrayNotHasKey('hasStyleguide', $multi);
        self::assertTrue($multi['has_styleguide'], 'multi/ ships a sibling styleguide.twig');

        $another = $parser->parse('component', 'another');
        self::assertNotNull($another);
        self::assertFalse($another['has_styleguide'], 'another/ has no sibling styleguide.twig and no `styleguide:` key');
    }

    #[Test]
    public function parse_defaults_render_to_inset_when_missing(): void
    {
        // The `another` fixture has no `render:` key in its YAML.
        $parser = new ComponentParser($this->fixturesPath);
        $another = $parser->parse('component', 'another');
        self::assertNotNull($another);
        self::assertSame('inset', $another['render']);
    }

    #[Test]
    public function responsive_defaults_true(): void
    {
        $parser = new ComponentParser($this->fixturesPath);
        $meta = $parser->parse('component', 'sample');
        self::assertNotNull($meta);
        self::assertTrue($meta['responsive']);
    }

    #[Test]
    public function responsive_false_when_declared(): void
    {
        $parser = new ComponentParser($this->fixturesPath);
        $meta = $parser->parse('doc', 'sample-doc');
        self::assertNotNull($meta);
        self::assertFalse($meta['responsive']);
    }

    #[Test]
    public function parse_all_skips_a_throwing_file_and_records_a_warning(): void
    {
        $real = new ComponentParser($this->fixturesPath);
        $parser = $this->getMockBuilder(ComponentParser::class)
            ->setConstructorArgs([$this->fixturesPath])
            ->onlyMethods(['parseTwigComment'])
            ->getMock();
        // Force exactly the `sample` fixture's content to throw a generic
        // \RuntimeException (not the ParseException the old code only
        // caught); every other fixture delegates to the real parser so the
        // rest of the catalogue proves unaffected.
        $parser->method('parseTwigComment')->willReturnCallback(
            static function (string $content) use ($real) {
                if (str_contains($content, 'name: "Sample"')) {
                    throw new \RuntimeException('simulated parser fault');
                }
                return $real->parseTwigComment($content);
            },
        );

        $items = $parser->parseAll('component');

        self::assertNotContains('Sample', array_column($items, 'name'));
        self::assertContains('Another', array_column($items, 'name'));
        self::assertContains('Gizmo', array_column($items, 'name'));

        $warnings = $parser->getWarnings();
        self::assertCount(1, $warnings);
        self::assertSame('component/sample/sample.twig', $warnings[0]['file']);
        self::assertSame('simulated parser fault', $warnings[0]['error']);
    }

    #[Test]
    public function warnings_do_not_duplicate_across_repeated_calls_for_the_same_file(): void
    {
        $real = new ComponentParser($this->fixturesPath);
        $parser = $this->getMockBuilder(ComponentParser::class)
            ->setConstructorArgs([$this->fixturesPath])
            ->onlyMethods(['parseTwigComment'])
            ->getMock();
        $parser->method('parseTwigComment')->willReturnCallback(
            static function (string $content) use ($real) {
                if (str_contains($content, 'name: "Sample"')) {
                    throw new \RuntimeException('simulated parser fault');
                }
                return $real->parseTwigComment($content);
            },
        );

        $parser->parseAll('component');
        $parser->parseAll('component');

        self::assertCount(1, $parser->getWarnings());
    }

    #[Test]
    public function parse_returns_null_and_records_a_warning_for_a_throwing_file(): void
    {
        $real = new ComponentParser($this->fixturesPath);
        $parser = $this->getMockBuilder(ComponentParser::class)
            ->setConstructorArgs([$this->fixturesPath])
            ->onlyMethods(['parseTwigComment'])
            ->getMock();
        $parser->method('parseTwigComment')->willReturnCallback(
            static function (string $content) use ($real) {
                if (str_contains($content, 'name: "Sample"')) {
                    throw new \RuntimeException('simulated parser fault');
                }
                return $real->parseTwigComment($content);
            },
        );

        self::assertNull($parser->parse('component', 'sample'));
        self::assertSame('component/sample/sample.twig', $parser->getWarnings()[0]['file']);
    }

    #[Test]
    public function discovers_sibling_variant_files_ordered_by_filename(): void
    {
        $parser = new ComponentParser($this->fixturesPath);
        $multi = $parser->parse('component', 'multi');

        self::assertNotNull($multi);
        self::assertSame(
            [
                // styleguide.dark-bg.twig carries no front-comment annotation
                // and has no `variants.dark-bg` map entry either -> title
                // falls back to the id, description stays empty.
                ['id' => 'dark-bg', 'title' => 'dark-bg', 'description' => ''],
                // styleguide.secondary.twig carries its OWN {# title:
                // description: #} annotation -- the primary authoring
                // convention -- which wins over (and here is the only source
                // for, since the map no longer has a `secondary` entry) the
                // display metadata.
                ['id' => 'secondary', 'title' => 'Secondary style', 'description' => 'Tuned for a secondary-toned surface.'],
            ],
            $multi['variants'],
            'variants are ordered by id/filename (dark-bg before secondary); dark-bg has no annotation or map entry (title falls back to id), secondary is sourced entirely from its own sibling-file annotation',
        );
    }

    #[Test]
    public function variant_entries_accept_plain_string_map_shape_or_garbage_values(): void
    {
        // Isolated fixture root (not tests/fixtures/templates) so this
        // doesn't perturb the shared component catalogue other tests assert
        // against (parses_all_components_sorted_by_weight and friends). None
        // of these siblings carry their own front-comment annotation, so
        // every title/description here is sourced from the legacy
        // `variants:` map fallback -- see discovers_sibling_variant_files_ordered_by_filename
        // for the annotation-wins case.
        $parser = new ComponentParser(__DIR__ . '/fixtures/variant-shapes-templates');
        $widget = $parser->parse('component', 'widget');

        self::assertNotNull($widget);
        self::assertSame(
            [
                // Garbage (int) value -> same fallback as an absent entry:
                // title = id, description = '' -- never throws.
                ['id' => 'garbage-label', 'title' => 'garbage-label', 'description' => ''],
                // Map shape uses the legacy `label:` alias for `title:` --
                // still accepted, still resolves to the same field.
                ['id' => 'map-label', 'title' => 'Map Label', 'description' => 'Map-shape description.'],
                // Plain string (BC shape) -> the string itself is the title,
                // description stays empty.
                ['id' => 'str-label', 'title' => 'String Label', 'description' => ''],
                // Map entry carries BOTH keys -> `title:` wins, `label:` is
                // ignored (not merely a fallback for a missing `title:`).
                ['id' => 'title-and-label', 'title' => 'Title Wins', 'description' => ''],
            ],
            $widget['variants'],
        );
    }

    #[Test]
    public function variants_is_empty_array_when_no_sibling_variant_files_exist(): void
    {
        // BC proof: the pre-existing `sample` fixture has no styleguide.<variant>.twig
        // siblings and must not suddenly grow phantom variants.
        $parser = new ComponentParser($this->fixturesPath);
        $sample = $parser->parse('component', 'sample');

        self::assertNotNull($sample);
        self::assertSame([], $sample['variants']);
    }

    #[Test]
    public function yaml_variants_label_with_no_matching_file_is_ignored(): void
    {
        // Guards the "filesystem is canonical" rule: the `multi` fixture's real
        // YAML maps a `ghost` variant that has no styleguide.ghost.twig sibling
        // on disk — discovery must drop it rather than fabricate a phantom
        // switcher entry from the label map. Similarly, the on-disk
        // styleguide.foo_bar.twig sibling violates the ^[a-z0-9-]+$ id rule
        // (underscore) and must be skipped silently.
        $parser = new ComponentParser($this->fixturesPath);

        $multi = $parser->parse('component', 'multi');
        self::assertNotNull($multi);
        $ids = array_column($multi['variants'], 'id');
        self::assertNotContains('ghost', $ids, 'a YAML label for a file that does not exist must not appear');
        self::assertNotContains('foo_bar', $ids, 'a variant filename outside ^[a-z0-9-]+$ must be skipped');
        self::assertSame(['dark-bg', 'secondary'], $ids, 'only real, valid-id variant files surface, ordered by id');
    }

    #[Test]
    public function parse_all_includes_variants_field(): void
    {
        $parser = new ComponentParser($this->fixturesPath);
        $components = $parser->parseAll('component');
        $multi = current(array_filter($components, static fn(array $c): bool => $c['id'] === 'multi'));

        self::assertNotFalse($multi);
        self::assertSame(['dark-bg', 'secondary'], array_column($multi['variants'], 'id'));
    }

    #[Test]
    public function sibling_annotation_wins_over_the_legacy_map_entry(): void
    {
        // Isolated fixture root: a component whose `secondary`-equivalent
        // variant (here `title-and-label`, see the widget fixture) is
        // covered elsewhere for the map-only path; this fixture set instead
        // proves the annotation itself takes priority when BOTH a sibling
        // annotation AND a map entry exist for the same id.
        $parser = new ComponentParser($this->fixturesPath);
        $multi = $parser->parse('component', 'multi');

        self::assertNotNull($multi);
        $secondary = current(array_filter($multi['variants'], static fn(array $v): bool => $v['id'] === 'secondary'));
        self::assertNotFalse($secondary);
        self::assertSame('Secondary style', $secondary['title']);
        self::assertSame('Tuned for a secondary-toned surface.', $secondary['description']);
    }

    #[Test]
    public function a_malformed_sibling_annotation_falls_back_silently_to_the_map_and_records_no_warning(): void
    {
        // `styleguide.broken.twig`'s own front comment is deliberately
        // unparseable YAML (an unclosed quoted scalar). parseTwigComment()
        // already degrades that to `false` without throwing -- discovery
        // must fall through to the component's `variants.broken` map entry
        // exactly as if the sibling carried no comment at all, and getWarnings()
        // must stay empty: a broken variant ANNOTATION is a per-field
        // fallback, not the same "skip this file, record a warning" failure
        // mode as a broken component/page front-comment.
        $parser = new ComponentParser(__DIR__ . '/fixtures/variant-annotation-templates');
        $gadget = $parser->parse('component', 'gadget');

        self::assertNotNull($gadget);
        self::assertSame(
            [['id' => 'broken', 'title' => 'Map Fallback Title', 'description' => 'Map fallback description.']],
            $gadget['variants'],
        );
        self::assertSame([], $parser->getWarnings(), 'a malformed variant annotation must not surface as a catalogue warning');
    }
}
