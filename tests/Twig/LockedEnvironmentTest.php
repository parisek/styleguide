<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Twig;

use Parisek\Styleguide\RenderObserver;
use Parisek\Styleguide\Styleguide;
use Parisek\Styleguide\Twig\StyleguideRuntime;
use Parisek\Styleguide\Twig\StyleguideTwigExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

/**
 * A Twig environment whose extension set is already locked.
 *
 * This is the shape of the consumer the package had no test for, and the
 * reason it had a silent failure. Every other suite builds Twig by hand and
 * never reads a function before handing it over, so the environment is never
 * locked when `Styleguide` mutates it. A Symfony application is the opposite:
 * Twig is a compiled, lazily-booted service, and by the time a controller
 * constructs anything its extension set may be closed.
 *
 * Reading a single function is enough to lock it — `ExtensionSet::
 * initExtensions()` sets `initialized = true`, after which `addFunction()`
 * throws `LogicException`. `Styleguide::tryAddFunction()` swallows that
 * exception by design, which is what makes the failure silent rather than
 * loud.
 *
 * Both halves are pinned below: the tolerant library path keeps tolerating,
 * and the compile-time path — register the extension before anything locks —
 * actually works. The second is the remedy; the first is what a consumer gets
 * today if they do not apply it.
 */
final class LockedEnvironmentTest extends TestCase
{
    private function twig(): Environment
    {
        return new Environment(new ArrayLoader([
            '@component/card/card.twig' => 'CARD:{{ content.title }}',
        ]));
    }

    #[Test]
    public function the_extension_survives_an_environment_that_locks_after_registration(): void
    {
        $twig = $this->twig();
        $runtime = new StyleguideRuntime(new RenderObserver());

        // The Symfony order: everything registered while the container is
        // still compiling, and only then is a function read.
        $twig->addExtension(new StyleguideTwigExtension([]));
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            StyleguideRuntime::class => static fn(): StyleguideRuntime => $runtime,
        ]));

        $twig->getFunction('range');

        self::assertSame(
            'CARD:Hi',
            $twig->createTemplate('{{ component_card({ title: "Hi" }) }}')->render([]),
        );
    }

    #[Test]
    public function the_runtime_backed_helpers_resolve_lazily_through_the_loader(): void
    {
        // The runtime is created AFTER the extension is registered and after
        // the environment locks. Nothing about the extension depends on it
        // existing yet — which is exactly what "stateless" buys.
        $twig = $this->twig();
        $twig->addExtension(new StyleguideTwigExtension([]));
        $twig->getFunction('range');

        $runtime = new StyleguideRuntime(new RenderObserver());
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            StyleguideRuntime::class => static fn(): StyleguideRuntime => $runtime,
        ]));

        self::assertMatchesRegularExpression(
            '/^[a-z][0-9a-f]{6}$/',
            $twig->createTemplate('{{ uniqueId() }}')->render([]),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function styleguide(Environment $twig): Styleguide
    {
        return new Styleguide([
            'templates_path' => __DIR__ . '/../fixtures/templates',
            'static_path' => __DIR__ . '/../fixtures',
            'config_yaml' => __DIR__ . '/../fixtures/styleguide.yaml',
            'twig' => $twig,
        ]);
    }

    #[Test]
    public function a_locked_environment_missing_an_extension_fails_at_construction(): void
    {
        // Loud, but blaming the wrong thing. `registerBundledExtensions()` uses
        // a bare `addExtension()` with no tolerant wrapper, so construction
        // dies on the first extension the consumer has not already registered
        // — here TypographyExtension — and never reaches helper registration
        // at all.
        //
        // The message names an extension, which sends whoever reads it looking
        // for a missing dependency. The actual cause is that the environment
        // was locked before it arrived.
        $twig = $this->twig();
        $twig->getFunction('range');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('extensions have already been initialized');
        $this->styleguide($twig);
    }

    #[Test]
    public function a_locked_environment_that_already_has_the_extensions_loses_its_helpers_silently(): void
    {
        // The real-world Symfony shape, and the genuinely silent one. When the
        // consumer already registered every extension the package would add,
        // `hasExtension()` skips them all, construction succeeds — and THEN
        // every helper is swallowed one by one by tryAddFunction().
        //
        // Nothing throws. To be exact, it is not literally silent: running
        // this test prints a `[parisek/styleguide] unexpected LogicException`
        // line per lost helper, because `logUnexpectedRegistrationFailure()`
        // reaches `error_log()` for messages that are not the ordinary
        // duplicate-name case. That is the best the current design can do and
        // it is still not enough — in a deployed WordPress or Drupal install
        // `error_log()` lands in a file nobody is watching, after the response
        // has already been served. The catalogue renders without
        // `component_*`, and the first symptom a human sees is an opaque Twig
        // error far from the cause.
        //
        // Pinned rather than fixed. Making this throw would turn a limping
        // install into a hard failure, and it should only do that once there
        // is a documented remedy to point at — register the extension while
        // the container compiles, as the two tests above do.
        $twig = $this->twig();
        foreach ([
            \Parisek\Twig\TypographyExtension::class,
            \Symfony\Bridge\Twig\Extension\DumpExtension::class,
            \Twig\Extra\Intl\IntlExtension::class,
            \Twig\Extra\String\StringExtension::class,
            \Parisek\Twig\AttributeExtension::class,
        ] as $class) {
            if (!$twig->hasExtension($class)) {
                $twig->addExtension($class === \Symfony\Bridge\Twig\Extension\DumpExtension::class
                    ? new $class(new \Symfony\Component\VarDumper\Cloner\VarCloner())
                    : new $class());
            }
        }

        $twig->getFunction('range');
        $this->styleguide($twig);

        self::assertNull($twig->getFunction('component_*'), 'component_* silently missing');
        self::assertNull($twig->getFunction('placeholder'), 'placeholder silently missing');
        self::assertNull($twig->getFunction('styleguide_data'), 'styleguide_data silently missing');
        self::assertNull($twig->getFilter('cachebust'), 'cachebust silently missing');
    }
}
