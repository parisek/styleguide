<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Styleguide::run() calls exit() unconditionally once it has dispatched a
 * /styleguide/* request (see Styleguide::run()'s doc comment) — correct for
 * a production entry point, but fatal for an in-process PHPUnit test: exit()
 * would terminate the whole test runner, not just this test ("Premature end
 * of PHP process"). PHPUnit's own #[RunInSeparateProcess] doesn't help either
 * — it expects the forked child to finish normally and hand back serialized
 * results; an explicit exit() cuts that hand-back short too ("Test was run
 * in child process and ended unexpectedly").
 *
 * So this suite drives Styleguide the same way a real request does: as a
 * genuine subprocess (mirrors tests/Cli/BinSmokeTest.php's proc_open
 * pattern), asserting on stdout/stderr/exit code instead of a return value.
 * Deviation from the task-4 brief's literal listing (which called ->run()
 * in-process and would abort the suite the moment dispatchSpa() succeeded).
 */
final class SpaConfigTest extends TestCase
{
    private string $distRoot;
    private string $fixturesRoot;
    private string $runnerScript;

    protected function setUp(): void
    {
        $this->distRoot = sys_get_temp_dir() . '/sg-spa-config-test-' . uniqid();
        mkdir($this->distRoot);

        $fixturesRoot = realpath(__DIR__ . '/fixtures');
        self::assertNotFalse($fixturesRoot, 'fixtures directory missing');
        $this->fixturesRoot = $fixturesRoot;

        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        self::assertNotFalse($autoload, 'vendor/autoload.php missing');

        // Tiny bootstrap that mirrors a consumer's static/index.php, run in a
        // fresh PHP process per call so Styleguide::run()'s exit() only ends
        // that subprocess. Reads the dist dir + request URI from argv so each
        // test can point it at its own synthetic dist/index.html.
        $this->runnerScript = $this->distRoot . '/run-styleguide.php';
        file_put_contents($this->runnerScript, <<<PHP
            <?php
            declare(strict_types=1);
            require '{$autoload}';
            \$_SERVER['REQUEST_URI'] = \$argv[2];
            (new \\Parisek\\Styleguide\\Styleguide([
                'templates_path' => '{$this->fixturesRoot}/templates',
                'static_path' => '{$this->fixturesRoot}',
                'config_yaml' => '{$this->fixturesRoot}/styleguide.yaml',
                'default_locale' => 'cs',
                'dist_path' => \$argv[1],
            ]))->run();
            PHP,
        );
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->distRoot . '/*') ?: []);
        rmdir($this->distRoot);
    }

    private function writeIndexHtml(string $body): void
    {
        file_put_contents($this->distRoot . '/index.html', $body);
    }

    /**
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runStyleguide(string $requestUri = '/styleguide/'): array
    {
        $cmd = sprintf(
            '%s %s %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->runnerScript),
            escapeshellarg($this->distRoot),
            escapeshellarg($requestUri),
        );
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        self::assertIsResource($proc);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return [$exit, $stdout, $stderr];
    }

    #[Test]
    public function injects_locale_favicon_project_name_and_title_into_sg_config(): void
    {
        $this->writeIndexHtml('<html><head><script id="sg-config" type="application/json">{}</script></head><body></body></html>');

        [$exit, $stdout, $stderr] = $this->runStyleguide();

        self::assertSame(0, $exit, "stderr: $stderr");
        self::assertMatchesRegularExpression(
            '/<script id="sg-config" type="application\/json">(\{.*?\})<\/script>/s',
            $stdout,
        );
        preg_match('/<script id="sg-config" type="application\/json">(\{.*?\})<\/script>/s', $stdout, $m);
        $config = json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('cs', $config['locale']);
        self::assertSame('Styleguide Fixture', $config['projectName']);
        self::assertArrayHasKey('favicon', $config);
        self::assertSame('Styleguide — Styleguide Fixture', $config['title']);
    }

    #[Test]
    public function throws_when_dist_index_html_is_missing_the_sg_config_injection_point(): void
    {
        $this->writeIndexHtml('<html><head><title>Styleguide</title></head><body></body></html>');

        [$exit, , $stderr] = $this->runStyleguide();

        self::assertNotSame(0, $exit);
        self::assertMatchesRegularExpression('/sg-config/', $stderr);
    }
}
