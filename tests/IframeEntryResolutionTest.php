<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * `iframe.js` names a logical path; the built file carries a content hash.
 *
 * Every test below that expects the path UNCHANGED is the important half. This
 * ships before the build change that hashes anything, so on every consumer it
 * starts out taking one of those paths — a regression there breaks every
 * styleguide at once, while a regression in the happy path breaks only the ones
 * that have rebuilt.
 */
final class IframeEntryResolutionTest extends TestCase
{
    private string $static = '';

    private string $js = '';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/sg-iframe-' . bin2hex(random_bytes(6));
        $this->static = $root . '/wp-content/themes/x/static';
        $this->js = $this->static . '/dist/js';
        mkdir($this->js . '/.vite', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['/.vite/manifest.json'] as $file) {
            if (is_file($this->js . $file)) {
                unlink($this->js . $file);
            }
        }
        foreach (glob($this->js . '/*.js') ?: [] as $file) {
            unlink($file);
        }
    }

    /** Call the private resolver on an instance built without its constructor. */
    private function resolve(string $url = '/dist/js/script.js'): string
    {
        $styleguide = (new ReflectionClass(Styleguide::class))->newInstanceWithoutConstructor();

        $config = (new ReflectionClass($styleguide))->getProperty('config');
        $config->setAccessible(true);
        $config->setValue($styleguide, ['static_path' => $this->static]);

        $method = new ReflectionMethod($styleguide, 'resolveIframeEntry');
        $method->setAccessible(true);

        return $method->invoke($styleguide, ['js' => $url])['js'];
    }

    private function writeManifest(array $records): void
    {
        file_put_contents($this->js . '/.vite/manifest.json', json_encode($records, JSON_UNESCAPED_SLASHES));
    }

    #[Test]
    public function it_substitutes_the_built_filename_named_by_the_manifest(): void
    {
        touch($this->js . '/script.B7fm2cuz.min.js');
        $this->writeManifest(['src/js/script.js' => ['file' => 'script.B7fm2cuz.min.js', 'isEntry' => true]]);

        self::assertSame('/dist/js/script.B7fm2cuz.min.js', $this->resolve());
    }

    #[Test]
    public function a_theme_that_has_not_rebuilt_keeps_the_logical_path(): void
    {
        self::assertSame('/dist/js/script.js', $this->resolve());
    }

    /**
     * The record must name a file that is there. Mirrors
     * `StarterBase::isUsableEntryFile()` — this decides what to SERVE and that
     * one what to ENQUEUE, so a manifest either would refuse must be refused by
     * both, or the disagreement is itself the defect.
     */
    #[Test]
    public function a_manifest_naming_a_missing_file_is_ignored(): void
    {
        $this->writeManifest(['src/js/script.js' => ['file' => 'script.GONE.min.js', 'isEntry' => true]]);

        self::assertSame('/dist/js/script.js', $this->resolve());
    }

    /** The key is the interface — a manifest without it does not describe this entry. */
    #[Test]
    public function a_manifest_without_this_entry_key_is_ignored(): void
    {
        touch($this->js . '/admin.Cq31vv.min.js');
        $this->writeManifest(['src/js/admin.js' => ['file' => 'admin.Cq31vv.min.js', 'isEntry' => true]]);

        self::assertSame('/dist/js/script.js', $this->resolve());
    }

    #[Test]
    public function a_malformed_manifest_is_ignored(): void
    {
        file_put_contents($this->js . '/.vite/manifest.json', '{ this is not json');

        self::assertSame('/dist/js/script.js', $this->resolve());
    }

    /** `is_file()` resolves `..`, so a path value would escape the bundle directory. */
    #[Test]
    public function a_file_value_that_escapes_the_directory_is_ignored(): void
    {
        $this->writeManifest(['src/js/script.js' => ['file' => '../../evil.js', 'isEntry' => true]]);

        self::assertSame('/dist/js/script.js', $this->resolve());
    }

    /** A stylesheet under the script key is never served as the script. */
    #[Test]
    public function a_non_javascript_entry_is_ignored(): void
    {
        touch($this->js . '/style.h.css');
        $this->writeManifest(['src/js/script.js' => ['file' => 'style.h.css', 'isEntry' => true]]);

        self::assertSame('/dist/js/script.js', $this->resolve());
    }

    /** An empty or absent `js` key stays absent — nothing to resolve. */
    #[Test]
    public function an_absent_js_key_is_left_alone(): void
    {
        $styleguide = (new ReflectionClass(Styleguide::class))->newInstanceWithoutConstructor();
        $config = (new ReflectionClass($styleguide))->getProperty('config');
        $config->setAccessible(true);
        $config->setValue($styleguide, ['static_path' => $this->static]);

        $method = new ReflectionMethod($styleguide, 'resolveIframeEntry');
        $method->setAccessible(true);

        self::assertSame(['css' => '/dist/css/style.css'], $method->invoke($styleguide, ['css' => '/dist/css/style.css']));
    }
}
