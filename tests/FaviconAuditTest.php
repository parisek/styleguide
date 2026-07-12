<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\FaviconAudit;
use Parisek\Styleguide\PathGuard;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testThemeColorInvalidHexIsConfiguredButInvalid(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, [
            'theme_color' => 'not-a-color',
        ]);

        self::assertTrue($result['theme_color']['configured']);
        self::assertFalse($result['theme_color']['valid']);
        self::assertNull($result['theme_color']['value']);
    }

    public function testThemeColorAbsentIsUnconfigured(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, []);

        self::assertFalse($result['theme_color']['configured']);
        self::assertFalse($result['theme_color']['valid']);
        self::assertNull($result['theme_color']['value']);
    }

    public function testThemeColorValidHexIsNormalizedViaColorUtilRoundTrip(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, $this->happyPathConfig());

        self::assertTrue($result['theme_color']['configured']);
        self::assertTrue($result['theme_color']['valid']);
        self::assertSame('#18181B', $result['theme_color']['value']);
    }

    public function testThemeColorShorthandHexIsExpandedByRoundTrip(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, [
            'theme_color' => '#fff',
        ]);

        self::assertTrue($result['theme_color']['valid']);
        self::assertSame('#FFFFFF', $result['theme_color']['value']);
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

    public function testPathTraversalOutsideStaticRootIsError(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, [
            'svg' => '/../../composer.json',
        ]);
        $entry = $this->entriesByKey($result)['svg'];

        self::assertTrue($entry['configured']);
        self::assertFalse($entry['exists']);
        self::assertSame('error', $entry['status']);
        self::assertSame('path escapes the static root', $entry['note']);
    }

    public function testNormalMissingFileExistsSemanticsPreserved(): void
    {
        // A plain missing-but-contained path must still resolve as
        // "missing", not "error" — containment doesn't hijack the ordinary
        // not-found path.
        $result = FaviconAudit::run(self::STATIC_PATH, [
            'svg' => self::TOUCH . '/does-not-exist.svg',
        ]);
        $entry = $this->entriesByKey($result)['svg'];

        self::assertTrue($entry['configured']);
        self::assertFalse($entry['exists']);
        self::assertSame('missing', $entry['status']);
    }

    public function testManifestIconSrcEscapingRootIsNotExistingWithNote(): void
    {
        $dir = sys_get_temp_dir() . '/favicon-audit-test-' . uniqid();
        mkdir($dir . self::TOUCH, 0777, true);
        file_put_contents(
            $dir . self::TOUCH . '/escaping.webmanifest',
            json_encode([
                'icons' => [
                    ['src' => '../../../outside-static-root.json', 'sizes' => '192x192', 'purpose' => 'maskable'],
                ],
            ]),
        );

        $result = FaviconAudit::run($dir, [
            'manifest' => self::TOUCH . '/escaping.webmanifest',
        ]);

        self::assertNotNull($result['manifest']);
        self::assertFalse($result['manifest']['icons'][0]['exists']);
        $joined = implode(' | ', $result['manifest']['notes']);
        self::assertStringContainsString('escapes the static root', $joined);

        $this->rrmdir($dir);
    }

    public function testIcoZeroByteEntryMapsTo256(): void
    {
        $dir = sys_get_temp_dir() . '/favicon-audit-test-' . uniqid();
        mkdir($dir . self::TOUCH, 0777, true);
        // reserved=0, type=1, count=1, then a single 16-byte directory
        // entry whose width/height bytes are both 0 → spec says 256.
        $ico = pack('vvv', 0, 1, 1) . pack('C*', 0, 0, 0, 0, 1, 0, 32, 0, 0, 0, 0, 0, 22, 0, 0, 0);
        file_put_contents($dir . self::TOUCH . '/zero.ico', $ico);

        $result = FaviconAudit::run($dir, [
            'ico' => self::TOUCH . '/zero.ico',
        ]);
        $entry = $this->entriesByKey($result)['ico'];

        self::assertSame('ok', $entry['status']);
        self::assertStringContainsString('256×256', $entry['note']);

        $this->rrmdir($dir);
    }

    public function testIcoGarbageBytesFailsGracefully(): void
    {
        $dir = sys_get_temp_dir() . '/favicon-audit-test-' . uniqid();
        mkdir($dir . self::TOUCH, 0777, true);
        file_put_contents($dir . self::TOUCH . '/garbage.ico', 'this is not an ico file at all');

        $result = FaviconAudit::run($dir, [
            'ico' => self::TOUCH . '/garbage.ico',
        ]);
        $entry = $this->entriesByKey($result)['ico'];

        self::assertSame('error', $entry['status']);
        self::assertNotSame('', $entry['note']);

        $this->rrmdir($dir);
    }

    public function testIcoTruncatedHeaderFailsGracefully(): void
    {
        $dir = sys_get_temp_dir() . '/favicon-audit-test-' . uniqid();
        mkdir($dir . self::TOUCH, 0777, true);
        // Fewer than the 6 header bytes required.
        file_put_contents($dir . self::TOUCH . '/truncated.ico', "\x00\x00\x01");

        $result = FaviconAudit::run($dir, [
            'ico' => self::TOUCH . '/truncated.ico',
        ]);
        $entry = $this->entriesByKey($result)['ico'];

        self::assertSame('error', $entry['status']);
        self::assertNotSame('', $entry['note']);

        $this->rrmdir($dir);
    }

    public function testIcoTruncatedDirectoryEntryFailsGracefully(): void
    {
        $dir = sys_get_temp_dir() . '/favicon-audit-test-' . uniqid();
        mkdir($dir . self::TOUCH, 0777, true);
        // Valid 6-byte header claiming 1 entry, but no directory bytes follow.
        file_put_contents($dir . self::TOUCH . '/short-dir.ico', pack('vvv', 0, 1, 1));

        $result = FaviconAudit::run($dir, [
            'ico' => self::TOUCH . '/short-dir.ico',
        ]);
        $entry = $this->entriesByKey($result)['ico'];

        self::assertSame('error', $entry['status']);
        self::assertNotSame('', $entry['note']);

        $this->rrmdir($dir);
    }

    public function testSvgWithoutSvgRootIsError(): void
    {
        $dir = sys_get_temp_dir() . '/favicon-audit-test-' . uniqid();
        mkdir($dir . self::TOUCH, 0777, true);
        file_put_contents($dir . self::TOUCH . '/not-svg.svg', 'this is plain text, not markup');

        $result = FaviconAudit::run($dir, [
            'svg' => self::TOUCH . '/not-svg.svg',
        ]);
        $entry = $this->entriesByKey($result)['svg'];

        self::assertTrue($entry['exists']);
        self::assertSame('error', $entry['status']);
        self::assertStringContainsString('<svg', $entry['note']);

        $this->rrmdir($dir);
    }

    public function testPngUnreadableImageIsError(): void
    {
        $dir = sys_get_temp_dir() . '/favicon-audit-test-' . uniqid();
        mkdir($dir . self::TOUCH, 0777, true);
        file_put_contents($dir . self::TOUCH . '/fake.png', 'this is not a png at all');

        $result = FaviconAudit::run($dir, [
            'png_96' => self::TOUCH . '/fake.png',
        ]);
        $entry = $this->entriesByKey($result)['png_96'];

        self::assertTrue($entry['exists']);
        self::assertSame('error', $entry['status']);
        self::assertSame('file is not a readable image', $entry['note']);

        $this->rrmdir($dir);
    }

    public function testHasSizeMatchesWhitespaceSeparatedTokenAnywhere(): void
    {
        $dir = sys_get_temp_dir() . '/favicon-audit-test-' . uniqid();
        mkdir($dir . self::TOUCH, 0777, true);
        copy(self::STATIC_PATH . self::TOUCH . '/web-app-manifest-192x192.png', $dir . self::TOUCH . '/multi.png');
        file_put_contents(
            $dir . self::TOUCH . '/multi-size.webmanifest',
            json_encode([
                'icons' => [
                    ['src' => self::TOUCH . '/multi.png', 'sizes' => '96x96 192x192', 'purpose' => 'any'],
                ],
            ]),
        );

        $result = FaviconAudit::run($dir, [
            'manifest' => self::TOUCH . '/multi-size.webmanifest',
        ]);

        self::assertNotNull($result['manifest']);
        // "no 192×192 icon" must NOT be in the notes — the 192 token is
        // present, just not the first one in the whitespace-separated list.
        $joined = implode(' | ', $result['manifest']['notes']);
        self::assertStringNotContainsString('no 192×192 icon', $joined);

        $this->rrmdir($dir);
    }

    public function testMaskableIconTieBreakPrefers192OverLargerNonMaskable(): void
    {
        $dir = sys_get_temp_dir() . '/favicon-audit-test-' . uniqid();
        mkdir($dir . self::TOUCH, 0777, true);
        copy(self::STATIC_PATH . self::TOUCH . '/web-app-manifest-192x192.png', $dir . self::TOUCH . '/icon-512.png');
        copy(self::STATIC_PATH . self::TOUCH . '/web-app-manifest-192x192.png', $dir . self::TOUCH . '/icon-192.png');
        file_put_contents(
            $dir . self::TOUCH . '/tiebreak.webmanifest',
            json_encode([
                'icons' => [
                    ['src' => self::TOUCH . '/icon-512.png', 'sizes' => '512x512', 'purpose' => 'maskable'],
                    ['src' => self::TOUCH . '/icon-192.png', 'sizes' => '192x192', 'purpose' => 'any'],
                ],
            ]),
        );

        $result = FaviconAudit::run($dir, [
            'manifest' => self::TOUCH . '/tiebreak.webmanifest',
        ]);

        // No icon is both maskable AND 192 — falls back to "first existing
        // maskable" (the 512), even though a non-maskable 192 also exists.
        self::assertSame(self::TOUCH . '/icon-512.png', $result['maskable_icon']);

        $this->rrmdir($dir);
    }

    public function testManifestIconRelativeSrcIsNormalizedAgainstManifestDir(): void
    {
        $dir = sys_get_temp_dir() . '/favicon-audit-test-' . uniqid();
        mkdir($dir . self::TOUCH, 0777, true);
        copy(self::STATIC_PATH . self::TOUCH . '/web-app-manifest-192x192.png', $dir . self::TOUCH . '/icon-192.png');
        file_put_contents(
            $dir . self::TOUCH . '/relative.webmanifest',
            json_encode([
                'icons' => [
                    ['src' => 'icon-192.png', 'sizes' => '192x192', 'purpose' => 'maskable'],
                ],
            ]),
        );

        $result = FaviconAudit::run($dir, [
            'manifest' => self::TOUCH . '/relative.webmanifest',
        ]);

        self::assertNotNull($result['manifest']);
        $icon = $result['manifest']['icons'][0];
        self::assertSame(self::TOUCH . '/icon-192.png', $icon['src']);
        self::assertTrue($icon['exists']);
        // The rendered maskable-icon <img> src must be the normalized web
        // path too, not the raw manifest-relative string.
        self::assertSame(self::TOUCH . '/icon-192.png', $result['maskable_icon']);

        $this->rrmdir($dir);
    }

    public function testManifestIconAbsoluteSrcStaysAsIs(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, $this->happyPathConfig());

        self::assertNotNull($result['manifest']);
        $bySrc = [];
        foreach ($result['manifest']['icons'] as $icon) {
            $bySrc[$icon['src']] = $icon;
        }

        self::assertArrayHasKey(self::TOUCH . '/web-app-manifest-192x192.png', $bySrc);
    }

    public function testExternalUrlYamlConfiguredPathIsRejectedProtocolRelative(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, [
            'svg' => '//evil.test/favicon.svg',
        ]);
        $entry = $this->entriesByKey($result)['svg'];

        self::assertTrue($entry['configured']);
        self::assertFalse($entry['exists']);
        self::assertSame('error', $entry['status']);
        self::assertStringContainsString('external URLs are not allowed', $entry['note']);
    }

    public function testExternalUrlYamlConfiguredPathIsRejectedAbsoluteScheme(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, [
            'svg' => 'https://evil.test/x.png',
        ]);
        $entry = $this->entriesByKey($result)['svg'];

        self::assertTrue($entry['configured']);
        self::assertFalse($entry['exists']);
        self::assertSame('error', $entry['status']);
        self::assertStringContainsString('external URLs are not allowed', $entry['note']);
    }

    public function testExternalUrlManifestSrcIsRejectedProtocolRelative(): void
    {
        $dir = sys_get_temp_dir() . '/favicon-audit-test-' . uniqid();
        mkdir($dir . self::TOUCH, 0777, true);
        file_put_contents(
            $dir . self::TOUCH . '/external.webmanifest',
            json_encode([
                'icons' => [
                    ['src' => '//evil.test/favicon.svg', 'sizes' => '192x192', 'purpose' => 'maskable'],
                ],
            ]),
        );

        $result = FaviconAudit::run($dir, [
            'manifest' => self::TOUCH . '/external.webmanifest',
        ]);

        self::assertNotNull($result['manifest']);
        self::assertFalse($result['manifest']['icons'][0]['exists']);
        $joined = implode(' | ', $result['manifest']['notes']);
        self::assertStringContainsString('external URLs are not allowed', $joined);
        self::assertNull($result['maskable_icon']);

        $this->rrmdir($dir);
    }

    public function testExternalUrlManifestSrcIsRejectedAbsoluteScheme(): void
    {
        $dir = sys_get_temp_dir() . '/favicon-audit-test-' . uniqid();
        mkdir($dir . self::TOUCH, 0777, true);
        file_put_contents(
            $dir . self::TOUCH . '/external-scheme.webmanifest',
            json_encode([
                'icons' => [
                    ['src' => 'https://evil.test/x.png', 'sizes' => '192x192', 'purpose' => 'maskable'],
                ],
            ]),
        );

        $result = FaviconAudit::run($dir, [
            'manifest' => self::TOUCH . '/external-scheme.webmanifest',
        ]);

        self::assertNotNull($result['manifest']);
        self::assertFalse($result['manifest']['icons'][0]['exists']);
        $joined = implode(' | ', $result['manifest']['notes']);
        self::assertStringContainsString('external URLs are not allowed', $joined);
        self::assertNull($result['maskable_icon']);

        $this->rrmdir($dir);
    }

    public function testBareSchemeUrlYamlConfiguredPathIsRejectedDataUri(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, [
            'svg' => 'data:image/svg+xml;base64,x',
        ]);
        $entry = $this->entriesByKey($result)['svg'];

        self::assertTrue($entry['configured']);
        self::assertFalse($entry['exists']);
        self::assertSame('error', $entry['status']);
        self::assertStringContainsString('external URLs are not allowed', $entry['note']);
    }

    public function testBareSchemeUrlYamlConfiguredPathIsRejectedJavascriptUri(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, [
            'svg' => 'javascript:alert(1)',
        ]);
        $entry = $this->entriesByKey($result)['svg'];

        self::assertTrue($entry['configured']);
        self::assertFalse($entry['exists']);
        self::assertSame('error', $entry['status']);
        self::assertStringContainsString('external URLs are not allowed', $entry['note']);
    }

    public function testBareSchemeUrlManifestSrcIsRejectedDataUriAndNotDirJoined(): void
    {
        $dir = sys_get_temp_dir() . '/favicon-audit-test-' . uniqid();
        mkdir($dir . self::TOUCH, 0777, true);
        file_put_contents(
            $dir . self::TOUCH . '/data-uri.webmanifest',
            json_encode([
                'icons' => [
                    ['src' => 'data:image/png;base64,x', 'sizes' => '192x192', 'purpose' => 'maskable'],
                ],
            ]),
        );

        $result = FaviconAudit::run($dir, [
            'manifest' => self::TOUCH . '/data-uri.webmanifest',
        ]);

        self::assertNotNull($result['manifest']);
        self::assertFalse($result['manifest']['icons'][0]['exists']);
        self::assertSame('data:image/png;base64,x', $result['manifest']['icons'][0]['src']);
        $joined = implode(' | ', $result['manifest']['notes']);
        self::assertStringContainsString('external URLs are not allowed', $joined);
        self::assertNull($result['maskable_icon']);

        $this->rrmdir($dir);
    }

    public function testRootRelativePathIsStillAcceptedAsNonExternal(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, [
            'svg' => self::TOUCH . '/favicon.svg',
        ]);
        $entry = $this->entriesByKey($result)['svg'];

        self::assertSame('ok', $entry['status']);
        self::assertTrue($entry['exists']);
    }

    #[DataProvider('nonExternalPathProvider')]
    public function testNonExternalPathsAreNotFlaggedByIsExternalUrl(string $path): void
    {
        // PathGuard::isExternalUrl() only gates the scheme-prefixed shape;
        // whether a relative/root-relative path additionally resolves or
        // escapes the static root is a separate concern covered elsewhere
        // (resolvePath()/pathEscapesRoot()). This test stays scoped to
        // that one method instead of coupling to concatenation details of
        // the full resolveEntry() pipeline. Method lives on PathGuard
        // (shared with OgImageAudit, #74) since #73's original promotion.
        self::assertFalse(PathGuard::isExternalUrl($path));
    }

    /** @return array<string, array{0: string}> */
    public static function nonExternalPathProvider(): array
    {
        return [
            'current-dir relative' => ['./icon.png'],
            'parent relative' => ['../icons/x.png'],
            'root relative' => ['/images/x.png'],
        ];
    }

    public function testIcoOffsetPlusSizeExceedingFileLengthFailsGracefully(): void
    {
        $dir = sys_get_temp_dir() . '/favicon-audit-test-' . uniqid();
        mkdir($dir . self::TOUCH, 0777, true);
        // Valid 6-byte header + one 16-byte directory entry (22 bytes
        // total), but dwBytesInRes (bytes 8-11) + dwImageOffset (bytes
        // 12-15) claims image data far beyond the actual 22-byte file.
        $entry = pack('C4', 32, 32, 0, 0) . pack('v2', 1, 32) . pack('V2', 1_000_000, 22);
        $ico = pack('v3', 0, 1, 1) . $entry;
        file_put_contents($dir . self::TOUCH . '/oob.ico', $ico);

        $result = FaviconAudit::run($dir, [
            'ico' => self::TOUCH . '/oob.ico',
        ]);
        $entry = $this->entriesByKey($result)['ico'];

        self::assertSame('error', $entry['status']);
        self::assertNotSame('', $entry['note']);

        $this->rrmdir($dir);
    }

    public function testIcoOffsetPlusSizeExactlyMatchingFileLengthIsOk(): void
    {
        $dir = sys_get_temp_dir() . '/favicon-audit-test-' . uniqid();
        mkdir($dir . self::TOUCH, 0777, true);
        // dwBytesInRes=0 + dwImageOffset=22 exactly equals the 22-byte
        // file length — boundary case, must still be treated as valid.
        $entry = pack('C4', 32, 32, 0, 0) . pack('v2', 1, 32) . pack('V2', 0, 22);
        $ico = pack('v3', 0, 1, 1) . $entry;
        file_put_contents($dir . self::TOUCH . '/exact.ico', $ico);

        $result = FaviconAudit::run($dir, [
            'ico' => self::TOUCH . '/exact.ico',
        ]);
        $entry = $this->entriesByKey($result)['ico'];

        self::assertSame('ok', $entry['status']);
        self::assertStringContainsString('32×32', $entry['note']);

        $this->rrmdir($dir);
    }

    public function testResolvePathRejectsDirectoryAsNotExisting(): void
    {
        $result = FaviconAudit::run(self::STATIC_PATH, [
            'svg' => self::TOUCH,
        ]);
        $entry = $this->entriesByKey($result)['svg'];

        self::assertTrue($entry['configured']);
        self::assertFalse($entry['exists']);
        self::assertSame('missing', $entry['status']);
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
