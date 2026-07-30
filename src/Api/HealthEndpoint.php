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
 * IT PARSES METADATA. IT DOES NOT COMPILE OR RENDER ANYTHING — and it says
 * so, in the `checked` field, because the name "health" invites the opposite
 * reading. A template whose body has a fatal Twig error still parses its
 * metadata fine, so it is counted here as a healthy component while every
 * render of it fails. Downstream that combination — `warnings: []`,
 * `components: 67`, and eleven templates that rendered nothing — is exactly
 * how the defect stayed invisible for days.
 *
 * The fix for the gap is NOT to compile here. Since 1.8.0 a broken template
 * makes `/render/component/<id>` return 500 with the real Twig error, so a
 * render sweep IS the check, and it is strictly stronger: it also catches a
 * missing partial, a runtime failure, and the alert fallback, none of which a
 * compile check sees. README § "CI smoke test" has the nine-line recipe.
 * Duplicating a weaker version of it behind an opt-in flag here would add a
 * diagnostic nobody switches on.
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
            // Scope disclosure, deliberately a value rather than prose in the
            // docs: a consumer reading this response can tell what was and was
            // not verified without knowing the package's internals. Additive —
            // existing readers of `warnings`/`counts` are unaffected.
            'checked' => 'metadata',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
