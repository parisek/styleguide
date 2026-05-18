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
    }

    #[Test]
    public function parses_all_components_sorted_by_weight(): void
    {
        $parser = new ComponentParser($this->fixturesPath);
        $components = $parser->parseAll('component');

        self::assertCount(2, $components);
        self::assertSame('Another', $components[0]['name'], 'weight 10 comes first');
        self::assertSame('Sample', $components[1]['name'], 'weight 20 comes second');
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
        $parser = new ComponentParser($this->fixturesPath);
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
}
