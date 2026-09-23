<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StyleguideTest extends TestCase
{
    private string $templatesPath;
    private string $missingYaml;

    protected function setUp(): void
    {
        $this->templatesPath = __DIR__ . '/fixtures/templates';
        $this->missingYaml = __DIR__ . '/fixtures/nonexistent.yaml';
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function newStyleguide(array $overrides = []): Styleguide
    {
        return new Styleguide($overrides + [
            'templates_path' => $this->templatesPath,
            'static_path' => __DIR__ . '/fixtures',
            'config_yaml' => $this->missingYaml,
        ]);
    }

    #[Test]
    public function resolve_foundations_css_url_prefers_newest_when_multiple_match(): void
    {
        $sg = $this->newStyleguide();

        $dir = sys_get_temp_dir() . '/styleguide-foundations-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/foundations.OLDHASH1.css', 'old');
        touch($dir . '/foundations.OLDHASH1.css', time() - 100);
        file_put_contents($dir . '/foundations.NEWHASH2.css', 'new');
        touch($dir . '/foundations.NEWHASH2.css', time());

        (new \ReflectionProperty(Styleguide::class, 'distRoot'))->setValue($sg, $dir);

        $method = new \ReflectionMethod(Styleguide::class, 'resolveFoundationsCssUrl');
        $url = $method->invoke($sg);

        self::assertSame('/styleguide/assets/foundations.NEWHASH2.css', $url);

        unlink($dir . '/foundations.OLDHASH1.css');
        unlink($dir . '/foundations.NEWHASH2.css');
        rmdir($dir);
    }

    #[Test]
    public function resolve_foundations_css_url_returns_null_when_no_match(): void
    {
        $sg = $this->newStyleguide();
        $dir = sys_get_temp_dir() . '/styleguide-foundations-empty-' . uniqid();
        mkdir($dir);

        (new \ReflectionProperty(Styleguide::class, 'distRoot'))->setValue($sg, $dir);
        $method = new \ReflectionMethod(Styleguide::class, 'resolveFoundationsCssUrl');

        self::assertNull($method->invoke($sg));

        rmdir($dir);
    }

    #[Test]
    public function resolve_foundations_js_url_prefers_newest_when_multiple_match(): void
    {
        $sg = $this->newStyleguide();

        $dir = sys_get_temp_dir() . '/styleguide-foundations-js-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/foundations.OLDHASH1.js', 'old');
        touch($dir . '/foundations.OLDHASH1.js', time() - 100);
        file_put_contents($dir . '/foundations.NEWHASH2.js', 'new');
        touch($dir . '/foundations.NEWHASH2.js', time());

        (new \ReflectionProperty(Styleguide::class, 'distRoot'))->setValue($sg, $dir);

        $method = new \ReflectionMethod(Styleguide::class, 'resolveFoundationsJsUrl');
        $url = $method->invoke($sg);

        self::assertSame('/styleguide/assets/foundations.NEWHASH2.js', $url);

        unlink($dir . '/foundations.OLDHASH1.js');
        unlink($dir . '/foundations.NEWHASH2.js');
        rmdir($dir);
    }

    #[Test]
    public function resolve_foundations_js_url_returns_null_when_no_match(): void
    {
        $sg = $this->newStyleguide();
        $dir = sys_get_temp_dir() . '/styleguide-foundations-js-empty-' . uniqid();
        mkdir($dir);

        (new \ReflectionProperty(Styleguide::class, 'distRoot'))->setValue($sg, $dir);
        $method = new \ReflectionMethod(Styleguide::class, 'resolveFoundationsJsUrl');

        self::assertNull($method->invoke($sg));

        rmdir($dir);
    }

    /**
     * @param array<string, mixed> $route
     */
    private function dispatchRoute(Styleguide $sg, array $route): \Parisek\Styleguide\Http\Result
    {
        // Still reflection — dispatch() takes a parsed route, which is the
        // level these tests are about. What changed is that it RETURNS the
        // response, so there is no output buffer and no process-global status
        // to read and put back.
        $dispatch = new \ReflectionMethod(Styleguide::class, 'dispatch');

        return $dispatch->invoke($sg, $route, new \Parisek\Styleguide\Http\Request('/styleguide'));
    }

    #[Test]
    public function auth_callable_returning_false_yields_403_before_any_dispatch(): void
    {
        $sg = $this->newStyleguide([
            'auth' => static fn(array $route): bool => false,
        ]);

        $result = $this->dispatchRoute($sg, ['type' => 'api', 'endpoint' => 'components']);

        self::assertSame(403, $result->status);
        self::assertSame('403 Forbidden', $result->body);
    }

    #[Test]
    public function auth_callable_returning_true_lets_dispatch_proceed(): void
    {
        $sg = $this->newStyleguide([
            'auth' => static fn(array $route): bool => true,
        ]);

        $result = $this->dispatchRoute($sg, ['type' => 'api', 'endpoint' => 'components']);

        self::assertNotSame(403, $result->status);
        self::assertIsArray(json_decode((string) $result->body, true));
    }

    #[Test]
    public function missing_auth_config_allows_every_route(): void
    {
        $sg = $this->newStyleguide(); // no 'auth' key at all

        $result = $this->dispatchRoute($sg, ['type' => 'api', 'endpoint' => 'components']);

        self::assertNotSame(403, $result->status);
        self::assertIsArray(json_decode((string) $result->body, true));
    }

    #[Test]
    public function explicit_null_auth_config_allows_every_route(): void
    {
        $sg = $this->newStyleguide(['auth' => null]);

        $result = $this->dispatchRoute($sg, ['type' => 'api', 'endpoint' => 'components']);

        self::assertNotSame(403, $result->status);
        self::assertIsArray(json_decode((string) $result->body, true));
    }

    #[Test]
    public function non_callable_non_null_auth_config_is_rejected_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("config key 'auth' must be null or callable");

        $this->newStyleguide(['auth' => 'not-a-callable']);
    }

    #[Test]
    public function throwing_auth_callable_yields_403_without_leaking_the_exception(): void
    {
        $sg = $this->newStyleguide([
            'auth' => static function (array $route): bool {
                throw new \RuntimeException('boom — should never reach the response body');
            },
        ]);

        // No exception should escape this call — isAuthorized() must catch it
        // and fail closed.
        $result = $this->dispatchRoute($sg, ['type' => 'api', 'endpoint' => 'components']);

        self::assertSame(403, $result->status);
        self::assertSame('403 Forbidden', $result->body);
        self::assertStringNotContainsString('boom', (string) $result->body);
    }

    #[Test]
    public function auth_callable_returning_false_denies_a_non_api_asset_route(): void
    {
        // Demonstrates the gate runs before ANY dispatch branch, not just
        // the API one — an asset route never reaches AssetServer::serve().
        $sg = $this->newStyleguide([
            'auth' => static fn(array $route): bool => false,
        ]);

        $result = $this->dispatchRoute($sg, ['type' => 'asset', 'path' => 'styleguide.js']);

        self::assertSame(403, $result->status);
        self::assertSame('403 Forbidden', $result->body);
    }

    #[Test]
    public function component_directories_lists_a_directory_with_no_template_that_inventory_never_sees(): void
    {
        // The gap this method exists to close: `yaml-only/` carries a
        // definition (`<id>.yaml`) but no `<id>.twig`, so it never becomes an
        // `inventory()`/`parseAll()` entry at all — invisible to both. A
        // consumer auditing "does every component directory have a real
        // template" needs exactly this directory-level fact, which
        // `inventory()` structurally cannot supply.
        $sg = $this->newStyleguide(['templates_path' => __DIR__ . '/fixtures/directory-listing-templates']);

        self::assertSame(
            [
                ['id' => 'js-only', 'hasTemplate' => false],
                ['id' => 'with-template', 'hasTemplate' => true],
                ['id' => 'yaml-only', 'hasTemplate' => false],
            ],
            $sg->componentDirectories(),
        );
    }
}
