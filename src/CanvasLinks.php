<?php

declare(strict_types=1);

namespace Parisek\Styleguide;

/**
 * Keeps navigation inside canvas mode.
 *
 * A render opened with `?canvas` is a clean, full-viewport document, but the
 * links it contains point at SPA routes: `Renderer::resolvePaths()` rebases a
 * data `url:` onto `homeUrl`, and templates build `homeUrl ~ 'page/…'`. As a
 * top-level document, `/styleguide/page/about` loads the whole chrome, so the
 * first click left canvas mode. Inside an iframe the same link is fine:
 * {@see Router::synthesizeEmbeddedRoute()} swaps it for a render on
 * `Sec-Fetch-Dest: iframe`. A top-level document sends no such signal, so the
 * swap happens here, in the output, before the browser ever follows the link.
 *
 * Only the `href` of an `<a>` whose path is `<base>/(component|page|doc)/<slug>`
 * changes. External, `mailto:`/`tel:`, fragment-only, asset and other catalogue
 * links (overview, foundations, render) pass through byte for byte, and so does
 * a link marked `data-sg-chrome`: the back-bar's own link to the SPA.
 *
 * @internal
 */
final class CanvasLinks
{
    /** An `<a>` start tag. */
    private const ANCHOR = '/<a\b[^>]*>/i';

    /**
     * A double- or single-quoted `href` attribute. The leading boundary keeps
     * `data-href` and similar out.
     */
    private const HREF = '/(?<=[\s"\'])(href\s*=\s*)(["\'])(.*?)\2/is';

    /**
     * Rewrite the entry links of a canvas render.
     *
     * @param string $html The render output.
     * @param string $base The catalogue base of this request (host base path
     *        plus mount, no trailing slash), e.g. `/styleguide`.
     * @param array<string, string> $carry Query values every rewritten link
     *        gets unless it sets them itself, e.g. `['locale' => 'ar']`.
     *        `canvas` is always added first.
     */
    public static function rewrite(string $html, string $base, array $carry = []): string
    {
        $entry = '#^' . preg_quote(rtrim($base, '/'), '#') . '/(component|page|doc)/([A-Za-z0-9_-]+)/?$#';

        $rewriteHref = static function (array $m) use ($entry, $base, $carry): string {
            $value = html_entity_decode($m[3], \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
            $parts = parse_url($value);
            if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
                return $m[0];
            }
            if (preg_match($entry, (string) ($parts['path'] ?? ''), $route) !== 1) {
                return $m[0];
            }

            parse_str((string) ($parts['query'] ?? ''), $own);
            unset($own['canvas']);
            $query = ['canvas' => '1'] + $own + $carry;

            $url = rtrim($base, '/') . '/render/' . $route[1] . '/' . $route[2]
                . '?' . http_build_query($query, '', '&', \PHP_QUERY_RFC3986)
                . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');

            return $m[1] . $m[2] . htmlspecialchars($url, \ENT_QUOTES | \ENT_HTML5, 'UTF-8') . $m[2];
        };

        return (string) preg_replace_callback(
            self::ANCHOR,
            static fn(array $tag): string => stripos($tag[0], 'data-sg-chrome') !== false
                ? $tag[0]
                : (string) preg_replace_callback(self::HREF, $rewriteHref, $tag[0], 1),
            $html,
        );
    }
}
