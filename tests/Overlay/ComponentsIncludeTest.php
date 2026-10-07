<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Overlay;

use Parisek\Styleguide\Cli\Command;
use Parisek\Styleguide\ComponentFilter;
use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * `components.include`: a filter on the catalogue, nothing more.
 */
final class ComponentsIncludeTest extends OverlayTestCase
{
    private string $kit;
    private string $static;

    protected function setUp(): void
    {
        $this->kit = $this->tempDir();
        $this->static = $this->tempDir();
        $twig = static fn(string $name, string $body): string => "{# name: {$name} #}\n{$body}";
        // `page` calls `leaf`; `leaf` is a hidden dependency when only `page` is listed.
        self::put($this->kit . '/component/page/page.twig', $twig('Page', '<main>PAGE {{ component_leaf({}) }}</main>'));
        self::put($this->kit . '/component/page/styleguide.twig', '{{ component_page({}) }}');
        self::put($this->kit . '/component/leaf/leaf.twig', $twig('Leaf', '<i>LEAF-OUTPUT</i>'));
        self::put($this->kit . '/component/leaf/styleguide.twig', '{{ component_leaf({}) }}');
        self::put($this->kit . '/component/other/other.twig', $twig('Other', '<b>OTHER</b>'));
        self::put($this->kit . '/component/other/styleguide.twig', '{{ component_other({}) }}');
        self::put($this->kit . '/page/home/home.twig', $twig('Home', 'HOME {{ component_leaf({}) }}'));
    }

    /**
     * @param string|list<string>|null $templates
     */
    private function catalogue(string $yaml, string|array|null $templates = null): Styleguide
    {
        // The source routes exist only with `show_source`; they must still answer 404 for a hidden id.
        return $this->styleguide(
            $templates ?? $this->kit,
            $this->static,
            [],
            "show_source: true\nsource_views: [data, html, twig, css, js]\n" . $yaml,
        );
    }

    private static function include(string $list): string
    {
        return "components:\n  include: {$list}\n";
    }

    #[Test]
    public function without_the_key_every_component_is_listed(): void
    {
        $all = ['leaf', 'other', 'page'];

        self::assertEqualsCanonicalizing($all, self::componentIds($this->catalogue('')));
        self::assertEqualsCanonicalizing($all, self::componentIds($this->catalogue("components:\n  group_by: kind\n")));
        self::assertSame(200, self::get($this->catalogue(''), '/styleguide/render/component/leaf')->status);
    }

    #[Test]
    public function a_list_shows_exactly_those_components(): void
    {
        $styleguide = $this->catalogue(self::include('[page, other]'));

        self::assertEqualsCanonicalizing(['page', 'other'], self::componentIds($styleguide));
        self::assertEqualsCanonicalizing(
            [['component', 'other', null], ['component', 'page', null]],
            array_map(
                static fn(array $row): array => [$row['kind'], $row['slug'], $row['variant']],
                array_values(array_filter($styleguide->inventory(), static fn(array $row): bool => $row['kind'] === 'component')),
            ),
        );
        self::assertEqualsCanonicalizing(
            [['id' => 'other', 'hasTemplate' => true], ['id' => 'page', 'hasTemplate' => true]],
            $styleguide->componentDirectories(),
        );
        // Pages are not filtered.
        $pages = (array) json_decode((string) self::get($styleguide, '/styleguide/api/pages')->body, true);
        self::assertSame(['home'], array_column($pages, 'id'));
    }

    #[Test]
    public function a_hidden_component_answers_404_on_every_route_that_takes_an_id(): void
    {
        $styleguide = $this->catalogue(self::include('[page]'));

        foreach ([
            '/styleguide/render/component/other',
            '/styleguide/render/component/other?variant=anything',
            '/styleguide/api/source/component/other',
            '/styleguide/api/markup/component/other',
            '/styleguide/api/files/component/other',
            '/styleguide/component/other',
            '/styleguide/component/other?variant=anything',
        ] as $uri) {
            self::assertSame(404, self::get($styleguide, $uri)->status, $uri);
        }
        // The same routes answer for a listed component.
        foreach ([
            '/styleguide/render/component/page',
            '/styleguide/api/source/component/page',
            '/styleguide/api/markup/component/page',
            '/styleguide/api/files/component/page',
            '/styleguide/component/page',
        ] as $uri) {
            self::assertSame(200, self::get($styleguide, $uri)->status, $uri);
        }
    }

    #[Test]
    public function a_hidden_dependency_still_renders_inside_a_listed_parent(): void
    {
        $styleguide = $this->catalogue(self::include('[page]'));

        self::assertSame(['page'], self::componentIds($styleguide));
        self::assertSame(404, self::get($styleguide, '/styleguide/render/component/leaf')->status);

        $render = self::get($styleguide, '/styleguide/render/component/page');
        self::assertSame(200, $render->status);
        self::assertStringContainsString('LEAF-OUTPUT', (string) $render->body, 'the hidden leaf renders for its parent');
        $markup = (string) self::get($styleguide, '/styleguide/api/markup/component/page')->body;
        self::assertStringContainsString('LEAF-OUTPUT', $markup);
    }

    #[Test]
    public function an_unknown_id_is_an_error_naming_the_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"nope"');
        $this->catalogue(self::include('[page, nope]'));
    }

    #[Test]
    public function an_unknown_id_names_every_missing_id(): void
    {
        try {
            $this->catalogue(self::include('[ghost, page, nope]'));
            self::fail('an unknown id must throw');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('components.include', $e->getMessage());
            self::assertStringContainsString('"ghost", "nope"', $e->getMessage());
        }
    }

    #[Test]
    public function a_folder_without_its_template_is_not_a_component(): void
    {
        self::put($this->kit . '/component/bare/styleguide.twig', 'x');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"bare"');
        $this->catalogue(self::include('[bare]'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function wrongTypes(): iterable
    {
        yield 'a string' => ['page', 'components.include` must be a list'];
        yield 'a map' => ['{a: page}', 'components.include` must be a list'];
        yield 'a number' => ['3', 'components.include` must be a list'];
        yield 'null' => ['~', 'components.include` must be a list'];
        yield 'a number in the list' => ['[page, 3]', 'components.include[1]'];
        yield 'a path' => ['["../page"]', 'components.include[0]'];
        yield 'an empty id' => ['[""]', 'components.include[0]'];
        yield 'a nested list' => ['[[page]]', 'components.include[0]'];
    }

    #[Test]
    #[DataProvider('wrongTypes')]
    public function a_wrong_type_fails_with_a_clear_message(string $value, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->catalogue(self::include($value));
    }

    #[Test]
    public function an_empty_list_shows_no_components(): void
    {
        $styleguide = $this->catalogue(self::include('[]'));

        self::assertSame([], self::componentIds($styleguide));
        self::assertSame(404, self::get($styleguide, '/styleguide/render/component/page')->status);
        self::assertSame(404, self::get($styleguide, '/styleguide/component/page')->status);
    }

    #[Test]
    public function a_duplicate_id_is_listed_once(): void
    {
        self::assertSame(['page'], self::componentIds($this->catalogue(self::include('[page, page]'))));
    }

    #[Test]
    public function it_works_with_a_list_of_template_roots(): void
    {
        $project = $this->tempDir();
        self::put($project . '/component/leaf/leaf.twig', "{# name: Leaf #}\n<i>PROJECT-LEAF</i>");
        self::put($project . '/component/leaf/styleguide.twig', '{{ component_leaf({}) }}');

        $styleguide = $this->catalogue(self::include('[page, leaf]'), [$project, $this->kit]);

        self::assertEqualsCanonicalizing(['page', 'leaf'], self::componentIds($styleguide));
        $render = (string) self::get($styleguide, '/styleguide/render/component/page')->body;
        self::assertStringContainsString('PROJECT-LEAF', $render, 'the override from the first root wins');
        self::assertSame(404, self::get($styleguide, '/styleguide/render/component/other')->status);

        // A listed id that only the second root has is found; one no root has is an error.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"missing"');
        $this->catalogue(self::include('[page, missing]'), [$project, $this->kit]);
    }

    #[Test]
    public function the_cli_list_and_show_follow_the_config(): void
    {
        file_put_contents($this->static . '/styleguide.yaml', self::include('[page]'));
        $run = function (array $argv): array {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            $exit = (new Command())->run($argv, $stdout, $stderr);
            rewind($stdout);
            rewind($stderr);

            return [$exit, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
        };
        $args = ['--templates=' . $this->kit, '--config=' . $this->static . '/styleguide.yaml'];

        [$exit, $out] = $run(['list', ...$args]);
        self::assertSame(0, $exit);
        self::assertSame(['page'], array_column((array) json_decode($out, true), 'id'));

        [$exit] = $run(['show', 'other', ...$args]);
        self::assertSame(1, $exit, 'a hidden component is not found');
        [$exit] = $run(['show', 'page', ...$args]);
        self::assertSame(0, $exit);

        file_put_contents($this->static . '/styleguide.yaml', self::include('[nope]'));
        [$exit, , $err] = $run(['list', ...$args]);
        self::assertSame(1, $exit);
        self::assertStringContainsString('"nope"', $err);

        // The key has no effect on pages.
        file_put_contents($this->static . '/styleguide.yaml', self::include('[page]'));
        [$exit, $out] = $run(['list', '--type=page', ...$args]);
        self::assertSame(0, $exit);
        self::assertSame(['home'], array_column((array) json_decode($out, true), 'id'));
    }

    #[Test]
    public function doctor_reports_an_unknown_id(): void
    {
        self::put($this->static . '/styleguide.yaml', "bootstrap:\n  templates_path: [" . $this->kit . "]\n  static_path: .\ncomponents:\n  include: [nope]\n");

        $findings = (new \Parisek\Styleguide\Cli\Doctor())->run($this->static . '/styleguide.yaml');

        self::assertCount(1, $findings);
        self::assertSame('config', $findings[0]->toArray()['check']);
        self::assertStringContainsString('"nope"', $findings[0]->toArray()['message']);
    }

    #[Test]
    public function the_filter_reads_only_a_components_map(): void
    {
        self::assertNull(ComponentFilter::fromConfig(null));
        self::assertNull(ComponentFilter::fromConfig(['group_by' => 'kind']));
        self::assertNull(ComponentFilter::fromConfig(['a', 'b']));
        self::assertSame(['a'], ComponentFilter::fromConfig(['include' => ['a']])?->ids());
    }
}
