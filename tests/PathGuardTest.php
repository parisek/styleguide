<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\PathGuard;
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
}
