<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\OgImageAudit;
use PHPUnit\Framework\TestCase;

/**
 * Covers {@see OgImageAudit::run()} — server-side existence/dimension/
 * aspect/file-size audit backing the `#og-image` foundations section
 * (#74). Fixed fixture lives at `tests/fixtures/images/og-image.png` — a
 * real 1200×630 PNG (solid fill, tiny). Tests needing other dimensions or
 * file sizes generate temp fixtures with GD / padding, mirroring
 * `FaviconAuditTest`'s temp-dir pattern.
 */
final class OgImageAuditTest extends TestCase
{
    private const STATIC_PATH = __DIR__ . '/fixtures';

    private const IMAGES = '/images';

    public function testFixturePngIsReallyTwelveHundredBySixThirty(): void
    {
        // Sanity check on the fixture itself, independent of the class
        // under test — protects against the fixture silently drifting
        // (e.g. someone regenerating it at the wrong size).
        $size = getimagesize(self::STATIC_PATH . self::IMAGES . '/og-image.png');

        self::assertNotFalse($size);
        self::assertSame(1200, $size[0]);
        self::assertSame(630, $size[1]);
    }

    public function testUnconfiguredIsUnconfigured(): void
    {
        $result = OgImageAudit::run(self::STATIC_PATH, null);

        self::assertFalse($result['configured']);
        self::assertSame('', $result['path']);
        self::assertFalse($result['exists']);
        self::assertNull($result['width']);
        self::assertNull($result['height']);
        self::assertNull($result['filesize']);
        self::assertSame('unconfigured', $result['status']);
        self::assertSame([], $result['notes']);
    }

    public function testEmptyStringIsUnconfigured(): void
    {
        $result = OgImageAudit::run(self::STATIC_PATH, '');

        self::assertFalse($result['configured']);
        self::assertSame('unconfigured', $result['status']);
    }

    public function testNonStringIsUnconfigured(): void
    {
        $result = OgImageAudit::run(self::STATIC_PATH, false);

        self::assertFalse($result['configured']);
        self::assertSame('unconfigured', $result['status']);
    }

    public function testConfiguredButMissingFileStatusMissing(): void
    {
        $result = OgImageAudit::run(self::STATIC_PATH, self::IMAGES . '/does-not-exist.png');

        self::assertTrue($result['configured']);
        self::assertFalse($result['exists']);
        self::assertNull($result['width']);
        self::assertNull($result['height']);
        self::assertNull($result['filesize']);
        self::assertSame('missing', $result['status']);
        self::assertSame([], $result['notes']);
    }

    public function testValidTwelveHundredBySixThirtyIsOk(): void
    {
        $result = OgImageAudit::run(self::STATIC_PATH, self::IMAGES . '/og-image.png');

        self::assertTrue($result['configured']);
        self::assertTrue($result['exists']);
        self::assertSame(1200, $result['width']);
        self::assertSame(630, $result['height']);
        self::assertNotNull($result['filesize']);
        self::assertSame('ok', $result['status']);
        self::assertSame([], $result['notes']);
    }

    public function testRelativePathWithoutLeadingSlashResolvesAndNormalizes(): void
    {
        // (#74 review) `PathGuard::resolvePath()` used to concatenate
        // `$staticPath . $path` verbatim — a yaml value with no leading
        // slash (`og_image: images/og-image.png`, the natural way an
        // editor would write it) produced `.../fixturesimages/og-image.png`
        // and was misreported as missing. The join must tolerate either
        // form, and the rendered `path` must always come back root-relative
        // regardless of how the yaml value was written.
        $result = OgImageAudit::run(self::STATIC_PATH, 'images/og-image.png');

        self::assertTrue($result['configured']);
        self::assertTrue($result['exists']);
        self::assertSame('/images/og-image.png', $result['path']);
        self::assertSame(1200, $result['width']);
        self::assertSame(630, $result['height']);
        self::assertSame('ok', $result['status']);
    }

    public function testSmallButOverMinimumIsWarningWithNote(): void
    {
        $dir = $this->makeTempDir();
        $this->writeSolidPng($dir . self::IMAGES . '/small.png', 800, 420);

        $result = OgImageAudit::run($dir, self::IMAGES . '/small.png');

        self::assertSame(800, $result['width']);
        self::assertSame(420, $result['height']);
        self::assertSame('warning', $result['status']);
        self::assertContains('below recommended 1200×630', $result['notes']);

        $this->rrmdir($dir);
    }

    public function testTinyImageIsError(): void
    {
        $dir = $this->makeTempDir();
        $this->writeSolidPng($dir . self::IMAGES . '/tiny.png', 100, 60);

        $result = OgImageAudit::run($dir, self::IMAGES . '/tiny.png');

        self::assertSame(100, $result['width']);
        self::assertSame(60, $result['height']);
        self::assertSame('error', $result['status']);
        self::assertNotSame([], $result['notes']);

        $this->rrmdir($dir);
    }

    public function testAspectDeviationAddsNoteButStaysOk(): void
    {
        $dir = $this->makeTempDir();
        // 1200×1200 clears both the recommended-dimension floor (height
        // 1200 ≥ 630) and the minimum floor, so status stays 'ok' — but
        // its 1:1 ratio is nowhere near 1.91:1, so a note must still fire.
        $this->writeSolidPng($dir . self::IMAGES . '/square.png', 1200, 1200);

        $result = OgImageAudit::run($dir, self::IMAGES . '/square.png');

        self::assertSame('ok', $result['status']);
        $joined = implode(' | ', $result['notes']);
        self::assertStringContainsString('aspect ratio 1:1 differs from 1.91:1', $joined);
        self::assertStringContainsString('platforms will crop', $joined);

        $this->rrmdir($dir);
    }

    public function testCorrectAspectRatioAddsNoNote(): void
    {
        // 1200×630 is exactly the 1.91:1 target (within rounding) — the
        // fixture used across the "ok" tests already covers this, but
        // assert it explicitly here for the aspect check itself.
        $result = OgImageAudit::run(self::STATIC_PATH, self::IMAGES . '/og-image.png');

        self::assertStringNotContainsString('aspect ratio', implode(' | ', $result['notes']));
    }

    public function testFilesizeOverOneMegabyteIsWarning(): void
    {
        $dir = $this->makeTempDir();
        $path = $dir . self::IMAGES . '/heavy.png';
        $this->writeSolidPng($path, 1200, 630);
        $this->padFileTo($path, 1_100_000);

        $result = OgImageAudit::run($dir, self::IMAGES . '/heavy.png');

        self::assertSame(1200, $result['width']);
        self::assertSame(630, $result['height']);
        self::assertGreaterThan(1_000_000, $result['filesize']);
        self::assertSame('warning', $result['status']);
        self::assertContains('large file — social crawlers may skip it', $result['notes']);

        $this->rrmdir($dir);
    }

    public function testFilesizeOverEightMegabytesIsError(): void
    {
        $dir = $this->makeTempDir();
        $path = $dir . self::IMAGES . '/huge.png';
        $this->writeSolidPng($path, 1200, 630);
        $this->padFileTo($path, 8_500_000);

        $result = OgImageAudit::run($dir, self::IMAGES . '/huge.png');

        self::assertSame(1200, $result['width']);
        self::assertSame(630, $result['height']);
        self::assertGreaterThan(8_000_000, $result['filesize']);
        self::assertSame('error', $result['status']);
        self::assertNotSame([], $result['notes']);

        $this->rrmdir($dir);
    }

    public function testFilesizeUnderOneMegabyteAddsNoFilesizeNote(): void
    {
        $result = OgImageAudit::run(self::STATIC_PATH, self::IMAGES . '/og-image.png');

        self::assertStringNotContainsString('MB', implode(' | ', $result['notes']));
    }

    public function testExternalUrlProtocolRelativeIsRejected(): void
    {
        $result = OgImageAudit::run(self::STATIC_PATH, '//evil.test/og.png');

        self::assertTrue($result['configured']);
        self::assertFalse($result['exists']);
        self::assertSame('error', $result['status']);
        self::assertContains('external URLs are not allowed', $result['notes']);
    }

    public function testExternalUrlAbsoluteSchemeIsRejected(): void
    {
        $result = OgImageAudit::run(self::STATIC_PATH, 'https://evil.test/og.png');

        self::assertTrue($result['configured']);
        self::assertFalse($result['exists']);
        self::assertSame('error', $result['status']);
        self::assertContains('external URLs are not allowed', $result['notes']);
    }

    public function testBareSchemeDataUriIsRejected(): void
    {
        $result = OgImageAudit::run(self::STATIC_PATH, 'data:image/png;base64,x');

        self::assertTrue($result['configured']);
        self::assertFalse($result['exists']);
        self::assertSame('error', $result['status']);
        self::assertContains('external URLs are not allowed', $result['notes']);
    }

    public function testBareSchemeJavascriptUriIsRejected(): void
    {
        $result = OgImageAudit::run(self::STATIC_PATH, 'javascript:alert(1)');

        self::assertTrue($result['configured']);
        self::assertSame('error', $result['status']);
        self::assertContains('external URLs are not allowed', $result['notes']);
    }

    public function testPathTraversalOutsideStaticRootIsError(): void
    {
        $result = OgImageAudit::run(self::STATIC_PATH, '/../../composer.json');

        self::assertTrue($result['configured']);
        self::assertFalse($result['exists']);
        self::assertSame('error', $result['status']);
        self::assertContains('path escapes the static root', $result['notes']);
    }

    public function testUnreadableImageIsError(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . self::IMAGES . '/fake.png', 'this is not a png at all');

        $result = OgImageAudit::run($dir, self::IMAGES . '/fake.png');

        self::assertTrue($result['exists']);
        self::assertNull($result['width']);
        self::assertNull($result['height']);
        self::assertSame('error', $result['status']);
        self::assertContains('file is not a readable image', $result['notes']);

        $this->rrmdir($dir);
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/og-image-audit-test-' . uniqid();
        mkdir($dir . self::IMAGES, 0777, true);

        return $dir;
    }

    private function writeSolidPng(string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        $fill = imagecolorallocate($image, 0x2A, 0x5C, 0x8A);
        imagefill($image, 0, 0, $fill);
        imagepng($image, $path, 9);
    }

    /**
     * Appends trailing zero bytes until `$path` reaches `$targetBytes` —
     * `getimagesize()` only parses the header, so junk appended after a
     * PNG's `IEND` chunk doesn't affect decodability, letting these tests
     * isolate the file-size check from the dimension check.
     */
    private function padFileTo(string $path, int $targetBytes): void
    {
        $current = (int) filesize($path);
        $padding = max(0, $targetBytes - $current);
        $handle = fopen($path, 'ab');
        self::assertNotFalse($handle);
        fwrite($handle, str_repeat("\0", $padding));
        fclose($handle);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
