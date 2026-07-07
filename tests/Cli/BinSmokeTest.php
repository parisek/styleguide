<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Cli;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end smoke: run bin/styleguide as a real subprocess and assert it
 * boots autoload + executes the Command. Complements CommandTest, which
 * exercises Command::run() in-process with mock streams.
 */
final class BinSmokeTest extends TestCase
{
    private string $bin;
    private string $fixtures;

    protected function setUp(): void
    {
        $bin = realpath(__DIR__ . '/../../bin/styleguide');
        $fixtures = realpath(__DIR__ . '/../fixtures/templates');
        self::assertNotFalse($bin, 'bin/styleguide missing');
        self::assertNotFalse($fixtures, 'fixtures path missing');
        $this->bin = $bin;
        $this->fixtures = $fixtures;
    }

    /**
     * @return array{0:int, 1:string, 2:string}
     */
    private function runBin(string $argString): array
    {
        $cmd = sprintf(
            '%s %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->bin),
            $argString,
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
    public function bin_list_returns_components_json(): void
    {
        $args = sprintf('list --templates=%s', escapeshellarg($this->fixtures));
        [$exit, $stdout, $stderr] = $this->runBin($args);

        self::assertSame(0, $exit, "stderr: $stderr");
        $decoded = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        // The bin emits the component roster as JSON — assert a known entry is
        // present rather than the exact count, so fixture additions don't break it.
        self::assertContains('Sample', array_column($decoded, 'name'));
    }

    #[Test]
    public function bin_help_works_without_templates(): void
    {
        [$exit, $stdout, $stderr] = $this->runBin('--help');
        self::assertSame(0, $exit, "stderr: $stderr");
        self::assertStringContainsString('Usage:', $stdout);
    }

    #[Test]
    public function bin_lint_runs_end_to_end(): void
    {
        $fixtures = realpath(__DIR__ . '/../fixtures/lint/templates');
        self::assertNotFalse($fixtures, 'lint fixtures path missing');

        $args = sprintf('lint --templates=%s', escapeshellarg($fixtures));
        [$exit, $stdout, $stderr] = $this->runBin($args);

        self::assertSame(1, $exit, "stderr: $stderr");
        self::assertStringContainsString('WARNING', $stdout);
    }
}
