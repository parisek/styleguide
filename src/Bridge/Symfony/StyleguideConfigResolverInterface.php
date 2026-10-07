<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Bridge\Symfony;

use Symfony\Component\HttpFoundation\Request;

/**
 * Picks the `styleguide.yaml` that answers one request.
 *
 * For a host application that serves several catalogues from one kernel, for
 * example one per project subdomain. Set `styleguide.config_resolver` to the id
 * of a service that implements this interface. The key is mutually exclusive
 * with `styleguide.config`.
 *
 * The bundle calls the resolver on every request and keeps nothing between
 * requests: no resolved path, no `Styleguide` object.
 *
 * A request value (a host name, a path segment) must NEVER become a path by
 * string work. Look it up in an allowlist, and read the path from the match.
 *
 * Every resolved file must use the same mount as the bundle: the default
 * `/styleguide`. The routes are fixed when the container compiles.
 *
 * @api
 */
interface StyleguideConfigResolverInterface
{
    /**
     * @return string absolute path of the `styleguide.yaml` for this request
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException when no catalogue answers this request.
     *         The bundle answers 404 with a fixed message. It drops the message of this exception, so that no host
     *         name or path reaches the response.
     */
    public function resolve(Request $request): string;
}
