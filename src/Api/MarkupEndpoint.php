<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Api;

use Parisek\Styleguide\Http\Result;
use Parisek\Styleguide\Renderer;

/**
 * @internal Implementation detail of `Styleguide::run()`. Consumer-facing
 *           contract is the HTTP URL (`/styleguide/api/markup/<kind>/<slug>`)
 *           and its JSON response shape — see `docs/API.md` § JSON API endpoints.
 *
 * GET /styleguide/api/markup/<kind>/<slug>[?variant=<id>] — the HTML one
 * variant tile renders, without the iframe document around it. The SPA's
 * Code panel shows it next to the fixture source: the source is the call a
 * reader copies, the markup is what that call produces. Built only when
 * `show_source` is on, like SourceEndpoint.
 */
final class MarkupEndpoint
{
    private const KINDS = ['component', 'page', 'doc'];

    public function __construct(private Renderer $renderer) {}

    public function handle(string $kind, string $slug, ?string $variant): Result
    {
        if (!in_array($kind, self::KINDS, true) || preg_match('/^[A-Za-z0-9_-]+$/', $slug) !== 1) {
            return self::notFound();
        }

        try {
            $html = $this->renderer->renderMarkup($kind, $slug, $variant);
        } catch (\Throwable $e) {
            // The preview itself reports a broken template; here the panel
            // only says there is nothing to show.
            error_log(sprintf('styleguide: markup of %s/%s failed: %s', $kind, $slug, $e->getMessage()));
            $html = null;
        }
        if ($html === null) {
            return self::notFound();
        }

        return Result::json((string) json_encode(
            [
                'kind' => $kind,
                'slug' => $slug,
                'variant' => $variant,
                'html' => self::tidy($html),
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    /**
     * Twig leaves a blank line for every tag and indents the output as the
     * template nests, not as the HTML does. The panel shows markup to read,
     * so it is laid out again: blank lines dropped, every line trimmed, runs
     * of spaces inside a line collapsed, and each line indented one tab per
     * open element. Line breaks stay where Twig put them. Markup with
     * `<pre>` or `<textarea>`, where whitespace is content, only loses its
     * blank lines and common indentation.
     */
    public static function tidy(string $html): string
    {
        $lines = array_values(array_filter(
            array_map(static fn(string $line): string => rtrim($line), preg_split('/\R/', $html) ?: []),
            static fn(string $line): bool => trim($line) !== '',
        ));
        if ($lines === []) {
            return '';
        }

        if (preg_match('/<(pre|textarea)\b/i', $html) === 1) {
            $indent = min(array_map(
                static fn(string $line): int => strlen($line) - strlen(ltrim($line, " \t")),
                $lines,
            ));

            return implode("\n", array_map(static fn(string $line): string => substr($line, $indent), $lines)) . "\n";
        }

        $depth = 0;
        $out = [];
        foreach ($lines as $line) {
            $line = (string) preg_replace('/[ \t]{2,}/', ' ', trim($line));
            // A line that opens by closing an element sits at that element's depth.
            $lead = preg_match('#^</[A-Za-z]#', $line) === 1 ? 1 : 0;
            $out[] = str_repeat("\t", max(0, $depth - $lead)) . $line;
            $depth = max(0, $depth + self::depthChange($line));
        }

        return implode("\n", $out) . "\n";
    }

    /**
     * Opened minus closed elements in one line: void and self-closed tags
     * and comments do not count.
     */
    private static function depthChange(string $line): int
    {
        $line = (string) preg_replace('/<!--.*?-->/s', '', $line);
        preg_match_all('#<(/?)([A-Za-z][A-Za-z0-9-]*)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*?(/?)>#', $line, $tags, PREG_SET_ORDER);
        $change = 0;
        foreach ($tags as [, $closing, $name, $selfClosing]) {
            if (in_array(strtolower($name), self::VOID, true) || $selfClosing === '/') {
                continue;
            }
            $change += $closing === '/' ? -1 : 1;
        }

        return $change;
    }

    private static function notFound(): Result
    {
        return Result::text(
            (string) json_encode(['error' => 'No markup for this entry']),
            404,
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }
}
