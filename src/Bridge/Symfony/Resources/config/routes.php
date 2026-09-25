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
 * `/styleguide` and `/styleguide/` are BOTH served, by one route with an
 * optional trailing-slash placeholder. Two separate routes cannot do it in
 * either order: Symfony's redirectable matcher tolerates a trailing-slash
 * difference on the first route that nearly matches and answers a 301 there
 * and then, never reaching the route that matches exactly. Declare the bare
 * prefix first and `/styleguide/` redirects; swap them and `/styleguide`
 * does. An optional `{path}` fails the same way from the other side, because
 * it makes `/styleguide` the canonical form.
 *
 * It matters because the library front controller served both directly, and
 * `/styleguide/` is the URL people bookmark and the one the SPA's history
 * base is written with. Reading the routing file would not have shown this;
 * the response header naming
 * `FrameworkBundle\Controller\RedirectController` did.
 *
 * The mount is the `styleguide.base_url` container parameter, which the
 * extension sets from styleguide.yaml's `bootstrap.base_url` (default
 * `/styleguide`). Symfony's router resolves `%parameter%` in route paths, so
 * the host's import stays the same whatever the mount is.
 */
return static function (RoutingConfigurator $routes): void {
    // The mount, resolved by the router from the container parameter the
    // extension sets from styleguide.yaml's bootstrap.base_url.
    $prefix = '%' . StyleguideExtension::BASE_URL_PARAMETER . '%';

    // One route for `/styleguide` AND `/styleguide/`, not two.
    //
    // Two routes cannot work in either order. Symfony's redirectable matcher
    // tolerates a trailing-slash difference on the FIRST route that nearly
    // matches and returns a 301 there and then, without trying the route that
    // matches exactly. So with `/styleguide` declared first, `/styleguide/`
    // redirects; swap them and `/styleguide` redirects instead. An optional
    // `{path}` has the same problem from the other side.
    //
    // An optional trailing-slash placeholder is the shape that matches both
    // exactly, so neither redirects.
    $routes->add('styleguide_root', $prefix . '{slash}')
        ->controller(StyleguideController::class)
        ->requirements(['slash' => '/?'])
        ->defaults(['slash' => ''])
        ->methods(['GET', 'HEAD']);

    $routes->add('styleguide', $prefix . '/{path}')
        ->controller(StyleguideController::class)
        ->requirements(['path' => '.+'])
        ->methods(['GET', 'HEAD']);
};
