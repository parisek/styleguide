<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\CanvasLinks;
use Parisek\Styleguide\Http\Request;
use Parisek\Styleguide\Router;
use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Canvas mode (`/styleguide/render/<kind>/<slug>?canvas`) keeps its links in
 * canvas mode, so a multi-page prototype can be clicked through without the
 * SPA chrome (#250).
 */
final class CanvasLinksTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root === null) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function entryLinks(): iterable
    {
        yield 'page' => ['/styleguide/page/about', '/styleguide/render/page/about?canvas=1'];
        yield 'component' => ['/styleguide/component/hero', '/styleguide/render/component/hero?canvas=1'];
        yield 'doc' => ['/styleguide/doc/intro', '/styleguide/render/doc/intro?canvas=1'];
        yield 'trailing slash' => ['/styleguide/page/about/', '/styleguide/render/page/about?canvas=1'];
        yield 'own variant and fragment stay' => [
            '/styleguide/page/about?variant=dark-hero#team',
            '/styleguide/render/page/about?canvas=1&amp;variant=dark-hero#team',
        ];
        yield 'own canvas value is not doubled' => ['/styleguide/page/about?canvas', '/styleguide/render/page/about?canvas=1'];
    }

    #[Test]
    #[DataProvider('entryLinks')]
    public function an_entry_link_opens_its_render_in_canvas(string $href, string $expected): void
    {
        self::assertSame(
            '<a href="' . $expected . '">x</a>',
            CanvasLinks::rewrite('<a href="' . $href . '">x</a>', '/styleguide'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherLinks(): iterable
    {
        yield 'external' => ['https://example.com/styleguide/page/about'];
        yield 'protocol-relative' => ['//example.com/styleguide/page/about'];
        yield 'mailto' => ['mailto:info@example.com'];
        yield 'tel' => ['tel:+41716449020'];
        yield 'fragment' => ['#main'];
        yield 'asset' => ['/themes/custom/x/dist/css/style.css'];
        yield 'render already' => ['/styleguide/render/page/about'];
        yield 'overview' => ['/styleguide/overview'];
        yield 'nested path' => ['/styleguide/page/about/team'];
        yield 'other mount' => ['/docs/page/about'];
        yield 'double prefix' => ['/styleguide//styleguide/page/about'];
    }

    #[Test]
    #[DataProvider('otherLinks')]
    public function any_other_link_is_left_byte_for_byte(string $href): void
    {
        $html = '<a href="' . $href . '">x</a>';

        self::assertSame($html, CanvasLinks::rewrite($html, '/styleguide'));
    }

    #[Test]
    public function locale_and_theme_are_carried_unless_the_link_sets_them(): void
    {
        $html = '<a href="/styleguide/page/about">a</a><a href="/styleguide/page/contact?locale=en">b</a>';

        self::assertSame(
            '<a href="/styleguide/render/page/about?canvas=1&amp;locale=ar&amp;theme=dark">a</a>'
            . '<a href="/styleguide/render/page/contact?canvas=1&amp;locale=en&amp;theme=dark">b</a>',
            CanvasLinks::rewrite($html, '/styleguide', ['locale' => 'ar', 'theme' => 'dark']),
        );
    }

    #[Test]
    public function single_quotes_entities_and_a_host_base_path_are_understood(): void
    {
        self::assertSame(
            "<a class='nav' href='/app/styleguide/render/page/about?canvas=1&amp;variant=b'>x</a>",
            CanvasLinks::rewrite("<a class='nav' href='/app/styleguide/page/about?variant=b'>x</a>", '/app/styleguide'),
        );
        self::assertSame(
            '<a href="/styleguide/render/page/about?canvas=1&amp;variant=b&amp;x=1">x</a>',
            CanvasLinks::rewrite('<a href="/styleguide/page/about?variant=b&amp;x=1">x</a>', '/styleguide'),
        );
    }

    #[Test]
    public function only_the_href_of_a_content_link_is_rewritten(): void
    {
        $html = '<a data-href="/styleguide/page/about">x</a><p>/styleguide/page/about</p>'
            . '<link rel="prefetch" href="/styleguide/page/about">'
            . '<a href="/styleguide/page/about" data-sg-chrome>back</a>';

        self::assertSame($html, CanvasLinks::rewrite($html, '/styleguide'));
    }

    #[Test]
    public function the_router_flags_canvas_by_presence(): void
    {
        self::assertTrue(Router::parse('/styleguide/render/page/about?canvas')['canvas'] ?? null);
        self::assertTrue(Router::parse('/styleguide/render/page/about?canvas=0')['canvas'] ?? null);
        self::assertArrayNotHasKey('canvas', (array) Router::parse('/styleguide/render/page/about'));
    }

    #[Test]
    public function a_canvas_render_links_to_canvas_renders_and_a_plain_render_does_not_change(): void
    {
        $styleguide = $this->styleguideWithLinkedPages();

        $canvas = (string) $styleguide->handle(new Request('/styleguide/render/page/home?canvas&locale=ar'))?->body;
        self::assertStringContainsString('href="/styleguide/render/page/about?canvas=1&amp;locale=ar"', $canvas);
        self::assertStringContainsString('href="/styleguide/render/page/contact?canvas=1&amp;locale=ar"', $canvas);
        self::assertStringContainsString('href="mailto:info@example.com"', $canvas);
        // The back-bar keeps its link to the SPA; nothing else does.
        self::assertSame(1, substr_count($canvas, 'href="/styleguide/page/'));
        self::assertStringContainsString('href="/styleguide/page/home" data-sg-chrome', $canvas);

        $plain = (string) $styleguide->handle(new Request('/styleguide/render/page/home?locale=ar'))?->body;
        self::assertStringContainsString('href="/styleguide/page/about"', $plain);
        self::assertStringContainsString('href="/styleguide/page/contact"', $plain);
        self::assertStringNotContainsString('render/page/about', $plain);
    }

    #[Test]
    public function the_canvas_render_keeps_its_status_and_headers(): void
    {
        $styleguide = $this->styleguideWithLinkedPages();

        $plain = $styleguide->handle(new Request('/styleguide/render/page/home'));
        $canvas = $styleguide->handle(new Request('/styleguide/render/page/home?canvas'));

        self::assertNotNull($plain);
        self::assertNotNull($canvas);
        self::assertSame($plain->status, $canvas->status);
        self::assertSame($plain->headers, $canvas->headers);
    }

    /**
     * Two kinds of entry link, as a real project has them: a data `url:` that
     * the renderer rebases onto `homeUrl`, and one built in Twig.
     */
    private function styleguideWithLinkedPages(): Styleguide
    {
        $this->root = sys_get_temp_dir() . '/sg-canvas-' . bin2hex(random_bytes(4));
        foreach (['home', 'about', 'contact'] as $page) {
            mkdir($this->root . '/templates/page/' . $page, 0777, true);
            file_put_contents($this->root . "/templates/page/$page/$page.twig", "<main>$page</main>");
        }
        file_put_contents(
            $this->root . '/templates/page/home/styleguide.data.yaml',
            "menu:\n  - title: About\n    url: page/about\n",
        );
        file_put_contents(
            $this->root . '/templates/page/home/styleguide.twig',
            '{% for item in styleguide_data().menu %}<a href="{{ item.url }}">{{ item.title }}</a>{% endfor %}'
            . '<a href="{{ homeUrl }}page/contact">Contact</a><a href="mailto:info@example.com">Mail</a>',
        );
        file_put_contents($this->root . '/styleguide.yaml', "project:\n  name: Canvas\n");

        return new Styleguide([
            'templates_path' => $this->root . '/templates',
            'static_path' => $this->root,
            'config_yaml' => $this->root . '/styleguide.yaml',
            'twig_context' => ['homeUrl' => '/styleguide/'],
        ]);
    }
}
