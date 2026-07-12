<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\FaviconAudit;
use PHPUnit\Framework\TestCase;

/**
 * Covers {@see FaviconAudit::run()} — server-side existence/dimension/manifest
 * audit backing the `#favicon` foundations section (#73). Fixture assets live
 * under `tests/fixtures/images/touch/`:
 *  - favicon.svg — well-formed, no dimension check
 *  - favicon-96x96.png — real 96×96 (matches `png_96` expectation)
 *  - favicon.ico — single-entry, header declares 32×32
 *  - apple-touch-icon.png — deliberately WRONG size (120×120, expected 180×180)
 *  - web-app-manifest-192x192.png — real 192×192, referenced by the manifest
 *  - site.webmanifest — one existing 192 maskable icon + one missing 512 icon
 */
final class FaviconAuditTest extends TestCase
{
    private const STATIC_PATH = __DIR__ . '/fixtures';

    private const TOUCH = '/images/touch';

    /** @return array<string, mixed> */
    private function happyPathConfig(): array
    {
        return [
            'svg' => self::TOUCH . '/favicon.svg',
            'png_96' => self::TOUCH . '/favicon-96x96.png',
            'ico' => self::TOUCH . '/favicon.ico',
            'apple_touch' => self::TOUCH . '/apple-touch-icon.png',
            'manifest' => self::TOUCH . '/site.webmanifest',
            'theme_color' => '#18181B',
        ];
    }

    public function testHappyPathSvgAndPng96AreOk(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, $this->happyPathConfig());

        $byKey = $this->entriesByKey($result);

        self::assertTrue($byKey['svg']['configured']);
        self::assertTrue($byKey['svg']['exists']);
        self::assertSame('ok', $byKey['svg']['status']);
        self::assertNull($byKey['svg']['width']);
        self::assertNull($byKey['svg']['height']);

        self::assertTrue($byKey['png_96']['configured']);
        self::assertTrue($byKey['png_96']['exists']);
        self::assertSame(96, $byKey['png_96']['width']);
        self::assertSame(96, $byKey['png_96']['height']);
        self::assertSame('96×96', $byKey['png_96']['expected']);
        self::assertSame('ok', $byKey['png_96']['status']);
        self::assertSame('', $byKey['png_96']['note']);
    }

    public function testEntriesFixedOrder(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, $this->happyPathConfig());

        self::assertSame(
            ['svg', 'png_96', 'ico', 'apple_touch', 'manifest'],
            array_column($result['entries'], 'key'),
        );
    }

    public function testWrongSizeAppleTouchIsWarningWithNote(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, $this->happyPathConfig());
        $entry = $this->entriesByKey($result)['apple_touch'];

        self::assertTrue($entry['configured']);
        self::assertTrue($entry['exists']);
        self::assertSame(120, $entry['width']);
        self::assertSame(120, $entry['height']);
        self::assertSame('180×180', $entry['expected']);
        self::assertSame('warning', $entry['status']);
        self::assertStringContainsString('180×180', $entry['note']);
        self::assertStringContainsString('120×120', $entry['note']);
    }

    public function testUnconfiguredKeyStatusUnconfigured(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, []);

        foreach ($result['entries'] as $entry) {
            self::assertFalse($entry['configured']);
            self::assertFalse($entry['exists']);
            self::assertSame('unconfigured', $entry['status']);
            self::assertNull($entry['width']);
            self::assertNull($entry['height']);
        }
    }

    public function testConfiguredButMissingFileStatusMissing(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, [
            'png_96' => self::TOUCH . '/does-not-exist.png',
        ]);
        $entry = $this->entriesByKey($result)['png_96'];

        self::assertTrue($entry['configured']);
        self::assertFalse($entry['exists']);
        self::assertSame('missing', $entry['status']);
        self::assertNull($entry['width']);
        self::assertNull($entry['height']);
    }

    public function testIcoParsesHeaderDimensions(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, $this->happyPathConfig());
        $entry = $this->entriesByKey($result)['ico'];

        self::assertTrue($entry['exists']);
        self::assertSame('ok', $entry['status']);
        self::assertStringContainsString('32×32', $entry['note']);
    }

    public function testInvalidManifestJsonFlagsInvalid(): void
    {
        $dir = sys_get_temp_dir() . '/favicon-audit-test-' . uniqid();
        mkdir($dir . self::TOUCH, 0777, true);
        file_put_contents($dir . self::TOUCH . '/broken.webmanifest', '{not valid json');

        $result = FaviconAudit::run($dir, [
            'manifest' => self::TOUCH . '/broken.webmanifest',
        ]);

        self::assertNotNull($result['manifest']);
        self::assertFalse($result['manifest']['valid']);

        $this->rrmdir($dir);
    }

    public function testManifestUnconfiguredIsNull(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, []);

        self::assertNull($result['manifest']);
    }

    public function testManifestMissingFileIsNull(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, [
            'manifest' => self::TOUCH . '/does-not-exist.webmanifest',
        ]);

        self::assertNull($result['manifest']);

        $entry = $this->entriesByKey($result)['manifest'];
        self::assertTrue($entry['configured']);
        self::assertFalse($entry['exists']);
        self::assertSame('missing', $entry['status']);
    }

    public function testManifestIconExistenceIsCheckedOnDisk(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, $this->happyPathConfig());

        self::assertNotNull($result['manifest']);
        $icons = $result['manifest']['icons'];

        $bySrc = [];
        foreach ($icons as $icon) {
            $bySrc[$icon['src']] = $icon;
        }

        self::assertTrue($bySrc[self::TOUCH . '/web-app-manifest-192x192.png']['exists']);
        self::assertFalse($bySrc[self::TOUCH . '/web-app-manifest-512x512.png']['exists']);
    }

    public function testManifestNotesMissing512Absent(): void
    {
        // Fixture manifest declares a 512 entry (whose file happens to be
        // missing) — the "no 512×512 icon" note fires when no *entry* of
        // that size is declared at all, distinct from the file-existence
        // check above.
        $dir = sys_get_temp_dir() . '/favicon-audit-test-' . uniqid();
        mkdir($dir . self::TOUCH, 0777, true);
        file_put_contents(
            $dir . self::TOUCH . '/no-512.webmanifest',
            json_encode([
                'icons' => [
                    ['src' => self::TOUCH . '/only-192.png', 'sizes' => '192x192', 'purpose' => 'maskable'],
                ],
            ]),
        );

        $result = FaviconAudit::run($dir, [
            'manifest' => self::TOUCH . '/no-512.webmanifest',
        ]);

        self::assertNotNull($result['manifest']);
        $joined = implode(' | ', $result['manifest']['notes']);
        self::assertStringContainsString('512', $joined);

        $this->rrmdir($dir);
    }

    public function testManifestNoMaskablePurposeIsNoted(): void
    {
        $dir = sys_get_temp_dir() . '/favicon-audit-test-' . uniqid();
        mkdir($dir . self::TOUCH, 0777, true);
        file_put_contents(
            $dir . self::TOUCH . '/no-maskable.webmanifest',
            json_encode([
                'icons' => [
                    ['src' => self::TOUCH . '/a192.png', 'sizes' => '192x192', 'purpose' => 'any'],
                    ['src' => self::TOUCH . '/a512.png', 'sizes' => '512x512', 'purpose' => 'any'],
                ],
            ]),
        );

        $result = FaviconAudit::run($dir, [
            'manifest' => self::TOUCH . '/no-maskable.webmanifest',
        ]);

        self::assertNotNull($result['manifest']);
        $joined = implode(' | ', $result['manifest']['notes']);
        self::assertStringContainsString('maskable', $joined);

        $this->rrmdir($dir);
    }

    public function testThemeColorInvalidHexBecomesNull(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, [
            'theme_color' => 'not-a-color',
        ]);

        self::assertNull($result['theme_color']);
    }

    public function testThemeColorAbsentIsNull(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, []);

        self::assertNull($result['theme_color']);
    }

    public function testThemeColorValidHexIsPreserved(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, $this->happyPathConfig());

        self::assertSame('#18181B', $result['theme_color']);
    }

    public function testTabIconPrefersSvgOverPng96(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, $this->happyPathConfig());

        self::assertSame(self::TOUCH . '/favicon.svg', $result['tab_icon']);
    }

    public function testTabIconFallsBackToPng96WhenSvgMissing(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, [
            'png_96' => self::TOUCH . '/favicon-96x96.png',
        ]);

        self::assertSame(self::TOUCH . '/favicon-96x96.png', $result['tab_icon']);
    }

    public function testTabIconNullWhenNeitherExists(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, []);

        self::assertNull($result['tab_icon']);
    }

    public function testTouchIconIsAppleTouchWhenExists(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, $this->happyPathConfig());

        self::assertSame(self::TOUCH . '/apple-touch-icon.png', $result['touch_icon']);
    }

    public function testTouchIconNullWhenAppleTouchMissing(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, []);

        self::assertNull($result['touch_icon']);
    }

    public function testMaskableIconPrefersMaskablePurposeIcon(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, $this->happyPathConfig());

        self::assertSame(self::TOUCH . '/web-app-manifest-192x192.png', $result['maskable_icon']);
    }

    public function testMaskableIconFallsBackToFirstExistingIconWhenNoneMaskable(): void
    {
        $dir = sys_get_temp_dir() . '/favicon-audit-test-' . uniqid();
        mkdir($dir . self::TOUCH, 0777, true);
        copy(self::STATIC_PATH . self::TOUCH . '/web-app-manifest-192x192.png', $dir . self::TOUCH . '/any-192.png');
        file_put_contents(
            $dir . self::TOUCH . '/any-purpose.webmanifest',
            json_encode([
                'icons' => [
                    ['src' => self::TOUCH . '/missing-first.png', 'sizes' => '96x96', 'purpose' => 'any'],
                    ['src' => self::TOUCH . '/any-192.png', 'sizes' => '192x192', 'purpose' => 'any'],
                ],
            ]),
        );

        $result = FaviconAudit::run($dir, [
            'manifest' => self::TOUCH . '/any-purpose.webmanifest',
        ]);

        self::assertSame(self::TOUCH . '/any-192.png', $result['maskable_icon']);

        $this->rrmdir($dir);
    }

    public function testMaskableIconNullWhenManifestUnconfigured(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, []);

        self::assertNull($result['maskable_icon']);
    }

    /** @param array<string, mixed> $result */
    private function entriesByKey(array $result): array
    {
        $byKey = [];
        foreach ($result['entries'] as $entry) {
            $byKey[$entry['key']] = $entry;
        }

        return $byKey;
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
