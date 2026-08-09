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
    public function sequence_bootstrap_key_fails_clearly(): void
    {
        // A YAML sequence under `bootstrap:` parses to a PHP list, which
        // is_array() alone accepts — without the array_is_list() check this
        // used to fall through and be misreported as a missing
        // 'bootstrap.templates_path' instead of the actual wrong-shape
        // problem.
        $yaml = $this->writeYaml("bootstrap:\n  - a\n  - b\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/'bootstrap' must be a mapping, got a YAML sequence/");

        Styleguide::fromYaml($yaml);
    }

    #[Test]
    public function null_bootstrap_key_fails_clearly(): void
    {
        // `bootstrap:` with no value parses to null. The old `?? []`
        // silently coerced that to an empty mapping and reported it as a
        // missing 'bootstrap.templates_path' — this asserts the key is
        // reported as the wrong shape instead, distinct from the
        // `missing_bootstrap_section_fails_clearly` case below where the
        // `bootstrap:` key is absent from the document entirely.
        $yaml = $this->writeYaml("bootstrap:\nproject:\n  name: Fixture\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/'bootstrap' must be a mapping, got null/");

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

    #[Test]
    public function templateUrl_in_bootstrap_twig_context_is_refused_not_silently_honoured(): void
    {
        // This is the reproduction for the boundary violation found in
        // review: a `templateUrl` written directly into bootstrap.twig_context
        // used to be copied wholesale and honoured, contradicting the
        // documented "run-truth can only arrive via $overrides" contract.
        mkdir($this->tempDir . '/templates');
        $yaml = $this->writeYaml(<<<YAML
        bootstrap:
          templates_path: templates
          static_path: .
          twig_context:
            templateUrl: /SMUGGLED-FROM-YAML
        YAML);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/bootstrap\.twig_context\.templateUrl/');

        Styleguide::fromYaml($yaml);
    }

    #[Test]
    public function forbidden_run_truth_keys_are_refused_not_silently_dropped(): void
    {
        mkdir($this->tempDir . '/templates');
        $yaml = $this->writeYaml(<<<YAML
        bootstrap:
          templates_path: templates
          static_path: .
          auth:
            user: sneaky
        YAML);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/bootstrap\.auth/');

        Styleguide::fromYaml($yaml);
    }

    #[Test]
    public function forbidden_twig_key_is_refused(): void
    {
        mkdir($this->tempDir . '/templates');
        $yaml = $this->writeYaml(<<<YAML
        bootstrap:
          templates_path: templates
          static_path: .
          twig: whatever
        YAML);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/bootstrap\.twig\b/');

        Styleguide::fromYaml($yaml);
    }

    #[Test]
    public function forbidden_twig_options_key_is_refused(): void
    {
        mkdir($this->tempDir . '/templates');
        $yaml = $this->writeYaml(<<<YAML
        bootstrap:
          templates_path: templates
          static_path: .
          twig_options:
            cache: /tmp/twig
        YAML);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/bootstrap\.twig_options/');

        Styleguide::fromYaml($yaml);
    }

    #[Test]
    public function forbidden_dist_path_key_is_refused(): void
    {
        mkdir($this->tempDir . '/templates');
        $yaml = $this->writeYaml(<<<YAML
        bootstrap:
          templates_path: templates
          static_path: .
          dist_path: /tmp/dist
        YAML);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/bootstrap\.dist_path/');

        Styleguide::fromYaml($yaml);
    }

    #[Test]
    public function forbidden_config_yaml_key_is_refused(): void
    {
        mkdir($this->tempDir . '/templates');
        $yaml = $this->writeYaml(<<<YAML
        bootstrap:
          templates_path: templates
          static_path: .
          config_yaml: /nonsense/path.yaml
        YAML);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/bootstrap\.config_yaml/');

        Styleguide::fromYaml($yaml);
    }

    #[Test]
    public function wrongly_typed_base_url_fails_clearly_naming_the_key(): void
    {
        mkdir($this->tempDir . '/templates');
        $yaml = $this->writeYaml(<<<YAML
        bootstrap:
          templates_path: templates
          static_path: .
          base_url: []
        YAML);

        $this->expectException(\InvalidArgumentException::class);
        // Not just that it names the key — that the message states the
        // expected AND actual type, since that wording is the specific
        // reason this loader is debuggable (a stray `[]` points straight at
        // an indentation mistake) rather than a vague "invalid" like a
        // regression could silently reduce it to.
        $this->expectExceptionMessageMatches('/bootstrap\.base_url.*must be a non-empty string, got array/');

        Styleguide::fromYaml($yaml);
    }

    #[Test]
    public function wrongly_typed_twig_context_fails_clearly_naming_the_key(): void
    {
        mkdir($this->tempDir . '/templates');
        $yaml = $this->writeYaml(<<<YAML
        bootstrap:
          templates_path: templates
          static_path: .
          twig_context: bad
        YAML);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/bootstrap\.twig_context.*must be a mapping, got string/');

        Styleguide::fromYaml($yaml);
    }

    #[Test]
    public function wrongly_typed_namespaces_entry_fails_clearly_naming_the_key(): void
    {
        mkdir($this->tempDir . '/templates');
        $yaml = $this->writeYaml(<<<YAML
        bootstrap:
          templates_path: templates
          static_path: .
          namespaces:
            icons: [not, a, string]
        YAML);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/bootstrap\.namespaces\.icons.*must be a non-empty string, got array/');

        Styleguide::fromYaml($yaml);
    }

    #[Test]
    public function an_unknown_bootstrap_key_is_ignored_not_rejected(): void
    {
        // Forward-compatibility: an unrecognised key (future schema, typo in
        // a non-forbidden name) must not hard-fail the whole load — only the
        // fixed, known-forbidden run-truth keys do. Mirrors how sync-styleguide
        // round-trips keys it doesn't own.
        mkdir($this->tempDir . '/templates');
        $yaml = $this->writeYaml(<<<YAML
        bootstrap:
          templates_path: templates
          static_path: .
          some_future_key: whatever
        YAML);

        $sg = Styleguide::fromYaml($yaml);

        self::assertIsArray($sg->inventory());
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
