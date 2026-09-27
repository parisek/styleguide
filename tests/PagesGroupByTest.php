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
 * `pages.group_by` in styleguide.yaml: how the sidebar groups page entries,
 * handed to the SPA as `pagesGroupBy` in #sg-config.
 */
final class PagesGroupByTest extends TestCase
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
        $path = (string) tempnam(sys_get_temp_dir(), 'sg-pages-');
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
    public function group_by_category_reaches_the_spa(): void
    {
        $config = $this->spaConfig($this->styleguide(['pages' => ['group_by' => 'category']]));

        self::assertSame('category', $config['pagesGroupBy']);
    }

    #[Test]
    public function without_the_key_the_payload_has_no_pages_group_by(): void
    {
        self::assertArrayNotHasKey('pagesGroupBy', $this->spaConfig($this->styleguide(['project' => ['name' => 'X']])));
        self::assertArrayNotHasKey('pagesGroupBy', $this->spaConfig($this->styleguide(['pages' => ['other' => 1]])));
        self::assertArrayNotHasKey('pagesGroupBy', $this->spaConfig($this->styleguide(['pages' => ['group_by' => null]])));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalid(): iterable
    {
        yield 'another key' => ['weight'];
        yield 'wrong case' => ['Category'];
        yield 'a list' => [['category']];
        yield 'a boolean' => [true];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function an_unknown_value_fails_at_construction(mixed $groupBy): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('pages.group_by');

        $this->styleguide(['pages' => ['group_by' => $groupBy]]);
    }

    #[Test]
    public function a_pages_key_that_is_not_a_map_is_left_to_the_project(): void
    {
        self::assertArrayNotHasKey('pagesGroupBy', $this->spaConfig($this->styleguide(['pages' => ['home', 'about']])));
        self::assertArrayNotHasKey('pagesGroupBy', $this->spaConfig($this->styleguide(['pages' => 'flat'])));
    }
}
