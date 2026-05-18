<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Api;

use Parisek\Styleguide\ComponentParser;

/**
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
