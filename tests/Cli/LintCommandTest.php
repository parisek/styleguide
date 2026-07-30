<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Cli;

use Parisek\Styleguide\Cli\Command;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LintCommandTest extends TestCase
{
    private string $fixtures;
    private string $noticeOnlyFixtures;
    private string $cleanFixtures;

    protected function setUp(): void
    {
        $this->fixtures = __DIR__ . '/../fixtures/lint/templates';
        $this->noticeOnlyFixtures = __DIR__ . '/../fixtures/lint-notice-only/templates';
        $this->cleanFixtures = __DIR__ . '/../fixtures/lint-clean/templates';
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
        return [
            $exit,
            (string) stream_get_contents($stdout),
            (string) stream_get_contents($stderr),
        ];
    }

    #[Test]
    public function text_format_prints_one_line_per_finding_as_severity_file_message(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli([
            'lint',
            '--templates=' . $this->fixtures,
        ]);

        self::assertSame(1, $exit, "stderr: $stderr");
        self::assertSame('', $stderr);
        self::assertStringContainsString(
            'WARNING  component/nameless/nameless.twig  No parseable `name:` key',
            $stdout,
        );
        // A named partial is still linted — the `unindexed` suppression is
        // scoped to the missing-name case, not to the directory.
        self::assertStringContainsString(
            'NOTICE  component/_partials/named.twig  No description set',
            $stdout,
        );
        // ...and the nameless one in the same directory is silent.
        self::assertStringNotContainsString('component/_partials/fragment.twig', $stdout);
        self::assertStringContainsString(
            'ERROR  component/referencer/referencer.twig  usage: references unknown id "ghost-id".',
            $stdout,
        );
        self::assertCount(10, array_filter(explode("\n", trim($stdout))));
    }

    #[Test]
    public function json_format_returns_an_array_of_severity_file_rule_message_objects(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli([
            'lint',
            '--format=json',
            '--templates=' . $this->fixtures,
        ]);

        self::assertSame(1, $exit, "stderr: $stderr");
        $decoded = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertCount(10, $decoded);
        foreach ($decoded as $entry) {
            self::assertArrayHasKey('severity', $entry);
            self::assertArrayHasKey('file', $entry);
            self::assertArrayHasKey('rule', $entry);
            self::assertArrayHasKey('message', $entry);
        }
    }

    #[Test]
    public function exit_code_0_when_only_notice_findings_are_present(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli([
            'lint',
            '--templates=' . $this->noticeOnlyFixtures,
        ]);

        self::assertSame(0, $exit, "stderr: $stderr");
        self::assertStringContainsString('NOTICE', $stdout);
    }

    #[Test]
    public function exit_code_0_and_empty_stdout_when_clean(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli([
            'lint',
            '--templates=' . $this->cleanFixtures,
        ]);

        self::assertSame(0, $exit, "stderr: $stderr");
        self::assertSame('', $stdout);
        self::assertSame('', $stderr);
    }

    #[Test]
    public function type_flag_restricts_scan_to_the_requested_type(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli([
            'lint',
            '--type=page',
            '--format=json',
            '--templates=' . $this->fixtures,
        ]);

        self::assertSame(1, $exit, "stderr: $stderr");
        $decoded = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $decoded);
        self::assertSame('page/landing/landing.twig', $decoded[0]['file']);
    }

    #[Test]
    public function invalid_type_flag_exits_2(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli([
            'lint',
            '--type=widget',
            '--templates=' . $this->fixtures,
        ]);

        self::assertSame(2, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Invalid --type', $stderr);
    }

    #[Test]
    public function invalid_format_flag_exits_2(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli([
            'lint',
            '--format=xml',
            '--templates=' . $this->fixtures,
        ]);

        self::assertSame(2, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Invalid --format', $stderr);
    }

    #[Test]
    public function missing_templates_dir_exits_2(): void
    {
        $originalCwd = getcwd();
        $originalEnv = getenv('STYLEGUIDE_TEMPLATES');
        chdir(sys_get_temp_dir());
        putenv('STYLEGUIDE_TEMPLATES');
        try {
            [$exit, $stdout, $stderr] = $this->runCli(['lint']);
            self::assertSame(2, $exit);
            self::assertSame('', $stdout);
            self::assertStringContainsString('templates/ directory not found', $stderr);
        } finally {
            if ($originalCwd !== false) {
                chdir($originalCwd);
            }
            if ($originalEnv !== false) {
                putenv('STYLEGUIDE_TEMPLATES=' . $originalEnv);
            }
        }
    }

    #[Test]
    public function pretty_flag_indents_json_output(): void
    {
        [, $stdout, ] = $this->runCli([
            'lint',
            '--format=json',
            '--templates=' . $this->fixtures,
            '--pretty',
        ]);

        self::assertStringContainsString("\n    ", $stdout);
    }

    #[Test]
    public function an_ignore_file_suppresses_the_finding_and_the_count_goes_to_stderr(): void
    {
        $path = sys_get_temp_dir() . '/sg-cli-ignore-' . bin2hex(random_bytes(6)) . '.yaml';
        file_put_contents($path, "ignore:\n  - file: component/_partials/*\n    rule: unindexed\n    reason: shared fragments\n");

        [$exit, $stdout, $stderr] = $this->runCli([
            'lint',
            '--templates=' . $this->fixtures,
            '--ignore=' . $path,
        ]);

        self::assertStringNotContainsString('component/_partials/fragment.twig', $stdout);
        // STDERR, so neither output contract is disturbed: --format=json emits
        // a bare array, and the text format is one finding per line.
        self::assertStringContainsString('1 finding(s) suppressed', $stderr);
        // Other findings still fail the build — suppression is per entry, and
        // this fixture tree has unrelated errors.
        self::assertSame(1, $exit);

        unlink($path);
    }

    #[Test]
    public function a_missing_ignore_path_is_a_usage_error_not_a_silent_no_op(): void
    {
        // A typo'd --ignore that quietly ignored nothing would recreate the
        // exact failure this feature exists to prevent, one level up.
        [$exit, , $stderr] = $this->runCli([
            'lint',
            '--templates=' . $this->fixtures,
            '--ignore=/definitely/not/here.yaml',
        ]);

        self::assertSame(2, $exit, 'usage error, not "findings present"');
        self::assertStringContainsString('not found', $stderr);
    }

    #[Test]
    public function a_malformed_ignore_file_exits_two_rather_than_one(): void
    {
        $path = sys_get_temp_dir() . '/sg-cli-ignore-' . bin2hex(random_bytes(6)) . '.yaml';
        file_put_contents($path, "ignore:\n  - file: a.twig\n    rule: unindexed\n");

        [$exit, , $stderr] = $this->runCli([
            'lint',
            '--templates=' . $this->fixtures,
            '--ignore=' . $path,
        ]);

        // Distinct from exit 1: a broken ignore file is not "the templates have
        // findings", and conflating them makes a typo here look like a lint
        // regression in the tree.
        self::assertSame(2, $exit);
        self::assertStringContainsString('reason', $stderr);

        unlink($path);
    }

    #[Test]
    public function the_conventional_ignore_file_inside_the_templates_root_is_picked_up(): void
    {
        $root = sys_get_temp_dir() . '/sg-conv-' . bin2hex(random_bytes(6));
        mkdir($root . '/component/_partials', 0777, true);
        file_put_contents(
            $root . '/component/_partials/fragment.twig',
            "{# no name key — a shared fragment #}\n<div></div>\n",
        );
        file_put_contents(
            $root . '/.styleguide-lintignore.yaml',
            "ignore:\n  - file: component/_partials/*\n    rule: unindexed\n    reason: shared fragments\n",
        );

        [$exit, $stdout, $stderr] = $this->runCli(['lint', '--templates=' . $root]);

        self::assertSame(0, $exit, "stdout: $stdout stderr: $stderr");
        self::assertStringContainsString('1 finding(s) suppressed', $stderr);

        unlink($root . '/.styleguide-lintignore.yaml');
        unlink($root . '/component/_partials/fragment.twig');
        rmdir($root . '/component/_partials');
        rmdir($root . '/component');
        rmdir($root);
    }
}
