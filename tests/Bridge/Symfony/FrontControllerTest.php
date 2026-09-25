<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Bridge\Symfony;

use Parisek\Styleguide\Bridge\Symfony\FrontController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FrontControllerTest extends TestCase
{
    private const STATIC_DIR = __DIR__ . '/../../fixtures/bundle';

    private const ASSET_DIR = __DIR__ . '/../../fixtures/front-controller/static';

    /**
     * @return iterable<string, array{array<string, string>, array<string, string>, array<string, string>, string, bool}>
     */
    public static function environments(): iterable
    {
        // The case every deployed WordPress and Drupal site is in.
        yield 'nothing set: production, no debug' => [[], [], [], 'prod', false];
        yield 'DDEV: development with debug' => [[], [], ['IS_DDEV_PROJECT' => 'true'], 'dev', true];
        yield 'APP_ENV wins over DDEV' => [['APP_ENV' => 'prod'], [], ['IS_DDEV_PROJECT' => 'true'], 'prod', false];
        yield 'APP_ENV=dev' => [['APP_ENV' => 'dev'], [], [], 'dev', true];
        yield 'APP_DEBUG=0 turns debug off' => [['APP_ENV' => 'dev', 'APP_DEBUG' => '0'], [], [], 'dev', false];
        yield 'prod never debugs' => [['APP_ENV' => 'prod', 'APP_DEBUG' => '1'], [], [], 'prod', false];
        yield 'custom environment debugs' => [['APP_ENV' => 'test'], [], [], 'test', true];
        yield 'empty APP_ENV reads as unset' => [['APP_ENV' => ''], [], [], 'prod', false];
        // PHP's built-in server leaves process variables out of $_SERVER.
        yield 'getenv() only' => [[], [], ['APP_ENV' => 'dev'], 'dev', true];
        yield '$_ENV only' => [[], ['APP_ENV' => 'dev'], [], 'dev', true];
        yield '$_SERVER before $_ENV' => [['APP_ENV' => 'prod'], ['APP_ENV' => 'dev'], [], 'prod', false];
        yield 'IS_DDEV_PROJECT other than true' => [[], [], ['IS_DDEV_PROJECT' => '1'], 'prod', false];
    }

    /**
     * @param array<string, string> $server
     * @param array<string, string> $env
     * @param array<string, string> $process
     */
    #[Test]
    #[DataProvider('environments')]
    public function it_resolves_the_environment(array $server, array $env, array $process, string $environment, bool $debug): void
    {
        $getenv = static fn(string $name): string|false => $process[$name] ?? false;

        self::assertSame([$environment, $debug], FrontController::resolveEnvironment($server, $env, $getenv));
    }

    #[Test]
    public function the_built_in_server_gets_an_asset_back(): void
    {
        self::assertTrue(self::served('/dist/css/style.css'));
        self::assertTrue(self::served('/dist/css/style.css?v=1'));
        self::assertTrue(self::served('/images/anim.json'));
    }

    #[Test]
    public function only_the_built_in_server_gets_a_file_back(): void
    {
        self::assertFalse(FrontController::isBuiltInServerFile(self::ASSET_DIR, '/dist/css/style.css', 'fpm-fcgi'));
    }

    #[Test]
    public function routes_and_directories_go_to_the_kernel(): void
    {
        self::assertFalse(self::served('/'));
        self::assertFalse(self::served('/styleguide/'));
        self::assertFalse(self::served('/dist'));
        self::assertFalse(self::served('/missing.css'));
    }

    #[Test]
    public function configuration_and_source_are_never_sent(): void
    {
        // styleguide.yaml is the catalogue's configuration; a PHP file would
        // be executed by the built-in server, not sent.
        self::assertFalse(self::served('/styleguide.yaml'));
        self::assertFalse(self::served('/script.php'));
        self::assertFalse(self::served('/composer.json'));
        self::assertFalse(self::served('/.env'));
        self::assertFalse(self::served('/.hidden/a.css'));
        self::assertFalse(self::served('/vendor/pkg/a.css'));
        self::assertFalse(self::served('/node_modules/pkg/a.js'));
        // A case-insensitive filesystem resolves these to the denied ones.
        self::assertFalse(self::served('/VENDOR/pkg/a.css'));
        self::assertFalse(self::served('/Node_Modules/pkg/a.js'));
        self::assertFalse(self::served('/Composer.JSON'));
    }

    #[Test]
    public function a_file_outside_the_static_dir_goes_to_the_kernel(): void
    {
        self::assertFalse(self::served('/../../bundle/styleguide.yaml'));
        self::assertFalse(self::served('/%2e%2e/%2e%2e/templates/component/sample/sample.twig'));
    }

    #[Test]
    public function a_symlink_cannot_hide_a_denied_segment(): void
    {
        $static = sys_get_temp_dir() . '/sg-front-' . bin2hex(random_bytes(4));
        mkdir($static . '/dist', 0o700, true);
        file_put_contents($static . '/dist/app.js', '');
        symlink($static . '/dist', $static . '/vendor');
        symlink($static . '/dist', $static . '/.hidden');

        try {
            self::assertTrue(FrontController::isBuiltInServerFile($static, '/dist/app.js', 'cli-server'));
            self::assertFalse(FrontController::isBuiltInServerFile($static, '/vendor/app.js', 'cli-server'));
            self::assertFalse(FrontController::isBuiltInServerFile($static, '/.hidden/app.js', 'cli-server'));
        } finally {
            @unlink($static . '/vendor');
            @unlink($static . '/.hidden');
            @unlink($static . '/dist/app.js');
            @rmdir($static . '/dist');
            @rmdir($static);
        }
    }

    #[Test]
    public function a_nul_byte_goes_to_the_kernel_instead_of_throwing(): void
    {
        self::assertFalse(self::served('/dist/css/style.css%00'));
    }

    private static function served(string $uri): bool
    {
        return FrontController::isBuiltInServerFile(self::ASSET_DIR, $uri, 'cli-server');
    }

    #[Test]
    public function a_kernel_class_must_extend_the_styleguide_kernel(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        /** @phpstan-ignore argument.type (the refusal is what is under test) */
        FrontController::run(self::STATIC_DIR, \stdClass::class);
    }

    #[Test]
    public function a_directory_without_styleguide_yaml_gets_a_plain_answer(): void
    {
        $logFile = (string) tempnam(sys_get_temp_dir(), 'sg-log-');
        $log = ini_set('error_log', $logFile);
        ob_start();
        try {
            FrontController::run(sys_get_temp_dir());
        } finally {
            $body = (string) ob_get_clean();
            ini_set('error_log', (string) $log);
        }
        $logged = (string) file_get_contents($logFile);
        unlink($logFile);

        // The visitor learns what is wrong, not where the site lives.
        self::assertStringContainsString('no styleguide.yaml', $body);
        self::assertStringNotContainsString(sys_get_temp_dir(), $body);
        // The log carries the directory.
        self::assertStringContainsString(sys_get_temp_dir(), $logged);
    }
}
