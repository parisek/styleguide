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
 * `builtin_pages` in styleguide.yaml: switch off the package's own pages
 * (Foundations, Icons, Fields, Overview, Grid), handed to the SPA as
 * `disabledPages` in #sg-config.
 */
final class BuiltinPagesTest extends TestCase
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
        $path = (string) tempnam(sys_get_temp_dir(), 'sg-builtin-');
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
    public function switched_off_pages_reach_the_spa(): void
    {
        $config = $this->spaConfig($this->styleguide(['builtin_pages' => ['fields' => false, 'overview' => false, 'icons' => true]]));

        self::assertSame(['fields', 'overview'], $config['disabledPages']);
    }

    #[Test]
    public function without_the_key_or_with_everything_on_the_payload_is_as_before(): void
    {
        self::assertArrayNotHasKey('disabledPages', $this->spaConfig($this->styleguide(['project' => ['name' => 'X']])));
        self::assertArrayNotHasKey('disabledPages', $this->spaConfig($this->styleguide(['builtin_pages' => ['grid' => true]])));
    }

    #[Test]
    public function switching_off_foundations_lands_on_the_grid(): void
    {
        $config = $this->spaConfig($this->styleguide(['builtin_pages' => ['foundations' => false]]));

        self::assertSame(['foundations'], $config['disabledPages']);
        self::assertSame('grid', $config['landing']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalid(): iterable
    {
        yield 'a list' => [['fields', 'overview']];
        yield 'an unknown page' => [['fundations' => false]];
        yield 'not a boolean' => [['fields' => 'no']];
        yield 'nothing left to land on' => [['foundations' => false, 'grid' => false]];
    }

    /**
     * @param mixed $builtinPages
     */
    #[Test]
    #[DataProvider('invalid')]
    public function a_malformed_value_fails_at_construction(mixed $builtinPages): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('builtin_pages');

        $this->styleguide(['builtin_pages' => $builtinPages]);
    }

    #[Test]
    public function the_grid_cannot_be_the_landing_and_switched_off(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('builtin_pages');

        $this->styleguide(['overview' => ['default' => 'grid'], 'builtin_pages' => ['grid' => false]]);
    }
}
