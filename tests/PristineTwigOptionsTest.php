<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Extension\EscaperExtension;

/**
 * Covers the three pristine-env options (`cache`, `debug`, `autoescape`):
 * the package's own defaults when `twig_options` is omitted, plus the
 * override semantics when the consumer supplies a subset of options.
 *
 * Behavior is asserted observably via the env's public API —
 * `EscaperExtension::getDefaultStrategy()` for autoescape, `isDebug()` /
 * `getCache()` for the other two — rather than reflecting into Environment
 * internals, so the test survives Twig version bumps that re-shape the
 * option storage. `createTemplate()` is unsuitable for autoescape because
 * Twig's html strategy is bound to filename extensions and inline string
 * templates have none.
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

        // Styleguide doesn't expose the pristine env publicly — production
        // consumers don't need it. Tests reach in via reflection so they can
        // observe the constructed Environment without forcing a public getter
        // that would widen the package's surface for no other caller.
        $ref = new \ReflectionProperty(Styleguide::class, 'twig');
        $twig = $ref->getValue($styleguide);
        self::assertInstanceOf(Environment::class, $twig);
        return $twig;
    }

    /**
     * Resolve the env's autoescape default strategy through the public
     * EscaperExtension API. Called with a filename that carries the `.html`
     * suffix so the `name`-based strategy (filename-driven) would also
     * resolve to `html` if anyone ever switched the package to that — keeps
     * the assertion semantics stable across both fixed-string and
     * filename-driven strategies.
     */
    private function autoescapeStrategy(Environment $twig): string|false|callable
    {
        return $twig->getExtension(EscaperExtension::class)->getDefaultStrategy('test.html.twig');
    }
}
