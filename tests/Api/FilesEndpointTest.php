<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Api;

use Parisek\Styleguide\Api\FilesEndpoint;
use Parisek\Styleguide\Http\Request;
use Parisek\Styleguide\Http\Result;
use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * `/api/files/<kind>/<slug>` — an entry's own Twig template, CSS and JS, as
 * far as `source_views` lets, behind the `show_source` gate; and the
 * `source_views` key itself.
 */
final class FilesEndpointTest extends TestCase
{
    private string $root;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/sg-files-' . bin2hex(random_bytes(4));
        $card = $this->root . '/component/card';
        mkdir($card . '/css', 0777, true);
        mkdir($card . '/js/lib', 0777, true);
        mkdir($card . '/tests', 0777, true);
        file_put_contents($card . '/card.twig', "<div class=\"card\">{{ content.title }}</div>\n");
        file_put_contents($card . '/styleguide.twig', "{{ component_card({ title: 'x' }) }}\n");
        file_put_contents($card . '/css/card.css', ".card { display: grid; }\n");
        file_put_contents($card . '/js/card.js', "export default 1;\n");
        file_put_contents($card . '/js/lib/helper.js', "export const h = 2;\n");
        file_put_contents($card . '/js/card.test.js', "test();\n");
        file_put_contents($card . '/js/card.spec.js', "spec();\n");
        file_put_contents($card . '/tests/behaviour.js', "nope();\n");
        file_put_contents($card . '/js/notes.txt', "not js\n");
        mkdir($this->root . '/component/plain', 0777, true);
        file_put_contents($this->root . '/component/plain/plain.twig', "<p>plain</p>\n");
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(Result $result): array
    {
        return (array) json_decode((string) $result->body, true, flags: \JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function it_returns_the_css_and_js_of_the_entry_without_tests(): void
    {
        $result = (new FilesEndpoint($this->root))->handle('component', 'card');

        self::assertSame(200, $result->status);
        self::assertSame(
            [
                ['component/card/css/card.css', 'css'],
                ['component/card/js/card.js', 'js'],
                ['component/card/js/lib/helper.js', 'js'],
            ],
            array_map(static fn (array $f): array => [$f['path'], $f['language']], self::body($result)['files']),
        );
        self::assertSame(".card { display: grid; }\n", self::body($result)['files'][0]['source']);
    }

    #[Test]
    public function the_template_comes_first_only_when_listed(): void
    {
        $files = self::body((new FilesEndpoint($this->root, ['twig', 'css', 'js']))->handle('component', 'card'))['files'];

        self::assertSame('component/card/card.twig', $files[0]['path']);
        self::assertSame('twig', $files[0]['language']);
        self::assertStringContainsString('{{ content.title }}', $files[0]['source']);
        self::assertNotContains('twig', array_column(self::body((new FilesEndpoint($this->root))->handle('component', 'card'))['files'], 'language'));
    }

    #[Test]
    public function an_entry_without_files_answers_an_empty_list(): void
    {
        self::assertSame([], self::body((new FilesEndpoint($this->root))->handle('component', 'plain'))['files']);
    }

    #[Test]
    public function a_symlink_out_of_the_templates_folder_is_not_read(): void
    {
        $outside = (string) tempnam(sys_get_temp_dir(), 'sg-outside-');
        file_put_contents($outside, "secret\n");
        $this->tempFiles[] = $outside;
        symlink($outside, $this->root . '/component/card/js/leak.js');

        $paths = array_column(self::body((new FilesEndpoint($this->root))->handle('component', 'card'))['files'], 'path');
        self::assertNotContains('component/card/js/leak.js', $paths);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refused(): iterable
    {
        yield 'unknown kind' => ['foundations', 'card'];
        yield 'traversal' => ['component', '../card'];
        yield 'unknown entry' => ['component', 'nope'];
    }

    #[Test]
    #[DataProvider('refused')]
    public function it_refuses_what_is_not_a_catalogue_entry(string $kind, string $slug): void
    {
        self::assertSame(404, (new FilesEndpoint($this->root))->handle($kind, $slug)->status);
    }

    /**
     * @param array<string, mixed> $yaml
     */
    private function styleguide(array $yaml): Styleguide
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'sg-files-yaml-');
        file_put_contents($path, Yaml::dump($yaml));
        $this->tempFiles[] = $path;

        return new Styleguide([
            'templates_path' => $this->root,
            'static_path' => __DIR__ . '/../fixtures',
            'config_yaml' => $path,
        ]);
    }

    #[Test]
    public function each_view_is_read_only_when_listed(): void
    {
        $languages = fn (array $views): array => array_column(
            self::body((new FilesEndpoint($this->root, $views))->handle('component', 'card'))['files'],
            'language',
        );

        self::assertSame(['css'], $languages(['css']));
        self::assertSame(['js', 'js'], $languages(['js']));
        self::assertSame(['twig'], $languages(['twig']));
    }

    #[Test]
    public function the_routes_follow_show_source_and_source_views(): void
    {
        $get = fn (array $yaml, string $endpoint = 'files'): Result => $this->styleguide($yaml)
            ->handle(new Request('/styleguide/api/' . $endpoint . '/component/card'))
            ?? self::fail('no result');

        // No show_source: nothing, whatever the views say.
        self::assertSame(404, $get([])->status);
        self::assertStringContainsString('Unknown API endpoint: files', (string) $get([])->body);
        self::assertSame(404, $get(['source_views' => ['twig']])->status);

        // The default: CSS and JS, not the template.
        $default = self::body($get(['show_source' => true]));
        self::assertSame(['css', 'js', 'js'], array_column($default['files'], 'language'));

        // Listed: the template too.
        $all = self::body($get(['show_source' => true, 'source_views' => ['data', 'twig', 'html', 'css', 'js']]));
        self::assertSame('twig', $all['files'][0]['language']);

        // A view left out is a route that does not exist.
        self::assertSame(404, $get(['show_source' => true, 'source_views' => ['data']])->status);
        self::assertSame(404, $get(['show_source' => true, 'source_views' => ['data']], 'markup')->status);
        self::assertSame(404, $get(['show_source' => true, 'source_views' => ['html']], 'source')->status);
    }

    #[Test]
    public function source_views_reach_the_spa_in_the_panel_order(): void
    {
        $config = function (array $yaml): array {
            $html = (string) $this->styleguide($yaml)->handle(new Request('/styleguide/'))?->body;
            self::assertSame(1, preg_match('#<script id="sg-config" type="application/json">(.*?)</script>#s', $html, $m));

            return (array) json_decode($m[1], true, flags: \JSON_THROW_ON_ERROR);
        };

        self::assertSame(['data', 'html', 'css', 'js'], $config(['show_source' => true])['sourceViews']);
        self::assertSame(['data', 'twig', 'js'], $config(['show_source' => true, 'source_views' => ['js', 'twig', 'data']])['sourceViews']);
        self::assertArrayNotHasKey('sourceViews', $config(['source_views' => ['twig']]));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidViews(): iterable
    {
        yield 'empty' => [[]];
        yield 'unknown name' => [['data', 'template']];
        yield 'a string' => ['twig'];
        yield 'a map' => [['twig' => true]];
    }

    #[Test]
    #[DataProvider('invalidViews')]
    public function an_unusable_source_views_fails_at_construction(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('source_views');

        $this->styleguide(['source_views' => $value]);
    }
}
