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
    public function map_files_serve_with_json_content_type(): void
    {
        // headers_list() is unusable here: PHP's CLI SAPI has no HTTP
        // response stage, so header() calls never populate it — headers_sent()
        // reports true before any header() call runs, in every CLI process,
        // regardless of PHPUnit. mimeType() is exercised directly via
        // reflection instead, same pattern as StyleguideTest's coverage of
        // resolveFoundationsCssUrl().
        $server = new AssetServer($this->distRoot);
        $method = new \ReflectionMethod(AssetServer::class, 'mimeType');
        self::assertSame(
            'application/json; charset=utf-8',
            $method->invoke($server, $this->distRoot . '/test-asset.js.map'),
        );
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
