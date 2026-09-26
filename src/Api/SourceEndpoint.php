<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Api;

use Parisek\Styleguide\Http\Result;
use Twig\Loader\LoaderInterface;

/**
 * @internal Implementation detail of `Styleguide::run()`. Consumer-facing
 *           contract is the HTTP URL (`/styleguide/api/source/<kind>/<slug>`)
 *           and its JSON response shape — see `docs/API.md` § JSON API endpoints.
 *
 * GET /styleguide/api/source/<kind>/<slug>[?variant=<id>] — the source of the
 * fixture file that renders one variant tile: `styleguide.<id>.twig`, or
 * `styleguide.twig` without a variant. The SPA's "Code" toggle shows it.
 *
 * Only the two fixture names, never the component's own `<slug>.twig`: the
 * fixture is the call a reader copies, the template is the implementation.
 *
 * `Styleguide::dispatchApi()` only builds this endpoint when `show_source` is
 * on. With it off, the route answers as an unknown endpoint.
 */
final class SourceEndpoint
{
    private const KINDS = ['component', 'page', 'doc'];

    public function __construct(private LoaderInterface $loader) {}

    public function handle(string $kind, string $slug, ?string $variant): Result
    {
        // The slug goes into a template name, so it is held to the id
        // characters a directory name in the catalogue actually uses.
        if (!in_array($kind, self::KINDS, true) || preg_match('/^[A-Za-z0-9_-]+$/', $slug) !== 1) {
            return self::notFound();
        }

        $file = $kind . '/' . $slug . '/' . ($variant === null ? 'styleguide.twig' : 'styleguide.' . $variant . '.twig');
        // The same `@project` lookup Renderer::renderInner() renders through,
        // so the source shown is the file that rendered the tile.
        $name = '@project/' . $file;
        if (!$this->loader->exists($name)) {
            return self::notFound();
        }

        return Result::json((string) json_encode(
            [
                'kind' => $kind,
                'slug' => $slug,
                'variant' => $variant,
                'file' => $file,
                'source' => self::stripLeadingComment($this->loader->getSourceContext($name)->getCode()),
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * Drops the first `{# … #}` block, and the blank lines after it, only
     * when it opens the file. That block is the metadata annotation (`title:`,
     * `description:`); a comment further down is part of the example.
     */
    public static function stripLeadingComment(string $code): string
    {
        return (string) preg_replace('/\A\s*\{#.*?#\}(?:[ \t]*\R)*/s', '', $code, 1);
    }

    private static function notFound(): Result
    {
        return Result::text(
            (string) json_encode(['error' => 'No fixture source for this entry']),
            404,
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }
}
