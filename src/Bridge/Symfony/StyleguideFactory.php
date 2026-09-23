<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Bridge\Symfony;

use Parisek\Styleguide\Styleguide;

/**
 * Builds one `Styleguide` per request, carrying that request's asset base.
 *
 * A container service cannot hold the value the catalogue needs. `templateUrl`
 * — the base every `iframe.css`, `iframe.js`, `iframe.fonts[]`, favicon and
 * logo path is rebased onto — is run truth: `Styleguide::RUN_TRUTH_KEYS`
 * forbids it in the YAML precisely because it is correct for exactly one
 * request. The bundle's DI extension runs when the container COMPILES, where
 * there is no request to read it from, so a service built there can only ever
 * carry an empty base.
 *
 * Empty is right for a host served from the domain root and wrong everywhere
 * else. A Symfony application serving a theme through a rewrite —
 * `/wp-content/themes/<theme>/static`, `/themes/custom/<theme>/static`, or
 * simply an install in a subdirectory — would have got asset URLs pointing at
 * the domain root with no way to correct them, because the bundle exposes no
 * key for it. That was the gap this class closes.
 *
 * Building per request costs one YAML parse and one Twig environment: about a
 * millisecond, measured, against roughly seven for the cheapest real request.
 * For a developer tool that is the right trade, and it removes the question of
 * shared mutable state from the bundle entirely — each request gets its own
 * instance, exactly as the library front controller has always done.
 */
final class StyleguideFactory
{
    public function __construct(private readonly string $configPath) {}

    /**
     * @param string $assetBase The consumer's asset base for this request —
     *        Symfony's `Request::getBasePath()`, which is the framework's
     *        equivalent of the front controller's
     *        `rtrim(dirname($_SERVER['SCRIPT_NAME']), '/')`. Verified equal
     *        across four deployment shapes in `BundleTest`.
     */
    public function forRequest(string $assetBase): Styleguide
    {
        // Passed through $overrides, the ONLY route run truth may travel by.
        // twig_context merges key by key, so the project's own homeUrl,
        // frontPageUrl and langcode from the YAML survive alongside it.
        return Styleguide::fromYaml($this->configPath, [
            'twig_context' => ['templateUrl' => $assetBase],
        ]);
    }
}
