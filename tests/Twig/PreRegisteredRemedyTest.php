<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Twig;

use Parisek\Styleguide\Styleguide;
use Parisek\Styleguide\Twig\StyleguideTwigExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * README § "If your environment is already initialised", executed literally
 * and then **rendered through**.
 *
 * A Codex review caught this the hard way. An earlier version of that section
 * told the consumer to register a `StyleguideRuntime` and its runtime loader
 * themselves. Construction then succeeded — and rendering was broken, silently
 * and in two directions: `Styleguide` builds its own runtime, observer and
 * `Renderer`, Twig resolves runtime loaders in registration order, so the
 * helpers reached the consumer's runtime instead. `styleguide_data()` threw
 * "no active render context" because nothing ever set that runtime's renderer,
 * and every `component_*` call was recorded into an observer `renderObserved()`
 * does not read.
 *
 * The remedy is smaller than it was. A runtime loader can be added to an
 * ALREADY INITIALISED environment — unlike a function, filter or extension —
 * so `Styleguide` can still install its own, correctly wired, after the fact.
 * The consumer registers the extension; the runtime stays the package's
 * business.
 *
 * Construction succeeding proves nothing here, which is why every assertion
 * below goes through a real render.
 */
final class PreRegisteredRemedyTest extends TestCase
{
    /**
     * The documented sequence: everything the package would have added, while
     * the environment is still open, and no runtime.
     */
    private function preparedEnvironment(string $staticPath): Environment
    {
        $twig = new Environment(new ArrayLoader());

        $twig->addExtension(new \Parisek\Twig\TypographyExtension(''));
        $twig->addExtension(new \Parisek\Twig\AttributeExtension());
        $twig->addExtension(new \Twig\Extra\Intl\IntlExtension());
        $twig->addExtension(new \Twig\Extra\String\StringExtension());
        $twig->addExtension(new \Symfony\Bridge\Twig\Extension\DumpExtension(
            new \Symfony\Component\VarDumper\Cloner\VarCloner(),
        ));
        $twig->addExtension(new StyleguideTwigExtension(['static_path' => $staticPath]));

        // The consumer's framework reads a function, closing the environment
        // to any further extension, function or filter.
        $twig->getFunctions();

        return $twig;
    }

    #[Test]
    public function styleguide_data_resolves_through_the_packages_own_runtime(): void
    {
        // component/data-demo/styleguide.twig is a bare
        // `{{ styleguide_data()|json_encode|raw }}`. It can only answer if the
        // runtime the helper reached is the one Styleguide told about the
        // active Renderer.
        $static = __DIR__ . '/../fixtures';
        $sg = new Styleguide([
            'templates_path' => __DIR__ . '/../fixtures/templates',
            'static_path' => $static,
            'config_yaml' => __DIR__ . '/../fixtures/styleguide.yaml',
            'twig' => $this->preparedEnvironment($static),
        ]);

        self::assertStringContainsString(
            'Demo Title',
            $sg->renderObserved('component', 'data-demo')['html'],
        );
    }

    #[Test]
    public function component_calls_are_recorded_into_the_observer_render_observed_reads(): void
    {
        // The other direction of the same bug. The helpers come from the
        // consumer's pre-registered extension, so the observation is only
        // correct if those helpers resolve to the runtime Styleguide owns.
        $static = __DIR__ . '/../fixtures';
        $sg = new Styleguide([
            'templates_path' => __DIR__ . '/../fixtures/trace/templates',
            'static_path' => $static,
            'config_yaml' => __DIR__ . '/../fixtures/nonexistent.yaml',
            'twig' => $this->preparedEnvironment($static),
        ]);

        $trace = $sg->renderObserved('component', 'wrapper');

        self::assertCount(2, $trace['calls'], 'observations went to the wrong observer');
        self::assertSame('wrapper', $trace['calls'][0]['component']);
        self::assertSame('direct', $trace['calls'][0]['position']);
        self::assertSame('nested', $trace['calls'][1]['position']);
        self::assertSame('wrapper', $trace['calls'][1]['parent']);
    }

    #[Test]
    public function a_consumer_component_override_is_still_reported_as_unobservable(): void
    {
        // The trap `hasExtension()` walked into. Twig initialises registered
        // extensions BEFORE its staging extension, so a consumer's own
        // `component_*` function overrides the extension's while
        // `hasExtension()` still reports true. Deciding observability from the
        // extension's presence would have returned a complete-looking trace
        // whose calls all bypassed the observer.
        $static = __DIR__ . '/../fixtures';
        $twig = new Environment(new ArrayLoader());
        $twig->addExtension(new \Parisek\Twig\TypographyExtension(''));
        $twig->addExtension(new \Parisek\Twig\AttributeExtension());
        $twig->addExtension(new \Twig\Extra\Intl\IntlExtension());
        $twig->addExtension(new \Twig\Extra\String\StringExtension());
        $twig->addExtension(new \Symfony\Bridge\Twig\Extension\DumpExtension(
            new \Symfony\Component\VarDumper\Cloner\VarCloner(),
        ));
        $twig->addExtension(new StyleguideTwigExtension(['static_path' => $static]));
        $twig->addFunction(new \Twig\TwigFunction(
            'component_*',
            static fn(string $name, array $content = []): string => 'CONSUMER',
            ['is_safe' => ['html']],
        ));
        $twig->getFunctions();

        $sg = new Styleguide([
            'templates_path' => __DIR__ . '/../fixtures/trace/templates',
            'static_path' => $static,
            'config_yaml' => __DIR__ . '/../fixtures/nonexistent.yaml',
            'twig' => $twig,
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot observe this render');
        $sg->renderObserved('component', 'simple');
    }

    #[Test]
    public function a_consumer_runtime_loader_registered_first_is_refused(): void
    {
        // Twig resolves runtime loaders in registration order and caches the
        // first instance, so a consumer's loader silently defeats the
        // package's correctly wired runtime. The README says not to register
        // one; a container can be configured that way by accident, and the
        // resulting failure is silent and far from its cause, so it is checked
        // rather than requested.
        $static = __DIR__ . '/../fixtures';
        $twig = $this->preparedEnvironment($static);
        $twig->addRuntimeLoader(new \Twig\RuntimeLoader\FactoryRuntimeLoader([
            \Parisek\Styleguide\Twig\StyleguideRuntime::class => static fn(): \Parisek\Styleguide\Twig\StyleguideRuntime
                => new \Parisek\Styleguide\Twig\StyleguideRuntime(new \Parisek\Styleguide\RenderObserver()),
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('an instance this Styleguide does not own');

        new Styleguide([
            'templates_path' => __DIR__ . '/../fixtures/templates',
            'static_path' => $static,
            'config_yaml' => __DIR__ . '/../fixtures/styleguide.yaml',
            'twig' => $twig,
        ]);
    }

    #[Test]
    public function the_filters_and_translators_work_through_the_remedy(): void
    {
        // The remedy claims to register everything. Construction succeeding
        // does not show that, and neither do the two render tests above —
        // they only exercise component_* and styleguide_data. These are the
        // rest of the surface a template actually reaches for.
        $static = __DIR__ . '/../fixtures';
        $twig = $this->preparedEnvironment($static);

        new Styleguide([
            'templates_path' => __DIR__ . '/../fixtures/templates',
            'static_path' => $static,
            'config_yaml' => __DIR__ . '/../fixtures/styleguide.yaml',
            'twig' => $twig,
        ]);

        self::assertSame(
            'not-a-date|hello|text|one|many',
            $twig->createTemplate(
                '{{ "not-a-date"|format_date }}|{{ __("hello") }}|{{ _x("text", "ctx") }}'
                . '|{{ _n("one", "many", 1) }}|{{ _n("one", "many", 5) }}',
            )->render(),
        );

        // |cachebust needs static_path, which the remedy passes to the
        // extension rather than having it injected by Styleguide.
        self::assertMatchesRegularExpression(
            '~^/asset-server/test-asset\.css\?v=\d+$~',
            $twig->createTemplate('{{ "/asset-server/test-asset.css"|cachebust }}')->render(),
        );

        self::assertMatchesRegularExpression(
            '/^[a-z][0-9a-f]{6}$/',
            $twig->createTemplate('{{ uniqueId() }}')->render(),
        );
    }

    #[Test]
    public function render_observed_does_not_refuse_our_own_pre_registered_helpers(): void
    {
        // `component_*` cannot be registered here — the extension already
        // provides it — and that used to land in $unobservableFunctions, which
        // makes renderObserved() refuse outright. A false alarm: the version
        // that won is the package's own.
        $static = __DIR__ . '/../fixtures';
        $sg = new Styleguide([
            'templates_path' => __DIR__ . '/../fixtures/trace/templates',
            'static_path' => $static,
            'config_yaml' => __DIR__ . '/../fixtures/nonexistent.yaml',
            'twig' => $this->preparedEnvironment($static),
        ]);

        self::assertSame([], $sg->renderObserved('component', 'simple')['unobservable']);
    }
}
