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
 * Most tests set the private property directly, which isolates the
 * threading from the configuration path; the `base_url` tests at the end go
 * through the constructor.
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

    #[Test]
    public function the_served_shell_loads_its_assets_under_the_mount(): void
    {
        // dist/index.html is relative (./styleguide.<hash>.js). A relative URL
        // in a shell served at /tools/ui (no trailing slash) would resolve
        // against /, so the served shell carries absolute asset URLs.
        foreach (['/tools/ui', '/tools/ui/'] as $uri) {
            $body = (string) $this->styleguideAt('/tools/ui')->handle(new Request($uri))?->body;

            self::assertMatchesRegularExpression('#<script type="module" crossorigin src="/tools/ui/assets/styleguide\.[^"]+\.js">#', $body, $uri);
            self::assertMatchesRegularExpression('#<link rel="stylesheet" crossorigin href="/tools/ui/assets/styleguide\.[^"]+\.css">#', $body, $uri);
            self::assertStringNotContainsString('"./', $body, $uri);
        }
    }

    #[Test]
    public function the_served_shell_at_the_default_mount_is_what_it_always_was(): void
    {
        $body = (string) $this->styleguideAt(null)->handle(new Request('/styleguide'))?->body;

        self::assertMatchesRegularExpression('#src="/styleguide/assets/styleguide\.[^"]+\.js"#', $body);
        self::assertMatchesRegularExpression('#href="/styleguide/assets/styleguide\.[^"]+\.css"#', $body);
        // The favicon slot's empty href is not an asset.
        self::assertStringContainsString('id="sg-favicon-tag" href=""', $body);
    }

    #[Test]
    public function only_dot_slash_references_are_rewritten(): void
    {
        $html = '<script src="./a.js"></script><link href="./b.css"><img src="../c.png">'
            . '<link href=".//d"><a href="/e">x</a><link href=""><script src="https://x/f.js"></script>';

        self::assertSame(
            '<script src="/m/assets/a.js"></script><link href="/m/assets/b.css"><img src="../c.png">'
                . '<link href=".//d"><a href="/e">x</a><link href=""><script src="https://x/f.js"></script>',
            Styleguide::absolutiseEntryAssets($html, '/m/assets/'),
        );
    }

    #[Test]
    public function base_url_sets_the_mount(): void
    {
        $styleguide = new Styleguide([
            'templates_path' => __DIR__ . '/../fixtures/templates',
            'static_path' => __DIR__ . '/../fixtures',
            'config_yaml' => __DIR__ . '/../fixtures/styleguide.yaml',
            'base_url' => '/tools/ui/',
        ]);

        self::assertNull($styleguide->handle(new Request('/styleguide/')));
        self::assertStringContainsString('"baseUrl":"/tools/ui"', (string) $styleguide->handle(new Request('/tools/ui'))?->body);
    }

    #[Test]
    public function an_invalid_base_url_fails_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("config key 'base_url'");

        new Styleguide([
            'templates_path' => __DIR__ . '/../fixtures/templates',
            'static_path' => __DIR__ . '/../fixtures',
            'config_yaml' => __DIR__ . '/../fixtures/styleguide.yaml',
            'base_url' => '/',
        ]);
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
