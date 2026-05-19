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
        // overview.twig depends on `create_attribute()` (parisek/twig-attribute)
        // and the `|typography` filter (parisek/twig-typography). Both are
        // composer requires, so they're already on the autoloader at test time
        // — register them here so the overview template can render.
        if (class_exists(\Parisek\Twig\AttributeExtension::class)) {
            $twig->addExtension(new \Parisek\Twig\AttributeExtension());
        }
        if (class_exists(\Parisek\Twig\TypographyExtension::class)) {
            $twig->addExtension(new \Parisek\Twig\TypographyExtension());
        }
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
    public function overview_lists_components_and_pages(): void
    {
        $components = [
            ['id' => 'button',     'name' => 'Tlačítko',    'category' => 'Basic',     'description' => 'A button.',   'asana' => 'https://asana.com/x', 'figma' => '', 'drupal' => '', 'web' => '', 'usage' => 'page-foo,page-bar', 'fields' => ['label' => []]],
            ['id' => 'hero',       'name' => 'Hero',         'category' => 'Block',     'description' => '',            'asana' => '', 'figma' => '', 'drupal' => '', 'web' => '', 'usage' => '',                  'fields' => []],
            ['id' => 'paragraph',  'name' => 'Paragraph',    'category' => 'Gutenberg', 'description' => 'Long text.',  'asana' => '', 'figma' => 'https://figma.com/y', 'drupal' => '', 'web' => '', 'usage' => '',          'fields' => []],
        ];
        $pages = [
            ['id' => 'article-detail', 'name' => 'Detail článku', 'category' => '', 'description' => 'Article view.', 'asana' => '', 'figma' => '', 'drupal' => '', 'web' => '', 'usage' => 'breadcrumb,article-teaser', 'fields' => []],
        ];

        $html = $this->renderer->render('overview', 'index', [
            'project' => ['name' => 'TestProject'],
            'iframe' => ['css' => '/dist/style.css'],
            'styleguide' => ['project' => ['name' => 'TestProject']],
            'components' => $components,
            'pages' => $pages,
        ], 'cs');

        // Section headings render.
        self::assertStringContainsString('Komponenty', $html);
        self::assertStringContainsString('Stránky', $html);

        // Category buckets render only when populated.
        self::assertStringContainsString('Základní prvky', $html);
        self::assertStringContainsString('Bloky', $html);
        self::assertStringContainsString('Gutenberg', $html);

        // Per-item card content renders.
        self::assertStringContainsString('Tlačítko', $html);
        self::assertStringContainsString('button', $html);
        self::assertStringContainsString('Detail článku', $html);

        // Deep links break out of the iframe via target="_top".
        self::assertStringContainsString('href="/styleguide/component/button" target="_top"', $html);
        self::assertStringContainsString('href="/styleguide/page/article-detail" target="_top"', $html);

        // External-link presence dots show only when the field is non-empty.
        self::assertStringContainsString('title="Asana"', $html);
        self::assertStringContainsString('title="Figma"', $html);
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
}
