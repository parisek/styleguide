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
        // render-cell.twig builds the <body> class via create_attribute() (same
        // helper foundations.twig uses) — register the extension so it parses.
        $twig->addExtension(new \Parisek\Twig\AttributeExtension());
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
    public function merges_per_page_body_class_after_global_iframe_body_class(): void
    {
        $html = $this->renderer->render('component', 'sample', [
            'iframe' => ['body_class' => 'antialiased'],
            'body_class' => 'bg-secondary-500 body-secondary',
        ], 'cs');

        // Global iframe.body_class first, then the per-entry body_class.
        self::assertStringContainsString('<body class="antialiased bg-secondary-500 body-secondary">', $html);
    }

    #[Test]
    public function omits_body_class_when_neither_global_nor_per_page_set(): void
    {
        $html = $this->renderer->render('component', 'sample', [
            'iframe' => [],
        ], 'cs');

        // create_attribute filters empty entries — no stray class="" on <body>.
        self::assertStringContainsString('<body>', $html);
        self::assertStringNotContainsString('<body class', $html);
    }

    #[Test]
    public function wraps_page_render_in_page_wrapper_when_configured(): void
    {
        $html = $this->renderer->render('page', 'landing', [
            'iframe' => ['page_wrapper_class' => 'page-wrapper flex flex-col min-h-dvh'],
        ], 'cs');

        // The configured shell wraps the page body so the preview matches the
        // production layout's `<div class="page-wrapper …">`.
        self::assertStringContainsString('<div class="page-wrapper flex flex-col min-h-dvh">', $html);
        self::assertStringContainsString('<div class="landing">Landing page</div>', $html);
    }

    #[Test]
    public function omits_page_wrapper_when_class_empty(): void
    {
        $html = $this->renderer->render('page', 'landing', [
            'iframe' => [],
        ], 'cs');

        // Empty (the default) keeps the package framework-agnostic — page body
        // renders with no styleguide-only wrapper div.
        self::assertStringContainsString('<div class="landing">Landing page</div>', $html);
        self::assertStringNotContainsString('page-wrapper', $html);
    }

    #[Test]
    public function does_not_wrap_components_with_page_wrapper_class(): void
    {
        // page_wrapper_class is page-only: even when set, component previews
        // must not get the shell (it would leak into small previews).
        $html = $this->renderer->render('component', 'sample', [
            'iframe' => ['page_wrapper_class' => 'page-wrapper flex flex-col min-h-dvh'],
        ], 'cs');

        self::assertStringNotContainsString('page-wrapper', $html);
        // Component still gets its own inset wrapper.
        self::assertStringContainsString('<div style="padding:1.5rem">', $html);
    }

    #[Test]
    public function omits_page_wrapper_when_class_is_explicit_empty_string(): void
    {
        // Distinct from the absent-key case: an explicit `page_wrapper_class: ''`
        // must behave identically (no wrapper), so a consumer that sets the key
        // to blank to opt out gets the same result as omitting it.
        $html = $this->renderer->render('page', 'landing', [
            'iframe' => ['page_wrapper_class' => ''],
        ], 'cs');

        self::assertStringContainsString('<div class="landing">Landing page</div>', $html);
        self::assertStringNotContainsString('page-wrapper', $html);
    }

    #[Test]
    public function does_not_wrap_docs_with_page_wrapper_class(): void
    {
        // Page-only also excludes doc renders — docs ship their own full-page
        // layout and must not inherit the page shell even when the key is set.
        $html = $this->renderer->render('doc', 'sample-doc', [
            'iframe' => ['page_wrapper_class' => 'page-wrapper flex flex-col min-h-dvh'],
        ], 'cs');

        self::assertStringNotContainsString('page-wrapper', $html);
    }

    #[Test]
    public function escapes_special_characters_in_page_wrapper_class(): void
    {
        // create_attribute owns attribute-context escaping; a class string with
        // a `"` / `>` must not break out of the attribute. Guards against a
        // future refactor that emits the class without the helper.
        $html = $this->renderer->render('page', 'landing', [
            'iframe' => ['page_wrapper_class' => 'shell" onmouseover="alert(1)'],
        ], 'cs');

        // The double-quote is entity-encoded, so the injected handler stays
        // inside the class value instead of becoming its own attribute.
        self::assertStringContainsString('&quot;', $html);
        self::assertStringNotContainsString('<div class="shell" onmouseover="alert(1)">', $html);
    }

    #[Test]
    public function theme_dark_stamps_dark_class_and_color_scheme(): void
    {
        $html = $this->renderer->render('component', 'sample', [
            'project' => ['name' => 'TestProject'],
            'iframe' => ['css' => '/dist/style.css'],
        ], 'cs', 'dark');

        self::assertStringContainsString('<html lang="cs" class="dark">', $html);
        self::assertStringContainsString('color-scheme: dark', $html);
    }

    #[Test]
    public function theme_light_is_the_default_and_omits_the_dark_class(): void
    {
        $html = $this->renderer->render('component', 'sample', [
            'project' => ['name' => 'TestProject'],
            'iframe' => ['css' => '/dist/style.css'],
        ], 'cs'); // theme omitted → default

        self::assertStringContainsString('<html lang="cs">', $html);
        self::assertStringNotContainsString('class="dark"', $html);
        self::assertStringContainsString('color-scheme: light', $html);
    }

    #[Test]
    public function theme_dark_combines_with_an_existing_iframe_html_class(): void
    {
        $html = $this->renderer->render('component', 'sample', [
            'project' => ['name' => 'TestProject'],
            'iframe' => ['css' => '/dist/style.css', 'html_class' => 'notranslate'],
        ], 'cs', 'dark');

        self::assertStringContainsString('<html lang="cs" class="notranslate dark">', $html);
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
    public function renders_doc_body(): void
    {
        $html = $this->renderer->render('doc', 'sample-doc', [
            'project' => ['name' => 'TestProject'],
            'iframe' => ['css' => '/dist/style.css'],
        ], 'en');

        self::assertStringContainsString('Fixture body.', $html);
    }

    #[Test]
    public function missing_doc_is_404(): void
    {
        $html = $this->renderer->render('doc', 'nonexistent', [
            'project' => ['name' => 'TestProject'],
            'iframe' => [],
        ], 'en');

        self::assertSame(404, http_response_code());
        self::assertStringContainsString('404', $html);
        self::assertStringContainsString('doc/nonexistent', $html);
        http_response_code(200);
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

    #[Test]
    public function resolve_asset_url_is_noop_for_empty_base(): void
    {
        // Standalone layout: the static dir IS the docroot, so the bootstrap
        // derives an empty templateUrl. Asset URLs must pass through unchanged
        // — byte-for-byte the historical behaviour.
        self::assertSame('/dist/css/style.css', Renderer::resolveAssetUrl('/dist/css/style.css', ''));
        self::assertSame('dist/css/style.css', Renderer::resolveAssetUrl('dist/css/style.css', ''));
        self::assertSame('https://cdn.example.com/x.css', Renderer::resolveAssetUrl('https://cdn.example.com/x.css', ''));
    }

    #[Test]
    public function resolve_asset_url_rebases_relative_paths_onto_base(): void
    {
        // WordPress / Drupal layout: templateUrl is the theme's static web path.
        // A short, docroot-agnostic `/dist/...` then resolves to the real file
        // under the theme instead of 404-ing at the domain root.
        $base = '/wp-content/themes/acme/static';
        self::assertSame("$base/dist/css/style.css", Renderer::resolveAssetUrl('/dist/css/style.css', $base));
        // Bare-relative (no leading slash) is rebased with a separating slash.
        self::assertSame("$base/dist/js/script.js", Renderer::resolveAssetUrl('dist/js/script.js', $base));
        // A trailing slash on the base is normalised away (no `//`).
        self::assertSame("$base/dist/a.css", Renderer::resolveAssetUrl('/dist/a.css', "$base/"));
    }

    #[Test]
    public function resolve_asset_url_leaves_absolute_and_already_based_urls_untouched(): void
    {
        $base = '/wp-content/themes/acme/static';
        // External / protocol-relative / data: / anchor are never rebased.
        self::assertSame('https://cdn.example.com/x.css', Renderer::resolveAssetUrl('https://cdn.example.com/x.css', $base));
        self::assertSame('//cdn.example.com/x.css', Renderer::resolveAssetUrl('//cdn.example.com/x.css', $base));
        self::assertSame('data:text/css,body{}', Renderer::resolveAssetUrl('data:text/css,body{}', $base));
        self::assertSame('#frag', Renderer::resolveAssetUrl('#frag', $base));
        // Already rooted under the base — a consumer that hardcoded the full
        // theme path keeps working, no double prefix.
        self::assertSame("$base/dist/style.css", Renderer::resolveAssetUrl("$base/dist/style.css", $base));
        self::assertSame($base, Renderer::resolveAssetUrl($base, $base));
    }

    #[Test]
    public function render_rebases_iframe_assets_against_template_url(): void
    {
        // Integration: a Renderer whose context carries templateUrl (the WP /
        // Drupal case) prefixes the iframe css / js / fonts emitted into
        // render-cell so the short styleguide.yaml paths resolve correctly.
        $base = '/wp-content/themes/acme/static';
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/../templates');
        $loader->addPath(__DIR__ . '/fixtures/templates', 'project');
        $twig = new Environment($loader, ['cache' => false]);
        $twig->addFilter(new TwigFilter('cachebust', static fn(mixed $u): mixed => $u));
        $twig->addExtension(new \Parisek\Twig\AttributeExtension());
        $renderer = new Renderer($twig, ['templateUrl' => $base, 'content' => ['title' => 'Hello']]);

        $html = $renderer->render('component', 'sample', [
            'project' => ['name' => 'TestProject'],
            'iframe' => [
                'css' => '/dist/style.css',
                'js' => '/dist/script.js',
                'fonts' => ['/fonts/stylesheet.css'],
            ],
        ], 'cs');

        self::assertStringContainsString('<link rel="stylesheet" href="' . $base . '/dist/style.css">', $html);
        self::assertStringContainsString('<link rel="stylesheet" href="' . $base . '/fonts/stylesheet.css">', $html);
        self::assertStringContainsString('<script type="module" src="' . $base . '/dist/script.js"></script>', $html);
    }

    #[Test]
    public function render_leaves_iframe_assets_untouched_without_template_url(): void
    {
        // Standalone: the shared $this->renderer has no templateUrl in context,
        // so the same `/dist/...` URLs render unprefixed — backward compat proof.
        $html = $this->renderer->render('component', 'sample', [
            'project' => ['name' => 'TestProject'],
            'iframe' => ['css' => '/dist/style.css', 'js' => '/dist/script.js'],
        ], 'cs');

        self::assertStringContainsString('<link rel="stylesheet" href="/dist/style.css">', $html);
        self::assertStringContainsString('<script type="module" src="/dist/script.js"></script>', $html);
    }

    /**
     * Build a Renderer whose context carries a templateUrl asset base (the
     * WordPress / Drupal case), wired like setUp()'s standalone one.
     */
    private function rendererWithBase(string $base): Renderer
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/../templates');
        $loader->addPath(__DIR__ . '/fixtures/templates', 'project');
        $twig = new Environment($loader, ['cache' => false]);
        $twig->addFilter(new TwigFilter('cachebust', static fn(mixed $u): mixed => $u));
        // foundations.twig pipes copy through `|typography` (real extension in the
        // boot path); identity-pass it so the bare test env can render it.
        $twig->addFilter(new TwigFilter('typography', static fn(mixed $u): mixed => $u));
        $twig->addExtension(new \Parisek\Twig\AttributeExtension());

        return new Renderer($twig, ['templateUrl' => $base, 'content' => ['title' => 'Hello']]);
    }

    #[Test]
    public function render_rebases_project_favicon_against_template_url(): void
    {
        // project.favicon feeds the <link rel="icon"> and the standalone-bar <img>;
        // both must resolve under the theme on WordPress / Drupal, not the docroot.
        $base = '/wp-content/themes/acme/static';
        $html = $this->rendererWithBase($base)->render('component', 'sample', [
            'project' => ['name' => 'TestProject', 'favicon' => '/images/touch/favicon.svg'],
            'iframe' => [],
        ], 'cs');

        self::assertStringContainsString('<link rel="icon" href="' . $base . '/images/touch/favicon.svg">', $html);
        self::assertStringContainsString('src="' . $base . '/images/touch/favicon.svg"', $html);
    }

    #[Test]
    public function render_leaves_project_favicon_untouched_without_template_url(): void
    {
        // Standalone (no templateUrl) — favicon path unchanged, byte-for-byte.
        $html = $this->renderer->render('component', 'sample', [
            'project' => ['name' => 'TestProject', 'favicon' => '/images/touch/favicon.svg'],
            'iframe' => [],
        ], 'cs');

        self::assertStringContainsString('<link rel="icon" href="/images/touch/favicon.svg">', $html);
    }

    #[Test]
    public function render_rebases_foundations_logo_against_template_url(): void
    {
        // The foundations screen renders styleguide.logo[*].src as <img> — rebase
        // each onto templateUrl so the overview logos load under the theme.
        $base = '/wp-content/themes/acme/static';
        $html = $this->rendererWithBase($base)->render('foundations', '', [
            'styleguide' => [
                'labels' => ['logo' => 'Logo'],
                'logo' => [
                    'main' => ['src' => '/images/logo.svg', 'alt' => 'Logo', 'label' => 'Main'],
                ],
            ],
        ], 'cs');

        self::assertStringContainsString('src="' . $base . '/images/logo.svg"', $html);
    }

    #[Test]
    public function standalone_bar_favicon_has_broken_image_fallback(): void
    {
        // The standalone "← back to styleguide" bar's favicon <img> carries an
        // onerror fallback so a 404 favicon shows a generic glyph instead of the
        // browser's broken-image icon (mirrors the SPA sidebar fallback).
        $html = $this->renderer->render('component', 'sample', [
            'project' => ['name' => 'TestProject', 'favicon' => '/missing.svg'],
            'iframe' => [],
        ], 'cs');

        self::assertStringContainsString('src="/missing.svg"', $html);
        self::assertStringContainsString('onerror="this.onerror=null;this.src=', $html);
        self::assertStringContainsString('data:image/svg+xml,', $html);
    }
}
