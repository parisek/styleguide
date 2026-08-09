<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Covers `renderObserved()`'s refusal when the supplied Twig environment
 * already had `component_*`/`page_*` registered before `Styleguide` was
 * constructed — the shape a WordPress theme produces because its component
 * templates need those functions independently of the styleguide package.
 *
 * An earlier revision closed this gap by reflecting into Twig's private
 * `ExtensionSet`/`StagingExtension` internals to force-win the registration
 * race regardless of order. That was rejected on review (parisek/styleguide#120):
 * it relocated the exact hack `renderObserved()` exists to eliminate — a
 * consumer no longer reaches into this package's private state, but this
 * package reached into `twig/twig`'s instead. This test file pins the
 * replacement contract instead: detect the collision via the ordinary
 * `addFunction()` return/throw (no internals reflection anywhere), and
 * REFUSE `renderObserved()` loudly rather than return an incomplete trace.
 */
final class RenderObservedCollisionTest extends TestCase
{
    private static function twigWithPreregisteredComponentFunction(): Environment
    {
        // Built the way a consumer does — see NestedRenderFailureTest::twig()
        // for the same pattern and its rationale.
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/fixtures/trace/templates');
        $twig = new Environment($loader, ['cache' => false, 'autoescape' => false]);

        // Pre-register component_* BEFORE Styleguide exists — this is the
        // exact shape a WordPress theme's own bootstrap produces.
        $twig->addFunction(new TwigFunction(
            'component_*',
            static fn(string $name, array $content = []): string => '<consumer-owned:' . $name . '>',
        ));

        return $twig;
    }

    #[Test]
    public function render_observed_refuses_when_component_star_was_preregistered(): void
    {
        $twig = self::twigWithPreregisteredComponentFunction();

        $styleguide = new Styleguide([
            'templates_path' => __DIR__ . '/fixtures/trace/templates',
            'static_path' => __DIR__ . '/fixtures',
            'config_yaml' => __DIR__ . '/fixtures/nonexistent.yaml',
            'twig' => $twig,
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/component_\*/');
        $this->expectExceptionMessageMatches('/not observable|cannot observe/i');

        $styleguide->renderObserved('component', 'simple');
    }

    #[Test]
    public function http_render_path_still_tolerates_the_consumers_preregistered_function(): void
    {
        // The constraint this whole approach exists to satisfy: run()'s HTTP
        // behaviour must not change. A consumer's pre-registered component_*
        // keeps winning the registration race and keeps rendering — exactly
        // as it did before renderObserved() existed at all. We exercise this
        // through the Twig environment directly (same technique as
        // NestedRenderFailureTest), since that's the actual mechanism run()
        // relies on: whichever component_* function is staged first is what
        // every {{ component_x(...) }} call in a real template invokes.
        $twig = self::twigWithPreregisteredComponentFunction();

        new Styleguide([
            'templates_path' => __DIR__ . '/fixtures/trace/templates',
            'static_path' => __DIR__ . '/fixtures',
            'config_yaml' => __DIR__ . '/fixtures/nonexistent.yaml',
            'twig' => $twig,
        ]);

        $html = $twig->createTemplate('{{ component_simple({}) }}')->render([]);

        self::assertSame('<consumer-owned:simple>', $html);
    }

    #[Test]
    public function render_observed_still_works_on_a_package_built_environment(): void
    {
        // No `twig` config key → the package builds its own pristine
        // environment, so there is nothing pre-registered to collide with,
        // and observation must work exactly as before this change.
        $styleguide = new Styleguide([
            'templates_path' => __DIR__ . '/fixtures/trace/templates',
            'static_path' => __DIR__ . '/fixtures',
            'config_yaml' => __DIR__ . '/fixtures/nonexistent.yaml',
        ]);

        $trace = $styleguide->renderObserved('component', 'simple');

        self::assertStringContainsString('<div class="simple">hello</div>', $trace['html']);
        self::assertCount(1, $trace['calls']);
        self::assertSame('simple', $trace['calls'][0]['component']);
    }
}
