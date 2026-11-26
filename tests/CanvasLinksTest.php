<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\CanvasLinks;
use Parisek\Styleguide\Http\Request;
use Parisek\Styleguide\Router;
use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function markupShapes(): iterable
    {
        $to = '/styleguide/render/page/about?canvas=1';
        yield '> inside a quoted attribute' => [
            '<a title="Next >" href="/styleguide/page/about">x</a>',
            '<a title="Next >" href="' . $to . '">x</a>',
        ];
        yield 'upper-case tag and attribute' => [
            '<A HREF="/styleguide/page/about">x</A>',
            '<A HREF="' . $to . '">x</A>',
        ];
        yield 'unquoted value gets double quotes' => [
            '<a href=/styleguide/page/about class=nav>x</a>',
            '<a href="' . $to . '" class=nav>x</a>',
        ];
        yield 'the first href wins, as in a browser' => [
            '<a href="/styleguide/page/about" href="/styleguide/page/other">x</a>',
            '<a href="' . $to . '" href="/styleguide/page/other">x</a>',
        ];
        yield 'a custom element named like a raw-text one is markup' => [
            '<script-demo><a href="/styleguide/page/about">x</a></script-demo>',
            '<script-demo><a href="' . $to . '">x</a></script-demo>',
        ];
        yield 'an anchor inside <template> is markup' => [
            '<template><a href="/styleguide/page/about">x</a></template>',
            '<template><a href="' . $to . '">x</a></template>',
        ];
    }

    #[Test]
    #[DataProvider('markupShapes')]
    public function valid_markup_shapes_are_understood(string $html, string $expected): void
    {
        self::assertSame($expected, CanvasLinks::rewrite($html, '/styleguide'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notMarkup(): iterable
    {
        yield 'comment' => ['<!-- <a href="/styleguide/page/about">old</a> -->'];
        yield 'script string' => ['<script>const a = \'<a href="/styleguide/page/about">\';</script>'];
        yield 'style' => ['<style>/* <a href="/styleguide/page/about"> */</style>'];
        yield 'textarea' => ['<textarea><a href="/styleguide/page/about">x</a></textarea>'];
        yield 'title' => ['<title><a href="/styleguide/page/about"></title>'];
        foreach (['xmp', 'iframe', 'noembed', 'noframes', 'noscript'] as $element) {
            yield $element => ["<$element><a href=\"/styleguide/page/about\">x</a></$element>"];
        }
        yield 'unterminated comment' => ['<!-- <a href="/styleguide/page/about">x</a>'];
        foreach (['script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript'] as $element) {
            yield "unterminated $element" => ["<$element><a href=\"/styleguide/page/about\">x</a>"];
        }
        yield 'plaintext to the end' => ['<plaintext><a href="/styleguide/page/about">x</a>'];
        yield 'href= inside another quoted value' => ['<a title="see href=/styleguide/page/about">x</a>'];
        yield 'abbr is not an anchor' => ['<abbr href="/styleguide/page/about">x</abbr>'];
        yield 'anchor without href' => ['<a name="top">x</a>'];
        yield 'href without value' => ['<a href>x</a>'];
    }

    #[Test]
    #[DataProvider('notMarkup')]
    public function text_that_is_not_an_entry_link_is_left_byte_for_byte(string $html): void
    {
        self::assertSame($html, CanvasLinks::rewrite($html, '/styleguide'));
    }

    #[Test]
    #[RunInSeparateProcess]
    public function a_regex_failure_returns_the_render_unchanged(): void
    {
        // The JIT ignores the backtrack limit, and a compiled pattern is cached
        // per process, hence the separate process.
        $limit = ini_get('pcre.backtrack_limit');
        $jit = ini_get('pcre.jit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '10');
        try {
            $html = '<a title="' . str_repeat('x', 200) . '" href="/styleguide/page/about">x</a>';
            self::assertSame($html, CanvasLinks::rewrite($html, '/styleguide'));
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
            ini_set('pcre.jit', (string) $jit);
        }
    }

    #[Test]
    public function a_variant_is_not_carried_to_another_entry(): void
    {
        // A variant is a styleguide.<id>.twig sibling of ONE entry, so it is not a
        // visitor preference like locale or theme (Router::withExplicitVariantIfPresent(),
        // useVariant.js). A link can still name one.
        $styleguide = $this->styleguideWithLinkedPages();
        $body = (string) $styleguide->handle(new Request('/styleguide/render/page/home?canvas&variant=dark'))?->body;

        self::assertStringContainsString('href="/styleguide/render/page/about?canvas=1"', $body);
        self::assertStringNotContainsString('variant=dark', substr($body, (int) strpos($body, '<body')));
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
     * @return iterable<string, array{string, string}>
     */
    public static function fragmentLinks(): iterable
    {
        $doc = '/styleguide/render/doc/intro?variant=b';
        yield 'double quotes' => ['<a href="#b">x</a>', '<a href="' . $doc . '#b">x</a>'];
        yield 'single quotes' => ["<a href='#b'>x</a>", "<a href='" . $doc . "#b'>x</a>"];
        yield 'unquoted' => ['<a href=#b class=x>x</a>', '<a href="' . $doc . '#b" class=x>x</a>'];
        yield 'entity in the value' => ['<a href="&#35;b">x</a>', '<a href="' . $doc . '#b">x</a>'];
        yield 'whitespace around the value' => ['<a href=" #b ">x</a>', '<a href="' . $doc . '#b">x</a>'];
        yield 'encoded fragment stays encoded' => ['<a href="#a%20b">x</a>', '<a href="' . $doc . '#a%20b">x</a>'];
    }

    #[Test]
    #[DataProvider('fragmentLinks')]
    public function a_fragment_link_gets_the_url_of_its_own_render(string $html, string $expected): void
    {
        self::assertSame(
            $expected,
            CanvasLinks::anchorFragments($html, '/styleguide/render/doc/intro?variant=b'),
        );
    }

    #[Test]
    public function the_query_of_the_document_is_escaped(): void
    {
        self::assertSame(
            '<a href="/styleguide/render/doc/intro?variant=b&amp;locale=cs#b">x</a>',
            CanvasLinks::anchorFragments('<a href="#b">x</a>', '/styleguide/render/doc/intro?variant=b&locale=cs'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notFragmentLinks(): iterable
    {
        yield 'empty fragment, a JavaScript hook' => ['<a href="#">x</a>'];
        yield 'path with a fragment' => ['<a href="/styleguide/page/about#b">x</a>'];
        yield 'query with a fragment' => ['<a href="?x=1#b">x</a>'];
        yield 'relative path' => ['<a href="about#b">x</a>'];
        yield 'external' => ['<a href="https://example.com/#b">x</a>'];
        yield 'empty href' => ['<a href="">x</a>'];
        yield 'href without value' => ['<a href>x</a>'];
        yield 'comment' => ['<!-- <a href="#b">x</a> -->'];
        yield 'script string' => ['<script>const a = \'<a href="#b">\';</script>'];
        yield 'not an anchor' => ['<link rel="x" href="#b"><area href="#b">'];
        yield 'chrome link' => ['<a href="#b" data-sg-chrome>x</a>'];
    }

    #[Test]
    #[DataProvider('notFragmentLinks')]
    public function any_other_href_is_left_byte_for_byte_by_the_fragment_pass(string $html): void
    {
        self::assertSame($html, CanvasLinks::anchorFragments($html, '/styleguide/render/doc/intro'));
    }

    #[Test]
    public function with_base_href_a_fragment_link_points_at_its_own_render(): void
    {
        $styleguide = $this->styleguideWithFragmentLink("iframe:\n  base_href: /\n");

        $body = (string) $styleguide->handle(new Request('/styleguide/render/doc/intro?variant=b&theme=dark'))?->body;
        self::assertStringContainsString('<base href="/">', $body);
        self::assertStringContainsString('href="/styleguide/render/doc/intro?variant=b&amp;theme=dark#b"', $body);
        self::assertStringContainsString('href="#"', $body);

        // An SPA path loaded inside the iframe is the document URL as well.
        $embedded = $styleguide->handle(new Request('/styleguide/doc/intro', [], 'iframe'));
        self::assertStringContainsString('href="/styleguide/doc/intro#b"', (string) $embedded?->body);

        // The host base path is part of the document URL.
        $hosted = $styleguide->handle(new Request('/styleguide/render/doc/intro', basePath: '/app/'));
        self::assertStringContainsString('href="/app/styleguide/render/doc/intro#b"', (string) $hosted?->body);
    }

    #[Test]
    public function with_base_href_a_canvas_render_gets_both_passes(): void
    {
        $styleguide = $this->styleguideWithFragmentLink("iframe:\n  base_href: /\n");

        $body = (string) $styleguide->handle(new Request('/styleguide/render/doc/intro?canvas'))?->body;
        self::assertStringContainsString('href="/styleguide/render/doc/intro?canvas#b"', $body);
        self::assertStringContainsString('href="/styleguide/render/doc/other?canvas=1"', $body);
    }

    #[Test]
    public function an_spa_path_in_canvas_keeps_its_own_url_in_a_fragment_link(): void
    {
        // The canvas pass would turn `/styleguide/doc/intro` into a render URL,
        // which reloads the frame. The fragment pass runs after it, so the link
        // keeps the URL the frame has.
        $styleguide = $this->styleguideWithFragmentLink("iframe:\n  base_href: /\n");

        $result = $styleguide->handle(new Request('/styleguide/doc/intro?canvas', [], 'iframe'));
        self::assertNotNull($result);
        self::assertStringContainsString('href="/styleguide/doc/intro?canvas#b"', (string) $result->body);
    }

    #[Test]
    public function a_falsy_base_href_adds_no_base_and_changes_no_link(): void
    {
        $styleguide = $this->styleguideWithFragmentLink("iframe:\n  base_href: '0'\n");

        $body = (string) $styleguide->handle(new Request('/styleguide/render/doc/intro'))?->body;
        self::assertStringNotContainsString('<base', $body);
        self::assertStringContainsString('<a href="#b">', $body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherOriginBases(): iterable
    {
        yield 'absolute' => ['https://cdn.example/'];
        yield 'dev server' => ['http://localhost:5173/'];
        yield 'protocol-relative' => ['//cdn.example/'];
        yield 'slash and backslash' => ['/\\cdn.example/'];
        yield 'backslashes' => ['\\\\cdn.example/'];
        yield 'tab inside' => ["/\t/cdn.example/"];
    }

    #[Test]
    #[DataProvider('otherOriginBases')]
    public function a_base_on_another_origin_leaves_the_links_alone(string $base): void
    {
        // A root-relative URL would resolve against that origin as well.
        $styleguide = $this->styleguideWithFragmentLink("iframe:\n  base_href: '$base'\n");

        $result = $styleguide->handle(new Request('/styleguide/render/doc/intro'));
        self::assertNotNull($result);
        self::assertStringContainsString('<a href="#b">', (string) $result->body);
    }

    #[Test]
    public function a_request_uri_that_does_not_parse_leaves_the_links_alone(): void
    {
        $styleguide = $this->styleguideWithFragmentLink("iframe:\n  base_href: /\n");

        // parse_url() refuses a path segment ending in `:<digits>`; a wrong
        // URL (the site root) would send the link away, so nothing changes.
        $result = $styleguide->handle(new Request('/styleguide/render/doc/intro/:80'));
        self::assertNotNull($result);
        self::assertStringContainsString('<a href="#b">', (string) $result->body);

        $body = (string) $styleguide->handle(new Request("/styleguide/render/doc/intro?x=\xff"))?->body;
        self::assertStringContainsString('<a href="#b">', $body);
    }

    #[Test]
    public function without_base_href_a_fragment_link_is_unchanged(): void
    {
        $styleguide = $this->styleguideWithFragmentLink('');

        $body = (string) $styleguide->handle(new Request('/styleguide/render/doc/intro'))?->body;
        self::assertStringNotContainsString('<base', $body);
        self::assertStringContainsString('<a href="#b">', $body);
    }

    private function styleguideWithFragmentLink(string $yaml): Styleguide
    {
        $this->root = sys_get_temp_dir() . '/sg-fragment-' . bin2hex(random_bytes(4));
        foreach (['intro', 'other'] as $doc) {
            mkdir($this->root . '/templates/doc/' . $doc, 0777, true);
        }
        file_put_contents(
            $this->root . '/templates/doc/intro/intro.twig',
            '<a href="#b">To B</a><a href="#">Hook</a><a href="{{ homeUrl }}doc/other">Other</a><section id="b">B</section>',
        );
        file_put_contents($this->root . '/templates/doc/other/other.twig', '<p>other</p>');
        file_put_contents($this->root . '/styleguide.yaml', "project:\n  name: Fragments\n" . $yaml);

        return new Styleguide([
            'templates_path' => $this->root . '/templates',
            'static_path' => $this->root,
            'config_yaml' => $this->root . '/styleguide.yaml',
            'twig_context' => ['homeUrl' => '/styleguide/'],
        ]);
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
