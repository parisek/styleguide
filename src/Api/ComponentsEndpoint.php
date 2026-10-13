<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Api;

use Parisek\Styleguide\ComponentParser;
use Parisek\Styleguide\ComponentSections;
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
    public function __construct(
        private ComponentParser $parser,
        private ?ComponentSections $sections = null,
    ) {}

    /**
     * @see \Parisek\Styleguide\Http\Result — describes the response rather
     *      than writing it, so a host application can serve this endpoint from
     *      its own stack. `Styleguide::run()` emits the result unchanged.
     */
    public function handle(): Result
    {
        $components = $this->parser->parseAll('component');
        // `components.sections`: each entry names its section, so the SPA and
        // the CLI never repeat the matching rules. Absent: the payload is
        // what it was before the key existed.
        if ($this->sections !== null) {
            foreach ($components as $index => $component) {
                $components[$index]['section'] = $this->sections->resolve($component)['id'];
            }
        }

        return Result::json((string) json_encode(
            $components,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }
}
