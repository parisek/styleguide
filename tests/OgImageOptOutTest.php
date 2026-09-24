<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Covers the `og_image: false` opt-out for the Foundations `#og-image`
 * section. Drives `Styleguide::dispatch()` end to end (same seam as
 * StyleguideLocaleTest) rather than Renderer::render() directly, because the
 * behaviour under test — whether `OgImageAudit::run()` runs at all and
 * whether `og_image_audit` reaches the template — lives in
 * `Styleguide::dispatchRender()`, upstream of the Renderer/template layer
 * already covered by RendererTest's og-image tests.
 */
final class OgImageOptOutTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/styleguide-og-opt-out-' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function newStyleguide(string $yaml, array $overrides = []): Styleguide
    {
        $path = $this->tempDir . '/styleguide.yaml';
        file_put_contents($path, $yaml);

        return new Styleguide($overrides + [
            'templates_path' => __DIR__ . '/fixtures/templates',
            'static_path' => __DIR__ . '/fixtures',
            'config_yaml' => $path,
        ]);
    }

    private function renderFoundations(Styleguide $sg): string
    {
        $dispatch = new \ReflectionMethod(Styleguide::class, 'dispatch');
        $result = $dispatch->invoke(
            $sg,
            ['type' => 'render', 'kind' => 'foundations', 'slug' => 'index', 'theme' => 'light'],
            new \Parisek\Styleguide\Http\Request('/styleguide'),
        );

        return (string) $result->body;
    }

    #[Test]
    public function og_image_false_hides_the_whole_section(): void
    {
        $sg = $this->newStyleguide("project:\n    name: Test\nog_image: false\n");
        $html = $this->renderFoundations($sg);

        self::assertStringNotContainsString('og-image', $html);
        self::assertStringNotContainsString('No og_image configured', $html);
    }

    #[Test]
    public function missing_og_image_key_keeps_todays_empty_state(): void
    {
        // Non-breaking: a project that has never set og_image: at all must
        // keep seeing the loud empty-state prompt, not have the section
        // silently disappear as a side effect of this change.
        $sg = $this->newStyleguide("project:\n    name: Test\n");
        $html = $this->renderFoundations($sg);

        self::assertStringContainsString('og-image', $html);
        self::assertStringContainsString('No og_image configured', $html);
    }
}
