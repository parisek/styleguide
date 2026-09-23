<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Twig;

use Parisek\Styleguide\RenderObserver;
use Parisek\Styleguide\Twig\StyleguideRuntime;
use Parisek\Styleguide\Twig\StyleguideTwigExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;
use Twig\TwigFunction;

/**
 * The OPTIONS each helper is declared with, not just its name.
 *
 * `RegisteredHelperNamesTest` freezes names, which is not enough. Dropping
 * `is_safe => ['html']` from `component_*` leaves the name in place and every
 * name assertion green, while the rendered markup starts arriving escaped in
 * any consumer that has autoescaping on. `needs_context` behaves the same way:
 * lose it and the arguments silently shift by one.
 *
 * Both halves are covered here — the declared metadata, and the consequence
 * of that metadata under `autoescape: 'html'`. The package's own pristine
 * environment sets `autoescape: false`, so escaping behaviour is invisible
 * there; a consumer that opts back in is where a lost `is_safe` would show up,
 * and that consumer needs to exist as a test.
 */
final class HelperOptionsTest extends TestCase
{
    /**
     * name => [needsEnvironment, needsContext, isHtmlSafe]
     *
     * Frozen from the declarations. A helper that changes shape has to change
     * this table in the same commit.
     */
    private const EXPECTED = [
        'component_*' => [true, true, true],
        'page_*' => [true, true, true],
        '__' => [false, false, false],
        '_x' => [false, false, false],
        '_n' => [false, false, false],
        '_nx' => [false, false, false],
        '__t' => [true, false, true],
        '_xt' => [true, false, true],
        '_nt' => [true, false, true],
        '_nxt' => [true, false, true],
        'uniqueId' => [false, false, false],
        'styleguide_data' => [false, false, false],
        'placeholder' => [false, false, false],
        'merge_resizer' => [false, false, false],
    ];

    #[Test]
    public function every_helper_keeps_its_declared_options(): void
    {
        $byName = [];
        foreach ((new StyleguideTwigExtension([]))->getFunctions() as $function) {
            $byName[$function->getName()] = $function;
        }

        foreach (self::EXPECTED as $name => [$env, $context, $safe]) {
            $function = $byName[$name] ?? null;
            self::assertInstanceOf(TwigFunction::class, $function, "{$name} is not declared");
            self::assertSame($env, $function->needsEnvironment(), "{$name} needs_environment");
            self::assertSame($context, $function->needsContext(), "{$name} needs_context");
            self::assertSame(
                $safe ? ['html'] : [],
                $function->getSafe(new \Twig\Node\Node()),
                "{$name} is_safe",
            );
        }
    }

    #[Test]
    public function the_declared_table_covers_every_helper(): void
    {
        // So a newly added helper cannot slip past this file unnoticed.
        $declared = array_map(
            static fn(TwigFunction $f): string => $f->getName(),
            (new StyleguideTwigExtension([]))->getFunctions(),
        );

        self::assertEqualsCanonicalizing(array_keys(self::EXPECTED), $declared);
    }

    private function escapingTwig(): Environment
    {
        $twig = new Environment(
            new ArrayLoader(['@component/card/card.twig' => '<b>{{ content.title }}</b>']),
            ['autoescape' => 'html'],
        );
        $runtime = new StyleguideRuntime(new RenderObserver());
        $twig->addExtension(new StyleguideTwigExtension([]));
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            StyleguideRuntime::class => static fn(): StyleguideRuntime => $runtime,
        ]));

        return $twig;
    }

    #[Test]
    public function component_markup_survives_an_autoescaping_consumer(): void
    {
        self::assertSame(
            '<b>Hi</b>',
            $this->escapingTwig()
                ->createTemplate('{{ component_card({ title: "Hi" }) }}')
                ->render([]),
        );
    }

    #[Test]
    public function the_typography_alias_survives_an_autoescaping_consumer(): void
    {
        $twig = $this->escapingTwig();
        $twig->addFilter(new \Twig\TwigFilter('typography', static fn(string $v): string => "<i>{$v}</i>"));

        self::assertSame(
            '<i>Hello</i>',
            $twig->createTemplate('{{ __t("Hello") }}')->render([]),
        );
    }

    #[Test]
    public function the_plain_translator_is_still_escaped_by_an_autoescaping_consumer(): void
    {
        // The other direction, and the reason `is_safe` is a per-helper
        // decision rather than a blanket one: `__()` returns copy, not markup,
        // so a consumer with autoescaping on must still escape it. If someone
        // "fixed" escaping by marking everything safe, this fails.
        self::assertSame(
            '&lt;script&gt;',
            $this->escapingTwig()
                ->createTemplate('{{ __("<script>") }}')
                ->render([]),
        );
    }
}
