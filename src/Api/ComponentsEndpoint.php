<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Api;

use Parisek\Styleguide\ComponentParser;
use Parisek\Styleguide\Http\Result;

/**
 * @internal Implementation detail of `Styleguide::run()`. Consumer-facing
 *           contract is the HTTP URL (`/styleguide/api/components`) and its
 *           JSON response shape — see `docs/API.md` § JSON API endpoints.
 *
 * GET /styleguide/api/components — JSON list of all parsed components.
 */
final class ComponentsEndpoint
{
    public function __construct(private ComponentParser $parser) {}

    /**
     * @see \Parisek\Styleguide\Http\Result — describes the response rather
     *      than writing it, so a host application can serve this endpoint from
     *      its own stack. `Styleguide::run()` emits the result unchanged.
     */
    public function handle(): Result
    {
        return Result::json((string) json_encode(
            $this->parser->parseAll('component'),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }
}
