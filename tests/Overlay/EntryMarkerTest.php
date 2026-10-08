<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Overlay;

use Parisek\Styleguide\Cli\Command;
use Parisek\Styleguide\Cli\Linter;
use Parisek\Styleguide\ComponentFilter;
use Parisek\Styleguide\ComponentParser;
use Parisek\Styleguide\Styleguide;
use Parisek\Styleguide\TemplateRoots;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * ADR-0009: a folder `<kind>/<id>/` is an entry when it holds `<id>.twig`,
 * `<id>.yaml` or `styleguide.twig`. A folder with `<id>.twig` behaves as
 * before (LegacySingleRootTest pins that).
 */
final class EntryMarkerTest extends OverlayTestCase
{
    private string $root;
    private string $static;

    protected function setUp(): void
    {
        $this->root = $this->tempDir();
        $this->static = $this->tempDir();
        // Yaml only: metadata, no template, no fixture.
        self::put($this->root . '/page/only-yaml/only-yaml.yaml', "name: Only yaml\ndescription: Metadata only\n");
        // Yaml and fixture, no template.
        self::put($this->root . '/page/yaml-fixture/yaml-fixture.yaml', "name: Yaml fixture\n");
        self::put($this->root . '/page/yaml-fixture/styleguide.twig', '<p>FIXTURE-OUTPUT</p>');
        // Fixture only: no yaml, no template.
        self::put($this->root . '/page/only-fixture/styleguide.twig', '<p>ONLY-FIXTURE-OUTPUT</p>');
        // Template only, as before.
        self::put($this->root . '/page/only-twig/only-twig.twig', "{# name: Only twig #}\n<p>TWIG-OUTPUT</p>");
        // None of the three markers.
        self::put($this->root . '/page/nothing/readme.md', 'No marker.');
        self::put($this->root . '/page/nothing/css/nothing.css', 'a{}');
        // Underscore folders stay out, whatever they hold.
        self::put($this->root . '/page/_partial/_partial.yaml', "name: Partial\n");
        self::put($this->root . '/page/_fixture/styleguide.twig', '<p>NO</p>');
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function rootForms(): iterable
    {
        yield 'string root' => [false];
        yield 'list of roots' => [true];
    }

    private function parser(bool $list): ComponentParser
    {
        return new ComponentParser($list ? [$this->root] : $this->root);
    }

    private function catalogue(bool $list, string $yaml = ''): Styleguide
    {
        return $this->styleguide(
            $list ? [$this->root] : $this->root,
            $this->static,
            [],
            "show_source: true\nsource_views: [data, html, twig, css, js]\n" . $yaml,
        );
    }

    /**
     * @return array<string, array<string, mixed>> id => entry
     */
    private static function byId(ComponentParser $parser, string $type): array
    {
        $rows = [];
        foreach ($parser->parseAll($type) as $entry) {
            $rows[$entry['id']] = $entry;
        }

        return $rows;
    }

    #[Test]
    #[DataProvider('rootForms')]
    public function a_yaml_only_folder_is_listed_with_its_metadata_and_no_tile(bool $list): void
    {
        $entry = self::byId($this->parser($list), 'page')['only-yaml'] ?? null;

        self::assertNotNull($entry);
        self::assertSame('Only yaml', $entry['name']);
        self::assertSame('Metadata only', $entry['description']);
        self::assertFalse($entry['has_styleguide']);
        self::assertFalse($entry['has_default_variant']);
        self::assertSame([], $entry['variants']);
        self::assertSame('Only yaml', $this->parser($list)->parse('page', 'only-yaml')['name'] ?? null);
    }

    #[Test]
    #[DataProvider('rootForms')]
    public function a_yaml_only_folder_has_no_page_to_show(bool $list): void
    {
        $styleguide = $this->catalogue($list);

        // The API lists it; the inventory (one row per real fixture) skips it.
        $rows = (array) json_decode((string) self::get($styleguide, '/styleguide/api/pages')->body, true);
        self::assertContains('only-yaml', array_column($rows, 'id'));
        self::assertNotContains(
            'only-yaml',
            array_column(array_filter($styleguide->inventory(), static fn(array $row): bool => $row['kind'] === 'page'), 'slug'),
        );
        // Nothing renders: no template and no fixture. The answer is a 404, not an error.
        self::assertSame(404, self::get($styleguide, '/styleguide/render/page/only-yaml')->status);
        // The files endpoint knows the entry and has no file to show.
        $files = self::get($styleguide, '/styleguide/api/files/page/only-yaml');
        self::assertSame(200, $files->status);
        self::assertSame([], (array) (json_decode((string) $files->body, true)['files'] ?? null));
    }

    #[Test]
    #[DataProvider('rootForms')]
    public function a_fixture_renders_without_a_production_template(bool $list): void
    {
        $styleguide = $this->catalogue($list);
        $entry = self::byId($this->parser($list), 'page')['yaml-fixture'] ?? null;

        self::assertNotNull($entry);
        self::assertTrue($entry['has_styleguide']);
        self::assertTrue($entry['has_default_variant']);
        $render = self::get($styleguide, '/styleguide/render/page/yaml-fixture');
        self::assertSame(200, $render->status);
        self::assertStringContainsString('FIXTURE-OUTPUT', (string) $render->body);
    }

    #[Test]
    #[DataProvider('rootForms')]
    public function a_fixture_only_folder_is_an_entry_but_has_no_name_to_list(bool $list): void
    {
        $parser = $this->parser($list);

        // Like a template with no `name:` today: an entry, not in the catalogue.
        self::assertArrayNotHasKey('only-fixture', self::byId($parser, 'page'));
        self::assertContains(
            ['id' => 'only-fixture', 'hasTemplate' => true],
            $parser->listDirectories('page'),
        );
        // It renders, so a direct link works.
        self::assertSame(200, self::get($this->catalogue($list), '/styleguide/render/page/only-fixture')->status);
    }

    #[Test]
    #[DataProvider('rootForms')]
    public function pages_include_lists_a_fixture_only_page_with_a_title_from_its_id(bool $list): void
    {
        $styleguide = $this->catalogue($list, "pages:\n  include: [only-fixture]\n");
        $rows = (array) json_decode((string) self::get($styleguide, '/styleguide/api/pages')->body, true);

        self::assertSame(['only-fixture'], array_column($rows, 'id'));
        self::assertSame('Only fixture', $rows[0]['name']);
        self::assertTrue($rows[0]['has_styleguide']);
    }

    #[Test]
    #[DataProvider('rootForms')]
    public function a_folder_with_a_template_behaves_as_before(bool $list): void
    {
        $entry = self::byId($this->parser($list), 'page')['only-twig'] ?? null;

        self::assertNotNull($entry);
        self::assertSame('Only twig', $entry['name']);
        self::assertFalse($entry['has_styleguide']);
        self::assertContains(['id' => 'only-twig', 'hasTemplate' => true], $this->parser($list)->listDirectories('page'));
        $render = self::get($this->catalogue($list), '/styleguide/render/page/only-twig');
        self::assertSame(200, $render->status);
        self::assertStringContainsString('TWIG-OUTPUT', (string) $render->body);
    }

    #[Test]
    #[DataProvider('rootForms')]
    public function a_folder_without_a_marker_is_not_an_entry(bool $list): void
    {
        $parser = $this->parser($list);

        self::assertContains(['id' => 'nothing', 'hasTemplate' => false], $parser->listDirectories('page'));
        self::assertArrayNotHasKey('nothing', self::byId($parser, 'page'));
        self::assertNull($parser->parse('page', 'nothing'));
        self::assertSame(404, self::get($this->catalogue($list), '/styleguide/render/page/nothing')->status);
    }

    #[Test]
    #[DataProvider('rootForms')]
    public function an_underscore_folder_stays_out_when_only_a_new_marker_is_there(bool $list): void
    {
        $parser = $this->parser($list);

        self::assertArrayNotHasKey('_partial', self::byId($parser, 'page'));
        self::assertNull($parser->parse('page', '_partial'));
        self::assertContains(['id' => '_partial', 'hasTemplate' => false], $parser->listDirectories('page'));
        self::assertContains(['id' => '_fixture', 'hasTemplate' => false], $parser->listDirectories('page'));
    }

    #[Test]
    public function the_first_root_with_any_marker_owns_the_folder(): void
    {
        $kit = $this->tempDir();
        self::put($kit . '/page/only-yaml/only-yaml.twig', "{# name: Kit page #}\n<p>KIT-OUTPUT</p>");
        self::put($kit . '/page/only-yaml/styleguide.twig', '<p>KIT-FIXTURE</p>');
        self::put($kit . '/page/only-yaml/only-yaml.yaml', "name: Kit page\n");
        $roots = [$this->root, $kit];

        self::assertSame(0, TemplateRoots::from($roots)->ownerIndex('page', 'only-yaml'));
        $entries = (new ComponentParser($roots))->parseAll('page');
        $mine = array_values(array_filter($entries, static fn(array $e): bool => $e['id'] === 'only-yaml'));
        self::assertCount(1, $mine);
        self::assertSame('Only yaml', $mine[0]['name']);
        self::assertFalse($mine[0]['has_styleguide']);

        // The kit's template and fixture do not leak in.
        $styleguide = $this->styleguide($roots, $this->static, [], "show_source: true\nsource_views: [twig]\n");
        self::assertSame(404, self::get($styleguide, '/styleguide/render/page/only-yaml')->status);
        $files = (array) json_decode((string) self::get($styleguide, '/styleguide/api/files/page/only-yaml')->body, true);
        self::assertSame([], $files['files']);
    }

    #[Test]
    public function a_weaker_root_with_a_marker_owns_what_the_stronger_root_lacks(): void
    {
        $kit = $this->tempDir();
        self::put($kit . '/page/kit-only/styleguide.twig', '<p>KIT-ONLY</p>');
        self::put($kit . '/page/kit-only/kit-only.yaml', "name: Kit only\n");

        self::assertSame(1, TemplateRoots::from([$this->root, $kit])->ownerIndex('page', 'kit-only'));
        self::assertSame(
            200,
            self::get($this->styleguide([$this->root, $kit], $this->static), '/styleguide/render/page/kit-only')->status,
        );
    }

    #[Test]
    public function a_marker_that_is_a_symlink_out_of_its_root_does_not_count(): void
    {
        $kit = $this->tempDir();
        self::put($kit . '/page/linked/linked.yaml', "name: Kit linked\n");
        mkdir($this->root . '/page/linked', 0777, true);
        symlink($kit . '/page/linked/linked.yaml', $this->root . '/page/linked/linked.yaml');
        // A second folder: the escaping fixture is refused, the contained yaml counts.
        self::put($this->root . '/page/mixed/mixed.yaml', "name: Mixed\n");
        symlink($kit . '/page/linked/linked.yaml', $this->root . '/page/mixed/styleguide.twig');

        $roots = TemplateRoots::from([$this->root, $kit]);
        self::assertSame(1, $roots->ownerIndex('page', 'linked'));
        self::assertSame(0, $roots->ownerIndex('page', 'mixed'));

        $parser = new ComponentParser([$this->root, $kit]);
        $entries = self::byId($parser, 'page');
        self::assertSame('Kit linked', $entries['linked']['name']);
        self::assertSame('Mixed', $entries['mixed']['name']);

        // With the kit out of the list the escaping marker is no entry at all.
        $alone = new ComponentParser([$this->root]);
        self::assertArrayNotHasKey('linked', self::byId($alone, 'page'));
        self::assertContains(['id' => 'linked', 'hasTemplate' => false], $alone->listDirectories('page'));
        self::assertNull($alone->parse('page', 'linked'));
    }

    #[Test]
    #[DataProvider('rootForms')]
    public function the_include_filters_accept_a_marker_only_entry(bool $list): void
    {
        $parser = $this->parser($list);

        foreach (['[only-yaml, only-fixture, yaml-fixture]', '[only-yaml]'] as $ids) {
            $filter = ComponentFilter::fromConfig(['include' => self::parseList($ids)], 'page');
            self::assertNotNull($filter);
            $filter->assertAllExist($parser->listDirectories('page'));
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"nothing"');
        $filter = ComponentFilter::fromConfig(['include' => ['only-yaml', 'nothing']], 'page');
        self::assertNotNull($filter);
        $filter->assertAllExist($parser->listDirectories('page'));
    }

    /**
     * @return list<string>
     */
    private static function parseList(string $flow): array
    {
        return array_map('trim', explode(',', trim($flow, '[]')));
    }

    #[Test]
    #[DataProvider('rootForms')]
    public function pages_include_lists_a_yaml_only_page_and_hides_the_others(bool $list): void
    {
        $styleguide = $this->catalogue($list, "pages:\n  include: [only-yaml]\n");
        $rows = (array) json_decode((string) self::get($styleguide, '/styleguide/api/pages')->body, true);

        self::assertSame(['only-yaml'], array_column($rows, 'id'));
        self::assertSame(404, self::get($styleguide, '/styleguide/render/page/yaml-fixture')->status);
    }

    #[Test]
    public function a_components_include_with_a_yaml_only_component_boots(): void
    {
        self::put($this->root . '/component/badge/badge.yaml', "name: Badge\n");
        $styleguide = $this->catalogue(true, "components:\n  include: [badge]\n");

        self::assertSame(['badge'], self::componentIds($styleguide));
    }

    #[Test]
    public function the_cli_lists_shows_and_lints_a_marker_only_entry(): void
    {
        [$exit, $out] = $this->runCli(['list', '--type=page', '--templates=' . $this->root]);
        self::assertSame(0, $exit);
        $ids = array_column((array) json_decode($out, true), 'id');
        self::assertContains('only-yaml', $ids);
        self::assertContains('yaml-fixture', $ids);

        [$exit, $out] = $this->runCli(['show', 'only-yaml', '--type=page', '--templates=' . $this->root]);
        self::assertSame(0, $exit);
        self::assertSame('Only yaml', json_decode($out, true)['name']);

        [$exit] = $this->runCli(['show', 'nothing', '--type=page', '--templates=' . $this->root]);
        self::assertSame(1, $exit);

        $files = array_column((new Linter($this->root))->run(['page']), 'file');
        // A yaml-only entry is linted: it has no fixture.
        self::assertContains('page/only-yaml/only-yaml.yaml', $files);
        // A folder with no marker is not walked.
        self::assertNotContains('page/nothing/nothing.twig', $files);
    }

    #[Test]
    public function the_linter_names_the_yaml_when_an_entry_has_no_name(): void
    {
        self::put($this->root . '/page/no-name/no-name.yaml', "description: No name\n");
        self::put($this->root . '/page/bad-yaml/bad-yaml.yaml', "name: [unclosed\n");

        $findings = (new Linter($this->root))->run(['page']);
        $byRule = [];
        foreach ($findings as $finding) {
            $byRule[$finding->rule][] = $finding;
        }

        $unindexed = array_column($byRule['unindexed'] ?? [], 'message', 'file');
        self::assertStringContainsString('<id>.yaml', $unindexed['page/no-name/no-name.yaml'] ?? '');
        self::assertStringNotContainsString('{# #}', $unindexed['page/no-name/no-name.yaml'] ?? '');
        // A fixture without a yaml has no name either.
        self::assertArrayHasKey('page/only-fixture/only-fixture.twig', $unindexed);
        self::assertContains('page/bad-yaml/bad-yaml.yaml', array_column($byRule['sidecar-yaml-invalid'] ?? [], 'file'));
    }

    /**
     * @param list<string> $argv
     * @return array{0:int, 1:string}
     */
    private function runCli(array $argv): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);
        $exit = (new Command())->run($argv, $stdout, $stderr);
        rewind($stdout);

        return [$exit, (string) stream_get_contents($stdout)];
    }
}
