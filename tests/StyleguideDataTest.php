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
     */
    private static function callStyleguideData(Renderer $renderer, Environment $twig, string $kind, string $slug, ?string $argSlug = null): mixed
    {
        $ref = new \ReflectionClass($renderer);
        $ref->getProperty('currentKind')->setValue($renderer, $kind);
        $ref->getProperty('currentSlug')->setValue($renderer, $slug);

        $callable = $twig->getFunction('styleguide_data')?->getCallable();
        self::assertIsCallable($callable);

        return $argSlug === null ? $callable() : $callable($argSlug);
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

        $expected = Placeholder::generate(['subject' => 'portrait', 'seed' => 42, 'ratio' => '16:9']);
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

        $expectedPath = self::TEMPLATES_PATH . '/component/data-demo-missing/styleguide.data.yaml';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($expectedPath);

        self::callStyleguideData($renderer, $twig, 'component', 'data-demo-missing');
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
    public function explicit_slug_argument_reads_a_sibling_directorys_sidecar(): void
    {
        // Escape-hatch form: styleguide_data('data-demo-2') called while
        // rendering 'data-demo-explicit' pulls data-demo-2's sidecar instead
        // of data-demo-explicit's own (which doesn't even have one).
        $twig = $this->newTwig();
        $renderer = new Renderer($twig, [], self::TEMPLATES_PATH);

        $data = self::callStyleguideData($renderer, $twig, 'component', 'data-demo-explicit', 'data-demo-2');

        self::assertSame('Second Demo', $data['title']);
    }

    #[Test]
    public function integration_render_with_explicit_slug_argument(): void
    {
        $renderer = $this->newRenderer();

        $html = $renderer->render('component', 'data-demo-explicit', [
            'project' => ['name' => 'TestProject'],
            'iframe' => [],
        ], 'en');

        self::assertSame('Second Demo', self::extractSgData($html)['title']);
    }
}
