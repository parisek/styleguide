<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Covers `Styleguide::fromYaml()` — the config loader from parisek/styleguide#119.
 * The array constructor's own behaviour is unchanged and stays covered by
 * StyleguideTest / the rest of the suite; this file only exercises the new
 * loader and the project-truth/run-truth boundary at `$overrides`.
 */
final class StyleguideFromYamlTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/styleguide-from-yaml-' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function writeYaml(string $contents, string $filename = 'styleguide.yaml'): string
    {
        $path = $this->tempDir . '/' . $filename;
        file_put_contents($path, $contents);
        return $path;
    }

    #[Test]
    public function the_array_constructor_stays_untouched(): void
    {
        // Same shape every other test in this suite has always used —
        // fromYaml() must be additive, never a replacement.
        $sg = new Styleguide([
            'templates_path' => __DIR__ . '/fixtures/templates',
            'static_path' => __DIR__ . '/fixtures',
            'config_yaml' => __DIR__ . '/fixtures/nonexistent.yaml',
        ]);

        self::assertIsArray($sg->inventory());
    }

    #[Test]
    public function loads_bootstrap_config_and_resolves_relative_paths_against_the_yaml_directory(): void
    {
        // templates/ sits next to the YAML file itself — resolution must be
        // relative to THIS directory, not getcwd() and not __DIR__ of the
        // test file (the temp dir is neither).
        mkdir($this->tempDir . '/templates/component/widget', 0777, true);
        file_put_contents(
            $this->tempDir . '/templates/component/widget/widget.twig',
            "{#\nname: Widget\n#}\n<div>widget</div>",
        );
        file_put_contents(
            $this->tempDir . '/templates/component/widget/styleguide.twig',
            '{{ component_widget({}) }}',
        );

        $yaml = $this->writeYaml(<<<YAML
        bootstrap:
          templates_path: templates
          static_path: .
          default_locale: cs
        project:
          name: Fixture
        YAML);

        $sg = Styleguide::fromYaml($yaml);

        $inventory = $sg->inventory();
        self::assertSame([
            ['kind' => 'component', 'slug' => 'widget', 'variant' => null],
        ], $inventory);

        $config = $this->readConfig($sg);
        self::assertSame(realpath($this->tempDir . '/templates'), $config['templates_path']);
        self::assertSame(realpath($this->tempDir), $config['static_path']);
        self::assertSame('cs', $config['default_locale']);
        self::assertSame($yaml, $config['config_yaml']);
    }

    #[Test]
    public function absolute_bootstrap_paths_are_used_as_is(): void
    {
        mkdir($this->tempDir . '/actual-templates');

        $yaml = $this->writeYaml(sprintf(<<<YAML
        bootstrap:
          templates_path: %s
          static_path: %s
        YAML, $this->tempDir . '/actual-templates', $this->tempDir));

        $sg = Styleguide::fromYaml($yaml);
        $config = $this->readConfig($sg);

        self::assertSame($this->tempDir . '/actual-templates', $config['templates_path']);
    }

    #[Test]
    public function override_wins_over_the_yaml_value(): void
    {
        mkdir($this->tempDir . '/templates');
        $yaml = $this->writeYaml(<<<YAML
        bootstrap:
          templates_path: templates
          static_path: .
          default_locale: cs
        YAML);

        $sg = Styleguide::fromYaml($yaml, ['default_locale' => 'de']);

        self::assertSame('de', $this->readConfig($sg)['default_locale']);
    }

    #[Test]
    public function override_config_yaml_is_ignored_in_favour_of_the_loaded_path(): void
    {
        mkdir($this->tempDir . '/templates');
        $yaml = $this->writeYaml(<<<YAML
        bootstrap:
          templates_path: templates
          static_path: .
        YAML);

        $sg = Styleguide::fromYaml($yaml, ['config_yaml' => '/nonsense/path.yaml']);

        self::assertSame($yaml, $this->readConfig($sg)['config_yaml']);
    }

    #[Test]
    public function twig_context_override_merges_on_top_of_the_yaml_value_instead_of_replacing_it(): void
    {
        mkdir($this->tempDir . '/templates');
        $yaml = $this->writeYaml(<<<YAML
        bootstrap:
          templates_path: templates
          static_path: .
          twig_context:
            homeUrl: /styleguide/render/
            langcode: cs
        YAML);

        $sg = Styleguide::fromYaml($yaml, [
            'twig_context' => ['templateUrl' => '/theme/static'],
        ]);

        $context = $this->readConfig($sg)['twig_context'];
        self::assertSame([
            'templateUrl' => '/theme/static',
            'homeUrl' => '/styleguide/render/',
            'langcode' => 'cs',
        ], $context);
    }

    #[Test]
    public function missing_file_fails_clearly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/config file not found/');

        Styleguide::fromYaml($this->tempDir . '/does-not-exist.yaml');
    }

    #[Test]
    public function malformed_yaml_syntax_fails_clearly(): void
    {
        $yaml = $this->writeYaml("bootstrap:\n  templates_path: [unterminated\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not valid YAML/');

        Styleguide::fromYaml($yaml);
    }

    #[Test]
    public function non_mapping_top_level_yaml_fails_clearly(): void
    {
        $yaml = $this->writeYaml("- one\n- two\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/mapping at the top level/');

        Styleguide::fromYaml($yaml);
    }

    #[Test]
    public function non_mapping_bootstrap_key_fails_clearly(): void
    {
        $yaml = $this->writeYaml("bootstrap: just-a-string\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/'bootstrap' must be a mapping/");

        Styleguide::fromYaml($yaml);
    }

    #[Test]
    public function missing_bootstrap_section_fails_clearly(): void
    {
        $yaml = $this->writeYaml("project:\n  name: Fixture\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/bootstrap.templates_path/');

        Styleguide::fromYaml($yaml);
    }

    #[Test]
    public function missing_static_path_fails_clearly(): void
    {
        $yaml = $this->writeYaml("bootstrap:\n  templates_path: templates\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/bootstrap.static_path/');

        Styleguide::fromYaml($yaml);
    }

    /**
     * @return array<string, mixed>
     */
    private function readConfig(Styleguide $sg): array
    {
        /** @var array<string, mixed> $config */
        $config = (new \ReflectionProperty(Styleguide::class, 'config'))->getValue($sg);
        return $config;
    }
}
