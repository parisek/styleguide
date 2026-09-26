<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Api;

use Parisek\Styleguide\Http\Request;
use Parisek\Styleguide\Http\Result;
use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `/api/source/<kind>/<slug>[?variant=<id>]` — the fixture source behind one
 * variant tile, and the `show_source` rule that decides whether it exists.
 */
final class SourceEndpointTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    /**
     * @param array<string,mixed> $yaml null = no styleguide.yaml at all
     * @param array<string,mixed> $config
     */
    private function styleguide(?array $yaml, array $config = []): Styleguide
    {
        $yamlPath = __DIR__ . '/../fixtures/does-not-exist.yaml';
        if ($yaml !== null) {
            $yamlPath = (string) tempnam(sys_get_temp_dir(), 'sg-source-');
            file_put_contents($yamlPath, \Symfony\Component\Yaml\Yaml::dump($yaml));
            $this->tempFiles[] = $yamlPath;
        }

        return new Styleguide($config + [
            'templates_path' => __DIR__ . '/../fixtures/templates',
            'static_path' => __DIR__ . '/../fixtures',
            'config_yaml' => $yamlPath,
        ]);
    }

    private function get(Styleguide $styleguide, string $uri): Result
    {
        $result = $styleguide->handle(new Request($uri));
        self::assertNotNull($result);

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private function spaConfig(Styleguide $styleguide): array
    {
        $html = (string) $this->get($styleguide, '/styleguide/')->body;
        self::assertSame(1, preg_match('#<script id="sg-config" type="application/json">(.*?)</script>#s', $html, $m));

        return (array) json_decode($m[1], true, flags: \JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function a_public_catalogue_does_not_serve_source_by_default(): void
    {
        $styleguide = $this->styleguide(null);

        $result = $this->get($styleguide, '/styleguide/api/source/component/multi');
        self::assertSame(404, $result->status);
        // The same answer as an endpoint that does not exist.
        self::assertStringContainsString('Unknown API endpoint: source', (string) $result->body);
        self::assertArrayNotHasKey('showSource', $this->spaConfig($styleguide));
    }

    #[Test]
    public function a_catalogue_behind_the_auth_callable_serves_source_by_default(): void
    {
        $styleguide = $this->styleguide(null, ['auth' => static fn(array $route): bool => true]);

        $result = $this->get($styleguide, '/styleguide/api/source/component/multi');
        self::assertSame(200, $result->status);
        self::assertSame('application/json; charset=utf-8', $result->headers['Content-Type']);
        self::assertSame(
            [
                'kind' => 'component',
                'slug' => 'multi',
                'variant' => null,
                'file' => 'component/multi/styleguide.twig',
                'source' => "<div class=\"multi multi--demo\">Multi demo (default variant)</div>\n",
            ],
            json_decode((string) $result->body, true, flags: \JSON_THROW_ON_ERROR),
        );
        self::assertTrue($this->spaConfig($styleguide)['showSource']);
    }

    #[Test]
    public function show_source_false_wins_over_the_auth_default(): void
    {
        $styleguide = $this->styleguide(['show_source' => false], ['auth' => static fn(array $route): bool => true]);

        self::assertSame(404, $this->get($styleguide, '/styleguide/api/source/component/multi')->status);
        self::assertArrayNotHasKey('showSource', $this->spaConfig($styleguide));
    }

    #[Test]
    public function show_source_true_turns_it_on_without_auth(): void
    {
        $styleguide = $this->styleguide(['show_source' => true]);

        self::assertSame(200, $this->get($styleguide, '/styleguide/api/source/component/multi')->status);
        self::assertTrue($this->spaConfig($styleguide)['showSource']);
    }

    #[Test]
    public function any_value_other_than_a_boolean_turns_it_off(): void
    {
        $styleguide = $this->styleguide(['show_source' => 'yes'], ['auth' => static fn(array $route): bool => true]);

        self::assertSame(404, $this->get($styleguide, '/styleguide/api/source/component/multi')->status);
    }

    #[Test]
    public function a_variant_serves_its_own_sibling_without_the_annotation_comment(): void
    {
        $styleguide = $this->styleguide(['show_source' => true]);

        $result = $this->get($styleguide, '/styleguide/api/source/component/multi?variant=secondary');
        self::assertSame(200, $result->status);
        $payload = json_decode((string) $result->body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('secondary', $payload['variant']);
        self::assertSame('component/multi/styleguide.secondary.twig', $payload['file']);
        self::assertSame("<div class=\"multi multi--secondary\">Multi demo (secondary variant)</div>\n", $payload['source']);
    }

    #[Test]
    public function only_a_leading_comment_is_stripped(): void
    {
        $styleguide = $this->styleguide(['show_source' => true]);

        $payload = json_decode(
            (string) $this->get($styleguide, '/styleguide/api/source/component/multi?variant=dark-bg')->body,
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        self::assertSame("<div class=\"multi multi--dark-bg\">Multi demo (dark-bg variant, no YAML label)</div>\n", $payload['source']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notFound(): iterable
    {
        yield 'unknown variant' => ['/styleguide/api/source/component/multi?variant=retired'];
        yield 'entry without a fixture file' => ['/styleguide/api/source/component/sample'];
        yield 'unknown entry' => ['/styleguide/api/source/component/nope'];
        yield 'kind outside component/page/doc' => ['/styleguide/api/source/foundations/index'];
        yield 'slug with a dot' => ['/styleguide/api/source/component/multi.twig'];
        yield 'no slug' => ['/styleguide/api/source/component'];
    }

    #[Test]
    #[DataProvider('notFound')]
    public function anything_but_a_fixture_file_is_a_404(string $uri): void
    {
        $result = $this->get($this->styleguide(['show_source' => true]), $uri);

        self::assertSame(404, $result->status);
        self::assertSame('application/json; charset=utf-8', $result->headers['Content-Type']);
    }

    #[Test]
    public function the_auth_callable_still_gates_the_endpoint(): void
    {
        $styleguide = $this->styleguide(['show_source' => true], ['auth' => static fn(array $route): bool => false]);

        self::assertSame(403, $this->get($styleguide, '/styleguide/api/source/component/multi')->status);
    }
}
