<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Bridge\Symfony;

use Parisek\Styleguide\Styleguide;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
    /**
     * @param string|null $configPath one fixed `styleguide.yaml`, or null when a resolver picks it per request
     */
    public function __construct(
        private readonly ?string $configPath,
        private readonly ?StyleguideConfigResolverInterface $resolver = null,
    ) {
        if (($configPath === null) === ($resolver === null)) {
            throw new \LogicException('StyleguideFactory needs exactly one of a config path and a config resolver.');
        }
    }

    /**
     * @param string $assetBase The consumer's asset base for this request —
     *        Symfony's `Request::getBasePath()`, which is the framework's
     *        equivalent of the front controller's
     *        `rtrim(dirname($_SERVER['SCRIPT_NAME']), '/')`. Verified equal
     *        across four deployment shapes in `BundleTest`.
     * @param Request|null $request the request the resolver reads; required when a resolver is set
     */
    public function forRequest(string $assetBase, ?Request $request = null): Styleguide
    {
        $configPath = $this->configPath ?? $this->resolve($request);

        // Passed through $overrides, the ONLY route run truth may travel by.
        // twig_context merges key by key, so the project's own homeUrl,
        // frontPageUrl and langcode from the YAML survive alongside it.
        return Styleguide::fromYaml($configPath, [
            'twig_context' => ['templateUrl' => $assetBase],
        ]);
    }

    /**
     * Asks the resolver, every time: the next request may be another catalogue,
     * so nothing is kept.
     */
    private function resolve(?Request $request): string
    {
        if ($this->resolver === null || $request === null) {
            throw new \LogicException('A config resolver needs the request.');
        }

        try {
            $path = $this->resolver->resolve($request);
        } catch (NotFoundHttpException) {
            // A fixed message: the resolver's own may carry the host or a path,
            // and neither belongs in a response.
            throw new NotFoundHttpException('No catalogue answers this request.');
        }

        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(sprintf(
                'styleguide.config_resolver: %s returned "%s", which is not a readable file.',
                $this->resolver::class,
                $path,
            ));
        }

        return $path;
    }
}
