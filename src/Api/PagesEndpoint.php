<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Api;

use Parisek\Styleguide\ComponentParser;

/**
 * @internal Implementation detail of `Styleguide::run()`. Consumer-facing
 *           contract is the HTTP URL (`/styleguide/api/pages`) and its JSON
 *           response shape — see `docs/API.md` § JSON API endpoints.
 *
 * GET /styleguide/api/pages — JSON list of all parsed pages.
 */
final class PagesEndpoint
{
    public function __construct(private ComponentParser $parser)
    {
    }

    public function handle(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache');
        echo json_encode(
            $this->parser->parseAll('page'),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}
