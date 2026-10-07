<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Overlay;

use Parisek\Styleguide\Cli\Command;
use Parisek\Styleguide\Cli\Doctor;
use Parisek\Styleguide\ComponentParser;
use Parisek\Styleguide\TemplateRoots;
use PHPUnit\Framework\Attributes\Test;

/**
 * With several template roots, a symlink never crosses a root, and a warning
 * says which root it comes from. With one root the shapes stay as they were.
 */
final class RootReportingTest extends OverlayTestCase
{
    private const BROKEN = "{# name: Broken\nfoo: [unclosed #}\nx";

    #[Test]
    public function a_warning_names_the_root_it_comes_from(): void
    {
        $project = $this->tempDir();
        $kit = $this->tempDir();
        // The same relative path is broken in both roots, under two ids so
        // that both roots are walked.
        self::put($project . '/component/card/card.twig', self::BROKEN);
        self::put($kit . '/component/panel/panel.twig', self::BROKEN);
        self::put($kit . '/component/ok/ok.twig', "{# name: Ok #}\nok");

        $parser = new ComponentParser([$project, $kit]);
        $parser->parseAll('component');

        $warnings = $parser->getWarnings();
        $byFile = array_column($warnings, 'root', 'file');
        self::assertSame('templates_path[0]', $byFile['component/card/card.twig']);
        self::assertSame('templates_path[1]', $byFile['component/panel/panel.twig']);
    }

    #[Test]
    public function a_single_root_keeps_the_old_warning_shape(): void
    {
        $dir = $this->tempDir();
        self::put($dir . '/component/card/card.twig', self::BROKEN);

        $parser = new ComponentParser($dir);
        $parser->parseAll('component');
        $parser->parseAll('component');

        $warnings = $parser->getWarnings();
        self::assertCount(1, $warnings);
        self::assertSame(['file', 'error'], array_keys($warnings[0]));

        // A one-entry list is a list: its warnings name the root.
        $viaList = new ComponentParser([$dir]);
        $viaList->parseAll('component');
        self::assertSame(['file', 'error', 'root'], array_keys($viaList->getWarnings()[0]));
    }

    #[Test]
    public function a_template_symlinked_into_another_root_is_refused_and_does_not_hide_the_real_entry(): void
    {
        $project = $this->tempDir();
        $kit = $this->tempDir();
        self::put($kit . '/component/card/card.twig', "{# name: Kit card #}\nkit");
        mkdir($project . '/component/card', 0777, true);
        symlink($kit . '/component/card/card.twig', $project . '/component/card/card.twig');

        $parser = new ComponentParser([$project, $kit]);
        $items = $parser->parseAll('component');

        self::assertSame(['Kit card'], array_column($items, 'name'), 'the kit entry stands');
        self::assertSame(
            [['file' => 'component/card/card.twig', 'error' => 'template resolves outside its template root', 'root' => 'templates_path[0]']],
            $parser->getWarnings(),
        );
        self::assertSame(1, TemplateRoots::from([$project, $kit])->ownerIndex('component', 'card'), 'the kit owns it');
    }

    #[Test]
    public function a_metadata_yaml_symlinked_into_another_root_is_refused(): void
    {
        $project = $this->tempDir();
        $kit = $this->tempDir();
        self::put($project . '/component/card/card.twig', "{# name: Project card #}\nown");
        self::put($kit . '/component/card/card.yaml', "name: Kit card\n");
        symlink($kit . '/component/card/card.yaml', $project . '/component/card/card.yaml');

        $parser = new ComponentParser([$project, $kit]);

        self::assertNull($parser->parse('component', 'card'));
        self::assertSame('card.yaml resolves outside its template root', $parser->getWarnings()[0]['error']);
        self::assertSame('templates_path[0]', $parser->getWarnings()[0]['root']);
    }

    #[Test]
    public function a_symlink_inside_one_root_is_still_fine(): void
    {
        $project = $this->tempDir();
        $kit = $this->tempDir();
        self::put($project . '/shared/card.twig', "{# name: Card #}\nown");
        mkdir($project . '/component/card', 0777, true);
        symlink($project . '/shared/card.twig', $project . '/component/card/card.twig');
        self::put($kit . '/component/other/other.twig', "{# name: Other #}\nkit");

        $parser = new ComponentParser([$project, $kit]);
        $names = array_column($parser->parseAll('component'), 'name');
        sort($names);

        self::assertSame(['Card', 'Other'], $names);
        self::assertSame([], $parser->getWarnings());
    }

    #[Test]
    public function maintenance_render_refuses_a_list_instead_of_falling_back(): void
    {
        $dir = $this->tempDir();
        self::put($dir . '/a/page/maintenance/maintenance.twig', 'x');
        self::put($dir . '/b/page/other/other.twig', 'x');
        self::put($dir . '/dist/style.css', '.a{}');
        self::put($dir . '/styleguide.yaml', "bootstrap:\n  templates_path: [a, b]\n  static_path: .\niframe:\n  css: /dist/style.css\n");

        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);
        $exit = (new Command())->run(['maintenance:render', '--config=' . $dir . '/styleguide.yaml'], $stdout, $stderr);
        rewind($stderr);

        self::assertSame(2, $exit);
        self::assertStringContainsString('single bootstrap.templates_path', (string) stream_get_contents($stderr));
        self::assertDirectoryDoesNotExist($dir . '/templates', 'nothing is written to a guessed folder');
        self::assertFileDoesNotExist($dir . '/a/component/maintenance/maintenance.html');
    }

    #[Test]
    public function doctor_names_the_root_it_cannot_find(): void
    {
        $dir = $this->tempDir();
        self::put($dir . '/a/component/x/x.twig', "{# name: X #}\nx");
        mkdir($dir . '/b');
        self::put($dir . '/styleguide.yaml', "bootstrap:\n  templates_path: [a, b]\n  static_path: .\n");

        // Both roots exist: no finding is about the paths.
        $paths = array_filter(
            (new Doctor())->run($dir . '/styleguide.yaml'),
            static fn($finding): bool => $finding->check === 'paths',
        );
        self::assertSame([], array_values($paths));

        // The second root is missing: the config is refused and the message names its index.
        rmdir($dir . '/b');
        $findings = (new Doctor())->run($dir . '/styleguide.yaml');
        self::assertSame('config', $findings[0]->check);
        self::assertStringContainsString('templates_path[1]', $findings[0]->message);
    }
}
