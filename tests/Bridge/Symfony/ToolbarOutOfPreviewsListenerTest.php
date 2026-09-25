<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Bridge\Symfony;

use Parisek\Styleguide\Bridge\Symfony\EventListener\ToolbarOutOfPreviewsListener;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The listener follows the mount path it is given, so it needs no change
 * when the mount becomes configurable.
 */
final class ToolbarOutOfPreviewsListenerTest extends TestCase
{
    #[Test]
    public function it_strips_the_token_under_the_given_mount(): void
    {
        $response = $this->respond(new ToolbarOutOfPreviewsListener('/catalogue'), '/catalogue/render/component/card');

        self::assertFalse($response->headers->has('X-Debug-Token'));
        self::assertFalse($response->headers->has('X-Debug-Token-Link'));
    }

    #[Test]
    public function it_leaves_the_shell_and_other_mounts_alone(): void
    {
        $listener = new ToolbarOutOfPreviewsListener('/catalogue');

        self::assertTrue($this->respond($listener, '/catalogue/')->headers->has('X-Debug-Token'));
        self::assertTrue($this->respond($listener, '/styleguide/render/component/card')->headers->has('X-Debug-Token'));
    }

    #[Test]
    public function a_trailing_slash_on_the_mount_changes_nothing(): void
    {
        $response = $this->respond(new ToolbarOutOfPreviewsListener('/styleguide/'), '/styleguide/render/component/card');

        self::assertFalse($response->headers->has('X-Debug-Token'));
    }

    private function respond(ToolbarOutOfPreviewsListener $listener, string $path): Response
    {
        $response = new Response('<html><body></body></html>');
        $response->headers->set('X-Debug-Token', 'abc');
        $response->headers->set('X-Debug-Token-Link', '/_profiler/abc');

        $listener(new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        ));

        return $response;
    }
}
