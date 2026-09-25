<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Cli;

use Parisek\Styleguide\Cli\Command;
use Parisek\Styleguide\Cli\Doctor;
use Parisek\Styleguide\Cli\DoctorFinding;
use Parisek\Styleguide\Cli\LintSeverity;
use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `styleguide doctor` — the diagnostic for configuration that loads and then
 * misbehaves.
 *
 * Every test here writes its own styleguide.yaml into a temp directory and
 * points `templates_path` back at the package's real templates, because a
 * doctor finding is about the relationship between a config file and the
 * filesystem around it. A fixture yaml committed next to the others could not
 * express "this path does not exist" without a reader wondering whether the
 * missing directory was an accident.
 */
final class DoctorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/sg-doctor-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $this->dir = $dir;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    private function removeTree(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $path) {
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * A styleguide.yaml in this test's own directory.
     *
     * Written rather than committed: a doctor finding is about the
     * relationship between a config file and the filesystem around it, and a
     * fixture on disk could not express "this path does not exist" without a
     * reader wondering whether the missing directory was an accident.
     *
     * @param array<string, string> $bootstrap
     */
    private function config(array $bootstrap = []): string
    {
        // The package's own templates/ holds packaged defaults and no demo
        // fixtures, so a catalogue built on it is legitimately empty — which
        // doctor warns about. tests/fixtures/templates is the tree with
        // fixtures in it.
        $templates = realpath(__DIR__ . '/../fixtures/templates');
        self::assertIsString($templates);

        $lines = ['bootstrap:'];
        foreach ($bootstrap + ['templates_path' => $templates, 'static_path' => $this->dir] as $key => $value) {
            $lines[] = sprintf('  %s: %s', $key, var_export($value, true));
        }
        $lines[] = 'project:';
        $lines[] = '  name: Doctor Fixture';

        $path = $this->dir . '/styleguide.yaml';
        file_put_contents($path, implode("\n", $lines) . "\n");

        return $path;
    }

    /**
     * @return array{0:int, 1:string, 2:string}
     */
    private function doctor(string $configPath, string ...$extra): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $exit = (new Command())->run(['doctor', '--config=' . $configPath, ...$extra], $stdout, $stderr);

        rewind($stdout);
        rewind($stderr);

        return [$exit, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }

    /**
     * A Styleguide whose dist root is a directory this test controls.
     *
     * `dist_path` is a run-truth key, so it cannot come from a YAML file —
     * which is exactly why the dist checks need this seam to be reachable
     * at all.
     *
     * @return array{0: Styleguide, 1: string}
     */
    private function styleguideWithDist(string $indexHtml): array
    {
        $templates = realpath(__DIR__ . '/../fixtures/templates');
        self::assertIsString($templates);

        $dist = $this->dir . '/dist';
        mkdir($dist);
        file_put_contents($dist . '/index.html', $indexHtml);

        $configPath = $this->config();

        return [Styleguide::fromYaml($configPath, ['dist_path' => $dist]), $configPath];
    }

    /**
     * @return list<string>
     */
    private function distMessages(string $indexHtml): array
    {
        [$styleguide, $configPath] = $this->styleguideWithDist($indexHtml);

        $messages = [];
        foreach ((new Doctor())->runFor($styleguide, $configPath) as $finding) {
            if ($finding->check === 'dist') {
                self::assertSame(LintSeverity::Error, $finding->severity);
                $messages[] = $finding->message;
            }
        }

        return $messages;
    }

    #[Test]
    public function a_dist_that_has_lost_its_injection_point_is_reported_before_a_visitor_meets_it(): void
    {
        // The CorruptBuildException condition. Without this check it surfaces
        // on a live request, as a 500, to whoever loads the catalogue first.
        $messages = $this->distMessages('<html><body>no config script</body></html>');

        self::assertCount(1, $messages);
        self::assertStringContainsString('#sg-config', $messages[0]);
    }

    #[Test]
    public function an_injection_point_missing_its_type_attribute_is_reported_too(): void
    {
        // The runtime replaces a script tag carrying BOTH the id and the
        // type. Checking for the id alone let a build through that
        // CorruptBuildException would refuse — the two now share one pattern.
        $messages = $this->distMessages('<html><head><script id="sg-config">{}</script></head></html>');

        self::assertCount(1, $messages);
        self::assertStringContainsString('#sg-config', $messages[0]);
    }

    #[Test]
    public function an_asset_the_build_no_longer_contains_is_reported(): void
    {
        // PHP never learns about this one: the shell is served, the browser
        // asks for the asset, and the page is blank.
        $messages = $this->distMessages(
            '<html><head><script id="sg-config" type="application/json">{}</script>'
            . '<script src="/styleguide/assets/gone.js"></script></head></html>',
        );

        self::assertCount(1, $messages);
        self::assertStringContainsString('gone.js', $messages[0]);
    }

    #[Test]
    public function a_relative_asset_the_build_no_longer_contains_is_reported(): void
    {
        // The relative build (Vite base './') references its entry assets as
        // ./x. doctor has to read that form, or it stops auditing the build.
        $messages = $this->distMessages(
            '<html><head><script id="sg-config" type="application/json">{}</script>'
            . '<script src="./gone.js"></script><link rel="stylesheet" href="./here.css"></head></html>',
        );

        self::assertCount(2, $messages);
        self::assertStringContainsString('gone.js', $messages[0]);
    }

    #[Test]
    public function an_asset_the_build_does_contain_is_not_reported(): void
    {
        // The route segment is not a directory. `/styleguide/assets/x.js` is
        // served from `dist/x.js`, and keeping the segment in the lookup made
        // every asset of the real build look missing — the first thing running
        // this command reported.
        [$styleguide, $configPath] = $this->styleguideWithDist(
            '<html><head><script id="sg-config" type="application/json">{}</script>'
            . '<script src="/styleguide/assets/here.js"></script></head></html>',
        );
        file_put_contents($this->dir . '/dist/here.js', '//');

        foreach ((new Doctor())->runFor($styleguide, $configPath) as $finding) {
            self::assertNotSame('dist', $finding->check, $finding->message);
        }
    }

    #[Test]
    public function a_missing_dist_is_reported_as_an_unbuilt_frontend(): void
    {
        $templates = realpath(__DIR__ . '/../fixtures/templates');
        self::assertIsString($templates);

        // The directory exists and the build never ran. A dist root that is
        // absent entirely does not reach this check at all: AssetServer
        // refuses it in the constructor, so it surfaces as a config error —
        // which is the louder, earlier failure, and the right one.
        mkdir($this->dir . '/unbuilt');
        $configPath = $this->config();
        $styleguide = Styleguide::fromYaml($configPath, ['dist_path' => $this->dir . '/unbuilt']);

        $messages = [];
        foreach ((new Doctor())->runFor($styleguide, $configPath) as $finding) {
            if ($finding->check === 'dist') {
                $messages[] = $finding->message;
            }
        }

        self::assertCount(1, $messages);
        self::assertStringContainsString('no index.html', $messages[0]);
    }

    #[Test]
    public function a_sound_configuration_passes(): void
    {
        [$exit, $stdout] = $this->doctor($this->config());

        // Exit 0 despite output: the helper listing is a notice, and a notice
        // must never fail a build — the same contract lint already has.
        self::assertSame(0, $exit);
        self::assertStringContainsString('NOTICE', $stdout);
        self::assertStringNotContainsString('ERROR', $stdout);
    }

    #[Test]
    public function a_templates_path_that_does_not_exist_names_the_key_to_edit(): void
    {
        // This one never reaches the path check: Twig's FilesystemLoader
        // throws while fromYaml() is still running, so it arrives as a config
        // finding. Twig's message names the directory but not the key, which
        // is the part a reader has to edit — doctor adds it.
        [$exit, $stdout] = $this->doctor($this->config(['templates_path' => $this->dir . '/nope']));

        self::assertSame(1, $exit);
        self::assertStringContainsString('ERROR', $stdout);
        self::assertStringContainsString('does not exist', $stdout);
        self::assertStringContainsString('bootstrap.templates_path', $stdout);
    }

    #[Test]
    public function a_static_path_that_does_not_exist_is_an_error(): void
    {
        // Unlike templates_path, nothing complains about this one on its own:
        // the conventional @icons/@images namespaces are simply never
        // registered.
        [$exit, $stdout] = $this->doctor($this->config(['static_path' => $this->dir . '/nope']));

        self::assertSame(1, $exit);
        self::assertStringContainsString('bootstrap.static_path', $stdout);
    }

    #[Test]
    public function a_namespace_whose_directory_is_absent_is_reported(): void
    {
        // The finding this command exists for. registerConventionalNamespaces()
        // skips a namespace whose directory is missing, so nothing throws and
        // nothing warns — templates under @broken simply stop resolving, and
        // the message a developer meets is "template not found".
        $path = $this->config();
        $templates = realpath(__DIR__ . '/../fixtures/templates');
        file_put_contents($path, sprintf(
            "bootstrap:\n  templates_path: '%s'\n  static_path: '%s'\n  namespaces:\n    broken: /no/such/place\n",
            $templates,
            $this->dir,
        ));

        [$exit, $stdout] = $this->doctor($path);

        self::assertSame(1, $exit);
        self::assertStringContainsString('bootstrap.namespaces.broken', $stdout);
        self::assertStringContainsString('skipped silently', $stdout);
    }

    #[Test]
    public function a_base_url_other_than_the_default_is_a_notice(): void
    {
        [$exit, $stdout] = $this->doctor($this->config(['base_url' => '/kit/']));

        // A notice: the catalogue moves, which is intended, and the web
        // server has to follow. Not a build failure.
        self::assertSame(0, $exit);
        self::assertStringContainsString('NOTICE', $stdout);
        self::assertStringContainsString("served at '/kit'", $stdout);
    }

    #[Test]
    public function the_default_base_url_is_not_reported(): void
    {
        // It says what already happens. Trailing slash normalises away.
        foreach (['/styleguide', '/styleguide/'] as $value) {
            [, $stdout] = $this->doctor($this->config(['base_url' => $value]));
            self::assertStringNotContainsString('base_url', $stdout, $value);
        }
    }

    #[Test]
    public function an_invalid_base_url_is_the_config_error(): void
    {
        foreach (['/', 'kit', '/a//b', '/caf%C3%A9', '/a/../b', '/kit?x=1'] as $value) {
            [$exit, $stdout] = $this->doctor($this->config(['base_url' => $value]));
            self::assertSame(1, $exit, $value);
            self::assertStringContainsString("config key 'base_url'", $stdout, $value);
        }
    }

    #[Test]
    public function a_config_the_library_refuses_is_one_finding_and_not_a_stack_trace(): void
    {
        $path = $this->dir . '/styleguide.yaml';
        file_put_contents($path, "bootstrap:\n  templates_path: ./t\n  static_path: .\n  auth: true\n");

        [$exit, $stdout] = $this->doctor($path);

        self::assertSame(1, $exit);
        self::assertStringContainsString("key 'bootstrap.auth' is a run-truth value", $stdout);
        // And nothing else: every other check needs the config this one could
        // not produce, so reporting them would be guessing.
        self::assertSame(1, substr_count($stdout, 'ERROR'));
    }

    #[Test]
    public function a_missing_config_file_is_a_usage_error_not_a_finding(): void
    {
        [$exit, $stdout, $stderr] = $this->doctor($this->dir . '/absent.yaml');

        // Exit 2, the usage code: "I could not run" is not "your project has
        // problems", and a CI job should be able to tell them apart.
        self::assertSame(2, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString('not found', $stderr);
    }

    #[Test]
    public function the_json_format_carries_the_remedy(): void
    {
        [$exit, $stdout] = $this->doctor($this->config(['base_url' => '/kit']), '--format=json');

        self::assertSame(0, $exit);

        /** @var list<array{severity: string, check: string, message: string, remedy: string}> $payload */
        $payload = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        $checks = array_column($payload, 'check');

        self::assertContains('base_url', $checks);
        foreach ($payload as $finding) {
            self::assertNotSame('', $finding['remedy'], 'Every doctor finding says what to do about it.');
        }
    }

    #[Test]
    public function the_helper_listing_names_every_registered_helper(): void
    {
        [, $stdout] = $this->doctor($this->config());

        // The listing is the point of the check: a consumer with its own
        // `__()` needs the names before Twig locks its extension set.
        // Including helpers that do NOT come from StyleguideTwigExtension.
        // An earlier version listed only that extension's names and claimed
        // to list every one, so a consumer with its own `create_attribute()`
        // was never warned.
        foreach (
            ['component_*()', 'page_*()', '__()', 'uniqueId()', '|cachebust', '|resizer', 'create_attribute()'] as $name
        ) {
            self::assertStringContainsString($name, $stdout);
        }
    }

    #[Test]
    public function an_empty_catalogue_is_a_warning(): void
    {
        mkdir($this->dir . '/empty-templates');

        [$exit, $stdout] = $this->doctor($this->config(['templates_path' => $this->dir . '/empty-templates']));

        self::assertSame(1, $exit);
        self::assertStringContainsString('The catalogue is empty', $stdout);

        rmdir($this->dir . '/empty-templates');
    }

    #[Test]
    public function a_front_controller_without_framework_bundle_is_an_error(): void
    {
        $config = $this->config();
        file_put_contents($this->dir . '/index.php', "<?php\n\\Parisek\\Styleguide\\Bridge\\Symfony\\FrontController::run(__DIR__);\n");

        $findings = array_values(array_filter(
            (new Doctor(frameworkBundleAvailable: false))->run($config),
            static fn(DoctorFinding $f): bool => $f->check === 'front-controller',
        ));

        self::assertCount(1, $findings);
        self::assertSame(LintSeverity::Error, $findings[0]->severity);
        self::assertStringContainsString('composer require symfony/framework-bundle', $findings[0]->remedy);
    }

    #[Test]
    public function a_front_controller_with_framework_bundle_is_fine(): void
    {
        $config = $this->config();
        file_put_contents($this->dir . '/index.php', "<?php\n\\Parisek\\Styleguide\\Bridge\\Symfony\\FrontController::run(__DIR__);\n");

        $checks = array_map(static fn(DoctorFinding $f): string => $f->check, (new Doctor(frameworkBundleAvailable: true))->run($config));

        self::assertNotContains('front-controller', $checks);
    }

    #[Test]
    public function a_library_front_controller_needs_no_framework_bundle(): void
    {
        $config = $this->config();
        file_put_contents($this->dir . '/index.php', "<?php\n(new \\Parisek\\Styleguide\\Styleguide([]))->run();\n");

        $checks = array_map(static fn(DoctorFinding $f): string => $f->check, (new Doctor(frameworkBundleAvailable: false))->run($config));

        self::assertNotContains('front-controller', $checks);
    }

    #[Test]
    public function a_comment_naming_the_front_controller_is_not_a_call(): void
    {
        $config = $this->config();
        file_put_contents($this->dir . '/index.php', "<?php\n// see FrontController::run() in the docs\n\$x = 'FrontController::run(';\n");

        $checks = array_map(static fn(DoctorFinding $f): string => $f->check, (new Doctor(frameworkBundleAvailable: false))->run($config));

        self::assertNotContains('front-controller', $checks);
    }

    #[Test]
    public function an_aliased_front_controller_is_found(): void
    {
        $config = $this->config();
        file_put_contents($this->dir . '/index.php', "<?php\nuse Parisek\\Styleguide\\Bridge\\Symfony\\FrontController as FC;\nFC::run(__DIR__);\n");

        $checks = array_map(static fn(DoctorFinding $f): string => $f->check, (new Doctor(frameworkBundleAvailable: false))->run($config));

        self::assertContains('front-controller', $checks);
    }

    #[Test]
    public function an_unrelated_front_controller_class_is_not_the_bridge(): void
    {
        $config = $this->config();
        file_put_contents($this->dir . '/index.php', "<?php\nuse App\\FrontController;\nFrontController::run();\n");

        $checks = array_map(static fn(DoctorFinding $f): string => $f->check, (new Doctor(frameworkBundleAvailable: false))->run($config));

        self::assertNotContains('front-controller', $checks);
    }

    #[Test]
    public function the_shipped_front_controller_is_found(): void
    {
        $config = $this->config();
        copy(\Parisek\Styleguide\Bridge\Symfony\FrontController::stubPath(), $this->dir . '/index.php');

        $checks = array_map(static fn(DoctorFinding $f): string => $f->check, (new Doctor(frameworkBundleAvailable: false))->run($config));

        self::assertContains('front-controller', $checks);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function groupedImports(): iterable
    {
        yield 'class in the group' => ['use Parisek\\Styleguide\\Bridge\\Symfony\\{FrontController};'];
        yield 'namespace in the group' => ['use Parisek\\Styleguide\\{Bridge\\Symfony\\FrontController, Styleguide};'];
        yield 'aliased in the group' => ['use Parisek\\Styleguide\\Bridge\\Symfony\\{StyleguideKernel, FrontController as FC};'];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('groupedImports')]
    public function a_grouped_import_is_found(string $use): void
    {
        $config = $this->config();
        file_put_contents($this->dir . '/index.php', "<?php\n" . $use . "\n");

        $checks = array_map(static fn(DoctorFinding $f): string => $f->check, (new Doctor(frameworkBundleAvailable: false))->run($config));

        self::assertContains('front-controller', $checks);
    }

    #[Test]
    public function an_unrelated_group_is_not_the_bridge(): void
    {
        $config = $this->config();
        file_put_contents($this->dir . '/index.php', "<?php\nuse App\\{FrontController};\n");

        $checks = array_map(static fn(DoctorFinding $f): string => $f->check, (new Doctor(frameworkBundleAvailable: false))->run($config));

        self::assertNotContains('front-controller', $checks);
    }
}
