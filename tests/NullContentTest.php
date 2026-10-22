<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

/**
 * `component_*()` / `page_*()` accept `null` content and say so in the log.
 *
 * A template that passes an optional sub-object straight through
 * (`component_pagination(content.pagination)`) hands the helper `null` when
 * the fixture omits the key. The Drupal side accepts that, so the styleguide
 * must not turn it into a 500. It renders the call with `[]` and logs the
 * caller instead. A scalar is still a template bug and still throws.
 */
final class NullContentTest extends TestCase
{
    private string $log = '';

    private string|false $previousLog = false;

    protected function tearDown(): void
    {
        if ($this->previousLog !== false && $this->previousLog !== '') {
            ini_set('error_log', $this->previousLog);
        }
        @unlink($this->log);
    }

    private function twig(): Environment
    {
        $this->log = (string) tempnam(sys_get_temp_dir(), 'sg-null-');
        $this->previousLog = ini_set('error_log', $this->log);

        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/fixtures/nested/templates');
        $twig = new Environment(
            new ChainLoader([
                new ArrayLoader([
                    '@page/home/home.twig' => 'HOME[{{ content.title|default("none") }}]',
                    'caller.twig' => "line one\n{{ page_home(content.missing) }}\n{{ page_home([]) }}",
                ]),
                $loader,
            ]),
            ['cache' => false, 'autoescape' => false],
        );

        new Styleguide([
            'templates_path' => __DIR__ . '/fixtures/nested/templates',
            'static_path' => __DIR__ . '/fixtures',
            'config_yaml' => __DIR__ . '/fixtures/styleguide.yaml',
            'twig' => $twig,
        ]);

        return $twig;
    }

    private function logged(): string
    {
        return (string) file_get_contents($this->log);
    }

    #[Test]
    public function null_content_renders_as_an_empty_array(): void
    {
        $html = $this->twig()->createTemplate('{{ component_ok(null) }}')->render([]);

        self::assertStringContainsString('<div class="ok"></div>', $html);
    }

    #[Test]
    public function a_null_page_call_renders_too(): void
    {
        $html = $this->twig()->createTemplate('{{ page_home(null) }}')->render([]);

        self::assertSame('HOME[none]', $html);
    }

    #[Test]
    public function the_warning_names_the_component_the_template_and_the_line(): void
    {
        $twig = $this->twig();
        $html = $twig->load('@component/null-caller/null-caller.twig')->render([]);

        self::assertStringContainsString('<div class="ok"></div>', $html);
        $log = $this->logged();
        self::assertStringContainsString('[parisek/styleguide]', $log);
        self::assertStringContainsString('component_ok() called with null content', $log);
        self::assertStringContainsString('"@component/null-caller/null-caller.twig" at line 5', $log);
    }

    #[Test]
    public function the_warning_reports_the_line_of_a_page_call_in_a_multiline_template(): void
    {
        $this->twig()->load('caller.twig')->render([]);

        self::assertStringContainsString('page_home() called with null content in "caller.twig" at line 2', $this->logged());
    }

    #[Test]
    public function an_array_and_a_missing_argument_stay_silent(): void
    {
        $html = $this->twig()->createTemplate('{{ component_ok({label: "x"}) }}{{ component_ok() }}{{ component_ok([]) }}')->render([]);

        self::assertStringContainsString('<div class="ok">x</div>', $html);
        self::assertStringNotContainsString('null content', $this->logged());
    }

    #[Test]
    public function a_scalar_still_fails(): void
    {
        $twig = $this->twig();

        try {
            $twig->createTemplate('{{ component_ok("nope") }}')->render([]);
            self::fail('A string as content must throw.');
        } catch (RuntimeError $e) {
            // Twig wraps the signature violation; the cause is what matters.
            self::assertInstanceOf(\TypeError::class, $e->getPrevious());
            self::assertStringContainsString('must be of type ?array, string given', $e->getMessage());
        }
    }
}
