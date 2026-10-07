<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Bridge\Symfony;

use Parisek\Styleguide\Bridge\Symfony\StyleguideFactory;
use Parisek\Styleguide\Tests\Support\UrlExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `StyleguideFactory` is public API. The constructor keeps the positional
 * order of the previous release: config path, then Twig extensions. A later
 * feature appends its parameter; it never moves one.
 */
final class StyleguideFactoryPositionalTest extends TestCase
{
    private const YAML = __DIR__ . '/../../fixtures/twig-extensions/styleguide.yaml';

    #[Test]
    public function the_path_and_the_extensions_still_work_by_position(): void
    {
        $factory = new StyleguideFactory(self::YAML, [new UrlExtension()]);

        $sg = $factory->forRequest('');

        self::assertStringContainsString('href="/about"', $sg->renderTemplate('@component/linked/linked.twig'));
    }

    #[Test]
    public function the_resolver_is_the_last_parameter(): void
    {
        $names = array_map(
            static fn(\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(StyleguideFactory::class, '__construct'))->getParameters(),
        );

        self::assertSame(['configPath', 'twigExtensions', 'resolver'], $names);
    }
}
