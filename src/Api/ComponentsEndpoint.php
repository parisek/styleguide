<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Api;

use Parisek\Styleguide\ComponentParser;

/**
 * @internal Implementation detail of `Styleguide::run()`. Consumer-facing
 *           contract is the HTTP URL (`/styleguide/api/components`) and its
 *           JSON response shape — see `docs/API.md` § JSON API endpoints.
 *
 * GET /styleguide/api/components — JSON list of all parsed components.
 */
final class ComponentsEndpoint
{
    public function __construct(private ComponentParser $parser)
    {
    }

    public function handle(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache');
        echo json_encode(
            $this->parser->parseAll('component'),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}
