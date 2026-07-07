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
     *   body_class?:string,
     *   foundations_css_url?:string,
     *   variant?:string,
     * } $config
     *   Resolved configuration from styleguide.yaml (project + iframe sections
     *   plus, for the foundations kind, the full yaml under `styleguide`).
     *   `variant`, when present and syntactically valid, is threaded into
     *   {@see self::renderInner()} to prefer a `styleguide.<variant>.twig`
     *   sibling over the default `styleguide.twig` — see that method for the
     *   full resolution order and fallback contract.
     * @param string $theme
     *   `'light'` or `'dark'` — stamps `class="dark"` + a matching
     *   `color-scheme` on the rendered `<html>`. Callers should route the
     *   raw (query-string-sourced) value through {@see Router::whitelistTheme()}
     *   first; this method re-coerces anyway as a defensive fallback.
     */
    public function render(string $kind, string $slug, array $config, string $langcode = 'en', string $theme = 'light'): string
    {
        if (!in_array($kind, ['component', 'page', 'doc', 'foundations'], true)) {
            return $this->render404($kind, $slug, $config);
        }

        // Consumer asset base (`twig_context.templateUrl`) — '' standalone, the
        // theme static web path under WordPress / Drupal. Every consumer-supplied
        // asset path the package emits is rebased onto it via resolveAssetUrl()
        // so styleguide.yaml can keep short, docroot-agnostic paths. Resolved up
        // here (before renderBody) because the foundations body reads
        // styleguide.logo; the iframe css/js/fonts are rebased further down after
        // list-normalisation. The SPA shell favicon is rebased separately in
        // Styleguide::dispatchSpa (that path doesn't go through this renderer).
        $assetBase = (string) ($this->context['templateUrl'] ?? '');
        if (isset($config['project']['favicon']) && is_string($config['project']['favicon'])) {
            $config['project']['favicon'] = self::resolveAssetUrl($config['project']['favicon'], $assetBase);
        }
        if (isset($config['styleguide']['logo']) && is_array($config['styleguide']['logo'])) {
            foreach ($config['styleguide']['logo'] as $key => $entry) {
                if (is_array($entry) && isset($entry['src']) && is_string($entry['src'])) {
                    $config['styleguide']['logo'][$key]['src'] = self::resolveAssetUrl($entry['src'], $assetBase);
                }
            }
        }

        try {
            $body = $this->renderBody($kind, $slug, $config);
            if ($body === null) {
                return $this->render404($kind, $slug, $config);
            }
        } catch (\Throwable $e) {
            // A component/page that throws during render used to return HTTP 200
            // with error markup — a health check or CI smoke test polling
            // `/render/component/<id>` would see "success" for a broken
            // component. The error markup itself stays visible (still useful for
            // local dev — the whole point of NOT swallowing it into a generic
            // "something went wrong" page).
            http_response_code(500);
            $body = $this->errorMarkup($e);
        }

        // `iframe.css` / `iframe.fonts` accept a single URL string or a list of
        // URLs in styleguide.yaml. Normalise both to a list here so the template
        // (render-cell.twig) can simply loop. Backward compatible with the
        // historical shapes (`css: <string>`, `fonts: [<list>]`).
        $iframe = $config['iframe'] ?? [];
        if (!is_array($iframe)) {
            $iframe = [];
        }
        $iframe['css'] = self::normaliseStylesheets($iframe['css'] ?? []);
        $iframe['fonts'] = self::normaliseStylesheets($iframe['fonts'] ?? []);

        // Rebase relative iframe asset URLs onto the consumer's asset base
        // (`twig_context.templateUrl`) so `styleguide.yaml` can keep a short,
        // docroot-agnostic path like `/dist/css/style.css`. Standalone layouts
        // pass an empty templateUrl → URLs are returned untouched (the historical
        // behaviour); WordPress/Drupal pass the theme's static web path
        // (`/wp-content/themes/<theme>/static`) → the same `/dist/...` value
        // resolves to the real file instead of 404-ing at the domain root.
        // See self::resolveAssetUrl() for the (backward-compatible) rules.
        // ($assetBase computed once at the top of render().)
        $iframe['css'] = array_map(static fn(string $u): string => self::resolveAssetUrl($u, $assetBase), $iframe['css']);
        $iframe['fonts'] = array_map(static fn(string $u): string => self::resolveAssetUrl($u, $assetBase), $iframe['fonts']);
        if (isset($iframe['js']) && is_string($iframe['js']) && trim($iframe['js']) !== '') {
            $iframe['js'] = self::resolveAssetUrl($iframe['js'], $assetBase);
        }

        return $this->twig->render('render-cell.twig', [
            'kind' => $kind,
            'slug' => $slug,
            'langcode' => $langcode,
            // Callers other than Styleguide::dispatchRender() (notably tests,
            // and any future direct Renderer use) may pass an unwhitelisted
            // string — re-coerce defensively rather than trusting the caller,
            // same rationale as ComponentParser::normaliseRender().
            'theme' => $theme === 'dark' ? 'dark' : 'light',
            'project' => $config['project'] ?? [],
            'iframe' => $iframe,
            'component' => [
                'id' => $slug,
                'name' => $config['component_name'] ?? $slug,
                // Re-normalise defensively: callers other than Styleguide.php
                // (notably tests) may pass an unvalidated string. ComponentParser
                // owns the canonical list, so we route the coercion through it.
                'render' => ComponentParser::normaliseRender($config['render'] ?? null),
                // Optional per-entry <body> class — merged after the global
                // `iframe.body_class` in render-cell.twig.
                'body_class' => $config['body_class'] ?? '',
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
            $value = trim($value) === '' ? [] : [$value];
        } elseif (!is_array($value)) {
            $value = [];
        }

        $urls = [];
        foreach ($value as $url) {
            // Drop non-strings (e.g. nested arrays) and blank / whitespace-only
            // entries so they never render an empty `<link href="">`.
            if (is_string($url) && trim($url) !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Rebase a single iframe asset URL (css / js / font stylesheet) onto the
     * consumer's asset base — the `twig_context.templateUrl` the styleguide
     * bootstrap derives from where its front controller lives:
     *   - standalone (static dir IS the docroot): templateUrl = ''  → no-op
     *   - WordPress:  '/wp-content/themes/<theme>/static'
     *   - Drupal:     '/themes/custom/<theme>/static'
     *
     * This lets `styleguide.yaml` keep a short, docroot-agnostic path
     * (`/dist/css/style.css`) that resolves correctly in every layout instead
     * of hardcoding the theme path per project.
     *
     * Backward compatible — left untouched when:
     *   - base is empty (standalone) — historical behaviour, byte-for-byte
     *   - the URL is external / protocol-relative / data: / an anchor
     *   - the URL is already under the base (a consumer that hardcoded the full
     *     theme path keeps working — no double prefix)
     */
    public static function resolveAssetUrl(string $url, string $base): string
    {
        $base = rtrim($base, '/');
        if ($base === '' || $url === '') {
            return $url;
        }
        // External (`https:`, `mailto:`…), protocol-relative (`//cdn…`),
        // `data:` URIs, and in-page anchors are never rebased.
        if (
            str_starts_with($url, '#')
            || str_starts_with($url, '//')
            || preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1
        ) {
            return $url;
        }
        // Already rooted under the asset base — don't double-prefix.
        if ($url === $base || str_starts_with($url, $base . '/')) {
            return $url;
        }
        // Root-relative or bare-relative project asset — rebase onto the base.
        return $base . '/' . ltrim($url, '/');
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
            'component', 'page', 'doc' => $this->renderInner(
                $kind,
                $slug,
                is_string($config['variant'] ?? null) ? $config['variant'] : null,
            ),
            'foundations' => $this->twig->render('foundations.twig', [
                'styleguide' => $config['styleguide'] ?? [],
            ] + $this->context),
            default => null,
        };
    }

    /**
     * Look up the actual component/page Twig template and render it with the
     * styleguide context.
     *
     * Resolution order: the requested variant sibling (only when `$variant`
     * is non-null AND syntactically valid) → the default `styleguide.twig`
     * demo file → the component's own `<slug>.twig`. Same convention as the
     * legacy TwigStyleguide renderer, extended with the variant preference
     * on top.
     *
     * The regex re-check is defensive, not redundant: `Renderer` is
     * unit-tested and can be called directly (bypassing `Router::
     * whitelistVariant()` entirely), so an invalid/malformed `$variant`
     * must never reach the candidate list — it degrades to exactly the
     * no-variant case, same as an unknown-but-well-formed id that has no
     * matching file. Either way a deleted/renamed/mistyped variant never
     * 404s a bookmarked deep link.
     */
    private function renderInner(string $kind, string $slug, ?string $variant = null): ?string
    {
        $loader = $this->twig->getLoader();
        $namespace = '@project/' . $kind . '/' . $slug;

        $candidates = [];
        if ($variant !== null && preg_match('/^[a-z0-9-]+$/', $variant) === 1) {
            $candidates[] = $namespace . '/styleguide.' . $variant . '.twig';
        }
        $candidates[] = $namespace . '/styleguide.twig';
        $candidates[] = $namespace . '/' . $slug . '.twig';

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
