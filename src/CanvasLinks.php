<?php

declare(strict_types=1);

namespace Parisek\Styleguide;

/**
 * Rewrites the `href` of links in a render, in two cases.
 *
 * {@see self::rewrite()} keeps navigation inside canvas mode.
 * {@see self::anchorFragments()} keeps a fragment-only link in its own
 * document when the render carries a `<base>`.
 *
 * Canvas mode.
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
 * Fragment links. `iframe.base_href` puts a `<base>` into every render, so a
 * relative asset URL resolves inside the iframe. A browser resolves a bare
 * `#b` against that base too, so with `base_href: "/"` the link opens the
 * site root instead of scrolling. On the live site the same markup has no
 * `<base>` and scrolls. Each fragment-only `href` therefore gets the render's
 * own URL in front, and resolves to the same document again. `href="#"` is
 * left alone: it names no target and is a common JavaScript hook.
 *
 * The scan is a small tokenizer, not a parser: it knows comments and the
 * elements whose content the browser does not parse as markup (`script`,
 * `style`, `textarea`, `title`, `xmp`, `iframe`, `noembed`, `noframes`,
 * `noscript` with scripting on, and `plaintext` to the end of the document), and the quoting rules of attributes, so a `>` or an `href=`
 * inside a quoted value is not a tag boundary or an attribute. A full HTML
 * parser would re-serialise the whole document; this changes one value.
 *
 * @internal
 */
final class CanvasLinks
{
    /**
     * Comments and raw-text elements, kept as they are, or an `<a>` start tag.
     * An attribute value may hold `>` when it is quoted. An unterminated
     * comment or raw-text element runs to the end, as in a browser.
     */
    private const TOKEN = '~(?<keep><!--.*?(?:-->|\z)|<(script|style|textarea|title|xmp|iframe|noembed|noframes|noscript)(?=[\s/>])(?:[^>"\']|"[^"]*"|\'[^\']*\')*>.*?(?:</\2\s*>|\z)|<plaintext(?=[\s/>]).*)|<a(?=[\s/>])(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~is';

    /** One attribute of a start tag: name, then an optional value in any of the three HTML forms. */
    private const ATTRIBUTE = '~([^\s"\'>/=]+)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?~';

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
        $base = rtrim($base, '/');

        return self::rewriteHrefs(
            $html,
            static fn(string $href): ?string => self::renderUrl($href, $base, $carry),
        );
    }

    /**
     * Prefix each fragment-only link (`#b`) with the URL of its own render.
     *
     * @param string $html The render output.
     * @param string $document The path and query of the render as the browser
     *        requested it, with no fragment, e.g.
     *        `/styleguide/render/doc/intro?variant=b`. The browser compares it
     *        with the document URL, so a link to it scrolls and does not reload.
     */
    public static function anchorFragments(string $html, string $document): string
    {
        return self::rewriteHrefs(
            $html,
            static function (string $href) use ($document): ?string {
                // A browser strips leading and trailing ASCII whitespace from a URL.
                $href = trim($href, " \t\n\f\r");

                return strlen($href) > 1 && $href[0] === '#' ? $document . $href : null;
            },
        );
    }

    /**
     * @param \Closure(string): ?string $map The new `href` for a decoded
     *        `href` value, or null to keep the tag byte for byte.
     */
    private static function rewriteHrefs(string $html, \Closure $map): string
    {
        // On a PCRE failure (backtrack limit on a pathological document) the
        // render goes out unchanged: an unchanged link beats an empty page.
        return preg_replace_callback(
            self::TOKEN,
            static fn(array $token): string => ($token['keep'] ?? '') !== ''
                ? $token[0]
                : self::anchor($token[0], $map),
            $html,
        ) ?? $html;
    }

    /**
     * @param \Closure(string): ?string $map
     */
    private static function anchor(string $tag, \Closure $map): string
    {
        // Attributes start after `<a`; the closing `>` or `/>` is not one.
        $inner = substr($tag, 2, -1);
        preg_match_all(self::ATTRIBUTE, $inner, $attributes, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);

        $href = null;
        foreach ($attributes as $attribute) {
            $name = strtolower($attribute[1][0]);
            if ($name === 'data-sg-chrome') {
                return $tag;
            }
            // The first `href` counts, as in a browser.
            if ($name === 'href' && $href === null) {
                $href = $attribute;
            }
        }
        if ($href === null || !isset($href[2])) {
            return $tag;
        }

        [$raw, $offset] = $href[2];
        $quoted = $raw[0] === '"' || $raw[0] === "'";
        $value = html_entity_decode($quoted ? substr($raw, 1, -1) : $raw, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $url = $map($value);
        if ($url === null) {
            return $tag;
        }

        $quote = $quoted ? $raw[0] : '"';
        $replacement = $quote . htmlspecialchars($url, \ENT_QUOTES | \ENT_HTML5, 'UTF-8') . $quote;

        return substr($tag, 0, 2) . substr_replace($inner, $replacement, $offset, strlen($raw)) . '>';
    }

    /**
     * The canvas render URL for an entry link, or null for any other URL.
     *
     * @param array<string, string> $carry
     */
    private static function renderUrl(string $href, string $base, array $carry): ?string
    {
        $parts = parse_url($href);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return null;
        }
        $entry = '#^' . preg_quote($base, '#') . '/(component|page|doc)/([A-Za-z0-9_-]+)/?$#';
        if (preg_match($entry, (string) ($parts['path'] ?? ''), $route) !== 1) {
            return null;
        }

        parse_str((string) ($parts['query'] ?? ''), $own);
        unset($own['canvas']);
        $query = ['canvas' => '1'] + $own + $carry;

        return $base . '/render/' . $route[1] . '/' . $route[2]
            . '?' . http_build_query($query, '', '&', \PHP_QUERY_RFC3986)
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }
}
