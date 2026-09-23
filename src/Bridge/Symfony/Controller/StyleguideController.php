<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Bridge\Symfony\Controller;

use Parisek\Styleguide\Http\Request as StyleguideRequest;
use Parisek\Styleguide\Styleguide;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the catalogue from the host application's own stack.
 *
 * Thin by design. Everything it does is translate between Symfony's request and
 * response objects and the package's own — which is the whole reason
 * {@see Styleguide::handle()} exists. If this class ever grows logic, that
 * logic belongs in the core instead, where the library and micro-kernel
 * consumers can reach it too.
 *
 * **Security is the host's, entirely** — and that is enforced by something that
 * was already true rather than by a check this bundle adds. `auth` is in
 * `Styleguide::RUN_TRUTH_KEYS`, so `fromYaml()` refuses it outright, and the DI
 * extension builds the service through `fromYaml()`. A bundle consumer therefore
 * has no way to set it.
 *
 * Which is the right outcome. Two gates that can disagree are worse than one:
 * `auth: null` means "allow", so a host trusting the internal hook would have
 * left the catalogue open, and an iframe request is rewritten from an SPA route
 * to a render route before that hook ever runs. Put the catalogue behind a
 * firewall — the README section says how.
 */
final class StyleguideController
{
    public function __construct(private readonly Styleguide $styleguide) {}

    public function __invoke(Request $request): Response
    {
        $result = $this->styleguide->handle(new StyleguideRequest(
            uri: $request->getRequestUri(),
            cookies: $request->cookies->all(),
            secFetchDest: (string) $request->headers->get('Sec-Fetch-Dest', ''),
            ifNoneMatch: (string) $request->headers->get('If-None-Match', ''),
        ));

        if ($result === null) {
            // The route matched but the package does not recognise the path.
            // Reachable because the route is a catch-all under the prefix —
            // `/styleguide` itself and every `/styleguide/*` — while
            // `Router::parse()` is stricter. A 404 from the host is the honest
            // answer; returning an empty 200 would let a CI smoke test pass on
            // a typo'd URL.
            throw new NotFoundHttpException('Not a styleguide route: ' . $request->getRequestUri());
        }

        // A file body stays a file. BinaryFileResponse streams it and can use
        // X-Sendfile where the host has it configured, which is the reason
        // Result carries a path rather than the bytes.
        if ($result->file !== null) {
            return new BinaryFileResponse($result->file, $result->status, $result->headers);
        }

        return new Response($result->body, $result->status, $result->headers);
    }
}
