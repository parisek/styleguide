<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\Http\Request;
use Parisek\Styleguide\Http\Result;
use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A render failure and the server's template paths (ADR-0006).
 *
 * A Twig loader error lists every directory it searched. Those are absolute
 * paths of the server, and anyone can request a render URL. With
 * `show_source` off the page says only that the entry cannot be shown; the
 * full message goes to the log. With `show_source` on, the page shows the
 * message as before.
 */
final class RenderErrorDisclosureTest extends TestCase
{
    private const FIXED_MESSAGE = 'This entry cannot be shown.';

    private string $log = '';
    private string|false $previousLog = false;

    protected function tearDown(): void
    {
        if ($this->previousLog !== false && $this->previousLog !== '') {
            ini_set('error_log', $this->previousLog);
        }
        @unlink($this->log);
    }

    /**
     * Points `error_log()` at a file of ours. PHPUnit sets its own `error_log`
     * after `setUp()`, so this runs inside the test, through `styleguide()`.
     */
    private function captureLog(): void
    {
        $this->log = (string) tempnam(sys_get_temp_dir(), 'sg-log-');
        $this->previousLog = ini_set('error_log', $this->log);
    }

    /**
     * @param array<string,mixed> $yaml
     */
    private function styleguide(array $yaml): Styleguide
    {
        $this->captureLog();
        $yamlPath = (string) tempnam(sys_get_temp_dir(), 'sg-render-error-');
        file_put_contents($yamlPath, \Symfony\Component\Yaml\Yaml::dump($yaml));
        register_shutdown_function(static fn() => @unlink($yamlPath));

        return new Styleguide([
            'templates_path' => __DIR__ . '/fixtures/render-error/templates',
            'static_path' => __DIR__ . '/fixtures',
            'config_yaml' => $yamlPath,
        ]);
    }

    private function get(Styleguide $styleguide, string $uri): Result
    {
        $result = $styleguide->handle(new Request($uri));
        self::assertNotNull($result);

        return $result;
    }

    private function logged(): string
    {
        return (string) file_get_contents($this->log);
    }

    #[Test]
    public function a_failed_render_shows_a_fixed_message_when_show_source_is_off(): void
    {
        $result = $this->get($this->styleguide(['show_source' => false]), '/styleguide/render/component/missing-include');
        $body = (string) $result->body;

        self::assertSame(500, $result->status);
        self::assertStringContainsString(self::FIXED_MESSAGE, $body);
        self::assertStringNotContainsString(__DIR__, $body);
        self::assertStringNotContainsString('does-not-exist', $body);
    }

    #[Test]
    public function the_default_without_auth_hides_the_message_too(): void
    {
        $result = $this->get($this->styleguide([]), '/styleguide/render/component/missing-include');

        self::assertSame(500, $result->status);
        self::assertStringContainsString(self::FIXED_MESSAGE, (string) $result->body);
        self::assertStringNotContainsString(__DIR__, (string) $result->body);
    }

    #[Test]
    public function a_non_boolean_show_source_fails_closed(): void
    {
        $result = $this->get($this->styleguide(['show_source' => 'yes']), '/styleguide/render/component/missing-include');

        self::assertStringContainsString(self::FIXED_MESSAGE, (string) $result->body);
        self::assertStringNotContainsString(__DIR__, (string) $result->body);
    }

    #[Test]
    public function the_full_message_and_the_entry_id_go_to_the_log(): void
    {
        $this->get($this->styleguide(['show_source' => false]), '/styleguide/render/component/missing-include');

        $log = $this->logged();
        self::assertStringContainsString('[parisek/styleguide] render of component/missing-include failed:', $log);
        self::assertStringContainsString('does-not-exist.twig', $log);
        self::assertStringContainsString(__DIR__, $log);
    }

    #[Test]
    public function show_source_true_shows_the_detail_as_before(): void
    {
        $result = $this->get($this->styleguide(['show_source' => true]), '/styleguide/render/component/missing-include');
        $body = (string) $result->body;

        self::assertSame(500, $result->status);
        self::assertStringContainsString('Render error:', $body);
        self::assertStringContainsString('does-not-exist.twig', $body);
        self::assertStringNotContainsString(self::FIXED_MESSAGE, $body);
    }

    #[Test]
    public function a_path_in_a_nested_include_does_not_leak(): void
    {
        $result = $this->get($this->styleguide(['show_source' => false]), '/styleguide/render/component/nested-outer');
        $body = (string) $result->body;

        self::assertSame(500, $result->status);
        self::assertStringContainsString(self::FIXED_MESSAGE, $body);
        self::assertStringNotContainsString('hidden-inner-secret', $body);
        self::assertStringNotContainsString(__DIR__, $body);
        self::assertStringContainsString('hidden-inner-secret', $this->logged());
    }

    #[Test]
    public function the_fixed_messages_of_the_other_error_pages_carry_no_path(): void
    {
        $styleguide = $this->styleguide(['show_source' => false]);

        foreach (['/styleguide/render/component/nope', '/styleguide/render/bogus/nope'] as $uri) {
            $result = $this->get($styleguide, $uri);
            self::assertSame(404, $result->status);
            self::assertStringNotContainsString(\dirname(__DIR__), (string) $result->body);
        }

        $result = $this->get($styleguide, '/styleguide/api/source/component/missing-include');
        self::assertSame(404, $result->status);
        self::assertStringNotContainsString(\dirname(__DIR__), (string) $result->body);
    }

    #[Test]
    public function the_health_endpoint_carries_no_absolute_path(): void
    {
        $result = $this->get($this->styleguide(['show_source' => false]), '/styleguide/api/health');

        self::assertStringNotContainsString(\dirname(__DIR__), (string) $result->body);
    }
}
