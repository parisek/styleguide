<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Http;

use Parisek\Styleguide\Http\Request;
use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every URL the catalogue recognises or produces reads one mount value.
 *
 * The mount cannot be configured yet, so the test sets the private property
 * directly. That is the point: it proves the value is threaded through
 * routing, the SPA config and the render output, so unlocking
 * `bootstrap.base_url` later only changes where the value comes from.
 */
final class MountPathThreadingTest extends TestCase
{
    #[Test]
    public function routing_follows_the_mount(): void
    {
        $styleguide = $this->styleguideAt('/tools/ui');

        self::assertNull($styleguide->handle(new Request('/styleguide/api/components')));

        $result = $styleguide->handle(new Request('/tools/ui/api/components'));
        self::assertNotNull($result);
        self::assertSame(200, $result->status);
    }

    #[Test]
    public function the_spa_config_carries_the_mount(): void
    {
        $result = $this->styleguideAt('/tools/ui')->handle(new Request('/tools/ui/'));

        self::assertNotNull($result);
        self::assertStringContainsString('"baseUrl":"/tools/ui"', (string) $result->body);
    }

    #[Test]
    public function the_default_is_unchanged(): void
    {
        $result = $this->styleguideAt(null)->handle(new Request('/styleguide/'));

        self::assertNotNull($result);
        self::assertStringContainsString('"baseUrl":"/styleguide"', (string) $result->body);
    }

    #[Test]
    public function a_render_links_back_under_the_mount(): void
    {
        $result = $this->styleguideAt('/tools/ui')->handle(new Request('/tools/ui/render/foundations/foundations'));

        self::assertNotNull($result);
        self::assertStringContainsString('href="/tools/ui/foundations"', (string) $result->body);
        // The package's foundations bundle is served under the mount too.
        self::assertStringContainsString('"/tools/ui/assets/foundations.', (string) $result->body);
        self::assertStringNotContainsString('"/styleguide/assets/', (string) $result->body);
    }

    private function styleguideAt(?string $mount): Styleguide
    {
        $styleguide = new Styleguide([
            'templates_path' => __DIR__ . '/../fixtures/templates',
            'static_path' => __DIR__ . '/../fixtures',
            'config_yaml' => __DIR__ . '/../fixtures/styleguide.yaml',
        ]);

        if ($mount !== null) {
            (new \ReflectionProperty(Styleguide::class, 'mountPath'))->setValue($styleguide, $mount);
        }

        return $styleguide;
    }
}
