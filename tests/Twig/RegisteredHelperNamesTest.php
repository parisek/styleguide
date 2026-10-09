<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Twig;

use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

/**
 * What a freshly constructed `Styleguide` puts on its Twig environment.
 *
 * A characterisation test, not a specification. The two lists below were
 * captured by RUNNING this assertion against the tree before the helper
 * definitions moved into {@see \Parisek\Styleguide\Twig\StyleguideTwigExtension},
 * and pasting back what came out. They were never typed from reading the
 * source, because the point is to record what ships, not what the source
 * appears to say — and a 440-line registration method is exactly where those
 * two drift apart.
 *
 * Twig core, intl and string contributions are included on purpose, so a
 * dropped EXTENSION fails this test as readily as a dropped helper.
 *
 * The package allows `twig/twig ^3.27` and tracks no lock file, so CI resolves
 * the newest Twig. A Twig minor release can add a helper of its own
 * (`include_only` and `format_list` arrived in 3.30). Such a name is not the
 * package's change, so the test accepts a name that a vendor extension
 * (`Twig\...`) contributes beyond the lists. It still fails for a missing
 * name, and for an extra name that no vendor extension provides.
 *
 * Adding a helper of the package updates these lists deliberately, in the
 * same commit, with a CHANGELOG entry. Losing one silently is the failure this
 * exists to prevent.
 */
final class RegisteredHelperNamesTest extends TestCase
{
    private const FUNCTIONS = [
        '__',
        '__t',
        '_n',
        '_nt',
        '_nx',
        '_nxt',
        '_x',
        '_xt',
        'attribute',
        'block',
        'component_*',
        'constant',
        'country_names',
        'country_timezones',
        'create_attribute',
        'currency_names',
        'cycle',
        'date',
        'dump',
        'enum',
        'enum_cases',
        'include',
        'language_names',
        'locale_names',
        'max',
        'merge_resizer',
        'min',
        'page_*',
        'parent',
        'placeholder',
        'random',
        'range',
        'script_names',
        'source',
        'styleguide_data',
        'timezone_names',
        'uniqueId',
    ];

    private const FILTERS = [
        'abs',
        'batch',
        'cachebust',
        'capitalize',
        'column',
        'convert_encoding',
        'country_name',
        'currency_name',
        'currency_symbol',
        'custom_price_format',
        'date',
        'date_modify',
        'default',
        'e',
        'escape',
        'filter',
        'find',
        'first',
        'format',
        'format_*_number',
        'format_currency',
        'format_date',
        'format_datetime',
        'format_number',
        'format_time',
        'invoke',
        'join',
        'json_encode',
        'keys',
        'language_name',
        'last',
        'length',
        'locale_name',
        'lower',
        'map',
        'merge',
        'nl2br',
        'number_format',
        'plural',
        'raw',
        'reduce',
        'replace',
        'resizer',
        'reverse',
        'round',
        'shuffle',
        'singular',
        'slice',
        'slug',
        'sort',
        'spaceless',
        'split',
        'striptags',
        'timezone_name',
        'title',
        'trim',
        'typography',
        'u',
        'upper',
        'url_encode',
    ];

    private static function twig(): Environment
    {
        $sg = new Styleguide([
            'templates_path' => __DIR__ . '/../fixtures/templates',
            'static_path' => __DIR__ . '/../fixtures',
            'config_yaml' => __DIR__ . '/../fixtures/styleguide.yaml',
        ]);

        return (new \ReflectionClass($sg))->getProperty('twig')->getValue($sg);
    }

    /**
     * Names that the installed Twig (its `Twig\Extra` packages) and `parisek/twig-attribute` register.
     * The attribute package adds the `without` filter from 1.7.0 on, so the lists must not name it.
     *
     * @param 'functions'|'filters' $kind
     *
     * @return list<string>
     */
    private static function vendorNames(Environment $twig, string $kind): array
    {
        $names = [];
        foreach ($twig->getExtensions() as $extension) {
            if (!str_starts_with($extension::class, 'Twig\\') && \Parisek\Twig\AttributeExtension::class !== $extension::class) {
                continue;
            }
            $items = 'functions' === $kind ? $extension->getFunctions() : $extension->getFilters();
            foreach ($items as $item) {
                $names[] = $item->getName();
            }
        }

        return $names;
    }

    #[Test]
    public function every_expected_function_is_registered(): void
    {
        $twig = self::twig();
        $actual = array_keys($twig->getFunctions());

        self::assertSame([], array_values(array_diff(self::FUNCTIONS, $actual)), 'A helper function is no longer registered.');
        self::assertSame(
            [],
            array_values(array_diff($actual, self::FUNCTIONS, self::vendorNames($twig, 'functions'))),
            'A function is registered that the lists do not know and no Twig extension provides.',
        );
    }

    #[Test]
    public function every_expected_filter_is_registered(): void
    {
        $twig = self::twig();
        $actual = array_keys($twig->getFilters());

        self::assertSame([], array_values(array_diff(self::FILTERS, $actual)), 'A helper filter is no longer registered.');
        self::assertSame(
            [],
            array_values(array_diff($actual, self::FILTERS, self::vendorNames($twig, 'filters'))),
            'A filter is registered that the lists do not know and no Twig extension provides.',
        );
    }
}
