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
 * `viewports.compare` in styleguide.yaml: the widths the SPA's compare mode
 * shows side by side, handed to the SPA as `compareWidths` in #sg-config.
 */
final class CompareWidthsTest extends TestCase
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
        $path = (string) tempnam(sys_get_temp_dir(), 'sg-compare-');
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
    public function the_widths_reach_the_spa_in_their_order(): void
    {
        $config = $this->spaConfig($this->styleguide(['viewports' => ['compare' => [1440, 768, 320]]]));

        self::assertSame([1440, 768, 320], $config['compareWidths']);
    }

    #[Test]
    public function without_the_key_the_payload_has_no_compare_widths(): void
    {
        self::assertArrayNotHasKey('compareWidths', $this->spaConfig($this->styleguide(['project' => ['name' => 'X']])));
        self::assertArrayNotHasKey('compareWidths', $this->spaConfig($this->styleguide(['viewports' => ['other' => 1]])));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalid(): iterable
    {
        yield 'one width' => [[1440]];
        yield 'five widths' => [[1440, 1280, 768, 375, 320]];
        yield 'a string' => ['1440, 768'];
        yield 'a non-integer' => [[1440, '768px']];
        yield 'below the custom-width minimum' => [[1440, 99]];
        yield 'above the custom-width maximum' => [[4001, 768]];
        yield 'a map' => [['desktop' => 1440, 'mobile' => 320]];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function a_malformed_list_fails_at_construction(mixed $compare): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('viewports.compare');

        $this->styleguide(['viewports' => ['compare' => $compare]]);
    }

    #[Test]
    public function a_viewports_key_that_is_not_a_map_is_left_to_the_project(): void
    {
        // Unowned top-level keys pass through to the templates, so a project
        // may already use `viewports` for something else: no throw, no button.
        self::assertArrayNotHasKey('compareWidths', $this->spaConfig($this->styleguide(['viewports' => [1440, 768]])));
        self::assertArrayNotHasKey('compareWidths', $this->spaConfig($this->styleguide(['viewports' => 'wide'])));
    }
}
