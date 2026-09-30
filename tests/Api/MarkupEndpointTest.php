<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Api;

use Parisek\Styleguide\Api\MarkupEndpoint;
use Parisek\Styleguide\Http\Request;
use Parisek\Styleguide\Http\Result;
use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `/api/markup/<kind>/<slug>[?variant=<id>]` — the HTML one variant tile
 * renders, behind the same `show_source` gate as its source; and
 * `source_url`, the repository links of the Code panel.
 */
final class MarkupEndpointTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    /**
     * @param array<string,mixed> $yaml null = no styleguide.yaml at all
     * @param array<string,mixed> $config
     */
    private function styleguide(?array $yaml, array $config = []): Styleguide
    {
        $yamlPath = __DIR__ . '/../fixtures/does-not-exist.yaml';
        if ($yaml !== null) {
            $yamlPath = (string) tempnam(sys_get_temp_dir(), 'sg-markup-');
            file_put_contents($yamlPath, \Symfony\Component\Yaml\Yaml::dump($yaml));
            $this->tempFiles[] = $yamlPath;
        }

        return new Styleguide($config + [
            'templates_path' => __DIR__ . '/../fixtures/templates',
            'static_path' => __DIR__ . '/../fixtures',
            'config_yaml' => $yamlPath,
        ]);
    }

    private function get(Styleguide $styleguide, string $uri): Result
    {
        $result = $styleguide->handle(new Request($uri));
        self::assertNotNull($result);

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private function spaConfig(Styleguide $styleguide): array
    {
        $html = (string) $this->get($styleguide, '/styleguide/')->body;
        self::assertSame(1, preg_match('#<script id="sg-config" type="application/json">(.*?)</script>#s', $html, $m));

        return (array) json_decode($m[1], true, flags: \JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function a_public_catalogue_does_not_serve_markup(): void
    {
        $result = $this->get($this->styleguide(null), '/styleguide/api/markup/component/multi');

        self::assertSame(404, $result->status);
        self::assertStringContainsString('Unknown API endpoint: markup', (string) $result->body);
    }

    #[Test]
    public function with_show_source_it_returns_the_rendered_html_of_the_variant(): void
    {
        $styleguide = $this->styleguide(['show_source' => true]);

        $default = $this->get($styleguide, '/styleguide/api/markup/component/multi');
        self::assertSame(200, $default->status);
        $body = json_decode((string) $default->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('component', $body['kind']);
        self::assertSame('multi', $body['slug']);
        self::assertNull($body['variant']);
        self::assertStringContainsString('Multi demo (default variant)', $body['html']);

        $variant = json_decode((string) $this->get($styleguide, '/styleguide/api/markup/component/multi?variant=secondary')->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('secondary', $variant['variant']);
        self::assertStringNotContainsString('default variant', $variant['html']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'unknown kind' => ['/styleguide/api/markup/foundations/multi'];
        yield 'traversal in the slug' => ['/styleguide/api/markup/component/..%2Fmulti'];
        yield 'unknown entry' => ['/styleguide/api/markup/component/nope'];
    }

    #[Test]
    #[DataProvider('refused')]
    public function it_refuses_what_is_not_a_catalogue_entry(string $uri): void
    {
        self::assertSame(404, $this->get($this->styleguide(['show_source' => true]), $uri)->status);
    }

    #[Test]
    public function a_template_that_fails_to_render_answers_404_not_500(): void
    {
        $result = $this->get($this->styleguide(['show_source' => true]), '/styleguide/api/markup/component/broken-sample');

        self::assertSame(404, $result->status);
    }

    #[Test]
    public function tidy_lays_the_markup_out_by_nesting(): void
    {
        self::assertSame(
            "<section class=\"a\">\n\t<div>\n\t\t<img src=\"x\">\n\t\t<a href=\"/\">Najít loď </a>\n\t</div>\n\t<br/>\n</section>\n",
            MarkupEndpoint::tidy("\n\t\t<section class=\"a\">  \n\n\t\t\t\t\t<div>\n<img src=\"x\">\n\t<a href=\"/\">Najít loď        </a>\n\t\t\t</div>\n<br/>\n  </section>\n\n"),
        );
        self::assertSame('', MarkupEndpoint::tidy("\n  \n"));
    }

    #[Test]
    public function tidy_counts_a_closing_tag_in_an_attribute_value_as_text(): void
    {
        self::assertSame(
            "<div x-data=\"{ html: '<b>' }\">\n\ttext\n</div>\n",
            MarkupEndpoint::tidy("<div x-data=\"{ html: '<b>' }\">\ntext\n</div>"),
        );
    }

    #[Test]
    public function tidy_keeps_whitespace_where_it_is_content(): void
    {
        self::assertSame("<pre>\n  two  spaces\n</pre>\n", MarkupEndpoint::tidy("\t<pre>\n\t  two  spaces\n\t</pre>\n"));
    }

    #[Test]
    public function source_url_reaches_the_spa_only_with_the_source(): void
    {
        $url = 'https://github.com/acme/site/blob/main/templates/{path}';

        self::assertSame($url, $this->spaConfig($this->styleguide(['show_source' => true, 'source_url' => $url]))['sourceUrl']);
        self::assertArrayNotHasKey('sourceUrl', $this->spaConfig($this->styleguide(['show_source' => false, 'source_url' => $url])));
        self::assertArrayNotHasKey('sourceUrl', $this->spaConfig($this->styleguide(['show_source' => true])));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidSourceUrl(): iterable
    {
        yield 'no placeholder' => ['https://github.com/acme/site'];
        yield 'not http' => ['javascript:alert(1)//{path}'];
        yield 'relative' => ['/repo/{path}'];
        yield 'not a string' => [['https://x.test/{path}']];
    }

    #[Test]
    #[DataProvider('invalidSourceUrl')]
    public function an_unusable_source_url_fails_at_construction(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('source_url');

        $this->styleguide(['source_url' => $value]);
    }
}
