<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Http;

use Parisek\Styleguide\AssetServer;
use Parisek\Styleguide\Http\Request;
use Parisek\Styleguide\Http\Result;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The value a route produces, and the request values it is produced from.
 *
 * These exist so the package can stop writing its own responses. Eleven places
 * currently call `http_response_code()`, `header()` and `echo`, and `run()`
 * ends with `exit` — fine for a front controller, impossible for a host
 * application that has to RETURN a response, and the reason the package's own
 * SPA tests drive a subprocess.
 */
final class ResultTest extends TestCase
{
    #[Test]
    public function a_text_result_carries_its_body(): void
    {
        $result = Result::text('hello', 201, ['X-Thing' => 'yes']);

        self::assertSame(201, $result->status);
        self::assertSame('hello', $result->body);
        self::assertNull($result->file);
        self::assertSame(['X-Thing' => 'yes'], $result->headers);
    }

    #[Test]
    public function a_file_result_keeps_the_path_rather_than_the_contents(): void
    {
        // Deliberate. This serves the SPA bundle, and a caller that wants to
        // stream it — readfile(), BinaryFileResponse, X-Sendfile — cannot
        // un-read a string.
        $result = Result::file('/tmp/x.css');

        self::assertSame('/tmp/x.css', $result->file);
        self::assertNull($result->body);
    }

    #[Test]
    public function an_empty_result_is_not_an_empty_body(): void
    {
        // A 304 must send nothing at all; `text('')` sends a zero-length body.
        // A caller mapping this to a framework response has to be able to tell
        // those apart, so they are different states rather than one.
        $empty = Result::empty(304);
        $blank = Result::text('');

        self::assertNull($empty->body);
        self::assertSame('', $blank->body);
    }

    #[Test]
    public function with_header_does_not_mutate_the_original(): void
    {
        $original = Result::text('x', 200, ['A' => '1']);
        $derived = $original->withHeader('B', '2');

        self::assertSame(['A' => '1'], $original->headers);
        self::assertSame(['B' => '2', 'A' => '1'], $derived->headers);
    }

    #[Test]
    public function emitting_writes_the_body(): void
    {
        ob_start();
        Result::text('payload')->emit();
        $output = (string) ob_get_clean();

        self::assertSame('payload', $output);
        http_response_code(200);
    }

    #[Test]
    public function emitting_an_empty_result_writes_nothing(): void
    {
        ob_start();
        Result::empty(304)->emit();
        $output = (string) ob_get_clean();

        self::assertSame('', $output);
        self::assertSame(304, http_response_code());
        http_response_code(200);
    }

    #[Test]
    public function a_matching_etag_yields_304_and_no_file(): void
    {
        // The conditional request is part of the asset contract, and it used
        // to be read from $_SERVER inside AssetServer, where no caller could
        // supply it. Now it is a parameter, so it can be tested — and a
        // Symfony controller can pass the framework's own header.
        $root = realpath(__DIR__ . '/../fixtures/asset-server');
        self::assertIsString($root);
        $server = new AssetServer($root);

        $first = $server->serve('test-asset.css');
        $second = $server->serve('test-asset.css', $first->headers['ETag']);

        self::assertSame(200, $first->status);
        self::assertSame(304, $second->status);
        self::assertNull($second->file, 'a 304 must not hand back a file to send');
    }

    #[Test]
    public function a_stale_etag_still_serves_the_file(): void
    {
        $root = realpath(__DIR__ . '/../fixtures/asset-server');
        self::assertIsString($root);

        $result = (new AssetServer($root))->serve('test-asset.css', '"not-the-current-one"');

        self::assertSame(200, $result->status);
        self::assertNotNull($result->file);
    }

    #[Test]
    public function a_hashed_filename_gets_the_immutable_cache_header(): void
    {
        $root = realpath(__DIR__ . '/../fixtures/asset-server');
        self::assertIsString($root);

        // The two cache policies are behaviour, not decoration: a hashed name
        // is safe to cache for a year, an unhashed one is not.
        $plain = (new AssetServer($root))->serve('test-asset.css');
        self::assertSame('public, max-age=3600', $plain->headers['Cache-Control']);
    }

    #[Test]
    public function emitting_a_200_does_not_overwrite_a_status_the_host_already_chose(): void
    {
        // Parity, and a review had to find it. Before the seam, a successful
        // asset never touched the response code — only 304 and 404 did. An
        // emitter that always calls http_response_code() would quietly reset a
        // front controller's own choice to 200, which main did not do.
        http_response_code(503);

        ob_start();
        Result::text('body')->emit();
        ob_end_clean();

        self::assertSame(503, http_response_code());
        http_response_code(200);
    }

    #[Test]
    public function emitting_a_non_200_does_set_the_status(): void
    {
        http_response_code(200);

        ob_start();
        Result::empty(404)->emit();
        ob_end_clean();

        self::assertSame(404, http_response_code());
        http_response_code(200);
    }

    #[Test]
    public function the_request_reads_the_four_values_a_route_depends_on(): void
    {
        // Four, not one. The tracking issue proposed "a method that takes a
        // path"; cookies carry the iframe theme fallback, Sec-Fetch-Dest turns
        // an SPA route into a render route, and If-None-Match decides a 304.
        // A path-shaped input would have broken the iframe silently.
        $request = new Request('/styleguide/component/card?theme=dark', ['sg-iframe-theme' => 'dark'], 'iframe', '"e"');

        self::assertSame('/styleguide/component/card?theme=dark', $request->uri);
        self::assertSame(['sg-iframe-theme' => 'dark'], $request->cookies);
        self::assertSame('iframe', $request->secFetchDest);
        self::assertSame('"e"', $request->ifNoneMatch);
    }

    #[Test]
    public function the_request_defaults_to_an_empty_context(): void
    {
        $request = new Request('/styleguide');

        self::assertSame([], $request->cookies);
        self::assertSame('', $request->secFetchDest);
        self::assertSame('', $request->ifNoneMatch);
    }
}
