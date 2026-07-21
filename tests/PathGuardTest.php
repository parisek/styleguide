<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\PathGuard;
use Parisek\Styleguide\Renderer;
use PHPUnit\Framework\TestCase;

/**
 * Covers {@see PathGuard::resolvePath()} / {@see PathGuard::pathEscapesRoot()}
 * directly — both are shared, security-sensitive plumbing behind
 * {@see \Parisek\Styleguide\FaviconAudit} (#73) and
 * {@see \Parisek\Styleguide\OgImageAudit} (#74), so a join bug here silently
 * breaks both callers. Fixture used: `tests/fixtures/images/og-image.png`
 * (a real file, already relied on by {@see OgImageAuditTest}).
 */
final class PathGuardTest extends TestCase
{
    private const STATIC_PATH = __DIR__ . '/fixtures';

    public function testResolvePathAcceptsLeadingSlashPath(): void
    {
        $real = PathGuard::resolvePath(self::STATIC_PATH, '/images/og-image.png');

        self::assertNotNull($real);
        self::assertStringEndsWith('/images/og-image.png', $real);
    }

    public function testResolvePathAcceptsPathWithoutLeadingSlash(): void
    {
        // The bug: naive `$staticPath . $path` concatenation turned this
        // into `.../fixturesimages/og-image.png` (no separator at all)
        // whenever the yaml value omitted its leading slash — a perfectly
        // natural way to write `og_image: images/og-image.png`. The join
        // must normalize to exactly one separator regardless of whether
        // either side already carries one.
        $real = PathGuard::resolvePath(self::STATIC_PATH, 'images/og-image.png');

        self::assertNotNull($real);
        self::assertStringEndsWith('/images/og-image.png', $real);
    }

    public function testResolvePathIsIdenticalRegardlessOfLeadingSlash(): void
    {
        $withSlash = PathGuard::resolvePath(self::STATIC_PATH, '/images/og-image.png');
        $withoutSlash = PathGuard::resolvePath(self::STATIC_PATH, 'images/og-image.png');

        self::assertNotNull($withSlash);
        self::assertSame($withSlash, $withoutSlash);
    }

    public function testResolvePathToleratesTrailingSlashOnStaticPath(): void
    {
        $real = PathGuard::resolvePath(self::STATIC_PATH . '/', 'images/og-image.png');

        self::assertNotNull($real);
        self::assertStringEndsWith('/images/og-image.png', $real);
    }

    public function testResolvePathReturnsNullForMissingRelativeFile(): void
    {
        self::assertNull(PathGuard::resolvePath(self::STATIC_PATH, 'images/does-not-exist.png'));
    }

    public function testPathEscapesRootIsFalseForContainedRelativePath(): void
    {
        self::assertFalse(PathGuard::pathEscapesRoot(self::STATIC_PATH, 'images/og-image.png'));
    }

    public function testPathEscapesRootIsTrueForTraversalWithoutLeadingSlash(): void
    {
        self::assertTrue(PathGuard::pathEscapesRoot(self::STATIC_PATH, '../composer.json'));
    }

    public function testPathEscapesRootIsTrueForTraversalWithLeadingSlash(): void
    {
        self::assertTrue(PathGuard::pathEscapesRoot(self::STATIC_PATH, '/../../composer.json'));
    }

    public function testStripAssetBaseRemovesTheBasePrefix(): void
    {
        $base = '/wp-content/themes/acme/static';

        self::assertSame('/images/x.png', PathGuard::stripAssetBase($base . '/images/x.png', $base));
        // A trailing slash on the base must not leave a doubled separator.
        self::assertSame('/images/x.png', PathGuard::stripAssetBase($base . '/images/x.png', $base . '/'));
        // The base itself degrades to the static root, never to an empty path.
        self::assertSame('/', PathGuard::stripAssetBase($base, $base));
    }

    public function testStripAssetBaseIsANoOpWhenItDoesNotApply(): void
    {
        $base = '/wp-content/themes/acme/static';

        // Standalone consumer — no base, nothing to strip.
        self::assertSame('/images/x.png', PathGuard::stripAssetBase('/images/x.png', ''));
        // Short (docroot-agnostic) and relative paths pass through.
        self::assertSame('/images/x.png', PathGuard::stripAssetBase('/images/x.png', $base));
        self::assertSame('images/x.png', PathGuard::stripAssetBase('images/x.png', $base));
        // External / scheme-carrying values are never rewritten.
        self::assertSame('https://cdn.example.com/x.png', PathGuard::stripAssetBase('https://cdn.example.com/x.png', $base));
        self::assertSame('//cdn.example.com/x.png', PathGuard::stripAssetBase('//cdn.example.com/x.png', $base));
        self::assertSame('data:image/png;base64,AA', PathGuard::stripAssetBase('data:image/png;base64,AA', $base));
        self::assertSame('', PathGuard::stripAssetBase('', $base));
    }

    public function testStripAssetBaseOnlyMatchesWholePathSegments(): void
    {
        // `/wp-content/themes/acme/static-legacy/...` shares a string prefix
        // with the base but is a different directory — must stay untouched.
        $base = '/wp-content/themes/acme/static';

        self::assertSame(
            '/wp-content/themes/acme/static-legacy/images/x.png',
            PathGuard::stripAssetBase('/wp-content/themes/acme/static-legacy/images/x.png', $base),
        );
    }

    public function testStripAssetBaseRoundTripsWithResolveAssetUrl(): void
    {
        // The two are inverses: Renderer::resolveAssetUrl() puts the base on
        // for the browser, stripAssetBase() takes it back off for the disk.
        $base = '/wp-content/themes/acme/static';
        $short = '/images/touch/favicon.svg';

        self::assertSame($short, PathGuard::stripAssetBase(Renderer::resolveAssetUrl($short, $base), $base));
    }
}
