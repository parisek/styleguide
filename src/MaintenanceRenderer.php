<?php

declare(strict_types=1);

namespace Parisek\Styleguide;

/**
 * Renders the outage screen into one self-contained HTML document.
 *
 * A CMS shows a fallback screen exactly when it cannot render one. WordPress
 * reads `.maintenance` in `wp_maintenance()` before plugins and theme load,
 * and reaches `wp-content/db-error.php` with no database at all; Drupal has
 * its own equivalents. In every such state the theme, the template engine and
 * the translation catalogue are unavailable, so the screen has to exist as a
 * finished file before the outage starts.
 *
 * That constraint is what shapes this class:
 *
 * - the stylesheet is inlined, because a `<link>` would resolve through a web
 *   server that may be the thing that is down;
 * - `@font-face` rules are stripped, because a request guaranteed to fail
 *   delays the first paint of the one screen that must appear at once;
 * - the caller writes the result to disk once and commits it.
 *
 * The project supplies the screen (`page/maintenance/maintenance.twig`) and,
 * optionally, the document around it. Everything mechanical lives here.
 */
final class MaintenanceRenderer
{
    /**
     * Where the rendered file lands, relative to `templates_path`.
     *
     * Next to the component it renders, not in a build directory: the
     * artefact is committed and reviewed like any other file of that
     * component, and whoever edits the template sees the stale output in the
     * same listing.
     */
    public const OUTPUT_RELATIVE = 'component/maintenance/maintenance.html';

    /**
     * The screen itself, supplied by the project.
     *
     * Checked before rendering, because a missing page does not throw:
     * `page_*()` logs and substitutes an alert block, which would produce a
     * file that looks rendered and shows an error banner during the one
     * outage it exists for.
     */
    public const PAGE_TEMPLATE = '@page/maintenance/maintenance.twig';

    /**
     * Document shell shipped with this package. Renders `page_maintenance()`
     * inside a minimal HTML document and neutralises the brand webfont.
     */
    public const PACKAGE_TEMPLATE = 'maintenance-document.twig';

    /**
     * Project override. A file at `<templates_path>/maintenance-document.twig`
     * wins over the packaged shell — the escape hatch for a project whose
     * outage screen needs markup the default cannot express.
     */
    public const PROJECT_TEMPLATE = '@project/maintenance-document.twig';

    /**
     * Bumped when a change to this class alters the rendered output.
     *
     * The fingerprint below is built from the project's own files; a package
     * upgrade that changes the shell or the CSS handling would otherwise leave
     * every committed screen looking current while rendering differently. That
     * exact miss happened once (1.13.0 moved the shell's layout inline), which
     * is why the version is part of the input rather than an afterthought.
     */
    public const RENDERER_VERSION = 1;

    /**
     * How the fingerprint is written into the rendered file, and found again.
     */
    public const FINGERPRINT_MARKER = '@screen-fingerprint';

    public function __construct(private Styleguide $styleguide, private string $templatesPath = '') {}

    /**
     * Fingerprint of everything that decides what the screen SAYS.
     *
     * Deliberately not a hash of the output: the output also carries the
     * project's compiled stylesheet, so a fingerprint over it would go stale
     * on every unrelated CSS change and turn a staleness check into a chore
     * every pull request has to pay. What it covers is the screen's content
     * and structure — the templates, the catalogue, the shell, this class's
     * own output version.
     *
     * The trade is explicit and worth stating where a reader will meet it: a
     * design-token change that alters the screen's colour or type does NOT
     * invalidate the fingerprint. Re-render after touching tokens.
     */
    public function fingerprint(string $locale = ''): string
    {
        $parts = [ 'renderer:' . self::RENDERER_VERSION ];

        foreach ($this->fingerprintFiles($locale) as $label => $path) {
            $parts[] = $label . ':' . (is_file($path) ? hash_file('sha256', $path) : 'absent');
        }

        return substr(hash('sha256', implode("\n", $parts)), 0, 32);
    }

    /**
     * Files the fingerprint is built from, keyed by a stable label.
     *
     * Sorted by label, so the order can never depend on the filesystem's.
     *
     * @return array<string, string>
     */
    private function fingerprintFiles(string $locale): array
    {
        $files = [];
        $root  = rtrim($this->templatesPath, '/');

        if ('' !== $root) {
            foreach (['component/maintenance', 'page/maintenance'] as $dir) {
                foreach (glob($root . '/' . $dir . '/*.{twig,yaml,yml}', GLOB_BRACE) ?: [] as $path) {
                    // The rendered .html lives in one of these directories and
                    // is the thing being checked — hashing it into its own
                    // fingerprint would make every file agree with itself.
                    $files[$dir . '/' . basename($path)] = $path;
                }
            }

            $override = $root . '/maintenance-document.twig';
            if (is_file($override)) {
                $files['shell'] = $override;
            }
        }

        if (!isset($files['shell'])) {
            $files['shell'] = __DIR__ . '/../templates/' . self::PACKAGE_TEMPLATE;
        }

        if ('' !== $locale) {
            $files['catalogue'] = $root . '/../translations/' . $locale . '.mo';
        }

        ksort($files);

        return $files;
    }

    /**
     * Reads the fingerprint out of a rendered file, or null when absent.
     */
    public static function fingerprintOf(string $html): ?string
    {
        return preg_match('/' . preg_quote(self::FINGERPRINT_MARKER, '/') . ' ([0-9a-f]{32})/', $html, $m) === 1
            ? $m[1]
            : null;
    }

    /**
     * @param string $css Stylesheet source to inline, already read from disk.
     * @param string $langcode Two-letter code for the document's `lang` attribute.
     */
    public function render(string $css, string $langcode, string $locale = ''): string
    {
        if (!$this->styleguide->hasTemplate(self::PAGE_TEMPLATE)) {
            throw new \RuntimeException(sprintf(
                'The project has no %s — the outage screen has nothing to render.',
                self::PAGE_TEMPLATE,
            ));
        }

        $html = $this->styleguide->renderTemplate($this->template(), [
            'stylesheet' => self::selfContain($css),
            'langcode' => $langcode,
        ]);

        // Appended after </html> rather than inserted into the shell: a
        // comment before the doctype puts old browsers into quirks mode, and
        // a project overriding the shell must not have to remember to carry
        // the marker.
        return rtrim($html, "\n") . sprintf(
            "\n<!-- %s %s -->\n",
            self::FINGERPRINT_MARKER,
            $this->fingerprint($locale),
        );
    }

    /**
     * Which shell this render uses — the project's, when it has one.
     */
    public function template(): string
    {
        return $this->styleguide->hasTemplate(self::PROJECT_TEMPLATE)
            ? self::PROJECT_TEMPLATE
            : self::PACKAGE_TEMPLATE;
    }

    /**
     * Makes a stylesheet safe to inline into a page served during an outage.
     *
     * Two passes, because a page with no server behind it must issue no
     * request at all:
     *
     * 1. every `@font-face` rule goes — each names a file this document
     *    cannot fetch, and a request guaranteed to fail delays the first
     *    paint of the one screen that must appear at once;
     * 2. every remaining `url()` that is not a `data:` URI becomes `none` —
     *    background images, cursors and the odd vendor spinner reach for the
     *    same unreachable server. `none` is valid wherever an image is, and
     *    the declarations that carried them are decoration on a page whose
     *    job is one heading and one sentence.
     */
    public static function selfContain(string $css): string
    {
        return self::stripExternalUrls(self::stripFontFaces($css));
    }

    /**
     * Removes every `@font-face` rule from a stylesheet.
     *
     * Case-insensitive: `@FONT-FACE` is the same at-rule to a browser, and a
     * case-sensitive match let one through into a shipped artefact.
     *
     * The rule body is scanned rather than matched to the first `}`, because
     * a `src:` value can carry a quoted data URI containing a brace — where
     * a non-greedy match cuts the rule in half and leaves its tail behind as
     * stray CSS.
     */
    public static function stripFontFaces(string $css): string
    {
        $out = '';
        $offset = 0;
        while (($start = self::findAtRule($css, '@font-face', $offset)) !== null) {
            $brace = strpos($css, '{', $start);
            if ($brace === false) {
                break;
            }
            $end = self::endOfBlock($css, $brace);
            if ($end === null) {
                break;
            }
            $out .= substr($css, $offset, $start - $offset);
            $offset = $end + 1;
        }

        return $out . substr($css, $offset);
    }

    /**
     * Rewrites every non-`data:` `url()` value to `none`.
     *
     * The quoting forms all appear in minified output: `url(x)`, `url('x')`,
     * `url("x")`. A `data:` URI is left alone — it is the payload, not a
     * request.
     */
    public static function stripExternalUrls(string $css): string
    {
        return (string) preg_replace_callback(
            '/url\(\s*(["\']?)(.*?)\1\s*\)/is',
            static fn(array $m): string => str_starts_with(ltrim($m[2]), 'data:') ? $m[0] : 'none',
            $css,
        );
    }

    /**
     * Offset of an at-rule name, matched case-insensitively, or null.
     *
     * Comments and quoted strings are skipped, so the name only matches where
     * a browser would read it as an at-rule. A plain `stripos()` also matched
     * it inside a CSS comment — and the caller then took the next opening brace
     * it could find, which belongs to an unrelated rule, and deleted that rule
     * instead. The default `--css` inlines the unminified stylesheet, which is
     * exactly the one that still carries comments.
     */
    private static function findAtRule(string $css, string $name, int $offset): ?int
    {
        $length = strlen($css);
        $needle = strlen($name);
        $quote = null;
        for ($i = $offset; $i < $length; $i++) {
            $char = $css[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            // Comments are tested BEFORE quotes, and the order is the whole
            // point: a comment is opaque, so an apostrophe inside one ("the
            // browser's own default", "don't rely on this") is prose, not a
            // string delimiter. Testing quotes first opened a string that
            // never closed and swallowed the rest of the stylesheet, so every
            // later @font-face went unseen — the exact inversion of the bug
            // this scanner was written to fix. A real Tailwind build carries
            // such a comment; the fixtures did not.
            if ($char === '/' && substr($css, $i, 2) === '/*') {
                $close = strpos($css, '*/', $i + 2);
                if ($close === false) {
                    return null;
                }
                $i = $close + 1;
                continue;
            }
            // Outside a string, a backslash escapes the next character —
            // CSS's own identifier-escape rule. Tailwind leans on it hard:
            // `content-[""]` compiles to the selector
            // `.before\:content-\[\"\"\]`, where the quotes are escaped
            // characters, not delimiters. Skipping the escape is what stops
            // one of them opening a string that runs to the end of the file
            // and hides every @font-face after it.
            if ($char === '\\') {
                $i++;
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === '@' && strncasecmp(substr($css, $i, $needle), $name, $needle) === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Offset of the `}` closing the block that opens at `$brace`.
     *
     * Skips over quoted strings so a brace inside a value cannot end the
     * block early, and honours escapes outside them for the same reason
     * {@see self::findAtRule()} does — an escaped quote in a selector must
     * not open a string. Returns null when the stylesheet is truncated.
     */
    private static function endOfBlock(string $css, int $brace): ?int
    {
        $depth = 0;
        $quote = null;
        $length = strlen($css);
        for ($i = $brace; $i < $length; $i++) {
            $char = $css[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '\\') {
                $i++;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }
}
