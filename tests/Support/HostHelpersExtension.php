<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Support;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Stands in for a host extension that defines two names the package also
 * defines: `placeholder()` and `|resizer`. A project's own resizer is the
 * common case.
 */
final class HostHelpersExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('placeholder', static fn(): string => '/host/placeholder.svg')];
    }

    public function getFilters(): array
    {
        return [new TwigFilter('resizer', static fn(string $src): string => '/host/resized/' . $src)];
    }
}
