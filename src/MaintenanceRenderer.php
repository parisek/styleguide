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

    public function __construct(private Styleguide $styleguide) {}

    /**
     * @param string $css Stylesheet source to inline, already read from disk.
     * @param string $langcode Two-letter code for the document's `lang` attribute.
     */
    public function render(string $css, string $langcode): string
    {
        if (!$this->styleguide->hasTemplate(self::PAGE_TEMPLATE)) {
            throw new \RuntimeException(sprintf(
                'The project has no %s — the outage screen has nothing to render.',
                self::PAGE_TEMPLATE,
            ));
        }

        return $this->styleguide->renderTemplate($this->template(), [
            'stylesheet' => self::stripFontFaces($css),
            'langcode' => $langcode,
        ]);
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
     * Removes every `@font-face` rule from a stylesheet.
     *
     * Each one names a file this document cannot fetch. Dropping the rules
     * makes the fallback stack the deliberate choice rather than the result of
     * a failed download — and saves the browser the failing requests.
     *
     * `@font-face` bodies carry no nested braces, so a non-greedy match to the
     * first closing brace is exact rather than approximate.
     */
    public static function stripFontFaces(string $css): string
    {
        return (string) preg_replace('/@font-face\s*\{[^}]*\}/', '', $css);
    }
}
