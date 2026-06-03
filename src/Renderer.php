<?php

declare(strict_types=1);

namespace Parisek\Styleguide;

use Twig\Environment;

/**
 * @internal Implementation detail of `Styleguide::run()` dispatch path.
 *           Signature and behaviour can change in any minor release. The
 *           rendered output (HTML shape served at `/styleguide/render/...`)
 *           is loosely contractual via the URL surface in `docs/API.md`.
 *
 * Renders a single component or page into a full HTML document for iframe embedding.
 *
 * The HTML structure (`<!DOCTYPE>`, `<head>` with project CSS/JS/fonts, `<body>`)
 * lives in the package's `templates/render-cell.twig`. The component/page content
 * comes from the project's templates (loaded via the `@project` Twig namespace).
 *
 * @see render-cell.twig
 */
final class Renderer
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        private Environment $twig,
        private array $context = [],
    ) {}

    /**
     * Render a component / page / foundations view into a full HTML
     * document for iframe embedding.
     *
     * @param array{
     *   project?:array<string,mixed>,
     *   iframe?:array<string,mixed>,
     *   styleguide?:array<string,mixed>,
     *   component_name?:string,
     *   render?:string,
     *   foundations_css_url?:string,
     * } $config
     *   Resolved configuration from styleguide.yaml (project + iframe sections
     *   plus, for the foundations kind, the full yaml under `styleguide`).
     */
    public function render(string $kind, string $slug, array $config, string $langcode = 'en'): string
    {
        if (!in_array($kind, ['component', 'page', 'foundations'], true)) {
            return $this->render404($kind, $slug, $config);
        }

        try {
            $body = $this->renderBody($kind, $slug, $config);
            if ($body === null) {
                return $this->render404($kind, $slug, $config);
            }
        } catch (\Throwable $e) {
            $body = $this->errorMarkup($e);
        }

        // `iframe.css` / `iframe.fonts` accept a single URL string or a list of
        // URLs in styleguide.yaml. Normalise both to a list here so the template
        // (render-cell.twig) can simply loop. Backward compatible with the
        // historical shapes (`css: <string>`, `fonts: [<list>]`).
        $iframe = $config['iframe'] ?? [];
        $iframe['css'] = self::normaliseStylesheets($iframe['css'] ?? []);
        $iframe['fonts'] = self::normaliseStylesheets($iframe['fonts'] ?? []);

        return $this->twig->render('render-cell.twig', [
            'kind' => $kind,
            'slug' => $slug,
            'langcode' => $langcode,
            'project' => $config['project'] ?? [],
            'iframe' => $iframe,
            'component' => [
                'id' => $slug,
                'name' => $config['component_name'] ?? $slug,
                // Re-normalise defensively: callers other than Styleguide.php
                // (notably tests) may pass an unvalidated string. ComponentParser
                // owns the canonical list, so we route the coercion through it.
                'render' => ComponentParser::normaliseRender($config['render'] ?? null),
            ],
            'body' => $body,
            'foundations_css_url' => $config['foundations_css_url'] ?? null,
        ]);
    }

    /**
     * Normalise an `iframe.css` / `iframe.fonts` value to a list of URL strings.
     *
     * Accepts a single URL string or a list of URLs (the two shapes allowed in
     * `styleguide.yaml`). Empty input becomes an empty list; non-string entries
     * and empty strings inside a list are dropped. Order is preserved.
     *
     * @return list<string>
     */
    public static function normaliseStylesheets(mixed $value): array
    {
        if (is_string($value)) {
            $value = $value === '' ? [] : [$value];
        } elseif (!is_array($value)) {
            $value = [];
        }

        $urls = [];
        foreach ($value as $url) {
            if (is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Dispatch the inner body render by kind. Components / pages render the
     * project's own template; foundations renders the package-shipped
     * template against the full styleguide.yaml.
     * @param array<string, mixed> $config
     */
    private function renderBody(string $kind, string $slug, array $config): ?string
    {
        return match ($kind) {
            'component', 'page' => $this->renderInner($kind, $slug),
            'foundations' => $this->twig->render('foundations.twig', [
                'styleguide' => $config['styleguide'] ?? [],
            ] + $this->context),
            default => null,
        };
    }

    /**
     * Look up the actual component/page Twig template and render it with the styleguide context.
     *
     * Prefers `styleguide.twig` (the visual demo file) over the main component template
     * — same convention as the legacy TwigStyleguide renderer.
     */
    private function renderInner(string $kind, string $slug): ?string
    {
        $loader = $this->twig->getLoader();
        $namespace = '@project/' . $kind . '/' . $slug;

        $candidates = [
            $namespace . '/styleguide.twig',
            $namespace . '/' . $slug . '.twig',
        ];

        foreach ($candidates as $path) {
            if ($loader->exists($path)) {
                return $this->twig->render($path, $this->context);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function render404(string $kind, string $slug, array $config): string
    {
        http_response_code(404);
        return $this->twig->render('styleguide-404.twig', [
            'kind' => $kind,
            'slug' => $slug,
            'project' => $config['project'] ?? [],
        ]);
    }

    private function errorMarkup(\Throwable $e): string
    {
        return '<div style="padding:20px;color:#dc2626;font-family:ui-monospace,monospace;border:1px solid #fecaca;background:#fef2f2;border-radius:4px">'
            . '<strong>Render error:</strong><br>'
            . htmlspecialchars($e->getMessage())
            . '</div>';
    }
}
