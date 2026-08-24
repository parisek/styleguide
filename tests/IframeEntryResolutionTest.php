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

    /**
     * The whole resolved array, for assertions about `js_hashed` rather than
     * just the rewritten URL.
     *
     * @return array<string, mixed>
     */
    private function resolveAll(string $url): array
    {
        $styleguide = (new ReflectionClass(Styleguide::class))->newInstanceWithoutConstructor();

        $config = (new ReflectionClass($styleguide))->getProperty('config');
        $config->setAccessible(true);
        $config->setValue($styleguide, ['static_path' => $this->static]);

        $method = new ReflectionMethod($styleguide, 'resolveIframeEntry');
        $method->setAccessible(true);

        return $method->invoke($styleguide, ['js' => $url]);
    }

    public function testFlagsAResolvedEntryAsHashedSoItIsNotCacheBusted(): void
    {
        $this->writeManifest(['src/js/script.js' => ['file' => 'script.B-Kb6C8P.min.js', 'isEntry' => true]]);
        touch($this->js . '/script.B-Kb6C8P.min.js');

        $resolved = $this->resolveAll('/dist/js/script.js');

        self::assertSame('/dist/js/script.B-Kb6C8P.min.js', $resolved['js']);
        self::assertTrue(
            $resolved['js_hashed'] ?? false,
            'A manifest-resolved entry must be flagged so render-cell.twig skips |cachebust; '
            . 'appending ?v= gives the browser a second URL for one immutable file.',
        );
    }

    public function testDoesNotFlagAnEntryItLeftAlone(): void
    {
        // No manifest: the logical path stays, and it still needs cache-busting.
        $resolved = $this->resolveAll('/dist/js/script.js');

        self::assertSame('/dist/js/script.js', $resolved['js']);
        self::assertArrayNotHasKey(
            'js_hashed',
            $resolved,
            'An unresolved entry carries no hash, so it must keep going through |cachebust.',
        );
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

    /**
     * `is_file()` resolves `..`, so a path value would escape the bundle
     * directory. The target is CREATED here on purpose: without it the test
     * passes because the file is missing, not because `basename()` rejected the
     * path, and it would keep passing with the containment check deleted.
     */
    #[Test]
    public function a_file_value_that_escapes_the_directory_is_ignored(): void
    {
        $outside = dirname($this->js) . '/evil.js';
        file_put_contents($outside, '/* elsewhere */');

        $this->writeManifest(['src/js/script.js' => ['file' => '../evil.js', 'isEntry' => true]]);
        $resolved = $this->resolve();

        unlink($outside);

        self::assertSame('/dist/js/script.js', $resolved);
    }

    /** A remote bundle is never rewritten from a local manifest. */
    #[Test]
    public function an_external_url_is_left_alone(): void
    {
        touch($this->js . '/script.B7fm2cuz.min.js');
        $this->writeManifest(['src/js/script.js' => ['file' => 'script.B7fm2cuz.min.js', 'isEntry' => true]]);

        self::assertSame(
            'https://cdn.example.com/dist/js/script.js',
            $this->resolve('https://cdn.example.com/dist/js/script.js'),
        );
        self::assertSame(
            '//cdn.example.com/dist/js/script.js',
            $this->resolve('//cdn.example.com/dist/js/script.js'),
        );
    }

    /** A query string or fragment the consumer wrote survives the substitution. */
    #[Test]
    public function it_carries_a_query_and_fragment_across(): void
    {
        touch($this->js . '/script.B7fm2cuz.min.js');
        $this->writeManifest(['src/js/script.js' => ['file' => 'script.B7fm2cuz.min.js', 'isEntry' => true]]);

        self::assertSame(
            '/dist/js/script.B7fm2cuz.min.js?v=1#top',
            $this->resolve('/dist/js/script.js?v=1#top'),
        );
    }

    /**
     * A directory URL names no file, so nothing beside it may claim to be one.
     *
     * The manifest one level UP is written on purpose. `/dist/js/` would
     * otherwise be read as though `js` were the filename, and the search would
     * move to `/dist/.vite` — so without a manifest there this test passes on
     * its absence and would keep passing with the guard deleted.
     */
    #[Test]
    public function a_directory_url_is_left_alone(): void
    {
        $parent = dirname($this->js);
        mkdir($parent . '/.vite', 0777, true);
        file_put_contents($parent . '/unrelated.min.js', '/* another build */');
        file_put_contents(
            $parent . '/.vite/manifest.json',
            json_encode(['src/js/script.js' => ['file' => 'unrelated.min.js', 'isEntry' => true]]),
        );

        $resolved = $this->resolve('/dist/js/');

        unlink($parent . '/.vite/manifest.json');
        unlink($parent . '/unrelated.min.js');
        rmdir($parent . '/.vite');

        self::assertSame('/dist/js/', $resolved);
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

    /**
     * Only the logical entry is rewritten, not any file beside the manifest.
     *
     * The decision used to be made from the URL's DIRECTORY alone, so a second
     * bundle sitting next to the manifest was silently replaced by the first.
     * `main.js` is the case that matters in practice — `sync-styleguide` still
     * emits it as an alternative to `script.js`.
     */
    #[Test]
    public function a_different_bundle_in_the_same_directory_is_left_alone(): void
    {
        touch($this->js . '/script.B7fm2cuz.min.js');
        $this->writeManifest(['src/js/script.js' => ['file' => 'script.B7fm2cuz.min.js', 'isEntry' => true]]);

        self::assertSame('/dist/js/admin.js', $this->resolve('/dist/js/admin.js'));
        self::assertSame('/dist/js/main.js', $this->resolve('/dist/js/main.js'));
    }
}
