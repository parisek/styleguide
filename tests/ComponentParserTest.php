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
        // widget-one/two/three 51/52/53, gizmo 54, Broken Sample 999
        // (deliberately near-last — its Twig body throws, exercised by
        // RendererTest, but its YAML metadata is valid so ComponentParser,
        // which never renders the body, picks it up like any other
        // component), and A11y Demo 1000 (Phase 4 Task 6 fixture — a bare
        // <img> with no alt, deliberately last, used by the on-demand a11y
        // check's Playwright spec).
        self::assertSame(
            ['Another', 'Sample', 'Multi', 'With fields', 'Widget - one', 'Widget - two', 'Widget - three', 'Gizmo', 'Broken Sample', 'A11y Demo'],
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
                // No YAML `variants.dark-bg` entry at all -> label falls back
                // to the id, description stays empty.
                ['id' => 'dark-bg', 'label' => 'dark-bg', 'description' => ''],
                // YAML entry is the {label, description} map shape.
                ['id' => 'secondary', 'label' => 'Secondary style', 'description' => 'Tuned for a secondary-toned surface.'],
            ],
            $multi['variants'],
            'variants are ordered by id/filename (dark-bg before secondary); dark-bg has no YAML entry (label falls back to id), secondary uses the {label, description} map shape',
        );
    }

    #[Test]
    public function variant_entries_accept_plain_string_map_shape_or_garbage_values(): void
    {
        // Isolated fixture root (not tests/fixtures/templates) so this
        // doesn't perturb the shared component catalogue other tests assert
        // against (parses_all_components_sorted_by_weight and friends).
        $parser = new ComponentParser(__DIR__ . '/fixtures/variant-shapes-templates');
        $widget = $parser->parse('component', 'widget');

        self::assertNotNull($widget);
        self::assertSame(
            [
                // Garbage (int) value -> same fallback as an absent entry:
                // label = id, description = '' -- never throws.
                ['id' => 'garbage-label', 'label' => 'garbage-label', 'description' => ''],
                ['id' => 'map-label', 'label' => 'Map Label', 'description' => 'Map-shape description.'],
                // Plain string (BC shape) -> the string itself is the label,
                // description stays empty.
                ['id' => 'str-label', 'label' => 'String Label', 'description' => ''],
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
}
