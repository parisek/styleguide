<?php

declare(strict_types=1);

use Parisek\Styleguide\Bridge\Symfony\Controller\StyleguideController;
use Parisek\Styleguide\Bridge\Symfony\DependencyInjection\StyleguideExtension;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Import this from the host's routing:
 *
 *     styleguide:
 *         resource: '@StyleguideBundle/Resources/config/routes.php'
 *         type: php
 *
 * Two routes, not one. `/styleguide` and `/styleguide/{path}` are separate
 * because a single pattern with an optional trailing segment does not match the
 * bare prefix cleanly, and the bare prefix is a real URL — `Router::parse()`
 * answers it with the SPA landing.
 *
 * `{path}` is a catch-all (`.+`) on purpose. The SPA routes with the history
 * API, so refreshing `/styleguide/component/card` must reach this controller
 * and get `index.html` back; a segment-limited pattern would 404 on exactly the
 * deep links people bookmark and paste.
 *
 * The prefix is read from the extension's constant rather than written here, so
 * the two cannot drift.
 */
return static function (RoutingConfigurator $routes): void {
    $prefix = StyleguideExtension::SUPPORTED_PREFIX;

    $routes->add('styleguide_root', $prefix)
        ->controller(StyleguideController::class)
        ->methods(['GET', 'HEAD']);

    $routes->add('styleguide', $prefix . '/{path}')
        ->controller(StyleguideController::class)
        ->requirements(['path' => '.+'])
        ->methods(['GET', 'HEAD']);
};
