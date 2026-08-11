<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\Cli\Command;
use Parisek\Styleguide\MaintenanceRenderer;
use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Covers the offline outage render: `MaintenanceRenderer` and the
 * `maintenance:render` CLI command.
 *
 * The output is served by a CMS drop-in while the CMS is down, so the
 * assertions are about self-containment — an inlined stylesheet, no surviving
 * `@font-face`, and a file that is left untouched when the render fails.
 */
final class MaintenanceRenderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/styleguide-maintenance-' . uniqid();
        $this->scaffold();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    /**
     * A minimal project: one page, one stylesheet, one config file.
     */
    private function scaffold(): void
    {
        mkdir($this->tempDir . '/templates/page/maintenance', 0777, true);
        mkdir($this->tempDir . '/dist/css', 0777, true);

        file_put_contents(
            $this->tempDir . '/templates/page/maintenance/maintenance.twig',
            "<p class=\"outage\">Closed for repairs</p>\n",
        );
        file_put_contents(
            $this->tempDir . '/dist/css/style.css',
            "@font-face{font-family:Poppins;src:url(/fonts/poppins.woff2)}.outage{color:red}\n",
        );
        file_put_contents($this->tempDir . '/styleguide.yaml', <<<YAML
        bootstrap:
          templates_path: templates
          static_path: .
          default_locale: en_US
        project:
          name: Test project
        iframe:
          css: /dist/css/style.css
        YAML);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * @param array<int,string> $argv
     * @return array{0:int, 1:string, 2:string}
     */
    private function runCli(array $argv): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $exit = (new Command())->run($argv, $stdout, $stderr);

        rewind($stdout);
        rewind($stderr);
        return [$exit, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }

    #[Test]
    public function font_face_rules_are_stripped(): void
    {
        $css = '@font-face { font-family: X; src: url(/x.woff2) } .a{color:red}';

        self::assertSame(' .a{color:red}', MaintenanceRenderer::stripFontFaces($css));
    }

    #[Test]
    public function render_inlines_the_stylesheet_around_the_project_page(): void
    {
        $styleguide = Styleguide::fromYaml($this->tempDir . '/styleguide.yaml');

        $html = (new MaintenanceRenderer($styleguide))->render('.outage{color:red}', 'en');

        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('Closed for repairs', $html);
        self::assertStringContainsString('.outage{color:red}', $html);
        // Self-containment: nothing to fetch while the site is down.
        self::assertStringNotContainsString('<link', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    #[Test]
    public function a_project_template_wins_over_the_packaged_shell(): void
    {
        $styleguide = Styleguide::fromYaml($this->tempDir . '/styleguide.yaml');
        $renderer = new MaintenanceRenderer($styleguide);
        self::assertSame(MaintenanceRenderer::PACKAGE_TEMPLATE, $renderer->template());

        file_put_contents(
            $this->tempDir . '/templates/maintenance-document.twig',
            "<!doctype html><html><body>own shell</body></html>\n",
        );
        $ownShell = new MaintenanceRenderer(Styleguide::fromYaml($this->tempDir . '/styleguide.yaml'));

        self::assertSame(MaintenanceRenderer::PROJECT_TEMPLATE, $ownShell->template());
        self::assertStringContainsString('own shell', $ownShell->render('', 'en'));
    }

    #[Test]
    public function cli_writes_the_file_and_reports_what_it_wrote(): void
    {
        $out = $this->tempDir . '/maintenance.html';

        [$exit, $stdout, $stderr] = $this->runCli([
            'maintenance:render',
            '--config=' . $this->tempDir . '/styleguide.yaml',
        ]);

        self::assertSame(0, $exit, $stderr);
        self::assertFileExists($out);
        self::assertStringContainsString('Closed for repairs', (string) file_get_contents($out));
        // The stylesheet came from iframe.css — no flag named it.
        self::assertStringContainsString('.outage{color:red}', (string) file_get_contents($out));
        self::assertStringNotContainsString('@font-face', (string) file_get_contents($out));
        self::assertStringContainsString('en_US', $stdout);
    }

    #[Test]
    public function a_missing_stylesheet_leaves_an_earlier_file_alone(): void
    {
        $out = $this->tempDir . '/maintenance.html';
        file_put_contents($out, 'previous render');
        unlink($this->tempDir . '/dist/css/style.css');

        [$exit, , $stderr] = $this->runCli([
            'maintenance:render',
            '--config=' . $this->tempDir . '/styleguide.yaml',
        ]);

        self::assertSame(2, $exit);
        self::assertStringContainsString('Stylesheet not found', $stderr);
        // A build error must not replace a working file with a broken one.
        self::assertSame('previous render', (string) file_get_contents($out));
    }

    #[Test]
    public function a_project_without_the_page_fails_without_writing(): void
    {
        unlink($this->tempDir . '/templates/page/maintenance/maintenance.twig');

        [$exit, , $stderr] = $this->runCli([
            'maintenance:render',
            '--config=' . $this->tempDir . '/styleguide.yaml',
        ]);

        self::assertSame(1, $exit);
        // Caught before rendering: page_*() would otherwise log the miss and
        // substitute an alert block, writing a file that shows an error banner.
        self::assertStringContainsString('has nothing to render', $stderr);
        self::assertFileDoesNotExist($this->tempDir . '/maintenance.html');
    }
}
