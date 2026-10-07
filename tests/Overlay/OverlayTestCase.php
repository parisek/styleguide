<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Overlay;

use Parisek\Styleguide\Http\Request;
use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\TestCase;

/**
 * Helpers for tests that build small template trees in the system temp dir.
 */
abstract class OverlayTestCase extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            self::remove($dir);
        }
        $this->tempDirs = [];
    }

    protected static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/sg-overlay-' . bin2hex(random_bytes(5));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return (string) realpath($dir);
    }

    protected static function put(string $path, string $content): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    /**
     * @param string|list<string> $templates
     * @param array<string, mixed> $extra
     */
    protected function styleguide(string|array $templates, string $static, array $extra = [], string $yaml = ''): Styleguide
    {
        $yamlFile = $static . '/styleguide.yaml';
        file_put_contents($yamlFile, $yaml);

        return new Styleguide($extra + [
            'templates_path' => $templates,
            'static_path' => $static,
            'config_yaml' => $yamlFile,
        ]);
    }

    protected static function get(Styleguide $styleguide, string $uri): \Parisek\Styleguide\Http\Result
    {
        $result = $styleguide->handle(new Request($uri));
        self::assertNotNull($result, $uri);

        return $result;
    }

    /**
     * @return list<string>
     */
    protected static function componentIds(Styleguide $styleguide): array
    {
        $result = self::get($styleguide, '/styleguide/api/components');

        return array_column((array) json_decode((string) $result->body, true), 'id');
    }
}
