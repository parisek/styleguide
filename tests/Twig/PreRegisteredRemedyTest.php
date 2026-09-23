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
