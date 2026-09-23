<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Api;

use Parisek\Styleguide\Api;
use Parisek\Styleguide\ComponentParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The headers every `/api/*` endpoint sends.
 *
 * These were untestable until the endpoints started returning a result. PHP's
 * CLI SAPI has no HTTP response stage, so `header()` never populates
 * `headers_list()` — `AssetServerTest` says as much where it reaches for
 * `mimeType()` by reflection instead. The whole header half of five endpoints
 * was therefore asserted nowhere.
 *
 * `Cache-Control: no-cache` is the one that matters. The catalogue changes
 * whenever a template does, so a cached `/api/components` is a stale sidebar —
 * the kind of bug that reads as "the styleguide didn't pick up my component".
 */
final class EndpointHeadersTest extends TestCase
{
    /**
     * @return iterable<string, array{object}>
     */
    public static function endpoints(): iterable
    {
        $parser = new ComponentParser(__DIR__ . '/../fixtures/templates');

        yield 'components' => [new Api\ComponentsEndpoint($parser)];
        yield 'pages' => [new Api\PagesEndpoint($parser)];
        yield 'docs' => [new Api\DocsEndpoint($parser)];
        yield 'fields' => [new Api\FieldsEndpoint($parser)];
        yield 'health' => [new Api\HealthEndpoint($parser)];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('endpoints')]
    public function every_endpoint_sends_json_and_forbids_caching(object $endpoint): void
    {
        $result = $endpoint->handle();

        self::assertSame(200, $result->status);
        self::assertSame('application/json; charset=utf-8', $result->headers['Content-Type']);
        self::assertSame('no-cache', $result->headers['Cache-Control']);
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('endpoints')]
    public function every_endpoint_returns_a_body_that_parses_as_json(object $endpoint): void
    {
        $result = $endpoint->handle();

        self::assertNotNull($result->body);
        self::assertNull($result->file, 'an API response is text, never a file');
        json_decode((string) $result->body, true, flags: \JSON_THROW_ON_ERROR);
    }
}
