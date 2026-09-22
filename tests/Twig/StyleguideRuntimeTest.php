<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Twig;

use Parisek\Styleguide\RenderObserver;
use Parisek\Styleguide\Twig\StyleguideRuntime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The runtime in isolation — no `Styleguide`, no HTTP, no fixtures on disk.
 *
 * That isolation is the point of the class existing. Everything here was
 * previously reachable only through a closure built inside
 * `Styleguide::registerBundledHelpers()`, so none of it could be exercised
 * without constructing the whole package.
 */
final class StyleguideRuntimeTest extends TestCase
{
    private function twig(): Environment
    {
        return new Environment(new ArrayLoader([
            '@component/card/card.twig' => 'CARD:{{ content.title }}',
            '@component/inner/inner.twig' => 'INNER',
            '@page/home/home.twig' => 'HOME',
        ]));
    }

    #[Test]
    public function render_component_records_the_call_on_an_armed_observer(): void
    {
        $observer = new RenderObserver();
        $runtime = new StyleguideRuntime($observer);

        $observer->arm(['kind' => 'component', 'slug' => 'card', 'variant' => null]);
        $html = $runtime->renderComponent($this->twig(), [], 'card', ['title' => 'Hi']);
        $calls = $observer->disarm();

        self::assertSame('CARD:Hi', $html);
        self::assertCount(1, $calls);
        self::assertSame('card', $calls[0]['component']);
        self::assertSame('direct', $calls[0]['position']);
        self::assertSame(['title' => 'Hi'], $calls[0]['arguments']);
    }

    #[Test]
    public function render_page_records_against_the_page_namespace(): void
    {
        $observer = new RenderObserver();
        $runtime = new StyleguideRuntime($observer);

        $observer->arm(['kind' => 'page', 'slug' => 'home', 'variant' => null]);
        self::assertSame('HOME', $runtime->renderPage($this->twig(), [], 'home'));
        self::assertCount(1, $observer->disarm());
    }

    #[Test]
    public function an_unarmed_observer_records_nothing_and_still_renders(): void
    {
        $observer = new RenderObserver();
        $runtime = new StyleguideRuntime($observer);

        self::assertSame('CARD:Hi', $runtime->renderComponent($this->twig(), [], 'card', ['title' => 'Hi']));
        self::assertSame([], $observer->disarm());
    }

    #[Test]
    public function underscores_in_the_call_name_resolve_to_hyphens(): void
    {
        $twig = new Environment(new ArrayLoader([
            '@component/card-wide/card-wide.twig' => 'WIDE',
        ]));

        self::assertSame(
            'WIDE',
            (new StyleguideRuntime(new RenderObserver()))->renderComponent($twig, [], 'card_wide'),
        );
    }

    #[Test]
    public function a_nested_observation_leaves_the_outer_frame_intact(): void
    {
        // RenderObserver is re-entrant by construction — its frame stack exists
        // so an inner renderObserved() cannot truncate an outer one. Routing
        // the calls through the runtime must not flatten that.
        $observer = new RenderObserver();
        $runtime = new StyleguideRuntime($observer);
        $twig = $this->twig();

        $observer->arm(['kind' => 'component', 'slug' => 'card', 'variant' => null]);
        $runtime->renderComponent($twig, [], 'card', ['title' => 'outer']);

        $observer->arm(['kind' => 'component', 'slug' => 'inner', 'variant' => null]);
        $runtime->renderComponent($twig, [], 'inner');
        self::assertCount(1, $observer->disarm(), 'inner frame');

        self::assertCount(1, $observer->disarm(), 'outer frame lost its call');
    }

    #[Test]
    public function styleguide_data_without_an_active_render_throws(): void
    {
        $runtime = new StyleguideRuntime(new RenderObserver());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no active render context');
        $runtime->styleguideData();
    }

    #[Test]
    public function unique_id_starts_with_a_letter_and_never_repeats(): void
    {
        $runtime = new StyleguideRuntime(new RenderObserver());

        $ids = [];
        for ($i = 0; $i < 200; $i++) {
            $ids[] = $runtime->uniqueId();
        }

        self::assertCount(200, array_unique($ids));
        foreach ($ids as $id) {
            self::assertMatchesRegularExpression('/^[a-z][0-9a-f]{6}$/', $id);
        }
    }

    #[Test]
    public function translators_fall_back_to_the_source_string_without_a_catalogue(): void
    {
        $runtime = new StyleguideRuntime(new RenderObserver());

        self::assertSame('Hello', $runtime->translate('Hello'));
        self::assertSame('Hello', $runtime->translateWithContext('Hello', 'greeting'));
        self::assertSame('one', $runtime->translatePlural('one', 'many', 1));
        self::assertSame('many', $runtime->translatePlural('one', 'many', 3));
    }

    #[Test]
    public function the_plural_with_context_translator_still_interpolates_the_number(): void
    {
        // sprintf() on the resolved string is part of _nx's contract, not an
        // artefact of the catalogue — it has to survive the no-catalogue path.
        $runtime = new StyleguideRuntime(new RenderObserver());

        self::assertSame('3 items', $runtime->translatePluralWithContext('%d item', '%d items', 3, 'cart'));
    }

    #[Test]
    public function the_typography_aliases_pass_through_when_no_filter_is_registered(): void
    {
        $runtime = new StyleguideRuntime(new RenderObserver());
        $twig = $this->twig();

        self::assertSame('Hello', $runtime->translateTypography($twig, 'Hello'));
        self::assertSame('Hello', $runtime->translateWithContextTypography($twig, 'Hello', 'greeting'));
    }

    #[Test]
    public function the_typography_aliases_resolve_the_filter_from_the_environment(): void
    {
        // Resolving at call time is what lets a project's tuned typography
        // settings compose in. A hard-coded call would bypass them silently.
        $runtime = new StyleguideRuntime(new RenderObserver());
        $twig = $this->twig();
        $twig->addFilter(new \Twig\TwigFilter('typography', static fn(string $v): string => "[{$v}]"));

        self::assertSame('[Hello]', $runtime->translateTypography($twig, 'Hello'));
    }

    #[Test]
    public function the_typography_aliases_prefer_a_translator_registered_on_the_environment(): void
    {
        // The reason these go through the environment rather than calling
        // $this->translate(): a WordPress consumer registers the real __()
        // before Styleguide is built, and it must win.
        $runtime = new StyleguideRuntime(new RenderObserver());
        $twig = $this->twig();
        $twig->addFunction(new \Twig\TwigFunction('__', static fn(string $t, string $d = 'default'): string => 'HOST:' . $t));

        self::assertSame('HOST:Hello', $runtime->translateTypography($twig, 'Hello'));
    }
}
