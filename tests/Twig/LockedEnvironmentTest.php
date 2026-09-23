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
    public function a_locked_environment_missing_an_extension_explains_itself(): void
    {
        // `registerBundledExtensions()` uses a bare `addExtension()` with no
        // tolerant wrapper, so construction dies on the first extension the
        // consumer has not already registered — here TypographyExtension —
        // before helper registration is reached at all.
        //
        // Twig's own message names that extension, which sends whoever reads
        // it looking for a missing dependency that is in fact installed. The
        // wrapper keeps Twig's wording as the evidence and puts the real
        // cause in front of it.
        $twig = $this->twig();
        $twig->getFunction('range');

        try {
            $this->styleguide($twig);
            self::fail('construction should have been refused');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('extension set has been initialised', $e->getMessage());
            self::assertStringContainsString('README', $e->getMessage());
            // Twig's own wording survives as evidence inside the explanation.
            self::assertStringContainsString('TypographyExtension', $e->getMessage());
            // Twig's exception is kept, not discarded — it is still the
            // evidence, it just should not be the headline.
            self::assertInstanceOf(\LogicException::class, $e->getPrevious());
        }
    }

    #[Test]
    public function a_locked_environment_that_already_has_the_extensions_is_refused(): void
    {
        // The real-world Symfony shape, and until now the genuinely silent
        // one. The consumer has already registered every extension the package
        // would add, so `hasExtension()` skips them all and construction gets
        // past that phase — and then every helper is refused in turn.
        //
        // It used to succeed, handing back a Styleguide with no helpers on it
        // and a line per loss in `error_log()`, which in a deployed install
        // lands in a file nobody watches, after the response has been served.
        // The first symptom a human saw was an opaque Twig error far from the
        // cause.
        //
        // It refuses now because there is finally somewhere to send them: the
        // two tests above register the extension while the environment is
        // still open, which is what the message points at.
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

        try {
            $this->styleguide($twig);
            self::fail('construction should have been refused');
        } catch (\RuntimeException $e) {
            // The message has to carry the helpers that were lost and the way
            // out. A refusal that only says "no" moves the problem rather than
            // solving it.
            self::assertStringContainsString('accepted none of the package', $e->getMessage());
            self::assertStringContainsString('component_*', $e->getMessage());
            self::assertStringContainsString('StyleguideTwigExtension', $e->getMessage());
            // And it must NOT tell them to register a runtime loader. An
            // earlier version did, which recreated the split-runtime failure
            // the README now warns about — the message is executable advice,
            // so it has to be advice that works.
            self::assertStringNotContainsString('addRuntimeLoader', $e->getMessage());
            self::assertStringContainsString('Do NOT register a StyleguideRuntime', $e->getMessage());
        }

        // And the helpers really were absent — the refusal is not describing
        // something that did not happen.
        self::assertNull($twig->getFunction('component_*'));
        self::assertNull($twig->getFilter('cachebust'));
    }
}
