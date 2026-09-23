<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Twig;

use Parisek\Styleguide\Styleguide;
use Parisek\Styleguide\Twig\StyleguideRuntime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How long the runtime holds on to a `Renderer`.
 *
 * `styleguide_data()` is only answerable while a fixture is rendering, and the
 * runtime learns which `Renderer` to ask by being told, around each render, in
 * `Renderer::renderInner()`.
 *
 * **Where the guarantee actually comes from.** A mutation test settled this:
 * deleting the `setRenderer()` stand-down changes nothing observable, because
 * `Renderer::resolveStyleguideData()` refuses on its own once `$currentKind` is
 * back to `null` — and that restore is in the pre-existing `finally`, older
 * than any of this work. Correctness against a stale render context was never
 * the stand-down's job.
 *
 * What the stand-down does do is release the reference, so a runtime does not
 * hold a finished `Renderer` alive between requests in a long-running worker.
 * That is a retention question, not a correctness one, and it is asserted
 * separately below rather than bundled into an assertion it does not drive.
 */
final class RuntimeRenderLifetimeTest extends TestCase
{
    private function styleguide(): Styleguide
    {
        return new Styleguide([
            'templates_path' => __DIR__ . '/../fixtures/templates',
            'static_path' => __DIR__ . '/../fixtures',
            'config_yaml' => __DIR__ . '/../fixtures/styleguide.yaml',
        ]);
    }

    private function runtimeOf(Styleguide $sg): StyleguideRuntime
    {
        return (new \ReflectionClass($sg))->getProperty('twigRuntime')->getValue($sg);
    }

    #[Test]
    public function the_runtime_holds_no_renderer_before_anything_renders(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no active render context');
        $this->runtimeOf($this->styleguide())->styleguideData();
    }

    #[Test]
    public function a_fixture_resolves_its_own_sidecar_during_the_render(): void
    {
        // component/data-demo/styleguide.twig is a bare
        // `{{ styleguide_data()|json_encode|raw }}`, so a successful render is
        // itself the proof that the runtime was told who to ask.
        $trace = $this->styleguide()->renderObserved('component', 'data-demo');

        self::assertStringContainsString('Demo Title', $trace['html']);
    }

    #[Test]
    public function calling_it_after_the_render_is_refused(): void
    {
        $sg = $this->styleguide();
        $runtime = $this->runtimeOf($sg);

        $sg->renderObserved('component', 'data-demo');

        // Note what this does and does not prove. It passes even with the
        // stand-down deleted, because `Renderer::resolveStyleguideData()`
        // refuses once `$currentKind` is restored. Worth keeping as the
        // end-to-end property; not evidence for the line below.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no active render context');
        $runtime->styleguideData();
    }

    #[Test]
    public function the_runtime_releases_the_renderer_once_the_render_finishes(): void
    {
        // This is the one that tests the pop, and it needs reflection to see
        // it: the effect is a dropped reference, not a changed answer. Without
        // it the runtime holds a finished Renderer alive until the next render
        // replaces it — harmless under one-request-per-process, wasteful in a
        // worker.
        $sg = $this->styleguide();
        $runtime = $this->runtimeOf($sg);

        $sg->renderObserved('component', 'data-demo');

        self::assertSame(
            [],
            (new \ReflectionClass($runtime))->getProperty('renderers')->getValue($runtime),
        );
    }

    #[Test]
    public function an_inner_render_returning_leaves_the_outer_fixture_resolvable(): void
    {
        // The case the ternary in Renderer's finally exists for, and the one
        // the sequential test above cannot see. `renderObserved()` is
        // re-entrant by design: an outer fixture may trigger another render,
        // and when that inner render returns, the outer template keeps
        // executing and may call `styleguide_data()` again.
        //
        // A flat `setRenderer(null)` in the finally would stand the runtime
        // down when the INNER render finishes, while the outer one is still
        // live — and the outer template's next call would refuse. Only
        // `$previousKind === null ? null : $this` releases on the outermost
        // return and holds otherwise.
        //
        // The fixture is `component/data-nested`, whose styleguide.twig reads
        // its own sidecar, triggers an inner render, then reads its sidecar
        // again. `nested_render()` has to come from a consumer-supplied
        // environment, because the package's pristine one knows nothing about
        // this test.
        $holder = new \stdClass();
        $holder->sg = null;

        $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader([]));
        $twig->addFunction(new \Twig\TwigFunction(
            'nested_render',
            static function () use ($holder): string {
                return $holder->sg->renderObserved('component', 'data-demo')['html'];
            },
            ['is_safe' => ['html']],
        ));

        $holder->sg = new Styleguide([
            'templates_path' => __DIR__ . '/../fixtures/templates',
            'static_path' => __DIR__ . '/../fixtures',
            'config_yaml' => __DIR__ . '/../fixtures/styleguide.yaml',
            'twig' => $twig,
        ]);

        $html = $holder->sg->renderObserved('component', 'data-nested')['html'];

        self::assertStringContainsString('before:Outer fixture', $html);
        self::assertStringContainsString('Demo Title', $html, 'the inner render produced nothing');
        self::assertStringContainsString(
            'after:Outer fixture',
            $html,
            'the outer fixture became unresolvable once the inner render returned',
        );
    }

    #[Test]
    public function two_renders_in_one_process_each_resolve_their_own_sidecar(): void
    {
        // The cross-request case, collapsed into one process: if the first
        // render's context survived, the second would answer with the first
        // fixture's data instead of its own.
        $sg = $this->styleguide();

        $first = $sg->renderObserved('component', 'data-demo');
        $second = $sg->renderObserved('component', 'data-demo-2');

        self::assertStringContainsString('Demo Title', $first['html']);
        self::assertStringNotContainsString('Demo Title', $second['html']);
    }
}
