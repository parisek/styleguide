<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Overlay;

use PHPUnit\Framework\Attributes\Test;
use Twig\Loader\FilesystemLoader;

/**
 * A project root over a kit root, built from small synthetic trees.
 */
final class OverlayCatalogueTest extends OverlayTestCase
{
    private string $project;
    private string $kit;
    private string $static;

    protected function setUp(): void
    {
        $this->project = $this->tempDir();
        $this->kit = $this->tempDir();
        $this->static = $this->tempDir();

        // The kit.
        self::put($this->kit . '/component/base/base.twig', "{# name: Base #}\n<div class=\"base\">{% block content %}{% endblock %}</div>");
        self::put($this->kit . '/component/button/button.twig', "{# name: Button #}\n<button>KIT-BUTTON</button>");
        self::put($this->kit . '/component/button/styleguide.twig', '<p>KIT-BUTTON-FIXTURE</p>');
        self::put(
            $this->kit . '/component/card/card.twig',
            "{# name: Card #}\n{% import '@macro/m/m.twig' as m %}{% extends '@component/base/base.twig' %}"
            . '{% block content %}{{ m.mark() }} {{ component_button({}) }}{% endblock %}',
        );
        self::put($this->kit . '/component/card/styleguide.twig', '{{ component_card({}) }} DATA={{ styleguide_data().label }}');
        self::put($this->kit . '/component/card/styleguide.data.yaml', "label: KIT-DATA\n");
        self::put($this->kit . '/component/card/card.yaml', "name: Card from the kit\ncategory: Kit\n");
        self::put($this->kit . '/component/panel/panel.twig', "{# name: Panel #}\n<aside>KIT-PANEL</aside>");
        self::put($this->kit . '/component/panel/styleguide.twig', '<p>KIT-PANEL-FIXTURE</p>');
        self::put($this->kit . '/component/panel/styleguide.data.yaml', "label: KIT-PANEL-DATA\n");
        self::put($this->kit . '/macro/m/m.twig', '{% macro mark() %}KIT-MACRO{% endmacro %}');
        self::put($this->kit . '/page/home/home.twig', "{# name: Kit home #}\nkit home");

        // The project: overrides the button (template only) and the panel
        // (own fixture, no data sidecar), adds a hero, adds a page.
        self::put($this->project . '/component/button/button.twig', "{# name: Project button #}\n<button>PROJECT-BUTTON</button>");
        self::put($this->project . '/component/panel/panel.twig', "{# name: Project panel #}\n<aside>PROJECT-PANEL</aside>");
        self::put($this->project . '/component/panel/styleguide.twig', 'PANEL-DATA={{ styleguide_data().label }}');
        self::put($this->project . '/component/hero/hero.twig', "{# name: Hero #}\n<section>HERO {{ component_button({}) }}</section>");
        self::put($this->project . '/component/hero/styleguide.twig', '{{ component_hero({}) }}');
        self::put($this->project . '/page/about/about.twig', "{# name: About #}\nabout");
    }

    private function overlay(array $extra = [], string $yaml = ''): \Parisek\Styleguide\Styleguide
    {
        return $this->styleguide([$this->project, $this->kit], $this->static, $extra, $yaml);
    }

    #[Test]
    public function the_twig_paths_are_ordered_project_first(): void
    {
        $styleguide = $this->overlay();
        $twig = (new \ReflectionProperty($styleguide, 'twig'))->getValue($styleguide);
        /** @var FilesystemLoader $loader */
        $loader = $twig->getLoader();

        foreach (['component', 'macro', 'page', 'static'] as $namespace) {
            $suffix = $namespace === 'static' ? '' : '/' . $namespace;
            $expected = array_values(array_filter(
                [$this->project . $suffix, $this->kit . $suffix],
                'is_dir',
            ));
            self::assertSame($expected, $loader->getPaths($namespace), "@{$namespace}");
        }
        self::assertSame([$this->project, $this->kit], $loader->getPaths('project'));

        // The order decides the winner, on the real loader.
        self::assertStringContainsString('PROJECT-BUTTON', $loader->getSourceContext('@component/button/button.twig')->getCode());
        self::assertStringContainsString('PROJECT-PANEL', $loader->getSourceContext('@component/panel/panel.twig')->getCode());
        self::assertStringContainsString('KIT-MACRO', $loader->getSourceContext('@macro/m/m.twig')->getCode());
        self::assertSame(
            $this->kit . '/component/card/card.twig',
            $loader->getSourceContext('@component/card/card.twig')->getPath(),
        );
        self::assertSame(
            $this->project . '/component/button/button.twig',
            $loader->getSourceContext('@component/button/button.twig')->getPath(),
        );
    }

    #[Test]
    public function the_catalogue_lists_the_union_once(): void
    {
        $ids = self::componentIds($this->overlay());
        sort($ids);

        self::assertSame(['base', 'button', 'card', 'hero', 'panel'], $ids);
        $pages = array_column((array) json_decode((string) self::get($this->overlay(), '/styleguide/api/pages')->body, true), 'id');
        sort($pages);
        self::assertSame(['about', 'home'], $pages);
    }

    #[Test]
    public function the_project_owns_the_whole_entry_and_no_kit_fixture_leaks_in(): void
    {
        $styleguide = $this->overlay();
        $list = (array) json_decode((string) self::get($styleguide, '/styleguide/api/components')->body, true);
        $button = current(array_filter($list, static fn(array $c): bool => $c['id'] === 'button'));

        self::assertSame('Project button', $button['name']);
        self::assertFalse($button['has_styleguide'], 'the kit fixture is not borrowed');

        $render = self::get($styleguide, '/styleguide/render/component/button');
        self::assertStringContainsString('PROJECT-BUTTON', (string) $render->body);
        self::assertStringNotContainsString('KIT-BUTTON-FIXTURE', (string) $render->body);
    }

    #[Test]
    public function a_kit_entry_uses_the_project_override_through_the_twig_namespace(): void
    {
        $render = (string) self::get($this->overlay(), '/styleguide/render/component/card')->body;

        self::assertStringContainsString('PROJECT-BUTTON', $render, 'component_button() finds the override');
        self::assertStringNotContainsString('KIT-BUTTON', $render);
        self::assertStringContainsString('KIT-MACRO', $render, 'the macro comes from the kit');
        self::assertStringContainsString('class="base"', $render, 'the base comes from the kit');
        self::assertStringContainsString('DATA=KIT-DATA', $render, 'the sidecar comes from the owner');
    }

    #[Test]
    public function a_data_sidecar_is_never_taken_from_a_second_root(): void
    {
        // The project owns `panel` and has no sidecar. The kit has one.
        $render = self::get($this->overlay(), '/styleguide/render/component/panel');

        self::assertStringNotContainsString('KIT-PANEL-DATA', (string) $render->body);
        self::assertStringContainsString('sidecar file not found', (string) $render->body);
    }

    #[Test]
    public function the_source_files_are_relative_to_the_owning_root(): void
    {
        $parser = new \Parisek\Styleguide\ComponentParser([$this->project, $this->kit]);
        $card = $parser->parse('component', 'card');
        self::assertNotNull($card);
        self::assertSame([], $parser->getWarnings());

        // card.yaml is the kit's metadata, and it wins over the twig comment.
        self::assertSame('Card from the kit', $card['name']);
    }

    #[Test]
    public function the_files_endpoint_reads_the_owner_only(): void
    {
        self::put($this->kit . '/component/panel/css/panel.css', '.kit-panel{}');
        self::put($this->project . '/component/panel/css/own.css', '.project-panel{}');
        $body = (string) self::get($this->overlay([], "show_source: true\nsource_views: [data, twig, css, js]\n"), '/styleguide/api/files/component/panel')->body;
        $files = (array) json_decode($body, true);

        $paths = array_column($files['files'], 'path');
        self::assertContains('component/panel/css/own.css', $paths);
        self::assertContains('component/panel/panel.twig', $paths);
        self::assertNotContains('component/panel/css/panel.css', $paths, 'the kit css belongs to the kit entry');
    }

    #[Test]
    public function a_symlink_into_the_kit_does_not_render_as_a_project_file(): void
    {
        mkdir($this->project . '/component/linked', 0777, true);
        symlink($this->kit . '/component/button/button.twig', $this->project . '/component/linked/linked.twig');
        $styleguide = $this->overlay();

        $render = self::get($styleguide, '/styleguide/render/component/linked');
        self::assertStringNotContainsString('KIT-BUTTON', (string) $render->body);
    }

    #[Test]
    public function a_single_root_list_behaves_like_the_string(): void
    {
        $viaList = $this->styleguide([$this->kit], $this->static);
        $viaString = $this->styleguide($this->kit, $this->static);

        self::assertSame(
            (string) self::get($viaString, '/styleguide/api/components')->body,
            (string) self::get($viaList, '/styleguide/api/components')->body,
        );
        self::assertSame(
            (string) self::get($viaString, '/styleguide/render/component/card')->body,
            (string) self::get($viaList, '/styleguide/render/component/card')->body,
        );
    }
}
