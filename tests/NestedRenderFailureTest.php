<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\Renderer;
use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

/**
 * `component_*()` / `page_*()` must distinguish "this template is missing"
 * from "this template is broken".
 *
 * `renderNamespaced()` used to catch `\Throwable` around both the load and the
 * render, so EVERY failure mode produced the same output: the alert component
 * saying "template not found", returned normally, and therefore HTTP 200. A
 * template with a fatal Twig syntax error was reported as missing, the real
 * parser message went only to `error_log`, and `Renderer::render()`'s 500 path
 * — whose own comment states it exists so that "a health check or CI smoke
 * test polling /render/component/<id>" cannot see success for a broken
 * component — could never fire, because the throw never reached it.
 *
 * Downstream (portadesign/tailwind-base, 2026-07) eleven templates shipped
 * that way for days: HTTP 200, no console error, no failed request, so every
 * automated check stayed green while the pages rendered none of the components
 * they named.
 *
 * These four tests pin both halves of the distinction — the fallback that must
 * be KEPT for a genuine miss, and the three throws that must now propagate.
 */
final class NestedRenderFailureTest extends TestCase
{
    private static function twig(): Environment
    {
        $sg = new Styleguide([
            'templates_path' => __DIR__ . '/fixtures/nested/templates',
            'static_path' => __DIR__ . '/fixtures',
            'config_yaml' => __DIR__ . '/fixtures/nested/styleguide.yaml',
        ]);

        return (new \ReflectionClass($sg))->getProperty('twig')->getValue($sg);
    }

    private static function render(string $source): string
    {
        return self::twig()->createTemplate($source)->render([]);
    }

    #[Test]
    public function a_genuinely_missing_component_still_falls_back_to_the_alert(): void
    {
        // The behaviour the fallback was written for, and the reason the catch
        // cannot simply be deleted: a missing template must not take the whole
        // page down, and the author needs to see WHICH one is missing.
        $html = self::render('{{ component_definitely_absent({}) }}');

        self::assertStringContainsString('not found', $html);
        self::assertStringContainsString('definitely-absent.twig', $html);
    }

    #[Test]
    public function a_component_with_a_fatal_syntax_error_propagates_instead_of_reporting_missing(): void
    {
        // The regression this change exists for. Before: this returned the
        // "not found" alert and a 200.
        $this->expectException(SyntaxError::class);
        self::render('{{ component_syntax_broken({}) }}');
    }

    #[Test]
    public function a_component_that_throws_while_rendering_propagates(): void
    {
        // Same principle one step later in the pipeline: the template compiled,
        // so it is emphatically not "missing" — it failed. Reporting that as a
        // missing file sends the author looking for a file that is right there.
        $this->expectException(RuntimeError::class);
        self::render('{{ component_runtime_broken({}) }}');
    }


    #[Test]
    public function a_missing_include_target_is_not_relabelled_as_the_host_being_missing(): void
    {
        // Twig resolves `{% include %}` at RENDER time, so this LoaderError
        // arrives after the narrowed try block. With render() still inside the
        // try it would have been caught and reported as
        // "missing-include.twig not found" — sending the author to look for a
        // file that is right there, while the actually-absent one goes unnamed.
        // This is why render() moved out, not merely why the catch narrowed.
        $this->expectException(LoaderError::class);
        self::render('{{ component_missing_include({}) }}');
    }

    #[Test]
    public function a_missing_extends_parent_is_not_relabelled_either(): void
    {
        // Same shape, one step earlier in the template lifecycle: the parent is
        // resolved during render, not during load().
        $this->expectException(LoaderError::class);
        self::render('{{ component_missing_parent({}) }}');
    }

    #[Test]
    public function the_propagated_error_reaches_the_consumer_as_http_500(): void
    {
        // The three tests above pin the exception boundary; this one pins the
        // contract consumers actually observe. It is the whole point of the
        // change — a CI smoke test polling /render/component/<id> must not see
        // 200 for a component that rendered nothing — and it is the only
        // assertion here that would still fail if Renderer::render()'s 500 path
        // were removed.
        $twig = self::twig();
        $renderer = new Renderer($twig, []);

        $html = $renderer->render('component', 'host', [
            'project' => ['name' => 'Test'],
            'iframe' => [],
        ], 'en');

        self::assertSame(500, http_response_code());
        self::assertStringContainsString('Render error:', $html);
        // Not just "an error happened": the body must NAME the template that
        // failed and carry Twig's own parser text. A generic error page would
        // satisfy the status code while leaving the author exactly as stranded
        // as the "not found" alert did.
        self::assertStringContainsString('syntax-broken.twig', $html);
        self::assertStringContainsString('at line 10', $html);
        // The real Twig message, not "not found" — the distinction this whole
        // change exists to restore.
        self::assertStringNotContainsString('not found', $html);
        http_response_code(200);
    }

    #[Test]
    public function a_healthy_component_still_renders(): void
    {
        // Guards the narrowing itself: a catch made too narrow in the wrong
        // place could break the happy path, and the three tests above would all
        // still pass.
        $html = self::render("{{ component_ok({ label: 'hello' }) }}");

        self::assertStringContainsString('class="ok"', $html);
        self::assertStringContainsString('hello', $html);
    }
}
