<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Api;

use Parisek\Styleguide\ComponentParser;

/**
 * GET /styleguide/api/fields — aggregated fields metadata across all components.
 *
 * Returns an array of `{component_id, component_name, fields[]}` objects so the SPA's
 * fields-inspector view can flatten fields from many components into one searchable list.
 */
final class FieldsEndpoint
{
    public function __construct(private ComponentParser $parser)
    {
    }

    public function handle(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache');

        $components = $this->parser->parseAll('component');
        $output = [];
        foreach ($components as $c) {
            if (empty($c['fields'])) {
                continue;
            }
            $output[] = [
                'component_id' => $c['id'],
                'component_name' => $c['name'],
                'fields' => $c['fields'],
            ];
        }

        echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
