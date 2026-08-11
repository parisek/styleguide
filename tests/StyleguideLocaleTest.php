<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end coverage for `?locale=` on the render route — the seam the
 * design doc's own acceptance test exercises against a real consumer
 * (`render/component/registration?locale=cs_CZ`). These tests drive the
 * same `dispatch()` path against the package's own fixtures, so they run
 * offline in CI without a consumer project.
 */
final class StyleguideLocaleTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private function newStyleguide(array $overrides = []): Styleguide
    {
        return new Styleguide($overrides + [
            'templates_path' => __DIR__ . '/fixtures/templates',
            'static_path' => __DIR__ . '/fixtures',
            'config_yaml' => __DIR__ . '/fixtures/nonexistent.yaml',
            'translations_path' => __DIR__ . '/fixtures/translations',
            'default_locale' => 'en',
        ]);
    }

    private function renderRoute(Styleguide $sg, array $route): string
    {
        $dispatch = new \ReflectionMethod(Styleguide::class, 'dispatch');
        ob_start();
        $dispatch->invoke($sg, $route);
        return (string) ob_get_clean();
    }

    #[Test]
    public function locale_query_param_selects_the_catalogue_for_this_render(): void
    {
        $sg = $this->newStyleguide();
        $html = $this->renderRoute($sg, [
            'type' => 'render',
            'kind' => 'component',
            'slug' => 'translated-sample',
            'theme' => 'light',
            'locale' => 'cs_CZ',
        ]);

        self::assertStringContainsString('Odeslat', $html);
        self::assertStringNotContainsString('>Submit<', $html);
    }

    #[Test]
    public function absent_locale_query_param_renders_default_locale_unchanged(): void
    {
        $sg = $this->newStyleguide(); // default_locale: en
        $html = $this->renderRoute($sg, [
            'type' => 'render',
            'kind' => 'component',
            'slug' => 'translated-sample',
            'theme' => 'light',
        ]);

        self::assertStringContainsString('Submit', $html);
        self::assertStringNotContainsString('Odeslat', $html);
    }

    #[Test]
    public function locale_query_param_also_drives_html_lang(): void
    {
        $sg = $this->newStyleguide();
        $html = $this->renderRoute($sg, [
            'type' => 'render',
            'kind' => 'component',
            'slug' => 'translated-sample',
            'theme' => 'light',
            'locale' => 'cs_CZ',
        ]);

        self::assertStringContainsString('lang="cs"', $html);
    }

    #[Test]
    public function bare_two_letter_locale_resolves_the_same_as_the_full_code(): void
    {
        $sg = $this->newStyleguide();
        $html = $this->renderRoute($sg, [
            'type' => 'render',
            'kind' => 'component',
            'slug' => 'translated-sample',
            'theme' => 'light',
            'locale' => 'cs',
        ]);

        self::assertStringContainsString('Odeslat', $html);
    }

    #[Test]
    public function unresolvable_locale_falls_back_to_default_locale_without_error(): void
    {
        $sg = $this->newStyleguide();
        $html = $this->renderRoute($sg, [
            'type' => 'render',
            'kind' => 'component',
            'slug' => 'translated-sample',
            'theme' => 'light',
            'locale' => 'xx_XX',
        ]);

        self::assertStringContainsString('Submit', $html); // default_locale: en, identity for xx_XX
    }

    #[Test]
    public function ambiguous_two_letter_locale_fails_loudly_with_400(): void
    {
        $sg = $this->newStyleguide();
        $dispatch = new \ReflectionMethod(Styleguide::class, 'dispatch');
        ob_start();
        $dispatch->invoke($sg, [
            'type' => 'render',
            'kind' => 'component',
            'slug' => 'translated-sample',
            'theme' => 'light',
            'locale' => 'pt', // matches both pt_BR and pt_PT in the fixture directory
        ]);
        $output = ob_get_clean();

        self::assertSame(400, http_response_code());
        self::assertStringContainsString('ambiguous', $output);
        http_response_code(200);
    }

    #[Test]
    public function no_translations_path_leaves_locale_query_param_inert(): void
    {
        $sg = new Styleguide([
            'templates_path' => __DIR__ . '/fixtures/templates',
            'static_path' => __DIR__ . '/fixtures',
            'config_yaml' => __DIR__ . '/fixtures/nonexistent.yaml',
            'default_locale' => 'en',
            // no translations_path — identity stubs stay wired
        ]);
        $html = $this->renderRoute($sg, [
            'type' => 'render',
            'kind' => 'component',
            'slug' => 'translated-sample',
            'theme' => 'light',
            'locale' => 'cs_CZ',
        ]);

        // langcode still tracks ?locale= (that part of the seam is
        // independent of whether a translator is wired), but the identity
        // stub renders the msgid unchanged regardless of locale.
        self::assertStringContainsString('lang="cs"', $html);
        self::assertStringContainsString('Submit', $html);
    }
}
