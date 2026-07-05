<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\ComponentParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
        // regression precisely. Weights: Another 10, Sample 20, With fields
        // 50 (no explicit weight -> parser default), then the sidebar-tree
        // cluster widget-one/two/three 51/52/53, gizmo 54, and Broken Sample
        // 999 (deliberately last — its Twig body throws, exercised by
        // RendererTest, but its YAML metadata is valid so ComponentParser,
        // which never renders the body, picks it up like any other component).
        self::assertSame(
            ['Another', 'Sample', 'With fields', 'Widget - one', 'Widget - two', 'Widget - three', 'Gizmo', 'Broken Sample'],
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
}
