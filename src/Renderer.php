<?php

declare(strict_types=1);

namespace Parisek\Styleguide;

use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\TwigFunction;

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
     * Directory + slug of the component/page/doc CURRENTLY being rendered —
     * set by {@see renderInner()} immediately before each Twig render call,
     * read by {@see resolveStyleguideData()} (the `styleguide_data()` Twig
     * function implementation) at CALL time from inside the template being
     * rendered. `null` outside an active render: before the first render, if
     * `$templatesPath` was never configured, or AFTER a render has completed
     * — {@see renderInner()} resets both back to `null` in a `finally` block
     * once its Twig render call returns (or throws), so a `styleguide_data()`
     * call reaching this class between renders never resolves a stale
     * directory left over from whichever render happened last.
     */
    private ?string $currentKind = null;
    private ?string $currentSlug = null;

    /**
     * @param array<string, mixed> $context
     * @param string|null $templatesPath
     *   Absolute path to the project's `templates_path` (mirrors the value
     *   passed to `Styleguide::__construct(['templates_path' => …])`).
     *   Required for `styleguide_data()` to resolve sidecar
     *   `styleguide.data.yaml` files on disk; `null` (the default, kept for
     *   backward compatibility with direct `new Renderer($twig, $context)`
     *   callers, e.g. existing unit tests) means `styleguide_data()` always
     *   throws — see {@see resolveStyleguideData()}.
     */
    public function __construct(
        private Environment $twig,
        private array $context = [],
        private ?string $templatesPath = null,
    ) {
        $this->registerDataFunction();
    }

    /**
     * Registers the `styleguide_data()` Twig function bound to THIS
     * `Renderer` instance via closure capture, so the callable can read
     * whichever directory is "currently rendering" ({@see $currentKind} /
     * {@see $currentSlug}) at CALL time rather than at registration time —
     * the seam that makes a no-arg `styleguide_data()` call inside ANY
     * component/page/doc fixture resolve to THAT fixture's own sidecar,
     * without needing a fresh Twig function per render.
     *
     * Idempotent-add pattern mirrors `Styleguide::tryAddFunction()` (not
     * reused directly — that method is private to `Styleguide` — but the
     * same reasoning applies here: a project that pre-registers its own
     * `styleguide_data` Twig function, or an env whose extensions are
     * already initialized, must not crash `Renderer` construction).
     */
    private function registerDataFunction(): void
    {
        $renderer = $this;
        try {
            $this->twig->addFunction(new TwigFunction(
                'styleguide_data',
                static fn(?string $name = null): array => $renderer->resolveStyleguideData($name),
            ));
        } catch (\LogicException) {
            // Duplicate function name, or "extensions already initialized"
            // on a shared env — swallow-and-defer, same contract as
            // Styleguide::tryAddFunction() (see that method's doc comment
            // for why the two cases aren't distinguished).
        }
    }

    /**
     * `styleguide_data()` Twig function implementation. `@internal` — only
     * reachable via the closure registered in {@see registerDataFunction()}.
     *
     * Resolves ONE OF POTENTIALLY SEVERAL sidecar files sitting next to the
     * component/page/doc CURRENTLY being rendered ({@see $currentKind} /
     * {@see $currentSlug}, set by {@see renderInner()} immediately before the
     * Twig render call that reaches this function):
     *
     *  - No argument (or `null`) → the DEFAULT set, `styleguide.data.yaml`.
     *  - `$name` given → the NAMED set `styleguide.data-<name>.yaml`, where
     *    `<name>` must match `^[a-z0-9-]+$` — the SAME id rule
     *    `Router::whitelistVariant()` / `Renderer::renderInner()` already use
     *    for `styleguide.<variant>.twig` variant ids, deliberately reused so
     *    the two flat-suffix-naming families (variant `.twig` siblings and
     *    named `.yaml` data sets) stay consistent.
     *
     * Resolution is ALWAYS scoped to the currently-rendering component's own
     * directory — there is no cross-component/cross-slug lookup. A page or
     * component that wants another component's demo data must duplicate it
     * (or the styleguide.yaml/`{% extends %}` data-template escape hatch);
     * this function intentionally never reaches outside `$currentKind` /
     * `$currentSlug`.
     *
     * Two resolution steps run over the parsed YAML, in order:
     *  1. {@see resolvePlaceholders()} — recursively replaces every
     *     `{ placeholder: {...} }` node with the real `Placeholder::generate()`
     *     output (the same shape the Twig `placeholder()` function itself
     *     returns).
     *  2. {@see resolvePaths()} — recursively rebases `src:` / `url:` string
     *     values onto `templateUrl` / `homeUrl` (same rules as
     *     {@see resolveAssetUrl()} already applies to iframe assets / logo
     *     entries).
     *
     * @throws \InvalidArgumentException
     *   When `$name` is the literal string `'default'` — that name is
     *   RESERVED for the no-arg form. Rejected before any filesystem access
     *   (including before the general `^[a-z0-9-]+$` check, though `'default'`
     *   would also pass that regex) so a stray `styleguide.data-default.yaml`
     *   sitting on disk is never loaded by an explicit
     *   `styleguide_data('default')` call — the no-arg form is the only way
     *   to reach the default set.
     * @throws \RuntimeException
     *   When called outside an active render (no `templates_path`
     *   configured, or invoked before any render has run, or after a render
     *   has already completed and cleared its context — see
     *   {@see renderInner()}); when `$name` doesn't match `^[a-z0-9-]+$`;
     *   when the resolved sidecar file doesn't exist on disk — in that case
     *   the message also enumerates whatever `styleguide.data*.yaml` sets
     *   ARE present in the directory (a typo aid), via
     *   {@see describeAvailableDataSets()}, using a path RELATIVE to
     *   `templates_path` (the absolute path is logged via `error_log()`
     *   instead, so it never leaks into rendered 500-page markup); or when
     *   the parsed YAML's top-level node is a bare scalar (a shape that
     *   can't sensibly stand in for the "data" mapping/list the rest of this
     *   pipeline expects). Fixtures are dev-time only, so failing loudly here
     *   — rather than silently returning `[]` — surfaces a typo'd/missing/
     *   malformed-shape sidecar immediately.
     * @throws \Symfony\Component\Yaml\Exception\ParseException
     *   Propagated UNCHANGED from `Yaml::parseFile()` on malformed YAML —
     *   deliberately not wrapped/caught, matching the existing (also
     *   uncaught) contract of `Styleguide::__construct()`'s own
     *   `Yaml::parseFile($config['config_yaml'])` call for the top-level
     *   `styleguide.yaml`. The package doesn't grow a resilience layer here
     *   that it doesn't already have for that file. No `object`/custom-tag
     *   flags are passed to `Yaml::parseFile()`, so `!php/object`-tagged
     *   nodes are never instantiated into real PHP objects (they resolve to
     *   `null`) and arbitrary custom tags (`!mytag …`) throw this same
     *   `ParseException` rather than silently constructing anything.
     *
     * @return array<string, mixed>
     */
    private function resolveStyleguideData(?string $name = null): array
    {
        if ($this->templatesPath === null || $this->currentKind === null || $this->currentSlug === null) {
            throw new \RuntimeException(
                'styleguide_data(): no active render context — this function can only be called '
                . 'while rendering a component/page/doc fixture (styleguide.twig / styleguide.<variant>.twig)',
            );
        }

        if ($name === 'default') {
            throw new \InvalidArgumentException(
                'styleguide_data(): "default" is a reserved data set name — '
                . 'use styleguide_data() for the default set, not styleguide_data(\'default\')',
            );
        }

        if ($name !== null && $name !== '' && preg_match('/^[a-z0-9-]+$/', $name) !== 1) {
            throw new \RuntimeException(sprintf(
                'styleguide_data(): invalid data set name "%s" — must match ^[a-z0-9-]+$ '
                . '(same id rule as styleguide.<variant>.twig variant ids)',
                $name,
            ));
        }

        $dir = rtrim($this->templatesPath, '/') . '/' . $this->currentKind . '/' . $this->currentSlug;
        $filename = ($name === null || $name === '') ? 'styleguide.data.yaml' : sprintf('styleguide.data-%s.yaml', $name);
        $file = $dir . '/' . $filename;
        // Path relative to templates_path — used in the exception messages
        // below so an absolute filesystem path never reaches rendered
        // 500-page markup; the absolute path is still logged server-side.
        $relativeFile = $this->currentKind . '/' . $this->currentSlug . '/' . $filename;

        if (!is_file($file)) {
            error_log(sprintf('styleguide_data(): sidecar file not found: %s', $file));
            throw new \RuntimeException(sprintf(
                'styleguide_data(): sidecar file not found: %s (%s)',
                $relativeFile,
                self::describeAvailableDataSets($dir),
            ));
        }

        $parsed = Yaml::parseFile($file);
        if ($parsed === null) {
            // Empty file, a bare `null`/`~` document, an empty map (`{}`),
            // or an empty list (`[]`) — all treated as "no data" rather than
            // an error; a component whose demo doesn't need any data yet
            // shouldn't be forced to author a placeholder mapping.
            $data = [];
        } elseif (is_array($parsed)) {
            $data = $parsed;
        } else {
            // A bare scalar top-level node (`"hello"`, `42`, `true`, …) can't
            // stand in for the mapping/list this pipeline expects — fail
            // loudly with a message naming the actual shape found, rather
            // than silently coercing to `[]` and masking an authoring
            // mistake (e.g. a stray unindented value at the top of the file).
            error_log(sprintf(
                'styleguide_data(): sidecar top-level node is not a mapping/list: %s (found %s)',
                $file,
                get_debug_type($parsed),
            ));
            throw new \RuntimeException(sprintf(
                'styleguide_data(): expected a YAML mapping or list at the top level of %s, found a bare %s '
                . 'value instead — sidecar files must contain a mapping or list',
                $relativeFile,
                get_debug_type($parsed),
            ));
        }

        $data = self::resolvePlaceholders($data);
        $data = $this->resolvePaths($data);

        return $data;
    }

    /**
     * Builds the "(did you mean one of: …)"-shaped fragment for the
     * missing-sidecar `RuntimeException` message — enumerates whichever
     * `styleguide.data*.yaml` sets actually exist in `$dir`, so a typo'd
     * name (or a missing default when only named sets exist) points
     * straight at what IS available instead of leaving the developer to
     * `ls` the directory themselves.
     */
    private static function describeAvailableDataSets(string $dir): string
    {
        $sets = self::listAvailableDataSets($dir);

        return $sets === []
            ? 'no styleguide.data*.yaml files found in this directory'
            : 'available data sets in this directory: ' . implode(', ', $sets);
    }

    /**
     * Lists the data-set names present in `$dir`, exactly as they'd be
     * passed to `styleguide_data()`: the bare `styleguide.data.yaml` sidecar
     * (if present) is reported as `'default'`; each
     * `styleguide.data-<name>.yaml` sibling is reported as `<name>`.
     * Alphabetically sorted for deterministic, readable error messages.
     *
     * @return list<string>
     */
    private static function listAvailableDataSets(string $dir): array
    {
        $sets = [];
        if (is_file($dir . '/styleguide.data.yaml')) {
            $sets[] = 'default';
        }
        foreach (glob($dir . '/styleguide.data-*.yaml') ?: [] as $path) {
            if (preg_match('/^styleguide\.data-([a-z0-9-]+)\.yaml$/', basename($path), $m) === 1) {
                $sets[] = $m[1];
            }
        }
        sort($sets);

        return $sets;
    }

    /**
     * Recursively resolves `{ placeholder: {...} }` mapping nodes anywhere in
     * a `styleguide.data.yaml` tree into the SAME shape the Twig
     * `placeholder()` function itself returns ({@see Placeholder::generate()}
     * — a one-element list). So:
     *
     *   image:
     *     placeholder:
     *       subject: people
     *       seed: 42
     *
     * resolves to exactly what `image: placeholder({subject: 'people', seed:
     * 42})` would have produced inline in a `.twig` fixture.
     *
     * A node matches only when `placeholder` is its SOLE key — deliberately
     * narrow so a legitimate map that happens to have a sibling key literally
     * named `placeholder` (holding an unrelated shape) isn't misdetected.
     * Runs top-down (checks the current node before recursing into it), so a
     * matched node's own opts (`subject`, `seed`, …) are never themselves
     * walked for further placeholder/path resolution — correct, since those
     * are `Placeholder::generate()` parameters, not further data.
     *
     * Before calling {@see Placeholder::generate()}, `ratio:` is accepted as
     * a YAML-only alias for `aspect:` (see {@see applyRatioAlias()}) — the
     * README/API.md examples write `ratio: "16:9"`, but `Placeholder::
     * generate()` itself only ever reads `aspect`. The alias is resolved
     * HERE (the sidecar path), not inside `Placeholder::generate()` — a
     * direct `placeholder({ratio: …})` call from a `.twig` fixture is
     * unaffected, `ratio:` only has meaning inside a YAML sidecar.
     */
    private static function resolvePlaceholders(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }
        if (count($data) === 1 && array_key_exists('placeholder', $data) && is_array($data['placeholder'])) {
            return Placeholder::generate(self::applyRatioAlias($data['placeholder']));
        }
        $out = [];
        foreach ($data as $key => $value) {
            $out[$key] = self::resolvePlaceholders($value);
        }
        return $out;
    }

    /**
     * Maps a YAML sidecar's `ratio:` key onto `Placeholder::generate()`'s
     * own `aspect:` option before the call.
     *
     * `ratio:` is written `"W:H"` (colon-separated, e.g. `"16:9"`) in every
     * README/API.md example, while `aspect:` (the option `Placeholder::
     * generate()` actually reads) expects a `"W/H"` (slash-separated, e.g.
     * `"3/2"`) string — so the alias also normalises the separator, not just
     * the key name. Without that normalisation `Placeholder::
     * resolveDimensions()`'s `explode('/', $aspect)` would never split a
     * colon-separated value and silently fall back to a bogus 16:1-shaped
     * ratio for an input like `"16:9"`.
     *
     * `aspect:`, when explicitly present alongside `ratio:`, always wins —
     * `ratio:` is dropped either way so it never reaches `Placeholder::
     * generate()`'s own `$opts` (and therefore never shows up in the
     * returned image array's `_placeholderOpts` diagnostic either).
     *
     * @param array<string, mixed> $opts
     * @return array<string, mixed>
     */
    private static function applyRatioAlias(array $opts): array
    {
        if (!array_key_exists('ratio', $opts)) {
            return $opts;
        }
        $ratio = $opts['ratio'];
        unset($opts['ratio']);

        if (!array_key_exists('aspect', $opts) && is_string($ratio)) {
            $opts['aspect'] = str_replace(':', '/', $ratio);
        }

        return $opts;
    }

    /**
     * Recursively rebases `src:` / `url:` string values anywhere in a
     * `styleguide.data.yaml` tree, mirroring the exact rules
     * {@see resolveAssetUrl()} already applies to `iframe.css` /
     * `project.favicon` / `styleguide.logo[*].src`:
     *
     *  - `src:` → rebased onto `$this->context['templateUrl']` (the
     *    consumer's asset base). Absent/empty `templateUrl` → no-op
     *    (standalone layout, byte-for-byte the historical behaviour).
     *  - `url:` → rebased onto `$this->context['homeUrl']` ONLY when that
     *    key is present in the render context as a non-empty string;
     *    otherwise the value is left untouched (never throws).
     *  - Absolute values (scheme incl. `data:`, `/`, `//`) always pass
     *    through unchanged — enforced by `resolveAssetUrl()` itself.
     */
    private function resolvePaths(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }
        $out = [];
        foreach ($data as $key => $value) {
            if ($key === 'src' && is_string($value)) {
                $assetBase = (string) ($this->context['templateUrl'] ?? '');
                $out[$key] = self::resolveAssetUrl($value, $assetBase);
            } elseif ($key === 'url' && is_string($value)) {
                $homeUrl = $this->context['homeUrl'] ?? null;
                $out[$key] = (is_string($homeUrl) && $homeUrl !== '')
                    ? self::resolveAssetUrl($value, $homeUrl)
                    : $value;
            } else {
                $out[$key] = $this->resolvePaths($value);
            }
        }
        return $out;
    }

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
     *   foundations_js_url?:string,
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
        if (!in_array($kind, ['component', 'page', 'doc', 'foundations', 'icons'], true)) {
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
        if (isset($config['styleguide']) && is_array($config['styleguide'])) {
            $config['styleguide'] = self::rebaseAuditAssets($config['styleguide'], $assetBase);
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
            'foundations_js_url' => $config['foundations_js_url'] ?? null,
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
     * Rebase the browser-facing asset paths inside `favicon_audit` /
     * `og_image_audit` onto the consumer asset base.
     *
     * {@see FaviconAudit} and {@see OgImageAudit} are pure filesystem
     * auditors — they resolve the yaml paths against `static_path` on disk
     * and echo the *yaml* value back out. That value is docroot-agnostic by
     * design (`/images/touch/favicon.svg`), so foundations.twig rendering it
     * straight into `<img src>` 404s on every consumer whose static dir is
     * not the docroot (WordPress: `/wp-content/themes/<theme>/static`).
     * Rebasing has to happen here, where `templateUrl` is known — pushing it
     * into the auditors would make them resolve one path for disk and a
     * different one for the browser.
     *
     * Two shapes, deliberately handled differently:
     *  - `favicon_audit.{tab_icon,touch_icon,maskable_icon}` are
     *    browser-only (the mockup `<img>`s) — rebased in place. The
     *    per-entry `entries[*].path` is NOT touched: the template prints it
     *    as a `<code>` echo of what the yaml says, and rebasing it would
     *    show the author a path they never wrote.
     *  - `og_image_audit.path` is dual-use (both `<img src>` and a `<code>`
     *    echo), so instead of mutating it a rebased `url` twin is added.
     *    Always set when `path` is a non-empty string, so the template can
     *    read `og_audit.url` unconditionally.
     *
     * @param array<string, mixed> $styleguide
     *
     * @return array<string, mixed>
     */
    private static function rebaseAuditAssets(array $styleguide, string $base): array
    {
        if (isset($styleguide['favicon_audit']) && is_array($styleguide['favicon_audit'])) {
            foreach (['tab_icon', 'touch_icon', 'maskable_icon'] as $key) {
                $value = $styleguide['favicon_audit'][$key] ?? null;
                if (is_string($value) && $value !== '') {
                    $styleguide['favicon_audit'][$key] = self::resolveAssetUrl($value, $base);
                }
            }
        }

        if (isset($styleguide['og_image_audit']) && is_array($styleguide['og_image_audit'])) {
            $path = $styleguide['og_image_audit']['path'] ?? null;
            if (is_string($path) && $path !== '') {
                $styleguide['og_image_audit']['url'] = self::resolveAssetUrl($path, $base);
            }
        }

        return $styleguide;
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
            'icons' => $this->twig->render('icons.twig', [
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
     *
     * `$currentKind`/`$currentSlug` are reset to `null` in a `finally` block
     * around the Twig render call — regardless of whether the render
     * succeeds or throws — so `styleguide_data()` can never reuse a STALE
     * "currently rendering" directory left over from a previous, already-
     * completed render. Without this reset, a direct `styleguide_data()`
     * invocation on the environment after `render()` has returned would
     * silently resolve the PREVIOUS render's sidecar instead of throwing
     * the "no active render context" `RuntimeException` a truly inactive
     * environment should produce.
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
                // Bind the "currently rendering" directory for
                // styleguide_data() BEFORE calling render() — the Twig
                // function reads $this->currentKind/$currentSlug at CALL
                // time from inside the template about to render.
                $this->currentKind = $kind;
                $this->currentSlug = $slug;
                try {
                    return $this->twig->render($path, $this->context);
                } finally {
                    $this->currentKind = null;
                    $this->currentSlug = null;
                }
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
