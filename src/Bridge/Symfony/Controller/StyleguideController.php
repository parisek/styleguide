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
            // getPathInfo(), NOT getRequestUri(). The latter includes the base
            // URL, so on a deployment without rewrites — `/index.php/styleguide/…`
            // — or in a subdirectory, routing matches (it uses pathInfo) and then
            // Router::parse() is handed `/index.php/styleguide/…`, does not
            // recognise it, and every request 404s. Found by review with a probe,
            // not by the tests.
            //
            // This makes routing correct on those deployments, not the whole
            // catalogue usable on them: the committed dist/index.html asks for
            // `/styleguide/assets/…` at the domain root, so the SPA shell still
            // needs the prefix there. `/api/*`, `/render/*` and `/assets/*`
            // answer correctly when addressed directly. A subdirectory is
            // effectively another mount point — see README § *Symfony bundle*
            // on why the prefix is not configurable.
            //
            // The query string has to be re-attached: Router::parse() reads
            // `?theme=`, `?variant=` and `?locale=` out of the URI itself.
            uri: $this->uri($request),
            cookies: $request->cookies->all(),
            secFetchDest: (string) $request->headers->get('Sec-Fetch-Dest', ''),
            ifNoneMatch: (string) $request->headers->get('If-None-Match', ''),
        ));

        if ($result === null) {
            // The route matched but the package does not recognise the path.
            //
            // Rarer than it looks, and the first version of this comment said
            // otherwise. A typo under the prefix does NOT land here — Router
            // answers an unknown path with the SPA landing, and an unknown
            // `/api/*` endpoint comes back as a 404 result. What reaches this
            // branch is a URI the router cannot parse at all, such as an
            // encoded slash that decodes to the prefix only after matching.
            //
            // The URI is deliberately not in the message: Symfony's production
            // error page never shows it, but the log line already carries the
            // request, so repeating it buys nothing and puts caller-controlled
            // text somewhere it does not need to be.
            throw new NotFoundHttpException('Not a styleguide route.');
        }

        // A file body stays a file. BinaryFileResponse streams it and can use
        // X-Sendfile where the host has it configured, which is the reason
        // Result carries a path rather than the bytes.
        if ($result->file !== null) {
            return new BinaryFileResponse($result->file, $result->status, $result->headers);
        }

        return new Response($result->body, $result->status, $result->headers);
    }

    /**
     * The path the package routes on, with its query string.
     */
    private function uri(Request $request): string
    {
        $query = $request->getQueryString();

        return $request->getPathInfo() . ($query === null ? '' : '?' . $query);
    }
}
