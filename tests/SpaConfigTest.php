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
        // argv[3] (config_yaml) is optional and defaults to the shared fixture
        // — the XSS regression test below points it at its own throwaway YAML
        // instead, so it can carry a malicious `project.name` without
        // touching the fixture every other test in this suite (and other
        // suites) asserts against verbatim.
        $this->runnerScript = $this->distRoot . '/run-styleguide.php';
        file_put_contents(
            $this->runnerScript,
            <<<PHP
            <?php
            declare(strict_types=1);
            require '{$autoload}';
            \$_SERVER['REQUEST_URI'] = \$argv[2];
            (new \\Parisek\\Styleguide\\Styleguide([
                'templates_path' => '{$this->fixturesRoot}/templates',
                'static_path' => '{$this->fixturesRoot}',
                'config_yaml' => \$argv[3] ?? '{$this->fixturesRoot}/styleguide.yaml',
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
    private function runStyleguide(string $requestUri = '/styleguide/', ?string $configYaml = null): array
    {
        $cmd = sprintf(
            '%s %s %s %s%s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->runnerScript),
            escapeshellarg($this->distRoot),
            escapeshellarg($requestUri),
            $configYaml === null ? '' : ' ' . escapeshellarg($configYaml),
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
        // project.name in the shared fixture carries a hostile suffix (#78
        // review — see tests/fixtures/styleguide.yaml) to pin foundations.twig's
        // escaping of styleguide.project.name; the SPA #sg-config JSON path
        // exercised here is a separate code path (JSON_HEX_TAG-encoded, not
        // HTML-escaped) so the literal value still round-trips verbatim.
        self::assertSame('Styleguide Fixture<img src=x onerror=alert(7)>', $config['projectName']);
        self::assertArrayHasKey('favicon', $config);
        self::assertSame('Styleguide — Styleguide Fixture<img src=x onerror=alert(7)>', $config['title']);
        // Sidebar icons-entry gate (#87) — the shared fixture yaml carries no
        // `icons:` block, so the flag must be present and false.
        self::assertFalse($config['hasIcons']);
    }

    #[Test]
    public function throws_when_dist_index_html_is_missing_the_sg_config_injection_point(): void
    {
        $this->writeIndexHtml('<html><head><title>Styleguide</title></head><body></body></html>');

        // http_response_code(500) is set right before the throw (matches the
        // sibling missing-dist branch's 500), but this suite drives
        // Styleguide::run() as a bare `php run-styleguide.php` subprocess —
        // no web server, so http_response_code()'s effect isn't observable
        // on stdout/stderr the way a real HTTP response's status line would
        // be. Asserting on the exit code + stderr message is the strongest
        // check available under this harness; see tests/SpaConfigTest.php's
        // class doc comment for why an in-process/HTTP-serving harness isn't
        // used instead.
        [$exit, , $stderr] = $this->runStyleguide();

        self::assertNotSame(0, $exit);
        self::assertMatchesRegularExpression('/sg-config/', $stderr);
    }

    #[Test]
    public function escapes_script_breakout_attempts_in_project_name(): void
    {
        // A project.name containing a literal `</script>` must not survive
        // into the response body verbatim — without JSON_HEX_TAG in
        // dispatchSpa()'s json_encode() call, this string would close the
        // #sg-config <script> element early and let `<script>alert(1)</script>`
        // execute as a real script tag (XSS via styleguide.yaml, which some
        // consumers populate from user-editable project settings).
        $maliciousYaml = $this->distRoot . '/malicious.yaml';
        file_put_contents(
            $maliciousYaml,
            <<<YAML
            project:
              name: 'Evil</script><script>alert(1)</script>'
            YAML,
        );
        $this->writeIndexHtml('<html><head><script id="sg-config" type="application/json">{}</script></head><body></body></html>');

        [$exit, $stdout, $stderr] = $this->runStyleguide(configYaml: $maliciousYaml);

        self::assertSame(0, $exit, "stderr: $stderr");
        self::assertStringNotContainsString('</script><script>alert(1)</script>', $stdout);
        self::assertStringNotContainsString('<script>alert(1)</script>', $stdout);

        // The escaped payload must still round-trip to the original string
        // once the browser's JSON.parse() decodes it — hardening must not
        // corrupt legitimate-but-adversarial-looking values.
        preg_match('/<script id="sg-config" type="application\/json">(\{.*?\})<\/script>/s', $stdout, $m);
        self::assertNotEmpty($m, "sg-config element not found in: $stdout");
        $config = json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Evil</script><script>alert(1)</script>', $config['projectName']);
    }
}
