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
}
