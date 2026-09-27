<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\Http\Request;
use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * `overview.default` in styleguide.yaml: what the bare mount shows, handed to
 * the SPA as `landing` in #sg-config.
 */
final class OverviewDefaultTest extends TestCase
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
     * @param array<string,mixed> $yaml
     */
    private function styleguide(array $yaml): Styleguide
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'sg-overview-');
        file_put_contents($path, Yaml::dump($yaml, 4));
        $this->tempFiles[] = $path;

        return new Styleguide([
            'templates_path' => __DIR__ . '/fixtures/templates',
            'static_path' => __DIR__ . '/fixtures',
            'config_yaml' => $path,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function spaConfig(Styleguide $styleguide): array
    {
        $result = $styleguide->handle(new Request('/styleguide/'));
        self::assertNotNull($result);
        self::assertSame(1, preg_match('#<script id="sg-config" type="application/json">(.*?)</script>#s', (string) $result->body, $m));

        return (array) json_decode($m[1], true, flags: \JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function default_grid_makes_the_grid_the_landing(): void
    {
        $config = $this->spaConfig($this->styleguide(['overview' => ['default' => 'grid']]));

        self::assertSame('grid', $config['landing']);
    }

    #[Test]
    public function foundations_or_no_key_keeps_the_payload_as_before(): void
    {
        self::assertArrayNotHasKey('landing', $this->spaConfig($this->styleguide(['project' => ['name' => 'X']])));
        self::assertArrayNotHasKey('landing', $this->spaConfig($this->styleguide(['overview' => ['default' => 'foundations']])));
        self::assertArrayNotHasKey('landing', $this->spaConfig($this->styleguide(['overview' => ['default' => null]])));
        self::assertArrayNotHasKey('landing', $this->spaConfig($this->styleguide(['overview' => ['other' => 1]])));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalid(): iterable
    {
        yield 'the old index' => ['overview'];
        yield 'wrong case' => ['Grid'];
        yield 'a list' => [['grid']];
        yield 'a boolean' => [true];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function an_unknown_value_fails_at_construction(mixed $default): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('overview.default');

        $this->styleguide(['overview' => ['default' => $default]]);
    }

    #[Test]
    public function an_overview_key_that_is_not_a_map_is_left_to_the_project(): void
    {
        self::assertArrayNotHasKey('landing', $this->spaConfig($this->styleguide(['overview' => 'grid'])));
        self::assertArrayNotHasKey('landing', $this->spaConfig($this->styleguide(['overview' => ['grid']])));
    }

    #[Test]
    public function the_grid_route_serves_the_spa_shell(): void
    {
        $result = $this->styleguide([])->handle(new Request('/styleguide/grid'));

        self::assertNotNull($result);
        self::assertSame(200, $result->status);
        self::assertStringContainsString('id="sg-config"', (string) $result->body);
    }
}
