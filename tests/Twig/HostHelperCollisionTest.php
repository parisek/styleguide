<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Twig;

use Parisek\Styleguide\Cli\Doctor;
use Parisek\Styleguide\Styleguide;
use Parisek\Styleguide\Tests\Support\HostHelpersExtension;
use Parisek\Styleguide\Tests\Support\UrlExtension;
use Parisek\Styleguide\Twig\StyleguideTwigExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * A host helper with the name of a bundled helper: the host's version renders,
 * as it does in production, and `doctor` says so.
 */
final class HostHelperCollisionTest extends TestCase
{
    private const YAML = __DIR__ . '/../fixtures/twig-extensions/styleguide.yaml';
    private const TEMPLATE = '@component/host-helpers/host-helpers.twig';

    #[Test]
    public function without_a_host_definition_the_package_helpers_render(): void
    {
        $sg = Styleguide::fromYaml(self::YAML, ['twig_extensions' => [new UrlExtension()]]);
        $html = $sg->renderTemplate(self::TEMPLATE);

        self::assertStringContainsString('data-placeholder="package"', $html);
        // The package's resizer hands a plain string back unchanged.
        self::assertStringContainsString('data-resized="photo.jpg"', $html);
        self::assertSame([], $sg->diagnostics()['shadowed']);
    }

    #[Test]
    public function a_host_extension_in_twig_extensions_wins(): void
    {
        $sg = Styleguide::fromYaml(self::YAML, ['twig_extensions' => [new HostHelpersExtension()]]);
        $html = $sg->renderTemplate(self::TEMPLATE);

        self::assertStringContainsString('data-placeholder="/host/placeholder.svg"', $html);
        self::assertStringContainsString('data-resized="/host/resized/photo.jpg"', $html);
        self::assertSame(['placeholder()', '|resizer'], $sg->diagnostics()['shadowed']);
    }

    #[Test]
    public function a_host_extension_on_the_host_environment_wins(): void
    {
        $twig = new Environment(new ArrayLoader());
        $twig->addExtension(new HostHelpersExtension());
        $sg = Styleguide::fromYaml(self::YAML, ['twig' => $twig]);
        $html = $sg->renderTemplate(self::TEMPLATE);

        self::assertStringContainsString('data-placeholder="/host/placeholder.svg"', $html);
        self::assertStringContainsString('data-resized="/host/resized/photo.jpg"', $html);
        self::assertSame(['placeholder()', '|resizer'], $sg->diagnostics()['shadowed']);
    }

    #[Test]
    public function a_host_function_added_one_at_a_time_wins_and_is_reported(): void
    {
        $twig = new Environment(new ArrayLoader());
        $twig->addFunction(new TwigFunction('placeholder', static fn(): string => '/host/placeholder.svg'));
        $twig->addFilter(new TwigFilter('resizer', static fn(string $src): string => '/host/resized/' . $src));
        $sg = Styleguide::fromYaml(self::YAML, ['twig' => $twig]);
        $html = $sg->renderTemplate(self::TEMPLATE);

        self::assertStringContainsString('data-placeholder="/host/placeholder.svg"', $html);
        self::assertStringContainsString('data-resized="/host/resized/photo.jpg"', $html);
        self::assertSame(['placeholder()', '|resizer'], $sg->diagnostics()['shadowed']);
    }

    #[Test]
    public function a_bundled_extra_the_host_registers_does_not_replace_a_package_helper(): void
    {
        // IntlExtension defines `|format_date` too. The package registers
        // IntlExtension itself when it is absent, so a host that registers it
        // gets what the package would have given it: the package's filter.
        if (!class_exists(\Twig\Extra\Intl\IntlExtension::class)) {
            self::markTestSkipped('twig/intl-extra is not installed.');
        }
        $sg = Styleguide::fromYaml(self::YAML, ['twig_extensions' => [new \Twig\Extra\Intl\IntlExtension()]]);

        self::assertSame('1. 2. 2026', trim($sg->renderTemplate('@component/host-helpers/date.twig')));
        self::assertSame([], $sg->diagnostics()['shadowed']);
    }

    #[Test]
    public function a_second_construction_on_one_environment_reports_nothing(): void
    {
        $twig = new Environment(new ArrayLoader());
        Styleguide::fromYaml(self::YAML, ['twig' => $twig]);
        $second = Styleguide::fromYaml(self::YAML, ['twig' => $twig]);

        self::assertSame([], $second->diagnostics()['shadowed']);
    }

    #[Test]
    public function a_second_construction_keeps_reporting_a_host_function(): void
    {
        $twig = new Environment(new ArrayLoader());
        $twig->addFunction(new TwigFunction('placeholder', static fn(): string => '/host/placeholder.svg'));
        Styleguide::fromYaml(self::YAML, ['twig' => $twig]);
        $second = Styleguide::fromYaml(self::YAML, ['twig' => $twig]);

        self::assertSame(['placeholder()'], $second->diagnostics()['shadowed']);
    }

    /**
     * README § "If your environment is already initialised": the host
     * registers StyleguideTwigExtension itself. Twig initialises extensions in
     * order, so the later definition renders, and the report must match.
     */
    #[Test]
    public function a_host_extension_before_the_pre_registered_package_extension_loses(): void
    {
        $twig = $this->lockedEnvironment(new HostHelpersExtension(), $this->packageExtension());
        $sg = Styleguide::fromYaml(self::YAML, ['twig' => $twig]);

        self::assertStringContainsString('data-placeholder="package"', $sg->renderTemplate(self::TEMPLATE));
        self::assertSame([], $sg->diagnostics()['shadowed']);
    }

    #[Test]
    public function a_host_extension_after_the_pre_registered_package_extension_wins(): void
    {
        $twig = $this->lockedEnvironment($this->packageExtension(), new HostHelpersExtension());
        $sg = Styleguide::fromYaml(self::YAML, ['twig' => $twig]);

        self::assertStringContainsString('data-placeholder="/host/placeholder.svg"', $sg->renderTemplate(self::TEMPLATE));
        self::assertSame(['placeholder()', '|resizer'], $sg->diagnostics()['shadowed']);
    }

    private function packageExtension(): StyleguideTwigExtension
    {
        return new StyleguideTwigExtension(['static_path' => dirname(self::YAML)]);
    }

    private function lockedEnvironment(\Twig\Extension\ExtensionInterface ...$extensions): Environment
    {
        $twig = new Environment(new ArrayLoader());
        $twig->addExtension(new \Parisek\Twig\TypographyExtension(''));
        $twig->addExtension(new \Parisek\Twig\AttributeExtension());
        $twig->addExtension(new \Twig\Extra\Intl\IntlExtension());
        $twig->addExtension(new \Twig\Extra\String\StringExtension());
        $twig->addExtension(new \Symfony\Bridge\Twig\Extension\DumpExtension(
            new \Symfony\Component\VarDumper\Cloner\VarCloner(),
        ));
        foreach ($extensions as $extension) {
            $twig->addExtension($extension);
        }
        $twig->getFunctions();

        return $twig;
    }

    #[Test]
    public function reading_host_names_leaves_the_environment_open(): void
    {
        $twig = new Environment(new ArrayLoader());
        $twig->addExtension(new HostHelpersExtension());
        Styleguide::fromYaml(self::YAML, ['twig' => $twig]);

        // An open environment still takes a function after construction.
        $twig->addFunction(new TwigFunction('late', static fn(): string => 'late'));
        self::assertSame('late', $twig->createTemplate('{{ late() }}')->render());
    }

    #[Test]
    public function doctor_reports_each_shadowed_helper_without_failing_a_build(): void
    {
        $sg = Styleguide::fromYaml(self::YAML, ['twig_extensions' => [new HostHelpersExtension()]]);
        $findings = (new Doctor())->runFor($sg, self::YAML);
        $shadowed = array_values(array_filter(
            $findings,
            static fn($f): bool => str_starts_with($f->message, 'The host defines'),
        ));

        self::assertCount(2, $shadowed);
        self::assertStringContainsString('placeholder()', $shadowed[0]->message);
        self::assertStringContainsString('|resizer', $shadowed[1]->message);
        foreach ($shadowed as $finding) {
            self::assertSame('twig', $finding->check);
            self::assertFalse($finding->severity->failsBuild());
        }
    }

    #[Test]
    public function doctor_reports_no_shadowed_helper_without_a_host_definition(): void
    {
        $sg = Styleguide::fromYaml(self::YAML, ['twig_extensions' => [new UrlExtension()]]);

        foreach ((new Doctor())->runFor($sg, self::YAML) as $finding) {
            self::assertStringStartsNotWith('The host defines', $finding->message);
        }
    }

    #[Test]
    public function a_host_component_function_shadows_the_package_one_and_is_reported(): void
    {
        // A host that defines `component_*` itself renders with its own; the
        // trace then cannot be trusted, which renderObserved() says (R5.14).
        $host = new class extends AbstractExtension {
            public function getFunctions(): array
            {
                return [new TwigFunction('component_*', static fn(string $name): string => 'host:' . $name)];
            }
        };
        $sg = Styleguide::fromYaml(self::YAML, ['twig_extensions' => [$host]]);

        self::assertSame('host:linked', trim($sg->renderTemplate('@component/host-helpers/component-call.twig')));
        self::assertSame(['component_*()'], $sg->diagnostics()['shadowed']);
    }
}
