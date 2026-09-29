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
 * `highlight_source` in styleguide.yaml: whether the Code panel highlights
 * the fixture source, handed to the SPA as `highlightSource: false` in
 * #sg-config only when turned off.
 */
final class HighlightSourceTest extends TestCase
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
        $path = (string) tempnam(sys_get_temp_dir(), 'sg-highlight-');
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
    public function highlighting_is_on_by_default_and_the_payload_stays_as_it_was(): void
    {
        $config = $this->spaConfig($this->styleguide(['show_source' => true]));

        self::assertTrue($config['showSource']);
        self::assertArrayNotHasKey('highlightSource', $config);
        self::assertArrayNotHasKey('highlightSource', $this->spaConfig($this->styleguide(['show_source' => true, 'highlight_source' => true])));
    }

    #[Test]
    public function false_turns_it_off(): void
    {
        $config = $this->spaConfig($this->styleguide(['show_source' => true, 'highlight_source' => false]));

        self::assertFalse($config['highlightSource']);
    }

    #[Test]
    public function without_the_source_there_is_nothing_to_highlight(): void
    {
        self::assertArrayNotHasKey('highlightSource', $this->spaConfig($this->styleguide(['show_source' => false, 'highlight_source' => false])));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalid(): iterable
    {
        yield 'a string' => ['false'];
        yield 'a number' => [0];
        yield 'a list' => [[false]];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function anything_but_a_boolean_fails_at_construction(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('highlight_source');

        $this->styleguide(['highlight_source' => $value]);
    }
}
