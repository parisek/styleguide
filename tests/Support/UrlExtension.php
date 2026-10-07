<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Support;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

/**
 * Stands in for a host's own Twig extension: one function (`url()`) and one
 * global (`build_url`), the two ways a host extension reaches a template.
 */
final class UrlExtension extends AbstractExtension implements GlobalsInterface
{
    public function getFunctions(): array
    {
        return [new TwigFunction('url', static fn(string $id): string => '/' . $id)];
    }

    public function getGlobals(): array
    {
        return ['build_url' => '/build'];
    }
}
