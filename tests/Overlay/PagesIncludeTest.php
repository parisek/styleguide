<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Overlay;

use Parisek\Styleguide\Cli\Command;
use Parisek\Styleguide\ComponentFilter;
use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * `pages.include`: a filter on the catalogue, nothing more. A listed page that
 * carries no metadata is listed with a title taken from its id.
 */
final class PagesIncludeTest extends OverlayTestCase
{
    private string $kit;
    private string $static;

    protected function setUp(): void
    {
        $this->kit = $this->tempDir();
        $this->static = $this->tempDir();
        self::put($this->kit . '/component/leaf/leaf.twig', "{# name: Leaf #}\n<i>LEAF-OUTPUT</i>");
        self::put($this->kit . '/component/leaf/styleguide.twig', '{{ component_leaf({}) }}');
        // Two pages with metadata, two without.
        self::put($this->kit . '/page/home/home.twig', "{# name: Home #}\nHOME {{ component_leaf({}) }}");
        self::put($this->kit . '/page/home/styleguide.twig', '{% include "@page/home/home.twig" %}');
        self::put($this->kit . '/page/about/about.twig', "{# name: About #}\nABOUT");
        self::put($this->kit . '/page/about/styleguide.twig', '{% include "@page/about/about.twig" %}');
        self::put($this->kit . '/page/boat-rental/boat-rental.twig', 'BOAT {{ component_leaf({}) }}');
        self::put($this->kit . '/page/contact_us/contact_us.twig', 'CONTACT');
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
        return "pages:\n  include: {$list}\n";
    }

    /**
     * @return array<string, string> id => name
     */
    private static function pages(Styleguide $styleguide): array
    {
        $result = self::get($styleguide, '/styleguide/api/pages');
        $rows = (array) json_decode((string) $result->body, true);

        return array_column($rows, 'name', 'id');
    }

    #[Test]
    public function without_the_key_a_page_without_metadata_stays_out(): void
    {
        $expected = ['about' => 'About', 'home' => 'Home'];

        self::assertEqualsCanonicalizing($expected, self::pages($this->catalogue('')));
        self::assertEqualsCanonicalizing($expected, self::pages($this->catalogue("pages:\n  group_by: category\n")));
        self::assertSame(200, self::get($this->catalogue(''), '/styleguide/render/page/home')->status);
    }

    #[Test]
    public function a_list_shows_exactly_those_pages(): void
    {
        $styleguide = $this->catalogue(self::include('[home]'));

        self::assertSame(['home' => 'Home'], self::pages($styleguide));
        self::assertSame(
            [['page', 'home', null]],
            array_map(
                static fn(array $row): array => [$row['kind'], $row['slug'], $row['variant']],
                array_values(array_filter($styleguide->inventory(), static fn(array $row): bool => $row['kind'] === 'page')),
            ),
        );
    }

    #[Test]
    public function a_hidden_page_answers_404_on_every_route_that_takes_an_id(): void
    {
        $styleguide = $this->catalogue(self::include('[home]'));

        foreach ([
            '/styleguide/render/page/about',
            '/styleguide/render/page/about?variant=anything',
            '/styleguide/api/source/page/about',
            '/styleguide/api/markup/page/about',
            '/styleguide/api/files/page/about',
            '/styleguide/page/about',
            '/styleguide/page/about?variant=anything',
        ] as $uri) {
            self::assertSame(404, self::get($styleguide, $uri)->status, $uri);
        }
        foreach ([
            '/styleguide/render/page/home',
            '/styleguide/api/source/page/home',
            '/styleguide/api/markup/page/home',
            '/styleguide/api/files/page/home',
            '/styleguide/page/home',
        ] as $uri) {
            self::assertSame(200, self::get($styleguide, $uri)->status, $uri);
        }
    }

    #[Test]
    public function an_unknown_id_is_an_error_naming_the_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"nope"');
        $this->catalogue(self::include('[home, nope]'));
    }

    #[Test]
    public function an_unknown_id_names_every_missing_id(): void
    {
        try {
            $this->catalogue(self::include('[ghost, home, nope]'));
            self::fail('an unknown id must throw');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('pages.include', $e->getMessage());
            self::assertStringContainsString('no page in templates_path', $e->getMessage());
            self::assertStringContainsString('"ghost", "nope"', $e->getMessage());
        }
    }

    #[Test]
    public function a_component_id_is_not_a_page(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"leaf"');
        $this->catalogue(self::include('[leaf]'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function wrongTypes(): iterable
    {
        yield 'a string' => ['home', 'pages.include` must be a list of page ids'];
        yield 'a map' => ['{a: home}', 'pages.include` must be a list'];
        yield 'a number' => ['3', 'pages.include` must be a list'];
        yield 'null' => ['~', 'pages.include` must be a list'];
        yield 'a number in the list' => ['[home, 3]', 'pages.include[1]'];
        yield 'a path' => ['["../home"]', 'pages.include[0]'];
        yield 'an empty id' => ['[""]', 'pages.include[0]'];
        yield 'a nested list' => ['[[home]]', 'pages.include[0]'];
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
    public function an_empty_list_shows_no_pages(): void
    {
        $styleguide = $this->catalogue(self::include('[]'));

        self::assertSame([], self::pages($styleguide));
        self::assertSame(404, self::get($styleguide, '/styleguide/render/page/home')->status);
        self::assertSame(404, self::get($styleguide, '/styleguide/page/home')->status);
    }

    #[Test]
    public function a_duplicate_id_is_listed_once(): void
    {
        self::assertSame(['home' => 'Home'], self::pages($this->catalogue(self::include('[home, home]'))));
    }

    #[Test]
    public function a_listed_page_without_metadata_appears_with_a_title_from_its_id(): void
    {
        $styleguide = $this->catalogue(self::include('[boat-rental, contact_us, home]'));

        self::assertEqualsCanonicalizing(
            ['boat-rental' => 'Boat rental', 'contact_us' => 'Contact us', 'home' => 'Home'],
            self::pages($styleguide),
        );
        $rows = (array) json_decode((string) self::get($styleguide, '/styleguide/api/pages')->body, true);
        $row = array_column($rows, null, 'id')['boat-rental'];
        self::assertSame('', $row['description']);
        self::assertSame('', $row['category']);
        self::assertSame([], $row['usage']);
        self::assertSame(50, $row['weight']);
    }

    #[Test]
    public function a_listed_page_without_metadata_renders(): void
    {
        $styleguide = $this->catalogue(self::include('[boat-rental]'));

        $render = self::get($styleguide, '/styleguide/render/page/boat-rental');
        self::assertSame(200, $render->status);
        self::assertStringContainsString('LEAF-OUTPUT', (string) $render->body);
        self::assertSame(200, self::get($styleguide, '/styleguide/api/markup/page/boat-rental')->status);
        self::assertSame(200, self::get($styleguide, '/styleguide/page/boat-rental')->status);
    }

    #[Test]
    public function a_listed_page_keeps_the_metadata_it_has(): void
    {
        self::put($this->kit . '/page/about/about.twig', "{# name: About us\ndescription: Who we are #}\nABOUT");
        self::put($this->kit . '/page/boat-rental/boat-rental.twig', "{# description: Rent a boat #}\nBOAT");

        $rows = (array) json_decode((string) self::get(
            $this->catalogue(self::include('[about, boat-rental]')),
            '/styleguide/api/pages',
        )->body, true);
        $byId = array_column($rows, null, 'id');

        self::assertSame('About us', $byId['about']['name']);
        self::assertSame('Who we are', $byId['about']['description']);
        self::assertSame('Boat rental', $byId['boat-rental']['name'], 'metadata without a name gets the default title');
        self::assertSame('Rent a boat', $byId['boat-rental']['description']);
    }

    #[Test]
    public function the_default_title_is_for_listed_pages_only(): void
    {
        // `contact_us` has no metadata and is not listed: it stays out.
        $styleguide = $this->catalogue(self::include('[home, boat-rental]'));

        self::assertArrayNotHasKey('contact_us', self::pages($styleguide));
        self::assertSame(404, self::get($styleguide, '/styleguide/render/page/contact_us')->status);
    }

    #[Test]
    public function it_does_not_filter_components(): void
    {
        self::put($this->kit . '/component/other/other.twig', "{# name: Other #}\n<b>OTHER</b>");
        self::put($this->kit . '/component/other/styleguide.twig', '{{ component_other({}) }}');

        $styleguide = $this->catalogue(self::include('[home]'));

        self::assertEqualsCanonicalizing(['leaf', 'other'], self::componentIds($styleguide));
        self::assertSame(200, self::get($styleguide, '/styleguide/render/component/other')->status);
    }

    #[Test]
    public function both_keys_work_together(): void
    {
        $styleguide = $this->catalogue("components:\n  include: [leaf]\npages:\n  include: [boat-rental]\n");

        self::assertSame(['leaf'], self::componentIds($styleguide));
        self::assertSame(['boat-rental' => 'Boat rental'], self::pages($styleguide));
    }

    #[Test]
    public function it_works_with_a_list_of_template_roots(): void
    {
        $project = $this->tempDir();
        self::put($project . '/page/boat-rental/boat-rental.twig', 'PROJECT-BOAT');
        self::put($project . '/page/home/home.twig', "{# name: Project home #}\nPROJECT-HOME");

        $styleguide = $this->catalogue(self::include('[boat-rental, home, about]'), [$project, $this->kit]);

        self::assertEqualsCanonicalizing(
            ['boat-rental' => 'Boat rental', 'home' => 'Project home', 'about' => 'About'],
            self::pages($styleguide),
        );
        self::assertStringContainsString('PROJECT-BOAT', (string) self::get($styleguide, '/styleguide/render/page/boat-rental')->body);
        self::assertStringContainsString('PROJECT-HOME', (string) self::get($styleguide, '/styleguide/render/page/home')->body);
        self::assertSame(404, self::get($styleguide, '/styleguide/render/page/contact_us')->status);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"missing"');
        $this->catalogue(self::include('[home, missing]'), [$project, $this->kit]);
    }

    #[Test]
    public function the_cli_list_and_show_follow_the_config(): void
    {
        file_put_contents($this->static . '/styleguide.yaml', self::include('[boat-rental]'));
        $run = function (array $argv): array {
            $stdout = fopen('php://memory', 'w+');
            $stderr = fopen('php://memory', 'w+');
            $exit = (new Command())->run($argv, $stdout, $stderr);
            rewind($stdout);
            rewind($stderr);

            return [$exit, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
        };
        $args = ['--templates=' . $this->kit, '--config=' . $this->static . '/styleguide.yaml'];

        [$exit, $out] = $run(['list', '--type=page', ...$args]);
        self::assertSame(0, $exit);
        self::assertSame(['boat-rental'], array_column((array) json_decode($out, true), 'id'));

        [$exit] = $run(['show', 'home', '--type=page', ...$args]);
        self::assertSame(1, $exit, 'a hidden page is not found');
        [$exit] = $run(['show', 'boat-rental', '--type=page', ...$args]);
        self::assertSame(0, $exit);

        // The key has no effect on components.
        [$exit, $out] = $run(['list', ...$args]);
        self::assertSame(0, $exit);
        self::assertSame(['leaf'], array_column((array) json_decode($out, true), 'id'));

        file_put_contents($this->static . '/styleguide.yaml', self::include('[nope]'));
        [$exit, , $err] = $run(['list', '--type=page', ...$args]);
        self::assertSame(1, $exit);
        self::assertStringContainsString('"nope"', $err);
    }

    #[Test]
    public function doctor_reports_an_unknown_id(): void
    {
        self::put($this->static . '/styleguide.yaml', "bootstrap:\n  templates_path: [" . $this->kit . "]\n  static_path: .\npages:\n  include: [nope]\n");

        $findings = (new \Parisek\Styleguide\Cli\Doctor())->run($this->static . '/styleguide.yaml');

        self::assertCount(1, $findings);
        self::assertSame('config', $findings[0]->toArray()['check']);
        self::assertStringContainsString('"nope"', $findings[0]->toArray()['message']);
    }

    #[Test]
    public function the_filter_reads_only_a_pages_map(): void
    {
        self::assertNull(ComponentFilter::fromConfig(null, 'page'));
        self::assertNull(ComponentFilter::fromConfig(['group_by' => 'category'], 'page'));
        self::assertNull(ComponentFilter::fromConfig(['a', 'b'], 'page'));
        self::assertSame(['a'], ComponentFilter::fromConfig(['include' => ['a']], 'page')?->ids());
    }
}
