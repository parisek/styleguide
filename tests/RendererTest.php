<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\Renderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class RendererTest extends TestCase
{
    private Renderer $renderer;

    protected function setUp(): void
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/../templates'); // package templates (render-cell, 404)
        $loader->addPath(__DIR__ . '/fixtures/templates/component', 'project'); // ...wait, see below

        // The @project namespace needs to point at the templates root (not the component subdir)
        // so that '@project/component/sample/sample.twig' resolves.
        $loader2 = new FilesystemLoader();
        $loader2->addPath(__DIR__ . '/../templates');
        $loader2->addPath(__DIR__ . '/fixtures/templates', 'project');

        $twig = new Environment($loader2, ['cache' => false]);
        $this->renderer = new Renderer($twig, ['content' => ['title' => 'Hello']]);
    }

    #[Test]
    public function renders_component_with_iframe_chrome(): void
    {
        $html = $this->renderer->render('component', 'sample', [
            'project' => ['name' => 'TestProject', 'favicon' => '/favicon.svg'],
            'iframe' => [
                'css' => '/dist/style.css',
                'js' => '/dist/script.js',
                'fonts' => ['/fonts/stylesheet.css'],
            ],
        ], 'cs');

        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('lang="cs"', $html);
        self::assertStringContainsString('<title>sample — TestProject</title>', $html);
        self::assertStringContainsString('<link rel="icon" href="/favicon.svg">', $html);
        self::assertStringContainsString('<link rel="stylesheet" href="/dist/style.css">', $html);
        self::assertStringContainsString('<link rel="stylesheet" href="/fonts/stylesheet.css">', $html);
        // Project JS is built as an ES module by Vite (top-level `export`/`import`),
        // so the iframe loads it with `type="module"` — `defer` is implicit for modules.
        self::assertStringContainsString('<script type="module" src="/dist/script.js"></script>', $html);
        // Component body rendered inline (from sample.twig + context)
        self::assertStringContainsString('<div class="sample">Hello</div>', $html);
        // Components render inside a padded wrapper so short bodies don't sit flush
        // against the iframe's top edge underneath the styleguide chrome.
        self::assertStringContainsString('<div style="padding:1.5rem">', $html);
    }

    #[Test]
    public function renders_404_for_missing_component(): void
    {
        $html = $this->renderer->render('component', 'nonexistent', [
            'project' => ['name' => 'TestProject'],
            'iframe' => [],
        ], 'en');

        self::assertSame(404, http_response_code());
        self::assertStringContainsString('404', $html);
        self::assertStringContainsString('component/nonexistent', $html);
        http_response_code(200);
    }

    #[Test]
    public function renders_404_for_invalid_kind(): void
    {
        $html = $this->renderer->render('invalid', 'whatever', [
            'project' => ['name' => 'TestProject'],
            'iframe' => [],
        ], 'en');

        self::assertSame(404, http_response_code());
        self::assertStringContainsString('invalid/whatever', $html);
        http_response_code(200);
    }

    #[Test]
    public function bleed_render_drops_inset_wrapper_and_resets_header_height(): void
    {
        $html = $this->renderer->render('component', 'sample', [
            'project' => ['name' => 'TestProject'],
            'iframe' => ['css' => '/dist/style.css'],
            'render' => 'bleed',
        ], 'cs');

        // No inset wrapper — the component renders edge-to-edge.
        self::assertStringNotContainsString('<div style="padding:1.5rem">', $html);
        // --header-height is reset so consumer hacks like
        // `margin-top: var(--header-height, 75px) * -1` collapse to 0 in
        // styleguide isolation (no sticky chrome above to hide behind).
        self::assertStringContainsString('--header-height: 0px', $html);
        // Bleed leaves body min-height alone.
        self::assertStringNotContainsString('min-height: 200vh', $html);
    }

    #[Test]
    public function chrome_render_adds_body_min_height_for_sticky_demos(): void
    {
        $html = $this->renderer->render('component', 'sample', [
            'project' => ['name' => 'TestProject'],
            'iframe' => ['css' => '/dist/style.css'],
            'render' => 'chrome',
        ], 'cs');

        self::assertStringNotContainsString('<div style="padding:1.5rem">', $html);
        self::assertStringContainsString('--header-height: 0px', $html);
        // 200vh on body gives sticky / fixed page chrome something to scroll
        // against so the sticky behaviour is demonstrable in isolation.
        self::assertStringContainsString('min-height: 200vh', $html);
    }

    #[Test]
    public function overlay_render_matches_bleed_iframe_shape(): void
    {
        $bleedHtml = $this->renderer->render('component', 'sample', [
            'project' => ['name' => 'TestProject'],
            'iframe' => ['css' => '/dist/style.css'],
            'render' => 'bleed',
        ], 'cs');

        $overlayHtml = $this->renderer->render('component', 'sample', [
            'project' => ['name' => 'TestProject'],
            'iframe' => ['css' => '/dist/style.css'],
            'render' => 'overlay',
        ], 'cs');

        // overlay ≡ bleed at the iframe-wrapper level (see spec § Mode semantics).
        // The separate label exists for future UI surfacing; both modes must emit
        // identical render-cell output today.
        self::assertSame($bleedHtml, $overlayHtml);
    }
}
