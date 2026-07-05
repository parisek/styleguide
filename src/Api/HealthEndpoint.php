<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Api;

use Parisek\Styleguide\ComponentParser;

/**
 * @internal Implementation detail of `Styleguide::run()`. Consumer-facing
 *           contract is the HTTP URL (`/styleguide/api/health`) and its
 *           JSON response shape — see `docs/API.md` § JSON API endpoints.
 *
 * GET /styleguide/api/health — parse-resilience diagnostics: how many
 * components/pages/docs parsed successfully, and which template files (if
 * any) `ComponentParser` had to skip because they threw while parsing.
 *
 * Deliberately a separate endpoint rather than a `_warnings` field bolted
 * onto the four catalogue endpoints — those each emit a bare JSON array,
 * so there is no additive place to attach a sibling field without
 * breaking every existing consumer of that shape (SPA, external tooling,
 * CI scripts) treating the body as `Component[]`.
 */
final class HealthEndpoint
{
    public function __construct(private ComponentParser $parser) {}

    public function handle(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache');

        $counts = [
            'components' => count($this->parser->parseAll('component')),
            'pages' => count($this->parser->parseAll('page')),
            'docs' => count($this->parser->parseAll('doc')),
        ];

        echo json_encode([
            'warnings' => $this->parser->getWarnings(),
            'counts' => $counts,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
