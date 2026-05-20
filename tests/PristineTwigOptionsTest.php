<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Extension\EscaperExtension;

/**
 * Pristine-env defaults + override semantics for `cache` / `debug` /
 * `autoescape`. Assertions go through the env's public API
 * (`EscaperExtension::getDefaultStrategy()`, `isDebug()`, `getCache()`)
 * rather than reflecting into internals, so the suite survives Twig
 * version bumps that re-shape option storage.
 */
final class PristineTwigOptionsTest extends TestCase
{
    private string $templatesPath;
    private string $missingYaml;

    protected function setUp(): void
    {
        $this->templatesPath = __DIR__ . '/fixtures/templates';
        $this->missingYaml = __DIR__ . '/fixtures/nonexistent.yaml';
    }

    #[Test]
    public function pristine_env_uses_package_defaults(): void
    {
        $twig = $this->buildPristineTwig([]);

        self::assertFalse($this->autoescapeStrategy($twig));
        self::assertTrue($twig->isDebug());
        self::assertFalse($twig->getCache());
    }

    #[Test]
    public function consumer_can_override_autoescape(): void
    {
        $twig = $this->buildPristineTwig(['autoescape' => 'html']);

        self::assertSame('html', $this->autoescapeStrategy($twig));
    }

    #[Test]
    public function consumer_can_override_debug(): void
    {
        $twig = $this->buildPristineTwig(['debug' => false]);

        self::assertFalse($twig->isDebug());
    }

    #[Test]
    public function consumer_can_override_cache_to_string_path(): void
    {
        $cacheDir = sys_get_temp_dir() . '/styleguide-test-cache-' . uniqid();
        $twig = $this->buildPristineTwig(['cache' => $cacheDir]);

        // Twig wraps a string path into FilesystemCache; just confirm the
        // cache flipped off the default `false` rather than asserting any
        // concrete return type (which has drifted across Twig versions).
        self::assertNotFalse($twig->getCache());
    }

    #[Test]
    public function override_subset_keeps_other_defaults(): void
    {
        $twig = $this->buildPristineTwig(['autoescape' => 'html']);

        self::assertTrue($twig->isDebug(), 'debug default must survive a partial override');
        self::assertFalse($twig->getCache(), 'cache default must survive a partial override');
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function buildPristineTwig(array $overrides): Environment
    {
        $styleguide = new Styleguide([
            'templates_path' => $this->templatesPath,
            'static_path' => __DIR__ . '/fixtures',
            'config_yaml' => $this->missingYaml,
            'twig_options' => $overrides,
        ]);

        // No public getter for the pristine env — production consumers don't
        // need it, so the test reaches in via reflection rather than widening
        // the package surface for a single caller.
        $ref = new \ReflectionProperty(Styleguide::class, 'twig');
        $twig = $ref->getValue($styleguide);
        self::assertInstanceOf(Environment::class, $twig);
        return $twig;
    }

    /**
     * Filename ends in `.html.twig` so the `name`-based strategy would also
     * resolve to `html` — assertion semantics survive a future switch away
     * from the fixed-string strategy.
     */
    private function autoescapeStrategy(Environment $twig): string|false|callable
    {
        return $twig->getExtension(EscaperExtension::class)->getDefaultStrategy('test.html.twig');
    }
}
