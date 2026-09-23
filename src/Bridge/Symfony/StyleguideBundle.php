<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Bridge\Symfony;

use Parisek\Styleguide\Bridge\Symfony\DependencyInjection\StyleguideExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Optional Symfony integration. The core stays a framework-agnostic library.
 *
 * Nothing in `require` changes: `symfony/framework-bundle` and
 * `symfony/http-kernel` are `require-dev` plus `suggest`, so a WordPress or
 * Drupal consumer never pulls them in. A host application enabling this bundle
 * already has both.
 *
 * Enable it in `config/bundles.php`:
 *
 *     Parisek\Styleguide\Bridge\Symfony\StyleguideBundle::class => ['all' => true],
 *
 * then point it at the project's `styleguide.yaml` in
 * `config/packages/styleguide.yaml` and import the bundle's routes. See README
 * § *Symfony bundle*.
 */
final class StyleguideBundle extends Bundle
{
    /**
     * Cached here rather than in `Bundle::$extension`, which is typed
     * `ExtensionInterface|false|null` so that `??=` cannot narrow it.
     */
    private ?StyleguideExtension $styleguideExtension = null;

    public function getContainerExtension(): ExtensionInterface
    {
        // Returned explicitly rather than discovered by convention: the
        // convention expects `DependencyInjection/<BundleName>Extension`, which
        // would be `StyleguideBundleExtension` here, and the configuration key
        // derived from it would be `styleguide_bundle`. `styleguide` is the key
        // a consumer expects to write.
        return $this->styleguideExtension ??= new StyleguideExtension();
    }
}
