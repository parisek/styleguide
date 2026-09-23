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
        self::assertSame(404, (new AssetServer($this->distRoot))->serve('../composer.json')->status);
    }

    #[Test]
    public function rejects_sibling_directory_sharing_the_root_prefix(): void
    {
        // A bare `str_starts_with($file, $distRoot)` passes here: the resolved
        // path `…/fixtures/asset-server-sibling/secret.txt` really does start
        // with `…/fixtures/asset-server`. The traversal test above never
        // caught it, because `../composer.json` fails that prefix check
        // honestly — only a sibling whose NAME extends the root's slips past.
        $result = (new AssetServer($this->distRoot))->serve('../asset-server-sibling/secret.txt');

        // The file first: this is the assertion that names the actual harm.
        // Returning a Result makes it cheaper to check than the old output
        // buffer did — there is nothing to leak if nothing is handed back.
        self::assertNull($result->file, 'the sibling file was handed back to be served');
        self::assertSame(404, $result->status);
    }

    #[Test]
    public function serves_existing_file_with_etag(): void
    {
        $result = (new AssetServer($this->distRoot))->serve('test-asset.css');

        self::assertSame(200, $result->status);
        self::assertNotNull($result->file);
        self::assertStringContainsString('test asset fixture', (string) file_get_contents($result->file));
        self::assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $result->headers['ETag']);
        self::assertSame('text/css; charset=utf-8', $result->headers['Content-Type']);
        self::assertSame('public, max-age=3600', $result->headers['Cache-Control']);
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
        self::assertSame(404, (new AssetServer($this->distRoot))->serve('does-not-exist.css')->status);
    }

    #[Test]
    public function throws_on_invalid_dist_root(): void
    {
        $this->expectException(\RuntimeException::class);
        new AssetServer('/nonexistent/path');
    }
}
