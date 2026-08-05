<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\Placeholder;
use Parisek\Styleguide\Renderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Exception\ParseException;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/**
 * Covers the `styleguide.data.yaml` sidecar + `styleguide_data()` Twig
 * function feature (see README § Fixtures & sample data → "YAML sidecar
 * data" and docs/API.md § Twig functions & filters).
 *
 * Two complementary test styles are used:
 *  - Direct closure invocation (via the registered Twig function's
 *    callable), with `currentKind`/`currentSlug` set through reflection —
 *    lets tests assert exact PHP array shapes and catch exceptions that
 *    `Renderer::render()`'s own top-level try/catch would otherwise
 *    swallow into 500-page markup.
 *  - End-to-end `Renderer::render()` calls that extract the
 *    `<div id="sgdata">…</div>` JSON payload emitted by the fixture
 *    templates — proves the real render seam (`renderInner()` setting
 *    `currentKind`/`currentSlug` before the Twig render call) wires up
 *    correctly, not just the isolated resolver method.
 */
final class StyleguideDataTest extends TestCase
{
    private const TEMPLATES_PATH = __DIR__ . '/fixtures/templates';

    private function newTwig(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/../templates');
        $loader->addPath(self::TEMPLATES_PATH, 'project');
        $twig = new Environment($loader, ['cache' => false]);
        $twig->addFilter(new TwigFilter('cachebust', static fn(mixed $u): mixed => $u));
        $twig->addExtension(new \Parisek\Twig\AttributeExtension());

        return $twig;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function newRenderer(array $context = [], ?string $templatesPath = self::TEMPLATES_PATH): Renderer
    {
        return new Renderer($this->newTwig(), $context, $templatesPath);
    }

    /**
     * Extracts and json_decodes the `<div id="sgdata">…</div>` payload the
     * fixture `styleguide.twig` files emit.
     *
     * @return array<string, mixed>
     */
    private static function extractSgData(string $html): array
    {
        self::assertMatchesRegularExpression('/<div id="sgdata">(.*?)<\/div>/s', $html);
        preg_match('/<div id="sgdata">(.*?)<\/div>/s', $html, $m);
        $decoded = json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * Calls the registered `styleguide_data` Twig function's callable
     * directly, having set `currentKind`/`currentSlug` via reflection —
     * bypasses `Renderer::render()`'s top-level try/catch so exceptions
     * surface to the test instead of being swallowed into 500 markup.
     *
     * @param string|null $argRef Optional `styleguide_data('<ref>')` reference —
     *                             a bare set name, `<kind>/<slug>`, or
     *                             `<kind>/<slug>/<name>`.
     */
    private static function callStyleguideData(Renderer $renderer, Environment $twig, string $kind, string $slug, ?string $argRef = null): mixed
    {
        $reflection = new \ReflectionClass($renderer);
        $reflection->getProperty('currentKind')->setValue($renderer, $kind);
        $reflection->getProperty('currentSlug')->setValue($renderer, $slug);

        $callable = $twig->getFunction('styleguide_data')?->getCallable();
        self::assertIsCallable($callable);

        return $argRef === null ? $callable() : $callable($argRef);
    }

    #[Test]
    public function loads_sidecar_yaml_as_an_array(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        $data = self::callStyleguideData($renderer, $twig, 'component', 'data-demo');

        self::assertIsArray($data);
        self::assertSame('Demo Title', $data['title']);
    }

    #[Test]
    public function no_arg_resolution_picks_the_currently_rendering_directory_not_a_hardcoded_one(): void
    {
        // Two distinct fixture directories, same Renderer/Twig instance —
        // proves resolution is per-render-state, not a global/hardcoded path.
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        $first = self::callStyleguideData($renderer, $twig, 'component', 'data-demo');
        $second = self::callStyleguideData($renderer, $twig, 'component', 'data-demo-2');

        self::assertSame('Demo Title', $first['title']);
        self::assertSame('Second Demo', $second['title']);
    }

    #[Test]
    public function integration_render_wires_current_directory_through_renderInner(): void
    {
        // End-to-end: render() itself (not a reflection-assisted direct call)
        // sets currentKind/currentSlug before the Twig render reaches
        // styleguide_data() inside styleguide.twig.
        $renderer = $this->newRenderer();

        $htmlOne = $renderer->render('component', 'data-demo', [
            'project' => ['name' => 'TestProject'],
            'iframe' => [],
        ], 'en');
        $htmlTwo = $renderer->render('component', 'data-demo-2', [
            'project' => ['name' => 'TestProject'],
            'iframe' => [],
        ], 'en');

        self::assertSame('Demo Title', self::extractSgData($htmlOne)['title']);
        self::assertSame('Second Demo', self::extractSgData($htmlTwo)['title']);
    }

    #[Test]
    public function resolves_placeholder_mapping_to_the_same_shape_placeholder_function_returns(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        $data = self::callStyleguideData($renderer, $twig, 'component', 'data-demo');

        // The fixture's `image:` node is authored as `ratio: '16:9'` — the
        // YAML-only alias (review follow-up, see the dedicated ratio-alias
        // tests below) normalises it onto Placeholder::generate()'s own
        // `aspect: '16/9'` option before the call, so the resolved shape
        // matches an EQUIVALENT direct `aspect` call, not a literal `ratio`
        // passthrough (Placeholder::generate() itself has no `ratio` option).
        $expected = Placeholder::generate(['subject' => 'portrait', 'seed' => 42, 'aspect' => '16/9']);
        self::assertSame($expected, $data['image']);
    }

    #[Test]
    public function resolves_deeply_nested_placeholder_occurrences_inside_a_list(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        $data = self::callStyleguideData($renderer, $twig, 'component', 'data-demo');

        self::assertSame('First', $data['items'][0]['label']);
        self::assertSame(Placeholder::generate(['subject' => 'product', 'seed' => 'item-1']), $data['items'][0]['icon']);
        self::assertSame('Second', $data['items'][1]['label']);
        self::assertSame(Placeholder::generate(['subject' => 'product', 'seed' => 'item-2']), $data['items'][1]['icon']);
    }

    #[Test]
    public function rebases_relative_src_onto_template_url(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, ['templateUrl' => '/wp-content/themes/acme/static'], self::TEMPLATES_PATH);

        $data = self::callStyleguideData($renderer, $twig, 'component', 'data-demo');

        self::assertSame('/wp-content/themes/acme/static/dist/foo.png', $data['asset']['src']);
    }

    #[Test]
    public function rebases_relative_url_onto_home_url_when_present(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, ['homeUrl' => '/en'], self::TEMPLATES_PATH);

        $data = self::callStyleguideData($renderer, $twig, 'component', 'data-demo');

        self::assertSame('/en/contact', $data['link']['url']);
    }

    #[Test]
    public function leaves_url_untouched_when_home_url_is_absent_from_context(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH); // no homeUrl in context

        $data = self::callStyleguideData($renderer, $twig, 'component', 'data-demo');

        self::assertSame('/contact', $data['link']['url']); // unchanged, never throws
    }

    #[Test]
    public function leaves_src_untouched_when_template_url_is_absent_from_context(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH); // no templateUrl in context

        $data = self::callStyleguideData($renderer, $twig, 'component', 'data-demo');

        self::assertSame('/dist/foo.png', $data['asset']['src']);
    }

    #[Test]
    public function absolute_src_and_url_pass_through_untouched_regardless_of_base(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [
            'templateUrl' => '/wp-content/themes/acme/static',
            'homeUrl' => '/en',
        ], self::TEMPLATES_PATH);

        $data = self::callStyleguideData($renderer, $twig, 'component', 'data-demo');

        self::assertSame('https://cdn.example.com/x.png', $data['external']['src']);
        self::assertSame('https://example.com/page', $data['external']['url']);
    }

    #[Test]
    public function throws_runtime_exception_naming_the_expected_path_when_sidecar_is_missing(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        // Relative to templates_path — the absolute path never reaches the
        // exception message (it's logged server-side via error_log()
        // instead), so it can never leak into rendered 500-page markup.
        $expectedRelativePath = 'component/data-demo-missing/styleguide.data.yaml';

        try {
            self::callStyleguideData($renderer, $twig, 'component', 'data-demo-missing');
            self::fail('Expected a RuntimeException.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($expectedRelativePath, $e->getMessage());
            self::assertStringNotContainsString(self::TEMPLATES_PATH, $e->getMessage());
            // data-demo-missing has NO styleguide.data*.yaml files at all —
            // the enumeration fragment says so explicitly rather than
            // listing an empty set.
            self::assertStringContainsString('no styleguide.data*.yaml files found in this directory', $e->getMessage());
        }
    }

    #[Test]
    public function integration_render_of_missing_sidecar_surfaces_as_500_error_markup(): void
    {
        // Through the real render() path, the RuntimeException is caught by
        // Renderer::render()'s existing top-level try/catch (same contract as
        // any other fixture-template exception, e.g. broken-sample) — it
        // still fails loudly (500 + visible message), it just isn't a raw
        // PHP exception at this call site.
        $renderer = $this->newRenderer();

        $html = $renderer->render('component', 'data-demo-missing', [
            'project' => ['name' => 'TestProject'],
            'iframe' => [],
        ], 'en');

        self::assertSame(500, http_response_code());
        self::assertStringContainsString('Render error:', $html);
        self::assertStringContainsString('styleguide.data.yaml', $html);
        http_response_code(200);
    }

    #[Test]
    public function throws_when_called_outside_an_active_render_context(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);
        // currentKind/currentSlug deliberately left null (no render() call yet).

        $callable = $twig->getFunction('styleguide_data')?->getCallable();
        self::assertIsCallable($callable);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no active render context');

        $callable();
    }

    #[Test]
    public function throws_when_templates_path_was_never_configured(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], null); // no templates_path at all

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no active render context');

        self::callStyleguideData($renderer, $twig, 'component', 'data-demo');
    }

    #[Test]
    public function propagates_symfony_parse_exception_unchanged_on_malformed_yaml(): void
    {
        // Same (uncaught) contract as Styleguide::__construct()'s own
        // Yaml::parseFile($config['config_yaml']) for the top-level
        // styleguide.yaml — the package doesn't add resilience here that it
        // doesn't already have there.
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        $this->expectException(ParseException::class);

        self::callStyleguideData($renderer, $twig, 'component', 'data-demo-malformed');
    }

    #[Test]
    public function integration_render_of_malformed_yaml_surfaces_as_500_error_markup(): void
    {
        $renderer = $this->newRenderer();

        $html = $renderer->render('component', 'data-demo-malformed', [
            'project' => ['name' => 'TestProject'],
            'iframe' => [],
        ], 'en');

        self::assertSame(500, http_response_code());
        self::assertStringContainsString('Render error:', $html);
        http_response_code(200);
    }

    #[Test]
    public function named_set_argument_loads_the_matching_flat_suffix_sidecar(): void
    {
        // styleguide_data('gallery') on the data-demo directory resolves
        // styleguide.data-gallery.yaml — a SEPARATE file from the default
        // styleguide.data.yaml in the SAME directory (never a cross-
        // component lookup).
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        $default = self::callStyleguideData($renderer, $twig, 'component', 'data-demo');
        $gallery = self::callStyleguideData($renderer, $twig, 'component', 'data-demo', 'gallery');

        self::assertSame('Demo Title', $default['title']);
        self::assertSame('Gallery Set', $gallery['title']);
    }

    #[Test]
    public function integration_render_of_a_named_set_via_a_variant_sibling(): void
    {
        // End-to-end: the 'gallery-view' variant sibling in data-demo calls
        // styleguide_data('gallery') — proves the named-set form works
        // through the real render() seam, not just a direct closure call.
        $renderer = $this->newRenderer();

        $html = $renderer->render('component', 'data-demo', [
            'project' => ['name' => 'TestProject'],
            'iframe' => [],
            'variant' => 'gallery-view',
        ], 'en');

        self::assertSame('Gallery Set', self::extractSgData($html)['title']);
    }

    #[Test]
    public function loads_multiple_named_sets_from_the_same_directory(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        $default = self::callStyleguideData($renderer, $twig, 'component', 'data-demo-sets');
        $hero = self::callStyleguideData($renderer, $twig, 'component', 'data-demo-sets', 'hero');
        $gallery = self::callStyleguideData($renderer, $twig, 'component', 'data-demo-sets', 'gallery');

        self::assertSame('Default Set', $default['title']);
        self::assertSame('Hero Set', $hero['title']);
        self::assertSame('Gallery Set', $gallery['title']);
    }

    #[Test]
    public function rejects_a_named_set_argument_that_does_not_match_the_variant_id_pattern(): void
    {
        // Same ^[a-z0-9-]+$ rule Router::whitelistVariant() / renderInner()
        // already enforce for `?variant=` — uppercase, spaces, underscores
        // all rejected before ever touching the filesystem.
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid data set name');

        self::callStyleguideData($renderer, $twig, 'component', 'data-demo-sets', 'Not Valid!');
    }

    #[Test]
    public function throws_and_enumerates_available_sets_when_a_named_set_is_missing(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        $expectedRelativePath = 'component/data-demo-sets/styleguide.data-nonexistent.yaml';

        try {
            self::callStyleguideData($renderer, $twig, 'component', 'data-demo-sets', 'nonexistent');
            self::fail('Expected a RuntimeException.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($expectedRelativePath, $e->getMessage());
            self::assertStringNotContainsString(self::TEMPLATES_PATH, $e->getMessage());
            // data-demo-sets ships default + hero + gallery — all three must
            // be enumerated as a typo aid, alphabetically sorted.
            self::assertStringContainsString('default, gallery, hero', $e->getMessage());
        }
    }

    #[Test]
    public function throws_and_enumerates_available_sets_when_the_default_set_is_missing_but_named_sets_exist(): void
    {
        // data-demo-only-named ships ONLY styleguide.data-alt.yaml — no bare
        // styleguide.data.yaml. A no-arg call still names the (absent)
        // default path as "expected", but enumerates what IS present.
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        $expectedRelativePath = 'component/data-demo-only-named/styleguide.data.yaml';

        try {
            self::callStyleguideData($renderer, $twig, 'component', 'data-demo-only-named');
            self::fail('Expected a RuntimeException.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($expectedRelativePath, $e->getMessage());
            self::assertStringNotContainsString(self::TEMPLATES_PATH, $e->getMessage());
            self::assertStringContainsString('available data sets in this directory: alt', $e->getMessage());
        }

        // The named set itself still resolves fine.
        $data = self::callStyleguideData($renderer, $twig, 'component', 'data-demo-only-named', 'alt');
        self::assertSame('Alt Set', $data['title']);
    }

    // ─── Review follow-up: reserved "default" set name ─────────────────────

    #[Test]
    public function rejects_the_reserved_default_set_name_before_touching_the_filesystem(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('use styleguide_data() for the default set');

        // data-demo-default-reserved ships a stray styleguide.data-default.yaml
        // sidecar (see fixture) — proves rejection happens BEFORE any
        // filesystem access, not merely "the file happens to be missing".
        self::callStyleguideData($renderer, $twig, 'component', 'data-demo-default-reserved', 'default');
    }

    #[Test]
    public function a_stray_styleguide_data_default_yaml_on_disk_is_never_loaded(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        // The no-arg call resolves styleguide.data.yaml ONLY — the sibling
        // styleguide.data-default.yaml (a name that could never legitimately
        // be reached via styleguide_data('default'), which is rejected
        // above) must never be the one that gets loaded.
        $data = self::callStyleguideData($renderer, $twig, 'component', 'data-demo-default-reserved');
        self::assertSame('Default Sidecar', $data['title']);

        // And the explicit 'default' name is rejected outright, never
        // silently falling through to load the file anyway.
        try {
            self::callStyleguideData($renderer, $twig, 'component', 'data-demo-default-reserved', 'default');
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('reserved', $e->getMessage());
        }
    }

    // ─── Review follow-up: "ratio" as a YAML-only alias for "aspect" ───────

    #[Test]
    public function ratio_alias_in_yaml_placeholder_produces_the_same_output_as_aspect(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        // data-demo's image is authored as `placeholder: {subject: portrait,
        // seed: 42, ratio: '16:9'}` — the colon-separated "16:9" must
        // normalise onto Placeholder::generate()'s own slash-separated
        // "aspect" option ("16/9") and produce byte-identical output.
        $data = self::callStyleguideData($renderer, $twig, 'component', 'data-demo');

        $expected = Placeholder::generate(['subject' => 'portrait', 'seed' => 42, 'aspect' => '16/9']);
        self::assertSame($expected, $data['image']);
    }

    #[Test]
    public function explicit_aspect_wins_over_ratio_when_both_are_present(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        $data = self::callStyleguideData($renderer, $twig, 'component', 'data-demo-ratio-conflict');

        $expected = Placeholder::generate(['subject' => 'abstract', 'seed' => 7, 'aspect' => '1/1']);
        self::assertSame($expected, $data['image']);
    }

    // ─── Review follow-up: root-relative path rebasing is a contract, not a bug ─

    #[Test]
    public function root_relative_src_and_url_are_rebased_not_passed_through(): void
    {
        // Pins the documented (and CORRECT) behaviour: "/dist/foo.png" and
        // "/contact" are root-relative, NOT scheme'd/protocol-relative/data:,
        // so Renderer::resolveAssetUrl() rebases them onto templateUrl /
        // homeUrl exactly like a bare-relative path would. Only a URI
        // scheme (incl. data:), "//", or "#" pass through untouched.
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [
            'templateUrl' => '/wp-content/themes/acme/static',
            'homeUrl' => '/en',
        ], self::TEMPLATES_PATH);

        $data = self::callStyleguideData($renderer, $twig, 'component', 'data-demo');

        self::assertSame('/wp-content/themes/acme/static/dist/foo.png', $data['asset']['src']);
        self::assertSame('/en/contact', $data['link']['url']);
    }

    // ─── Review follow-up: context lifecycle (cleared after render) ────────

    #[Test]
    public function styleguide_data_throws_after_a_completed_render_instead_of_reusing_stale_context(): void
    {
        $renderer = $this->newRenderer();

        $html = $renderer->render('component', 'data-demo', [
            'project' => ['name' => 'TestProject'],
            'iframe' => [],
        ], 'en');
        self::assertStringContainsString('Demo Title', self::extractSgData($html)['title']);

        // Reach the SAME renderer's registered styleguide_data callable via
        // reflection on its OWN Twig environment, without re-seeding
        // currentKind/currentSlug — render() must have already reset them
        // to null in renderInner()'s finally block.
        $ref = new \ReflectionClass($renderer);
        $rendererTwig = $ref->getProperty('twig')->getValue($renderer);
        $callable = $rendererTwig->getFunction('styleguide_data')?->getCallable();
        self::assertIsCallable($callable);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no active render context');

        $callable();
    }

    // ─── Review follow-up: edge-case YAML shapes ────────────────────────────

    #[Test]
    public function empty_sidecar_file_resolves_to_an_empty_array(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        self::assertSame([], self::callStyleguideData($renderer, $twig, 'component', 'data-demo-edge-empty'));
    }

    #[Test]
    public function null_document_sidecar_resolves_to_an_empty_array(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        self::assertSame([], self::callStyleguideData($renderer, $twig, 'component', 'data-demo-edge-null'));
    }

    #[Test]
    public function empty_map_sidecar_resolves_to_an_empty_array(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        self::assertSame([], self::callStyleguideData($renderer, $twig, 'component', 'data-demo-edge-empty-map'));
    }

    #[Test]
    public function empty_list_sidecar_resolves_to_an_empty_array(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        self::assertSame([], self::callStyleguideData($renderer, $twig, 'component', 'data-demo-edge-empty-list'));
    }

    #[Test]
    public function bare_scalar_top_level_sidecar_throws_a_clear_runtime_exception(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        try {
            self::callStyleguideData($renderer, $twig, 'component', 'data-demo-edge-scalar');
            self::fail('Expected a RuntimeException.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('expected a YAML mapping or list', $e->getMessage());
            self::assertStringContainsString('bare string', $e->getMessage());
        }
    }

    // ─── Review follow-up: traversal-shaped set names rejected ──────────────

    /**
     * Still rejected, but now as a malformed REFERENCE rather than a malformed
     * set name: once `/` separates a reference's segments, a value containing
     * one stopped being a candidate set name at all. The diagnostic changed
     * with it, and the new one is the more accurate of the two — `a/b` really
     * is a reference naming a kind that does not exist.
     */
    #[Test]
    public function rejects_parent_directory_traversal_shaped_set_names(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid kind');

        self::callStyleguideData($renderer, $twig, 'component', 'data-demo-sets', '../x');
    }

    #[Test]
    public function rejects_a_set_name_containing_a_path_separator(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid kind');

        self::callStyleguideData($renderer, $twig, 'component', 'data-demo-sets', 'a/b');
    }

    #[Test]
    public function rejects_a_url_encoded_traversal_shaped_set_name(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid data set name');

        self::callStyleguideData($renderer, $twig, 'component', 'data-demo-sets', '%2e%2e');
    }

    // ─── Review follow-up: custom YAML tags / serialized objects are inert ──

    #[Test]
    public function custom_yaml_tags_throw_a_parse_exception_rather_than_being_instantiated(): void
    {
        // Yaml::parseFile() is called with no PARSE_CUSTOM_TAGS /
        // PARSE_OBJECT flags anywhere in the resolution path — an arbitrary
        // custom tag is therefore a hard parse error, matching plain
        // symfony/yaml behaviour with no flags enabled.
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        $this->expectException(ParseException::class);

        self::callStyleguideData($renderer, $twig, 'component', 'data-demo-edge-custom-tag');
    }

    #[Test]
    public function php_object_tagged_values_resolve_to_null_never_a_real_object(): void
    {
        // `!php/object` is a symfony/yaml built-in tag name, but without the
        // Yaml::PARSE_OBJECT flag (never passed here) it resolves to `null`
        // rather than throwing OR instantiating a real PHP object — pinning
        // that no object ever gets constructed from sidecar YAML.
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        $data = self::callStyleguideData($renderer, $twig, 'component', 'data-demo-edge-php-object');

        self::assertArrayHasKey('payload', $data);
        self::assertNull($data['payload']);
    }
    // ─── Cross-fixture references ──────────────────────────────────────────

    /**
     * End-to-end through `Renderer::render()`, not the reflection-assisted
     * direct call: the reference shapes are what README and docs/API.md tell
     * consumers to write, so they are covered the way a consumer reaches them.
     */
    #[Test]
    public function integration_render_resolves_every_reference_shape(): void
    {
        $renderer = $this->newRenderer();
        $context = ['project' => ['name' => 'TestProject'], 'iframe' => []];

        // `variant` travels in the config array, not as a positional argument
        // (Renderer::render()'s 5th parameter is the theme).
        $crossDefault = $renderer->render('page', 'data-consumer', $context, 'en');
        $crossNamed = $renderer->render('page', 'data-consumer', $context + ['variant' => 'named-elsewhere'], 'en');
        $own = $renderer->render('page', 'data-consumer', $context + ['variant' => 'own-wins'], 'en');

        self::assertSame('Demo Title', self::extractSgData($crossDefault)['title']);
        self::assertSame('Gallery Set', self::extractSgData($crossNamed)['title']);
        self::assertSame("Page's own data", self::extractSgData($own)['title']);
    }

    #[Test]
    public function reads_another_fixtures_default_set(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        $data = self::callStyleguideData($renderer, $twig, 'page', 'data-consumer', 'component/data-demo');

        self::assertIsArray($data);
        self::assertSame('Demo Title', $data['title']);
    }

    #[Test]
    public function reads_another_fixtures_named_set_from_a_three_segment_reference(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        $data = self::callStyleguideData($renderer, $twig, 'page', 'data-consumer', 'component/data-demo/gallery');

        self::assertIsArray($data);
        self::assertSame('Gallery Set', $data['title']);
    }

    /**
     * The compatibility guarantee: a one-segment reference is still a set name
     * in the CURRENT directory, and no reference at all is still the current
     * default set. Adding the cross-fixture shapes must not touch either.
     */
    #[Test]
    public function a_reference_without_a_separator_still_means_the_current_directory(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        self::assertSame(
            "Page's own data",
            self::callStyleguideData($renderer, $twig, 'page', 'data-consumer')['title'],
        );
        self::assertSame(
            'Gallery Set',
            self::callStyleguideData($renderer, $twig, 'component', 'data-demo', 'gallery')['title'],
        );
    }

    #[Test]
    public function reports_the_referenced_directory_not_the_rendering_one_when_a_file_is_missing(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('component/data-demo-missing/styleguide.data.yaml');

        self::callStyleguideData($renderer, $twig, 'page', 'data-consumer', 'component/data-demo-missing');
    }

    #[Test]
    public function rejects_an_unknown_kind(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid kind');

        self::callStyleguideData($renderer, $twig, 'page', 'data-consumer', 'partial/data-demo');
    }

    #[Test]
    public function rejects_a_reference_with_too_many_segments(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid reference');

        self::callStyleguideData($renderer, $twig, 'page', 'data-consumer', 'component/data-demo/gallery/extra');
    }

    /**
     * `''` resolved to the current default set before references existed. An
     * earlier revision of this branch made it throw; that was a real
     * compatibility break for any consumer passing a possibly-empty variable,
     * and it is exactly what the segment-count grammar promises not to do.
     */
    #[Test]
    public function an_empty_reference_still_means_the_current_default_set(): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        $data = self::callStyleguideData($renderer, $twig, 'page', 'data-consumer', '');

        self::assertIsArray($data);
        self::assertSame("Page's own data", $data['title']);
    }

    /**
     * A trailing separator used to make this a three-segment reference whose
     * EMPTY set name skipped the id check and fell through to the default set —
     * the reference saying one thing and the resolver doing another.
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('referencesWithAnEmptySegment')]
    public function rejects_a_reference_with_an_empty_segment(string $ref): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('empty segment');

        self::callStyleguideData($renderer, $twig, 'page', 'data-consumer', $ref);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function referencesWithAnEmptySegment(): array
    {
        return [
            'trailing separator'     => ['component/data-demo/'],
            'doubled separator'      => ['component//data-demo'],
            'empty set name segment' => ['component/data-demo//'],
            'separator only'         => ['//'],
        ];
    }

    /**
     * Containment is checked on the resolved FILE. A directory-only check walks
     * straight past a symlinked sidecar, which is the one escape the lexical
     * whitelist cannot describe.
     */
    #[Test]
    public function rejects_a_sidecar_symlinked_outside_templates_path(): void
    {
        $outside = sys_get_temp_dir() . '/sg-outside-' . bin2hex(random_bytes(4));
        mkdir($outside);
        file_put_contents($outside . '/leaked.yaml', "title: \"Leaked\"\n");

        $dir = self::TEMPLATES_PATH . '/component/data-demo-symlinked';
        mkdir($dir);
        symlink($outside . '/leaked.yaml', $dir . '/styleguide.data.yaml');

        try {
            $twig = $this->newTwig();
            $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('resolves outside templates_path');

            self::callStyleguideData($renderer, $twig, 'page', 'data-consumer', 'component/data-demo-symlinked');
        } finally {
            @unlink($dir . '/styleguide.data.yaml');
            @rmdir($dir);
            @unlink($outside . '/leaked.yaml');
            @rmdir($outside);
        }
    }

    /**
     * A reference lands in a filesystem path, so it gets the same traversal
     * treatment set names already had.
     *
     * The trailing-newline case is why both id patterns carry the `D`
     * modifier: PCRE's default `$` also matches before a final newline, so
     * `component/data-demo\n` would otherwise pass validation and contradict
     * the documented "nothing else is expressible" guarantee.
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('traversalShapedReferences')]
    public function rejects_traversal_shaped_references(string $ref): void
    {
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        $this->expectException(\RuntimeException::class);

        self::callStyleguideData($renderer, $twig, 'page', 'data-consumer', $ref);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function traversalShapedReferences(): array
    {
        return [
            'parent segment as slug' => ['component/..'],
            'nested traversal'       => ['component/../../etc'],
            'absolute path'          => ['/etc/passwd'],
            'url-encoded traversal'  => ['component/%2e%2e'],
            'empty slug'             => ['component/'],
            'empty kind'             => ['/data-demo'],
            'backslash separator'    => ['component\\data-demo'],
            'trailing newline'       => ["component/data-demo\n"],
            'uppercase slug'         => ['component/Data-Demo'],
        ];
    }

}
