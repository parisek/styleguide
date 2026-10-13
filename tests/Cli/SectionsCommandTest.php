<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Cli;

use Parisek\Styleguide\Cli\Command;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `styleguide list --sections` and `show <id>` name the section of a
 * component and the `components.sections` entry that took it. They use the
 * same resolver as the catalogue.
 */
final class SectionsCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sg-sections-cli-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function config(string $components): string
    {
        $path = $this->dir . '/styleguide.yaml';
        file_put_contents($path, "project:\n  name: Sections CLI\n" . $components);

        return $path;
    }

    private const SECTIONS = <<<YAML
        components:
          sections:
            - id: pinned
              label: Pinned
              component: [sample]
            - id: rest
              label: Rest
              fallback: true
        YAML;

    /**
     * @param array<int,string> $argv
     * @return array{0:int, 1:string, 2:string}
     */
    private function runCli(array $argv): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $exit = (new Command())->run($argv, $stdout, $stderr);
        rewind($stdout);
        rewind($stderr);

        return [$exit, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function list(string $config, string ...$extra): array
    {
        [$exit, $stdout, $stderr] = $this->runCli([
            'list',
            '--templates=' . __DIR__ . '/../fixtures/templates',
            '--config=' . $config,
            ...$extra,
        ]);
        self::assertSame(0, $exit, $stderr);

        return (array) json_decode(trim($stdout), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private static function byId(array $items, string $id): array
    {
        foreach ($items as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }
        self::fail("no component $id");
    }

    #[Test]
    public function list_sections_names_the_section_the_entry_and_the_tier(): void
    {
        $items = $this->list($this->config(self::SECTIONS), '--sections');

        $sample = self::byId($items, 'sample');
        self::assertSame('pinned', $sample['section']);
        self::assertSame(0, $sample['section_entry']);
        self::assertSame('component', $sample['section_tier']);
        self::assertFalse($sample['section_hidden']);

        $another = self::byId($items, 'another');
        self::assertSame('rest', $another['section']);
        self::assertSame(1, $another['section_entry']);
        self::assertSame('fallback', $another['section_tier']);
    }

    #[Test]
    public function a_component_in_the_implicit_section_has_no_entry(): void
    {
        $config = $this->config("components:\n  sections:\n    - id: pinned\n      label: Pinned\n      component: [sample]\n");
        $another = self::byId($this->list($config, '--sections'), 'another');

        self::assertSame('other', $another['section']);
        self::assertNull($another['section_entry']);
        self::assertSame('implicit', $another['section_tier']);
    }

    #[Test]
    public function a_hidden_section_is_marked(): void
    {
        $config = $this->config("components:\n  sections:\n    - id: pinned\n      component: [sample]\n      hidden: true\n");

        self::assertTrue(self::byId($this->list($config, '--sections'), 'sample')['section_hidden']);
    }

    #[Test]
    public function list_without_the_flag_keeps_its_shape(): void
    {
        $items = $this->list($this->config(self::SECTIONS));

        self::assertArrayNotHasKey('section', $items[0]);
        self::assertArrayNotHasKey('section_entry', $items[0]);
    }

    #[Test]
    public function show_adds_the_section_when_the_config_has_the_key(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli([
            'show',
            'sample',
            '--templates=' . __DIR__ . '/../fixtures/templates',
            '--config=' . $this->config(self::SECTIONS),
        ]);

        self::assertSame(0, $exit, $stderr);
        $item = (array) json_decode(trim($stdout), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('pinned', $item['section']);
        self::assertSame('component', $item['section_tier']);
    }

    #[Test]
    public function the_flag_without_the_key_is_an_error(): void
    {
        [$exit, , $stderr] = $this->runCli([
            'list',
            '--sections',
            '--templates=' . __DIR__ . '/../fixtures/templates',
            '--config=' . $this->config("components:\n  group_by: kind\n"),
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('No `components.sections`', $stderr);
    }

    #[Test]
    public function an_invalid_sections_block_is_an_error_with_the_flag(): void
    {
        [$exit, , $stderr] = $this->runCli([
            'list',
            '--sections',
            '--templates=' . __DIR__ . '/../fixtures/templates',
            '--config=' . $this->config("components:\n  sections:\n    - kind: nope\n"),
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('components.sections[0]', $stderr);
    }

    #[Test]
    public function the_flag_applies_to_components_only(): void
    {
        [$exit, , $stderr] = $this->runCli([
            'list',
            '--type=page',
            '--sections',
            '--templates=' . __DIR__ . '/../fixtures/templates',
            '--config=' . $this->config(self::SECTIONS),
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('components only', $stderr);
    }
}
