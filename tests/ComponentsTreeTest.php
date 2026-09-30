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
 * `components.tree` in styleguide.yaml: whether the sidebar groups the
 * components of a section or lists them flat. Reaches the SPA as
 * `componentsTree` in #sg-config, only when it is `false`.
 */
final class ComponentsTreeTest extends TestCase
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
        $path = (string) tempnam(sys_get_temp_dir(), 'sg-tree-');
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
    public function tree_false_reaches_the_spa(): void
    {
        self::assertFalse($this->spaConfig($this->styleguide(['components' => ['tree' => false]]))['componentsTree']);
    }

    #[Test]
    public function the_payload_is_unchanged_without_the_key_or_with_true(): void
    {
        self::assertArrayNotHasKey('componentsTree', $this->spaConfig($this->styleguide(['project' => ['name' => 'X']])));
        self::assertArrayNotHasKey('componentsTree', $this->spaConfig($this->styleguide(['components' => ['tree' => true]])));
        self::assertArrayNotHasKey('componentsTree', $this->spaConfig($this->styleguide(['components' => ['group_by' => 'kind']])));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalid(): iterable
    {
        yield 'a string' => ['flat'];
        yield 'a number' => [0];
        yield 'null-like list' => [[]];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function a_value_that_is_not_a_boolean_fails_at_construction(mixed $tree): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('components.tree');

        $this->styleguide(['components' => ['tree' => $tree]]);
    }
}
