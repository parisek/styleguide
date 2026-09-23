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
    public function a_second_styleguide_on_one_environment_renders_through_correctly(): void
    {
        // Constructing twice against one environment is a supported pattern
        // with a test of its own — but that test only checked loader-path
        // idempotence. A Codex review pointed out that the SECOND object was
        // unusable: Twig caches the first runtime a loader returns, so the
        // second instance's own runtime was reached by nothing. Its Renderer
        // announced itself to a runtime no helper consulted, and
        // styleguide_data() threw.
        //
        // The second construction adopts the cached runtime now. This renders
        // through the second object to prove it, which is the assertion the
        // earlier tests were missing.
        $static = __DIR__ . '/../fixtures';
        $config = [
            'templates_path' => __DIR__ . '/../fixtures/templates',
            'static_path' => $static,
            'config_yaml' => __DIR__ . '/../fixtures/styleguide.yaml',
            'twig' => new Environment(new ArrayLoader()),
        ];

        new Styleguide($config);
        $second = new Styleguide($config);

        self::assertStringContainsString(
            'Demo Title',
            $second->renderObserved('component', 'data-demo')['html'],
            'the second instance could not resolve its own sidecar',
        );
    }

    #[Test]
    public function a_second_styleguide_with_a_different_locale_is_refused(): void
    {
        // Adoption carries the first construction's catalogue and locale,
        // because those live on the runtime. A review pointed out that the
        // second object's constructor was therefore accepting a translation
        // configuration and then ignoring it — silently rendering with the
        // other object's translations. Refusing is the honest answer.
        $static = __DIR__ . '/../fixtures';
        $twig = new Environment(new ArrayLoader());
        $base = [
            'templates_path' => __DIR__ . '/../fixtures/templates',
            'static_path' => $static,
            'config_yaml' => __DIR__ . '/../fixtures/styleguide.yaml',
            'twig' => $twig,
        ];

        new Styleguide($base + ['default_locale' => 'en']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('translation configuration differs');
        new Styleguide($base + ['default_locale' => 'cs']);
    }

    #[Test]
    public function a_second_styleguide_with_the_same_catalogue_adopts_and_renders(): void
    {
        // The regression the identity comparison caused. Every construction
        // builds its own TranslationCatalog, so comparing the OBJECTS refused
        // two identically configured instances along with the mismatched ones —
        // banning the supported path in the act of guarding the unsupported
        // one. The fingerprint compares the configuration instead.
        //
        // Non-null catalogue on purpose: with translations_path unset both
        // sides were null and the bug was invisible.
        $static = __DIR__ . '/../fixtures';
        $config = [
            'templates_path' => __DIR__ . '/../fixtures/templates',
            'static_path' => $static,
            'config_yaml' => __DIR__ . '/../fixtures/styleguide.yaml',
            'translations_path' => __DIR__ . '/../fixtures/translations',
            'default_locale' => 'cs',
            'twig' => new Environment(new ArrayLoader()),
        ];

        new Styleguide($config);
        $second = new Styleguide($config);

        self::assertStringContainsString(
            'Demo Title',
            $second->renderObserved('component', 'data-demo')['html'],
        );
    }

    #[Test]
    public function a_second_styleguide_with_a_different_translations_path_is_refused(): void
    {
        $static = __DIR__ . '/../fixtures';
        $base = [
            'templates_path' => __DIR__ . '/../fixtures/templates',
            'static_path' => $static,
            'config_yaml' => __DIR__ . '/../fixtures/styleguide.yaml',
            'default_locale' => 'cs',
            'twig' => new Environment(new ArrayLoader()),
        ];

        new Styleguide($base + ['translations_path' => __DIR__ . '/../fixtures/translations']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('translation configuration differs');
        new Styleguide($base);
    }

    #[Test]
    public function an_inner_render_by_the_other_instance_leaves_the_outer_resolvable(): void
    {
        // The cross-instance nesting a review reproduced. Both objects share
        // one runtime but own different Renderers. With a single renderer slot,
        // the SECOND object's inner render overwrote the first's and then
        // cleared it on the way out — because its own previousKind was null —
        // so the outer template's next styleguide_data() found no context.
        //
        // A stack cannot get that wrong: whoever finishes pops only itself.
        $static = __DIR__ . '/../fixtures';
        $holder = new \stdClass();
        $holder->inner = null;

        $twig = new Environment(new ArrayLoader([]));
        $twig->addFunction(new \Twig\TwigFunction(
            'nested_render',
            static fn(): string => $holder->inner->renderObserved('component', 'data-demo')['html'],
            ['is_safe' => ['html']],
        ));

        $config = [
            'templates_path' => __DIR__ . '/../fixtures/templates',
            'static_path' => $static,
            'config_yaml' => __DIR__ . '/../fixtures/styleguide.yaml',
            'twig' => $twig,
        ];

        $outer = new Styleguide($config);
        $holder->inner = new Styleguide($config);

        $html = $outer->renderObserved('component', 'data-nested')['html'];

        self::assertStringContainsString('before:Outer fixture', $html);
        self::assertStringContainsString('Demo Title', $html, 'the inner instance rendered nothing');
        self::assertStringContainsString(
            'after:Outer fixture',
            $html,
            "the inner instance's render cleared the outer one's context",
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
