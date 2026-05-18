<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\AssetServer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AssetServerTest extends TestCase
{
    private string $distRoot;

    protected function setUp(): void
    {
        $fixtureRoot = __DIR__ . '/fixtures/asset-server';
        $this->distRoot = realpath($fixtureRoot);
        self::assertNotFalse($this->distRoot, 'fixture directory must exist: ' . $fixtureRoot);
    }

    #[Test]
    public function detects_hashed_filename(): void
    {
        $server = new AssetServer($this->distRoot);
        self::assertTrue($server->isHashedFilename('styleguide.abc12345.js'));
        self::assertTrue($server->isHashedFilename('styleguide.deadbeefcafe.css'));
        // Vite default alphabet — mixed case base64url, not pure hex.
        self::assertTrue($server->isHashedFilename('styleguide.CWEjyLdQ.css'));
        self::assertTrue($server->isHashedFilename('styleguide.DeQCkO9Y.js'));
        self::assertFalse($server->isHashedFilename('locales/cs.json'));
        self::assertFalse($server->isHashedFilename('icons/folder.svg'));
        self::assertFalse($server->isHashedFilename('styleguide.js'));
    }

    #[Test]
    public function rejects_path_traversal(): void
    {
        $server = new AssetServer($this->distRoot);
        ob_start();
        $server->serve('../composer.json');
        ob_end_clean();
        self::assertSame(404, http_response_code());
        http_response_code(200);
    }

    #[Test]
    public function serves_existing_file_with_etag(): void
    {
        $server = new AssetServer($this->distRoot);
        ob_start();
        $server->serve('test-asset.css');
        $output = ob_get_clean();
        self::assertStringContainsString('test asset fixture', $output);
    }

    #[Test]
    public function returns_404_for_missing_file(): void
    {
        $server = new AssetServer($this->distRoot);
        ob_start();
        $server->serve('does-not-exist.css');
        ob_end_clean();
        self::assertSame(404, http_response_code());
        http_response_code(200);
    }

    #[Test]
    public function throws_on_invalid_dist_root(): void
    {
        $this->expectException(\RuntimeException::class);
        new AssetServer('/nonexistent/path');
    }
}
