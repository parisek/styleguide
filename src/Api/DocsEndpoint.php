<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Api;

use Parisek\Styleguide\ComponentParser;

/**
 * @internal Implementation detail of `Styleguide::run()`. Consumer-facing
 *           contract is the HTTP URL (`/styleguide/api/docs`) and its JSON
 *           response shape — see `docs/API.md` § JSON API endpoints.
 *
 * GET /styleguide/api/docs — JSON list of all parsed docs.
 */
final class DocsEndpoint
{
    public function __construct(private ComponentParser $parser) {}

    public function handle(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache');
        echo json_encode(
            $this->parser->parseAll('doc'),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
