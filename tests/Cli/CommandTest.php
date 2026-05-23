<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Cli;

use Parisek\Styleguide\Cli\Command;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CommandTest extends TestCase
{
    private string $fixtures;

    protected function setUp(): void
    {
        $this->fixtures = __DIR__ . '/../fixtures/templates';
    }

    /**
     * @param array<int,string> $argv
     * @return array{0:int, 1:string, 2:string}  [exitCode, stdout, stderr]
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
    public function list_returns_all_components_as_json(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli([
            'list',
            '--templates=' . $this->fixtures,
        ]);

        self::assertSame(0, $exit, "stderr: $stderr");
        self::assertSame('', $stderr);

        $decoded = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertCount(2, $decoded);
        self::assertSame('Another', $decoded[0]['name'], 'weight 10 first');
        self::assertSame('Sample', $decoded[1]['name'], 'weight 20 second');
    }

    #[Test]
    public function show_returns_single_component_as_json(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli([
            'show',
            'sample',
            '--templates=' . $this->fixtures,
        ]);

        self::assertSame(0, $exit, "stderr: $stderr");
        self::assertSame('', $stderr);

        $decoded = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('sample', $decoded['id']);
        self::assertSame('Sample', $decoded['name']);
        self::assertSame('Block', $decoded['category']);
        self::assertSame(20, $decoded['weight']);
    }

    #[Test]
    public function show_returns_exit_1_and_stderr_message_when_not_found(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli([
            'show',
            'nonexistent',
            '--templates=' . $this->fixtures,
        ]);

        self::assertSame(1, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Component "nonexistent" not found', $stderr);
    }

    #[Test]
    public function show_returns_exit_1_when_id_is_missing(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli([
            'show',
            '--templates=' . $this->fixtures,
        ]);

        self::assertSame(1, $exit);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Missing component id', $stderr);
    }

    #[Test]
    public function list_with_type_page_returns_pages(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli([
            'list',
            '--type=page',
            '--templates=' . $this->fixtures,
        ]);

        self::assertSame(0, $exit, "stderr: $stderr");
        $decoded = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertCount(1, $decoded);
        self::assertSame('Landing', $decoded[0]['name']);
    }

    #[Test]
    public function show_with_type_page_returns_single_page(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli([
            'show',
            'landing',
            '--type=page',
            '--templates=' . $this->fixtures,
        ]);

        self::assertSame(0, $exit, "stderr: $stderr");
        $decoded = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('landing', $decoded['id']);
        self::assertSame('Marketing', $decoded['category']);
    }

    #[Test]
    public function show_page_not_found_uses_page_in_message(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli([
            'show',
            'ghost',
            '--type=page',
            '--templates=' . $this->fixtures,
        ]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('Page "ghost" not found', $stderr);
    }

    #[Test]
    public function templates_path_from_env_var(): void
    {
        $originalEnv = getenv('STYLEGUIDE_TEMPLATES');
        putenv('STYLEGUIDE_TEMPLATES=' . $this->fixtures);
        try {
            [$exit, $stdout, $stderr] = $this->runCli(['list']);
            self::assertSame(0, $exit, "stderr: $stderr");
            self::assertNotSame('', $stdout, 'expected JSON on stdout');
        } finally {
            if ($originalEnv === false) {
                putenv('STYLEGUIDE_TEMPLATES');
            } else {
                putenv('STYLEGUIDE_TEMPLATES=' . $originalEnv);
            }
        }
    }

    #[Test]
    public function flag_overrides_env_var(): void
    {
        $originalEnv = getenv('STYLEGUIDE_TEMPLATES');
        putenv('STYLEGUIDE_TEMPLATES=/nonexistent/should/not/win');
        try {
            [$exit, $stdout, $stderr] = $this->runCli([
                'list',
                '--templates=' . $this->fixtures,
            ]);
            self::assertSame(0, $exit, "stderr: $stderr");
            $decoded = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
            self::assertCount(2, $decoded);
        } finally {
            if ($originalEnv === false) {
                putenv('STYLEGUIDE_TEMPLATES');
            } else {
                putenv('STYLEGUIDE_TEMPLATES=' . $originalEnv);
            }
        }
    }

    #[Test]
    public function returns_exit_1_when_no_templates_directory_resolvable(): void
    {
        $originalEnv = getenv('STYLEGUIDE_TEMPLATES');
        $originalCwd = getcwd();
        chdir(sys_get_temp_dir());
        putenv('STYLEGUIDE_TEMPLATES');
        try {
            [$exit, $stdout, $stderr] = $this->runCli(['list']);
            self::assertSame(1, $exit);
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
}
