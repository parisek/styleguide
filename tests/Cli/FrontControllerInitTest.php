<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Cli;

use Parisek\Styleguide\Bridge\Symfony\FrontController;
use Parisek\Styleguide\Cli\Command;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `styleguide front-controller:init` — writes the shipped front controller.
 */
final class FrontControllerInitTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sg-fc-init-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        file_put_contents($this->dir . '/styleguide.yaml', "project:\n  name: Init Fixture\n");
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($this->dir . '/' . $entry);
            }
        }
        rmdir($this->dir);
    }

    #[Test]
    public function it_writes_the_shipped_front_controller(): void
    {
        [$exit, $out] = $this->runCli(['front-controller:init', '--dir=' . $this->dir]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Wrote', $out);
        self::assertFileEquals(FrontController::stubPath(), $this->dir . '/index.php');
        // Readable by a web server running as another user.
        self::assertSame(0o644, fileperms($this->dir . '/index.php') & 0o777);
        self::assertSame(['index.php', 'styleguide.yaml'], array_values(array_diff(scandir($this->dir) ?: [], ['.', '..'])));
    }

    #[Test]
    public function force_keeps_the_existing_mode(): void
    {
        file_put_contents($this->dir . '/index.php', "<?php // project's own\n");
        chmod($this->dir . '/index.php', 0o640);

        $this->runCli(['front-controller:init', '--dir=' . $this->dir, '--force']);

        self::assertSame(0o640, fileperms($this->dir . '/index.php') & 0o777);
    }

    #[Test]
    public function it_finds_the_directory_through_the_config(): void
    {
        [$exit] = $this->runCli(['front-controller:init', '--config=' . $this->dir . '/styleguide.yaml']);

        self::assertSame(0, $exit);
        self::assertFileEquals(FrontController::stubPath(), $this->dir . '/index.php');
    }

    #[Test]
    public function a_second_run_changes_nothing(): void
    {
        $this->runCli(['front-controller:init', '--dir=' . $this->dir]);
        [$exit, $out] = $this->runCli(['front-controller:init', '--dir=' . $this->dir]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('already the shipped front controller', $out);
    }

    #[Test]
    public function a_different_index_php_is_kept_without_force(): void
    {
        // It may be the project's own front controller, or a subclassed kernel.
        file_put_contents($this->dir . '/index.php', "<?php // project's own\n");

        [$exit, , $err] = $this->runCli(['front-controller:init', '--dir=' . $this->dir]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('--force', $err);
        self::assertSame("<?php // project's own\n", file_get_contents($this->dir . '/index.php'));
    }

    #[Test]
    public function force_replaces_a_different_index_php(): void
    {
        file_put_contents($this->dir . '/index.php', "<?php // project's own\n");

        [$exit] = $this->runCli(['front-controller:init', '--dir=' . $this->dir, '--force']);

        self::assertSame(0, $exit);
        self::assertFileEquals(FrontController::stubPath(), $this->dir . '/index.php');
    }

    #[Test]
    public function a_directory_without_styleguide_yaml_is_refused(): void
    {
        unlink($this->dir . '/styleguide.yaml');

        [$exit, , $err] = $this->runCli(['front-controller:init', '--dir=' . $this->dir]);

        self::assertSame(2, $exit);
        self::assertStringContainsString('No styleguide.yaml', $err);
        self::assertFileDoesNotExist($this->dir . '/index.php');
    }

    #[Test]
    public function a_mistyped_config_does_not_fall_back(): void
    {
        // Run from a directory that has a styleguide.yaml, so a fallback would
        // have somewhere to write.
        $cwd = (string) getcwd();
        chdir($this->dir);
        try {
            [$exit, , $err] = $this->runCli(['front-controller:init', '--config=' . $this->dir . '/typo.yaml']);
        } finally {
            chdir($cwd);
        }

        self::assertSame(2, $exit);
        self::assertStringContainsString('no such file', $err);
        self::assertFileDoesNotExist($this->dir . '/index.php');
    }

    #[Test]
    public function a_bare_dir_flag_is_a_usage_error(): void
    {
        [$exit] = $this->runCli(['front-controller:init', '--dir']);

        self::assertSame(2, $exit);
    }

    #[Test]
    public function a_trailing_slash_on_the_dir_is_fine(): void
    {
        [$exit] = $this->runCli(['front-controller:init', '--dir=' . $this->dir . '/']);

        self::assertSame(0, $exit);
        self::assertFileEquals(FrontController::stubPath(), $this->dir . '/index.php');
    }

    #[Test]
    public function a_symlinked_index_php_is_never_written_through(): void
    {
        $outside = $this->dir . '/outside.php';
        file_put_contents($outside, "<?php // elsewhere\n");
        symlink($outside, $this->dir . '/index.php');

        [$exit] = $this->runCli(['front-controller:init', '--dir=' . $this->dir, '--force']);

        self::assertSame(1, $exit);
        self::assertSame("<?php // elsewhere\n", file_get_contents($outside));
    }

    #[Test]
    public function a_broken_symlink_is_not_written_through(): void
    {
        symlink($this->dir . '/missing.php', $this->dir . '/index.php');

        [$exit] = $this->runCli(['front-controller:init', '--dir=' . $this->dir]);

        self::assertSame(1, $exit);
        self::assertFileDoesNotExist($this->dir . '/missing.php');
    }

    #[Test]
    public function the_shipped_front_controller_is_valid_php(): void
    {
        $output = [];
        exec(\PHP_BINARY . ' -l ' . escapeshellarg(FrontController::stubPath()) . ' 2>&1', $output, $code);

        self::assertSame(0, $code, implode("\n", $output));
    }

    /**
     * @param list<string> $argv
     * @return array{0: int, 1: string, 2: string}
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
}
