<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\Renderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

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
        // render-cell.twig applies `|cachebust` to iframe.css / iframe.js /
        // iframe.fonts URLs (registered by Styleguide::registerBundledHelpers
        // in the real boot path; not present on this bare test env).
        // Identity-pass it through here so the template parses and the
        // existing assertions can target the unprefixed URLs.
        $twig->addFilter(new TwigFilter('cachebust', static fn(mixed $u): mixed => $u));
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

    #[Test]
    public function inset_render_keeps_wrapper_and_leaves_header_height_unchanged(): void
    {
        $html = $this->renderer->render('component', 'sample', [
            'project' => ['name' => 'TestProject'],
            'iframe' => ['css' => '/dist/style.css'],
            // No 'render' key → normaliseRender → 'inset' (default).
        ], 'cs');

        self::assertStringContainsString('<div style="padding:1.5rem">', $html);
        // Inset must not inject the bleed/chrome/overlay CSS overrides.
        self::assertStringNotContainsString('--header-height', $html);
        self::assertStringNotContainsString('min-height: 200vh', $html);
    }

    #[Test]
    public function iframe_css_accepts_an_array_of_stylesheets(): void
    {
        $html = $this->renderer->render('component', 'sample', [
            'project' => ['name' => 'TestProject'],
            'iframe' => [
                'css' => ['/dist/bundle.css', '/legacy/style.css'],
            ],
        ], 'cs');

        self::assertStringContainsString('<link rel="stylesheet" href="/dist/bundle.css">', $html);
        self::assertStringContainsString('<link rel="stylesheet" href="/legacy/style.css">', $html);

        // Links render in the order given in the array.
        $posBundle = strpos($html, '/dist/bundle.css');
        $posLegacy = strpos($html, '/legacy/style.css');
        self::assertNotFalse($posBundle);
        self::assertNotFalse($posLegacy);
        self::assertLessThan($posLegacy, $posBundle, 'iframe.css links should render in array order');
    }

    #[Test]
    public function iframe_css_accepts_a_single_string(): void
    {
        $html = $this->renderer->render('component', 'sample', [
            'project' => ['name' => 'TestProject'],
            'iframe' => ['css' => '/dist/only.css'],
        ], 'cs');

        self::assertStringContainsString('<link rel="stylesheet" href="/dist/only.css">', $html);
        self::assertSame(1, substr_count($html, 'rel="stylesheet"'));
    }

    #[Test]
    public function iframe_fonts_accepts_a_single_string(): void
    {
        $html = $this->renderer->render('component', 'sample', [
            'project' => ['name' => 'TestProject'],
            'iframe' => ['fonts' => '/fonts/single.css'],
        ], 'cs');

        self::assertStringContainsString('<link rel="stylesheet" href="/fonts/single.css">', $html);
    }

    #[Test]
    public function iframe_fonts_accepts_an_array_of_stylesheets(): void
    {
        $html = $this->renderer->render('component', 'sample', [
            'project' => ['name' => 'TestProject'],
            'iframe' => [
                'fonts' => ['/fonts/a.css', '/fonts/b.css'],
            ],
        ], 'cs');

        self::assertStringContainsString('<link rel="stylesheet" href="/fonts/a.css">', $html);
        self::assertStringContainsString('<link rel="stylesheet" href="/fonts/b.css">', $html);

        // Links render in the order given in the array.
        $posA = strpos($html, '/fonts/a.css');
        $posB = strpos($html, '/fonts/b.css');
        self::assertNotFalse($posA);
        self::assertNotFalse($posB);
        self::assertLessThan($posB, $posA, 'iframe.fonts links should render in array order');
    }

    #[Test]
    public function normalise_stylesheets_coerces_string_array_and_empty(): void
    {
        // single string → list of one
        self::assertSame(['/a.css'], Renderer::normaliseStylesheets('/a.css'));
        // list → list, order preserved
        self::assertSame(['/a.css', '/b.css'], Renderer::normaliseStylesheets(['/a.css', '/b.css']));
        // empty / missing → empty list
        self::assertSame([], Renderer::normaliseStylesheets(''));
        self::assertSame([], Renderer::normaliseStylesheets(null));
        self::assertSame([], Renderer::normaliseStylesheets([]));
        // whitespace-only is treated as empty (would otherwise render <link href="   ">)
        self::assertSame([], Renderer::normaliseStylesheets('   '));
        // non-string (incl. nested arrays), empty, and whitespace-only entries are
        // dropped; order preserved, keys reindexed
        self::assertSame(
            ['/a.css', '/b.css'],
            Renderer::normaliseStylesheets(['/a.css', '', '   ', null, 5, ['/nested.css'], '/b.css']),
        );
    }
}
