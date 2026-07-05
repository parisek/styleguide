# Phase 2: Backend Robustness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the six backend-robustness gaps identified in the Styleguide 2.0 design review (`docs/superpowers/specs/2026-07-04-storybook-lite-2.0-design.md`, § Phase 2): implement the documented-but-missing `?theme=` render param, make render errors and parser failures observable instead of silently returning `200`, stop matching Twig exception text by string, harden a couple of small `AssetServer`/foundations-CSS edge cases, add an optional programmatic `auth` gate, and reconcile `docs/API.md`/`README.md`/`CHANGELOG.md` with what the code actually does.

**Architecture:** No new subsystems. Every task is a targeted, additive change inside the existing `src/` classes (`Router`, `Renderer`, `ComponentParser`, `Styleguide`, `AssetServer`) plus one new internal API endpoint class (`src/Api/HealthEndpoint.php`) and one new Vue SPA affordance (iframe-theme toggle) on top of the Phase 1 rewrite.

**Tech Stack:** PHP 8.3, Twig 3.27, Symfony YAML 6/7/8, PHPUnit 11/12, PHPStan level 8 (this repo's backend); Vue 3 + Pinia + Vite + Vitest (Phase 1 frontend, assumed already merged).

## Global Constraints

- Full backward compatibility: every change is additive — new optional query param (`theme`), new optional constructor config key (`auth`), new optional JSON endpoint (`/api/health`), new optional YAML/route fields. No existing consumer-observable shape loses a field or changes a default.
- PHP: PSR-12, `declare(strict_types=1)` at the top of every file, `final` classes by default.
- PHPStan level 8 must stay green — run `composer phpstan` after any `src/` change.
- TDD: write the failing test first, run `composer test` (or the narrower `vendor/bin/phpunit --filter <name>`) to see it fail, then implement, then confirm green.
- Docs are updated in the **same task** as the surface they document (AGENTS.md doc gate) — not deferred to a follow-up.
- Every task appends its own bullet under `CHANGELOG.md` → `[Unreleased]` in the same task.
- Docs and comments are English; no emoji anywhere.
- Assumes **Phase 1 (Vue 3 + Pinia SPA rewrite) is already merged**. The two SPA-side subtasks (Task 1 iframe-theme toggle, Task 3 health-warning indicator) are written against the Vue frontend under `frontend/src/` per the Phase 1 design (`stores/ui.js`, `stores/catalog.js`, `components/*.vue`). Those exact filenames don't exist yet in this checkout (today's frontend is still Alpine under `frontend/components/*.js` + `frontend/stores/*.js`) — when Phase 1 has actually landed, locate the real equivalents first (e.g. today's iframe-`src` getter lives in `frontend/components/preview.js:512-533`; today's persisted chrome-theme store is `frontend/stores/theme.js`) and adapt the paths/snippets below to match. The acceptance criteria (toggle exists, persists, appends `?theme=` to the iframe `src`, has a Vitest test) do not change.

### Task 1: Implement `?theme=light|dark` on the render endpoint

**Files:**
- `src/Router.php` — `parse()` (currently strips the query string at line 34 via `strtok`), new `whitelistTheme()` helper, `synthesizeEmbeddedRoute()` (lines 105-121)
- `src/Renderer.php` — `render()` signature (line 49), `renderBody`/template context (lines 110-129)
- `src/Styleguide.php` — `dispatchRender()` (lines 1040-1090)
- `templates/render-cell.twig` — `<html>` tag (line 3), `<style>` block (lines 20-29)
- `tests/RouterTest.php`, `tests/RendererTest.php`
- `README.md` (§ URL surface, line 225 — verify wording), `docs/API.md` (§ URL surface table, § Twig functions unaffected)
- `CHANGELOG.md`
- (SPA) `frontend/src/stores/ui.js`, `frontend/src/components/ViewportToolbar.vue` or wherever the iframe `src` is built (today: `frontend/components/preview.js:512`), `frontend/src/stores/ui.spec.js` or equivalent

**Interfaces:**
- Consumes: `GET /styleguide/render/<kind>/<slug>?theme=light|dark`
- Produces: `Router::parse(string $uri): ?array` — for `type === 'render'` entries only, adds `'theme' => 'light'|'dark'`. `Router::whitelistTheme(mixed $raw): string` (new, `public static`, mirrors `ComponentParser::normaliseRender()`). `Renderer::render(string $kind, string $slug, array $config, string $langcode = 'en', string $theme = 'light'): string`.

Router is `@internal` (see `docs/API.md` § "Other PHP classes & methods — `@internal`") — its return-array shape is not itself SemVer-covered, so widening/changing it (and updating the handful of `assertSame(...)` tests that pin the exact shape) is in scope for this task, not a compatibility break.

- [x] Add the whitelist helper to `Router` with a failing test first.
  - Add to `tests/RouterTest.php`:
    ```php
    #[Test]
    public function whitelist_theme_accepts_dark_and_defaults_everything_else_to_light(): void
    {
        self::assertSame('dark', Router::whitelistTheme('dark'));
        self::assertSame('light', Router::whitelistTheme('light'));
        self::assertSame('light', Router::whitelistTheme(null));
        self::assertSame('light', Router::whitelistTheme(''));
        self::assertSame('light', Router::whitelistTheme('DARK')); // case-sensitive, no normalisation guesswork
        self::assertSame('light', Router::whitelistTheme(['dark'])); // never trust raw — non-string is rejected
    }
    ```
  - Run `vendor/bin/phpunit --filter whitelist_theme` — fails (`Call to undefined method Router::whitelistTheme()`).
  - Implement in `src/Router.php` (add near `synthesizeEmbeddedRoute`):
    ```php
    /**
     * Whitelist an arbitrary (query-string-sourced, therefore untrusted) theme
     * value down to one of the two values `render-cell.twig` understands.
     * Anything else — missing, wrong case, an array from a malformed query
     * string — falls back to `'light'`, the historical (pre-feature) render
     * output, so a bad/absent `?theme=` never surfaces as broken markup.
     */
    public static function whitelistTheme(mixed $raw): string
    {
        return $raw === 'dark' ? 'dark' : 'light';
    }
    ```
  - Run `vendor/bin/phpunit --filter whitelist_theme` — passes.

- [x] Wire the whitelist into `parse()` for `render` routes only, with failing tests first.
  - Add to `tests/RouterTest.php`:
    ```php
    #[Test]
    public function render_route_carries_whitelisted_theme_from_query_string(): void
    {
        self::assertSame(
            ['type' => 'render', 'kind' => 'component', 'slug' => 'hero', 'theme' => 'dark'],
            Router::parse('/styleguide/render/component/hero?theme=dark'),
        );
        self::assertSame(
            ['type' => 'render', 'kind' => 'component', 'slug' => 'hero', 'theme' => 'light'],
            Router::parse('/styleguide/render/component/hero?theme=neon'), // invalid → default
        );
        self::assertSame(
            ['type' => 'render', 'kind' => 'component', 'slug' => 'hero', 'theme' => 'light'],
            Router::parse('/styleguide/render/component/hero'), // absent → default
        );
    }
    ```
  - Update the existing `parses_render_endpoint` test (it currently asserts the array *without* `theme` — must gain the key now that every `render`-type route always carries one):
    ```php
    #[Test]
    public function parses_render_endpoint(): void
    {
        self::assertSame(
            ['type' => 'render', 'kind' => 'component', 'slug' => 'hero', 'theme' => 'light'],
            Router::parse('/styleguide/render/component/hero'),
        );
        self::assertSame(
            ['type' => 'render', 'kind' => 'page', 'slug' => 'homepage', 'theme' => 'light'],
            Router::parse('/styleguide/render/page/homepage'),
        );
    }
    ```
  - Run `vendor/bin/phpunit --filter RouterTest` — fails on both the new test (no `theme` key yet) and the updated existing one (mismatch either way until implemented).
  - Implement in `src/Router.php::parse()` — capture the query string before it's discarded, and attach `theme` only on the `render` branch:
    ```php
    public static function parse(string $uri): ?array
    {
        // Captured before strtok() below discards it — only the `render`
        // branch consumes it (theme only matters for iframe HTML output).
        $queryString = (string) (strpos($uri, '?') !== false ? substr($uri, strpos($uri, '?') + 1) : '');

        // Strip query string and trailing slash
        $uri = (string) strtok($uri, '?');
        $uri = rtrim($uri, '/');

        if ($uri === '/styleguide') {
            return ['type' => 'landing'];
        }

        if (!str_starts_with($uri, '/styleguide/')) {
            return null;
        }

        $path = substr($uri, strlen('/styleguide/'));
        $parts = explode('/', $path);

        // /styleguide/assets/<path...>
        if ($parts[0] === 'assets') {
            return ['type' => 'asset', 'path' => implode('/', array_slice($parts, 1))];
        }

        // /styleguide/render/<kind>/<slug>
        if ($parts[0] === 'render' && count($parts) >= 3) {
            parse_str($queryString, $query);
            return [
                'type' => 'render',
                'kind' => $parts[1],
                'slug' => $parts[2],
                'theme' => self::whitelistTheme($query['theme'] ?? null),
            ];
        }

        // ... rest unchanged
    }
    ```
  - Run `vendor/bin/phpunit --filter RouterTest` — all green.

- [x] Give `synthesizeEmbeddedRoute()`'s synthesized `render` output the same shape (no query-string signal is available there, so it always defaults), with a failing test first.
  - Update the three existing `synthesize_embedded_swaps_*` tests in `tests/RouterTest.php` to expect `'theme' => 'light'` in their expected arrays, e.g.:
    ```php
    #[Test]
    public function synthesize_embedded_swaps_component_route_for_render(): void
    {
        self::assertSame(
            ['type' => 'render', 'kind' => 'component', 'slug' => 'hero', 'theme' => 'light'],
            Router::synthesizeEmbeddedRoute(
                ['type' => 'component', 'slug' => 'hero'],
                'iframe',
            ),
        );
    }
    ```
    (Same edit for `synthesize_embedded_swaps_page_route_for_render`, `synthesize_embedded_swaps_foundations_with_index_slug`, `synthesize_embedded_swaps_doc_route_for_render`.)
  - Run `vendor/bin/phpunit --filter RouterTest` — fails (missing key on the synthesized route).
  - Implement in `src/Router.php::synthesizeEmbeddedRoute()` — add the key to the returned array:
    ```php
    return [
        'type' => 'render',
        'kind' => $route['type'],
        'slug' => $route['slug'] ?? 'index',
        // No query-string signal survives past Router::parse() by this point
        // (the SPA-shell route it's swapping from never carried a `theme` —
        // only `render`-type routes read the query string). Default to
        // 'light', matching Renderer's own default for an absent theme.
        'theme' => 'light',
    ];
    ```
  - Run `vendor/bin/phpunit --filter RouterTest` — all green. Also update the return-type PHPDoc on both `parse()` and `synthesizeEmbeddedRoute()` to add `theme?:string`.

- [x] Thread `theme` through `Renderer::render()` and `render-cell.twig`, with failing tests first.
  - Add to `tests/RendererTest.php`:
    ```php
    #[Test]
    public function theme_dark_stamps_dark_class_and_color_scheme(): void
    {
        $html = $this->renderer->render('component', 'sample', [
            'project' => ['name' => 'TestProject'],
            'iframe' => ['css' => '/dist/style.css'],
        ], 'cs', 'dark');

        self::assertStringContainsString('<html lang="cs" class="dark">', $html);
        self::assertStringContainsString('color-scheme: dark', $html);
    }

    #[Test]
    public function theme_light_is_the_default_and_omits_the_dark_class(): void
    {
        $html = $this->renderer->render('component', 'sample', [
            'project' => ['name' => 'TestProject'],
            'iframe' => ['css' => '/dist/style.css'],
        ], 'cs'); // theme omitted → default

        self::assertStringContainsString('<html lang="cs">', $html);
        self::assertStringNotContainsString('class="dark"', $html);
        self::assertStringContainsString('color-scheme: light', $html);
    }

    #[Test]
    public function theme_dark_combines_with_an_existing_iframe_html_class(): void
    {
        $html = $this->renderer->render('component', 'sample', [
            'project' => ['name' => 'TestProject'],
            'iframe' => ['css' => '/dist/style.css', 'html_class' => 'notranslate'],
        ], 'cs', 'dark');

        self::assertStringContainsString('<html lang="cs" class="notranslate dark">', $html);
    }
    ```
  - Run `vendor/bin/phpunit --filter RendererTest` — fails (no `theme` param yet, extra args error).
  - Implement in `src/Renderer.php` — widen the signature and pass `theme` into the template context:
    ```php
    public function render(string $kind, string $slug, array $config, string $langcode = 'en', string $theme = 'light'): string
    {
        // ... unchanged up to the twig->render() call ...

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
                // ... unchanged ...
            ],
            'body' => $body,
            'foundations_css_url' => $config['foundations_css_url'] ?? null,
        ]);
    }
    ```
  - Update `templates/render-cell.twig`:
    ```twig
    <html lang="{{ langcode|default('en')|e }}"{{ create_attribute({ class: [iframe.html_class|default(''), theme == 'dark' ? 'dark' : ''] }) }}>
    ```
    and the `<style>` block:
    ```twig
    <style>
        :root { color-scheme: {{ theme == 'dark' ? 'dark' : 'light' }}; }
        body { background-color: {{ theme == 'dark' ? '#0a0a0a' : '#fff' }}; }{# ... rest of the comment/mode-CSS block unchanged ... #}
    ```
    (Keep the existing comment above the `<style>` block, but append: "When `?theme=dark` is explicitly requested, both the color-scheme and this safety-net background flip to dark — the whole point of an opt-in dark preview is that the iframe should actually look dark before the consumer's own `dark:` utilities paint, not flash white first.")
  - Run `vendor/bin/phpunit --filter RendererTest` — all green.

- [x] Pass the whitelisted theme from `Router` through `Styleguide::dispatchRender()`.
  - No new test needed here beyond the existing `RendererTest`/`RouterTest` coverage above — this is glue. Edit `src/Styleguide.php::dispatchRender()`:
    ```php
    header('Content-Type: text/html; charset=utf-8');
    echo $this->renderer->render(
        kind: $route['kind'],
        slug: $route['slug'],
        config: $config,
        langcode: $langcode,
        // Router::parse() / synthesizeEmbeddedRoute() always set this for
        // `render`-type routes, but re-whitelist defensively — $route is a
        // loosely-typed array<string,mixed>, not a value object.
        theme: Router::whitelistTheme($route['theme'] ?? null),
    );
    ```
  - Run `composer test` — full suite green. Run `composer phpstan` — clean.

- [x] Verify and, if needed, correct the README wording (it already documents the contract per the design review's doc-drift finding — this is now real, so confirm rather than rewrite).
  - `README.md` line 225 already reads: `Accepts `?theme=light\|dark`` (whitelisted) to stamp `class="dark"` on the iframe `<html>` for consumers that opt into Tailwind dark mode.` — add the `color-scheme` detail so the doc matches the implementation exactly:
    ```
    | `/styleguide/render/<kind>/<slug>` | iframe HTML | Bare render — `<kind>` ∈ `component` \| `page` \| `doc` \| `foundations`. Used as iframe `src`, also browsable directly. Accepts `?theme=light\|dark` (whitelisted, invalid/missing → `light`) to stamp `class="dark"` and a matching `color-scheme` on the iframe `<html>` for consumers that opt into Tailwind dark mode; inert for projects with no dark-mode CSS. |
    ```
  - `docs/API.md` § URL surface table currently has **no** row-level mention of `?theme=` at all (this is the actual documented drift, distinct from README which already had the aspirational text) — add it to the same row as `/styleguide/render/<kind>/<slug>`:
    ```
    | `/styleguide/render/<kind>/<slug>` | Render endpoint — HTML document of a single component / page / doc in isolation (no SPA chrome); `<kind>` ∈ `component \| page \| doc \| foundations`. Accepts an additive `?theme=light\|dark` query param (whitelisted server-side, default `light`) — stamps `class="dark"` + `color-scheme: dark` on the rendered `<html>`. |
    ```
  - `CHANGELOG.md` — add under `[Unreleased]`:
    ```markdown
    ## [Unreleased]

    ### Added

    - **`?theme=light|dark` on the render endpoint.** Implements the contract `README.md`/`docs/API.md` already documented but the code never enforced (doc drift closed). Whitelisted server-side (`Router::whitelistTheme()`) — anything other than the literal string `dark` resolves to `light`. Stamps `class="dark"` + a matching `color-scheme` on the rendered `<html>`; inert for projects without dark-mode CSS. SPA toolbar gained an iframe-theme toggle independent of the chrome theme.
    ```
  - Run `composer test` once more to confirm the docs-adjacent edits didn't touch any executable code path unexpectedly (they didn't — pure Markdown).

- [x] (SPA, Vue/Pinia — Phase 1 assumed merged) Add an iframe-theme toggle independent of the chrome theme.
  - Locate the Phase 1 equivalents of today's `frontend/components/preview.js` (`iframeSrc` getter, line 512) and `frontend/stores/theme.js` (chrome-theme persistence via `Alpine.$persist('system').as('sg-theme')`) under `frontend/src/`. Reference implementation to adapt:
  - `frontend/src/stores/ui.js` (Pinia) — add a **separate** persisted ref, distinct from the chrome theme:
    ```js
    // Iframe content theme — independent of the SPA chrome's own light/dark/
    // system toggle. Persisted under its own localStorage key so switching
    // one doesn't affect the other.
    const iframeTheme = ref(localStorage.getItem('sg-iframe-theme') || 'light');
    function setIframeTheme(value) {
        iframeTheme.value = value === 'dark' ? 'dark' : 'light';
        localStorage.setItem('sg-iframe-theme', iframeTheme.value);
    }
    ```
  - Wherever the iframe `src` is assembled (today: `preview.js` `iframeSrc` getter) — append the query param:
    ```js
    if (src && ui.iframeTheme === 'dark') {
        src += (src.includes('?') ? '&' : '?') + 'theme=dark';
    }
    ```
  - Add a toggle button to `frontend/src/components/ViewportToolbar.vue` (or wherever the existing viewport-preset dropdown / reload button lives) calling `ui.setIframeTheme(...)`; label via the existing i18n mechanism (new key, e.g. `toolbar.iframe_theme`).
  - Add `frontend/src/stores/ui.spec.js` (Vitest):
    ```js
    import { describe, it, expect, beforeEach } from 'vitest';
    import { setActivePinia, createPinia } from 'pinia';
    import { useUiStore } from './ui.js';

    describe('iframeTheme', () => {
        beforeEach(() => {
            localStorage.clear();
            setActivePinia(createPinia());
        });

        it('defaults to light', () => {
            const ui = useUiStore();
            expect(ui.iframeTheme).toBe('light');
        });

        it('persists the chosen theme across store instances', () => {
            useUiStore().setIframeTheme('dark');
            setActivePinia(createPinia());
            expect(useUiStore().iframeTheme).toBe('dark');
        });

        it('rejects invalid values by falling back to light', () => {
            const ui = useUiStore();
            ui.setIframeTheme('neon');
            expect(ui.iframeTheme).toBe('light');
        });
    });
    ```
  - Run `npm run test` (Vitest) in `frontend/` — green. Run `npm run build` — `dist/` rebuilds; commit both source and rebuilt `dist/` per AGENTS.md § SPA chrome change workflow.
  - Verify on the consumer (`composer styleguide:local` in `tailwind-base`): open a component, toggle iframe theme, confirm the iframe URL gains `?theme=dark` and the iframe's `<html>` gets `class="dark"`.

- [x] Commit: `feat(render): implement documented ?theme=light|dark param`.

### Task 2: Render-time exception → HTTP 500

**Files:**
- `src/Renderer.php` — `render()` catch block (lines 75-82)
- `tests/RendererTest.php`
- `tests/fixtures/templates/component/broken-sample/broken-sample.twig` (new fixture)
- `tests/ComponentParserTest.php` (collateral: one exact-list assertion gains an entry)
- `tests/e2e/smoke-http.sh` (optional extra e2e assertion)
- `CHANGELOG.md`

**Interfaces:**
- Consumes: nothing new.
- Produces: `Renderer::render()` now calls `http_response_code(500)` before returning error markup for any `\Throwable` raised while rendering a component/page/doc/foundations body. `render404()` is untouched (still `404`).

- [x] Add the throwing fixture (needed by the failing test below) and immediately reconcile the one existing test that enumerates the full fixture roster.
  - Create `tests/fixtures/templates/component/broken-sample/broken-sample.twig`:
    ```twig
    {#
    name: "Broken Sample"
    category: "Block"
    weight: 999
    #}
    <div class="broken">{{ this_function_does_not_exist() }}</div>
    ```
    (`weight: 999` deliberately sorts it last among the fixtures so it's an append-only change to the one test that pins the exact roster, not an insertion.) Calling an unregistered Twig function raises `Twig\Error\SyntaxError` (a `\Throwable`) at template-compile time, which happens synchronously inside `$this->twig->render(...)` — i.e. inside `Renderer::render()`'s existing try block, no extra wiring needed to trigger it.
  - Update `tests/ComponentParserTest.php::parses_all_components_sorted_by_weight()` — append the new fixture to the expected roster (still non-decreasing weight, still an accurate documentation of the fixture set):
    ```php
    self::assertSame(
        ['Another', 'Sample', 'Widget - one', 'Widget - two', 'Widget - three', 'Gizmo', 'Broken Sample'],
        array_column($components, 'name'),
        'parseAll returns the full fixture set sorted by weight',
    );
    ```
  - Run `vendor/bin/phpunit --filter ComponentParserTest` — green (this fixture's YAML is valid; only its Twig *body* is broken, so `ComponentParser` — which never renders the body — picks it up like any other component).

- [x] Write the failing `RendererTest` for the 500 path.
  - Add to `tests/RendererTest.php`:
    ```php
    #[Test]
    public function render_error_sets_http_500_and_keeps_error_markup_visible(): void
    {
        $html = $this->renderer->render('component', 'broken-sample', [
            'project' => ['name' => 'TestProject'],
            'iframe' => [],
        ], 'en');

        self::assertSame(500, http_response_code());
        self::assertStringContainsString('Render error:', $html);
        // The underlying Twig message stays visible (existing errorMarkup()
        // behaviour) — this test only pins the new status-code contract, not
        // a new markup shape.
        http_response_code(200);
    }

    #[Test]
    public function render_404_status_is_unaffected_by_the_500_path(): void
    {
        // Guards against a sloppy refactor that moves the 500 call somewhere
        // that also fires for the 404 branch.
        $this->renderer->render('component', 'nonexistent', [
            'project' => ['name' => 'TestProject'],
            'iframe' => [],
        ], 'en');

        self::assertSame(404, http_response_code());
        http_response_code(200);
    }
    ```
  - Run `vendor/bin/phpunit --filter render_error_sets_http_500` — fails: `http_response_code()` is still `200` (or whatever PHPUnit's ambient default is) because nothing sets it today.

- [x] Implement the fix in `src/Renderer.php::render()`:
    ```php
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
    ```
  - Run `vendor/bin/phpunit --filter RendererTest` — all green. Run `composer test` (full suite) — green. Run `composer phpstan` — clean.

- [x] (Optional, cheap belt-and-suspenders) Add an e2e HTTP assertion.
  - In `tests/e2e/smoke-http.sh`, near the existing `render component` assertions (~line 146):
    ```bash
    assert_status "/styleguide/render/component/broken-sample" "500" "render error → 500"
    ```
  - Run `bash tests/e2e/run.sh` (starts the built-in PHP server against `tests/fixtures/` and runs the smoke suite) — confirm the new line passes.

- [x] Update `CHANGELOG.md` under `[Unreleased]`:
    ```markdown
    ### Fixed

    - **Render-time exceptions now return HTTP 500.** A component/page/doc template that throws during render used to respond `200 OK` with the error markup embedded in the body — a health check or CI canary hitting `/render/<kind>/<slug>` couldn't distinguish a broken component from a working one. `Renderer` now calls `http_response_code(500)` before returning the (still-visible) error markup. `render404()` is unaffected.
    ```

- [x] Commit: `fix(renderer): return HTTP 500 for render-time exceptions`.

### Task 3: `ComponentParser` catches `\Throwable` per file; new `/api/health` endpoint

**Files:**
- `src/ComponentParser.php` — `parse()` (lines 71-91), `parseAll()` loop (lines 98-143)
- `src/Api/HealthEndpoint.php` (new)
- `src/Styleguide.php` — `dispatchApi()` match (lines 1112-1118)
- `tests/ComponentParserTest.php`
- `tests/Api/HealthEndpointTest.php` (new)
- `tests/RouterTest.php` (one cheap regression assertion)
- `docs/API.md` (§ JSON API endpoints, § Other PHP classes table), `README.md` (§ API intro, § Adding a new endpoint)
- `CHANGELOG.md`
- (SPA) `frontend/src/stores/catalog.js` (or wherever `/api/*` is fetched) + a small warning indicator component + a Vitest test

**Interfaces:**
- Consumes: nothing new from the outside; internally `ComponentParser::parseAll()`/`parse()` now catch `\Throwable` instead of only `Symfony\Component\Yaml\Exception\ParseException`.
- Produces:
  - `ComponentParser::getWarnings(): list<array{file:string, error:string}>` (new, `@internal` — not `@api`, same tier as the rest of the class per `docs/API.md`).
  - `GET /styleguide/api/health` → `{"warnings": [{"file": string, "error": string}, ...], "counts": {"components": int, "pages": int, "docs": int}}` (new endpoint; the four *existing* endpoints keep their bare-array shape untouched — see the design note below).

**Design note — why a new endpoint instead of an additive `_warnings` field:** `ComponentsEndpoint`/`PagesEndpoint`/`DocsEndpoint`/`FieldsEndpoint` (`src/Api/*.php`) each `echo json_encode($this->parser->parseAll(...))` directly — the HTTP response body **is** a bare JSON array, not an object with an `items` key. Adding a sibling `_warnings` field to that shape is impossible without breaking every existing consumer of those four endpoints (the SPA's `array.map(...)`, any external tooling treating the body as `Component[]`) — that would be the one non-additive change in this whole phase, which the design's compatibility contract explicitly forbids ("only additive fields like `_warnings`" assumed an object-shaped response that doesn't actually exist). The additive-safe equivalent is a **new** endpoint, mirroring the existing "three/four near-identical classes, no shared base" convention documented in `README.md` § Adding a new endpoint.

- [x] `ComponentParser::parseAll()` — failing test first, using a controlled mock rather than a "naturally" broken YAML fixture (deliberately corrupting YAML to trip a *different* Throwable subtype than `ParseException` is brittle across `symfony/yaml` versions and tests the library's edge cases, not our resilience contract; a mock exercises the exact mechanism deterministically).
  - Add to `tests/ComponentParserTest.php`:
    ```php
    #[Test]
    public function parse_all_skips_a_throwing_file_and_records_a_warning(): void
    {
        $real = new ComponentParser($this->fixturesPath);
        $parser = $this->getMockBuilder(ComponentParser::class)
            ->setConstructorArgs([$this->fixturesPath])
            ->onlyMethods(['parseTwigComment'])
            ->getMock();
        // Force exactly the `sample` fixture's content to throw a generic
        // \RuntimeException (not the ParseException the old code only
        // caught); every other fixture delegates to the real parser so the
        // rest of the catalogue proves unaffected.
        $parser->method('parseTwigComment')->willReturnCallback(
            static function (string $content) use ($real) {
                if (str_contains($content, 'name: "Sample"')) {
                    throw new \RuntimeException('simulated parser fault');
                }
                return $real->parseTwigComment($content);
            },
        );

        $items = $parser->parseAll('component');

        self::assertNotContains('Sample', array_column($items, 'name'));
        self::assertContains('Another', array_column($items, 'name'));
        self::assertContains('Gizmo', array_column($items, 'name'));

        $warnings = $parser->getWarnings();
        self::assertCount(1, $warnings);
        self::assertSame('component/sample/sample.twig', $warnings[0]['file']);
        self::assertSame('simulated parser fault', $warnings[0]['error']);
    }

    #[Test]
    public function warnings_do_not_duplicate_across_repeated_calls_for_the_same_file(): void
    {
        $real = new ComponentParser($this->fixturesPath);
        $parser = $this->getMockBuilder(ComponentParser::class)
            ->setConstructorArgs([$this->fixturesPath])
            ->onlyMethods(['parseTwigComment'])
            ->getMock();
        $parser->method('parseTwigComment')->willReturnCallback(
            static function (string $content) use ($real) {
                if (str_contains($content, 'name: "Sample"')) {
                    throw new \RuntimeException('simulated parser fault');
                }
                return $real->parseTwigComment($content);
            },
        );

        $parser->parseAll('component');
        $parser->parseAll('component');

        self::assertCount(1, $parser->getWarnings());
    }
    ```
  - Run `vendor/bin/phpunit --filter ComponentParserTest` — fails (`getWarnings()` undefined; the mock's thrown `\RuntimeException` currently propagates out of `parseAll()` uncaught, failing the test with an unhandled exception).

- [x] Implement in `src/ComponentParser.php`:
    ```php
    /** @var list<array{file:string, error:string}> */
    private array $warnings = [];

    /**
     * @internal Exposed for `Api\HealthEndpoint`; not part of the SemVer
     *           contract — see docs/API.md § Other PHP classes.
     *
     * @return list<array{file:string, error:string}>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
    ```
    Wrap the `parseAll()` per-file body in try/catch:
    ```php
    foreach ($regex as $file) {
        if ($file->getFilename() === 'styleguide.twig') {
            continue;
        }

        $content = (string) file_get_contents($file->getPathname());

        try {
            $metadata = $this->parseTwigComment($content);

            if (!$metadata || !isset($metadata['name'])) {
                continue;
            }

            $id = $file->getBasename('.twig');
            $hasStyleguide = file_exists($file->getPath() . '/styleguide.twig')
                || isset($metadata['styleguide']);

            $items[] = $this->normaliseMetadata($id, $metadata, $hasStyleguide);
        } catch (\Throwable $e) {
            // One pathological template must not 500 the whole catalogue for
            // every sibling component. Record it and keep walking; surfaced
            // via GET /styleguide/api/health, invisible to the normal
            // component list the SPA renders.
            $this->recordWarning($this->relativePath($file->getPathname()), $e);
            continue;
        }
    }
    ```
    Wrap `parse()`'s body similarly:
    ```php
    public function parse(string $type, string $id): ?array
    {
        $dir = $this->templatesPath . '/' . $type . '/' . $id;
        $file = $dir . '/' . $id . '.twig';

        if (!file_exists($file)) {
            return null;
        }

        try {
            $content = (string) file_get_contents($file);
            $metadata = $this->parseTwigComment($content);

            if (!$metadata || !isset($metadata['name'])) {
                return null;
            }

            $hasStyleguide = file_exists($dir . '/styleguide.twig')
                || isset($metadata['styleguide']);

            return $this->normaliseMetadata($id, $metadata, $hasStyleguide);
        } catch (\Throwable $e) {
            // Single-file lookup path (used by Styleguide::dispatchRender()
            // for the render endpoint's <title>/body_class/render metadata)
            // — same resilience contract as parseAll(): a broken template
            // degrades this lookup to the pre-existing "no metadata" outcome
            // (null) instead of 500ing the render endpoint itself.
            $this->recordWarning($this->relativePath($file), $e);
            return null;
        }
    }
    ```
    Add the two private helpers:
    ```php
    private function recordWarning(string $relativeFile, \Throwable $e): void
    {
        foreach ($this->warnings as $warning) {
            if ($warning['file'] === $relativeFile) {
                // Idempotent within one request/instance — a caller that
                // queries the same type twice shouldn't accumulate
                // duplicate entries for the same broken file.
                return;
            }
        }
        $this->warnings[] = ['file' => $relativeFile, 'error' => $e->getMessage()];
    }

    private function relativePath(string $absolutePath): string
    {
        return ltrim(substr($absolutePath, strlen($this->templatesPath)), '/');
    }
    ```
  - Run `vendor/bin/phpunit --filter ComponentParserTest` — all green.

- [x] Same resilience for `parse()` — failing test first.
  - Add to `tests/ComponentParserTest.php`:
    ```php
    #[Test]
    public function parse_returns_null_and_records_a_warning_for_a_throwing_file(): void
    {
        $real = new ComponentParser($this->fixturesPath);
        $parser = $this->getMockBuilder(ComponentParser::class)
            ->setConstructorArgs([$this->fixturesPath])
            ->onlyMethods(['parseTwigComment'])
            ->getMock();
        $parser->method('parseTwigComment')->willReturnCallback(
            static function (string $content) use ($real) {
                if (str_contains($content, 'name: "Sample"')) {
                    throw new \RuntimeException('simulated parser fault');
                }
                return $real->parseTwigComment($content);
            },
        );

        self::assertNull($parser->parse('component', 'sample'));
        self::assertSame('component/sample/sample.twig', $parser->getWarnings()[0]['file']);
    }
    ```
  - Since the implementation above already wraps `parse()`, this should already pass — run `vendor/bin/phpunit --filter ComponentParserTest` to confirm; if it was written before the `parse()` try/catch landed, it fails first as expected, then passes once that edit is in place.

- [x] Create `src/Api/HealthEndpoint.php` — failing test first.
  - Add `tests/Api/HealthEndpointTest.php`:
    ```php
    <?php

    declare(strict_types=1);

    namespace Parisek\Styleguide\Tests\Api;

    use Parisek\Styleguide\Api\HealthEndpoint;
    use Parisek\Styleguide\ComponentParser;
    use PHPUnit\Framework\Attributes\Test;
    use PHPUnit\Framework\TestCase;

    final class HealthEndpointTest extends TestCase
    {
        private string $fixturesPath;

        protected function setUp(): void
        {
            $this->fixturesPath = __DIR__ . '/../fixtures/templates';
        }

        #[Test]
        public function handle_returns_counts_and_empty_warnings_for_healthy_fixtures(): void
        {
            $parser = new ComponentParser($this->fixturesPath);
            $endpoint = new HealthEndpoint($parser);

            ob_start();
            $endpoint->handle();
            $output = ob_get_clean();

            $data = json_decode($output, true);
            self::assertIsArray($data);
            self::assertSame([], $data['warnings']);
            self::assertGreaterThan(0, $data['counts']['components']);
            self::assertArrayHasKey('pages', $data['counts']);
            self::assertArrayHasKey('docs', $data['counts']);
        }

        #[Test]
        public function handle_surfaces_warnings_the_parser_already_collected(): void
        {
            $real = new ComponentParser($this->fixturesPath);
            $parser = $this->getMockBuilder(ComponentParser::class)
                ->setConstructorArgs([$this->fixturesPath])
                ->onlyMethods(['parseTwigComment'])
                ->getMock();
            $parser->method('parseTwigComment')->willReturnCallback(
                static function (string $content) use ($real) {
                    if (str_contains($content, 'name: "Sample"')) {
                        throw new \RuntimeException('simulated parser fault');
                    }
                    return $real->parseTwigComment($content);
                },
            );

            $endpoint = new HealthEndpoint($parser);
            ob_start();
            $endpoint->handle();
            $output = ob_get_clean();

            $data = json_decode($output, true);
            self::assertNotEmpty($data['warnings']);
            self::assertSame('component/sample/sample.twig', $data['warnings'][0]['file']);
        }
    }
    ```
  - Run `vendor/bin/phpunit --filter HealthEndpointTest` — fails (class doesn't exist).
  - Implement `src/Api/HealthEndpoint.php`:
    ```php
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
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
    ```
  - Run `vendor/bin/phpunit --filter HealthEndpointTest` — all green.

- [x] Wire the route in `src/Styleguide.php::dispatchApi()`:
    ```php
    $endpoint = match ($route['endpoint']) {
        'components' => new Api\ComponentsEndpoint($this->parser),
        'docs' => new Api\DocsEndpoint($this->parser),
        'fields' => new Api\FieldsEndpoint($this->parser),
        'pages' => new Api\PagesEndpoint($this->parser),
        'health' => new Api\HealthEndpoint($this->parser),
        default => null,
    };
    ```
  - Add a cheap regression assertion to `tests/RouterTest.php::parses_api_endpoints()`:
    ```php
    self::assertSame(['type' => 'api', 'endpoint' => 'health'], Router::parse('/styleguide/api/health'));
    ```
    (`Router::parse()`'s generic `api/<endpoint>` branch already matches this — the assertion documents the contract rather than fixing a gap.)
  - Run `composer test` (full suite) — green. Run `composer phpstan` — clean.

- [x] Update docs in the same task.
  - `docs/API.md` § JSON API endpoints — add after the `/api/fields` section:
    ```markdown
    ### `GET /styleguide/api/health`

    Diagnostics for `ComponentParser`'s per-file resilience (added alongside the `\Throwable`-catching change — see CHANGELOG). Not part of the four catalogue endpoints' bare-array shape; this one is deliberately an object.

    **Response shape:**

    ```ts
    {
      warnings: Array<{ file: string; error: string }>; // relative to templates_path; empty when nothing was skipped
      counts: { components: number; pages: number; docs: number };
    }
    ```
    ```
  - `docs/API.md` § "Other PHP classes & methods — `@internal`" table — add a row: `| `Api\HealthEndpoint` | Same — consumed via HTTP |`.
  - `docs/API.md` § URL surface table — add a row: `| `/styleguide/api/health` | JSON — parse-resilience diagnostics (warnings + counts) |`.
  - `README.md` § API — bump "Four read-only JSON endpoints" to "Five read-only JSON endpoints", and add a short subsection after `### GET /styleguide/api/fields` mirroring the `docs/API.md` shape (see README's existing per-endpoint subsections for the format to match).
  - `README.md` § Adding a new endpoint — the intro line says "The three endpoint classes (`src/Api/*Endpoint.php`) share the same shape" (already understated even before this task — there were four). Reword to: "The endpoint classes (`src/Api/*Endpoint.php`) share the same shape: constructor takes the `ComponentParser`, `handle()` emits headers + `json_encode()`."

- [x] (SPA, Vue/Pinia) Surface a small warning indicator when `/api/health` returns warnings.
  - Add a fetch of `/styleguide/api/health` to wherever the catalogue store already fetches `/api/components` etc. (today: `frontend/stores/components.js`; Phase 1 equivalent: `frontend/src/stores/catalog.js`), exposing `warnings` on the store.
  - Add a small component (e.g. `frontend/src/components/HealthWarningBadge.vue`) rendered near the sidebar header, visible only when `catalog.warnings.length > 0`; clicking it could simply `console.warn` the list or open a tooltip — kept intentionally minimal, this is an unobtrusive operator signal, not a new UI surface to design in depth.
  - Add a Vitest test asserting the badge is absent when `warnings` is empty and present with the correct count when non-empty.
  - Run `npm run test` — green. Run `npm run build` — commit `dist/`.

- [x] Update `CHANGELOG.md` under `[Unreleased]`:
    ```markdown
    ### Added

    - **New `GET /styleguide/api/health` endpoint.** Reports per-file parse warnings (`ComponentParser` now catches `\Throwable`, not just YAML `ParseException`, so one pathological template no longer 500s the whole `/api/components` catalogue — it's skipped and recorded instead) plus component/page/doc counts. A separate endpoint rather than a `_warnings` field on the existing four, which each emit a bare JSON array with no additive slot for a sibling field.
    ```

- [x] Commit: `feat(api): resilient ComponentParser parsing + /api/health endpoint`.

### Task 4: Helper registration stops matching exception message text

**Files:**
- `src/Styleguide.php` — `tryAddFunction()` / `tryAddFilter()` (lines 661-684)
- `tests/BundledHelpersTest.php`
- `CHANGELOG.md`

**Interfaces:**
- Consumes: nothing new.
- Produces: `tryAddFunction`/`tryAddFilter` (private, unchanged signatures) now swallow **every** `\LogicException` from `addFunction()`/`addFilter()`, logging to `error_log()` only when the message doesn't look like the expected "already registered" collision.

- [x] Failing test: a project-pre-registered helper must still win (this already passes today — write it anyway as the explicit contract pin the outline calls for, since no test currently asserts it against a *pristine* env path).
  - Add to `tests/BundledHelpersTest.php`:
    ```php
    #[Test]
    public function project_preregistered_helper_wins_without_throwing(): void
    {
        $env = new Environment(new ArrayLoader());
        $env->addFunction(new TwigFunction('placeholder', static fn(array $opts = []): array => ['project' => true]));

        $sg = new Styleguide([
            'templates_path' => __DIR__ . '/fixtures/templates',
            'static_path' => __DIR__ . '/fixtures',
            'config_yaml' => __DIR__ . '/fixtures/styleguide.yaml',
            'twig' => $env,
        ]);
        $twig = self::twigOf($sg);

        $result = $twig->createTemplate('{{ placeholder()["project"] ? "Y" : "N" }}')->render();
        self::assertSame('Y', $result);
    }
    ```
  - Run `vendor/bin/phpunit --filter project_preregistered_helper_wins` — passes already (this is the pre-existing "duplicate name" path, unaffected by this task) — confirms the safety net before changing anything.

- [x] Failing test: a *genuinely different* `LogicException` (Twig's "extensions already initialized" case) must be swallowed, not rethrown — this is the actual behavior change.
  - Add to `tests/BundledHelpersTest.php`:
    ```php
    #[Test]
    public function non_duplicate_logic_exception_from_twig_is_swallowed_not_thrown(): void
    {
        // Pre-register every extension the package would otherwise add itself
        // (so registerBundledExtensions()'s addExtension() calls are skipped
        // via its own hasExtension() guard — unrelated to what this test
        // covers) and lock the environment via getFunctions(), which forces
        // Twig's ExtensionSet::initExtensions(). Every subsequent
        // addFunction()/addFilter() call inside registerBundledHelpers() then
        // throws "Unable to register ... as extensions have already been
        // initialized" — a LogicException with a message that does NOT
        // contain "already registered", i.e. exactly the case the old
        // str_contains() check used to rethrow.
        $env = new Environment(new ArrayLoader());
        $env->addExtension(new \Parisek\Twig\TypographyExtension(''));
        $env->addExtension(new \Symfony\Bridge\Twig\Extension\DumpExtension(new \Symfony\Component\VarDumper\Cloner\VarCloner()));
        $env->addExtension(new \Twig\Extra\Intl\IntlExtension());
        $env->addExtension(new \Twig\Extra\String\StringExtension());
        $env->addExtension(new \Parisek\Twig\AttributeExtension());
        $env->getFunctions(); // locks the extension set

        $sg = new Styleguide([
            'templates_path' => __DIR__ . '/fixtures/templates',
            'static_path' => __DIR__ . '/fixtures',
            'config_yaml' => __DIR__ . '/fixtures/styleguide.yaml',
            'twig' => $env,
        ]);

        self::assertInstanceOf(Styleguide::class, $sg);
    }
    ```
  - Run `vendor/bin/phpunit --filter non_duplicate_logic_exception` — fails today: the constructor throws an uncaught `LogicException` (the old code's `if (!str_contains(...)) { throw $e; }` rethrows it).

- [x] Implement in `src/Styleguide.php`:
    ```php
    /**
     * Register a Twig function, swallowing any `LogicException` from
     * `addFunction()`. See {@see registerBundledHelpers()} for why we can't
     * distinguish "duplicate name" from "extensions already initialized"
     * cleanly (Twig exposes both as a bare `LogicException` with only the
     * message text differing, and that text isn't a stable API — matching on
     * it to decide whether to rethrow broke once already). Swallow-and-defer:
     * never crash a consumer's boot because of a Twig internal-message
     * change; log to error_log() only when the message doesn't look like the
     * expected "already registered" collision, so the rare genuine-misuse
     * case (constructing Styleguide against an env that's already been used
     * to render) still leaves a breadcrumb for whoever's debugging it.
     */
    private static function tryAddFunction(Environment $twig, TwigFunction $function): void
    {
        try {
            $twig->addFunction($function);
        } catch (\LogicException $e) {
            self::logUnexpectedRegistrationFailure($function->getName(), $e);
        }
    }

    /**
     * Sibling of {@see tryAddFunction()} for filters.
     */
    private static function tryAddFilter(Environment $twig, TwigFilter $filter): void
    {
        try {
            $twig->addFilter($filter);
        } catch (\LogicException $e) {
            self::logUnexpectedRegistrationFailure($filter->getName(), $e);
        }
    }

    private static function logUnexpectedRegistrationFailure(string $name, \LogicException $e): void
    {
        if (!str_contains($e->getMessage(), 'already registered')) {
            error_log(sprintf(
                '[parisek/styleguide] unexpected LogicException registering "%s": %s',
                $name,
                $e->getMessage(),
            ));
        }
    }
    ```
  - Run `vendor/bin/phpunit --filter BundledHelpersTest` — all green. Run `composer test` — full suite green. Run `composer phpstan` — clean.

- [x] Update `CHANGELOG.md` under `[Unreleased]`:
    ```markdown
    ### Changed

    - **Helper registration no longer matches Twig exception text.** `tryAddFunction()`/`tryAddFilter()` used to rethrow unless the `LogicException` message contained the literal substring `"already registered"` — version-fragile and untested. They now always swallow (never crash a consumer's boot over a Twig-internal message change) and log to `error_log()` when the message doesn't match the expected collision, so the rare genuine-misuse case still leaves a trace.
    ```

- [x] Commit: `fix(styleguide): stop matching Twig LogicException text for helper registration`.

### Task 5: AssetServer `.map` MIME + foundations-CSS glob hardening

**Files:**
- `src/AssetServer.php` — `mimeType()` (lines 69-85)
- `src/Styleguide.php` — `resolveFoundationsCssUrl()` (lines 1098-1105)
- `tests/AssetServerTest.php`
- `tests/StyleguideTest.php` (new file — also used by Task 6)
- `tests/fixtures/asset-server/test-asset.js.map` (new fixture)
- `CHANGELOG.md`

**Interfaces:**
- Consumes: nothing new.
- Produces: `AssetServer::mimeType()` returns `application/json; charset=utf-8` for `.map`. `Styleguide::resolveFoundationsCssUrl()` (private, unchanged signature) picks the newest-by-mtime file and logs a warning when `glob()` matches more than one `dist/foundations.*.css`.

- [x] `.map` MIME type — failing test first.
  - Create `tests/fixtures/asset-server/test-asset.js.map` with dummy sourcemap-shaped JSON: `{"version":3,"sources":[],"mappings":""}`.
  - Add to `tests/AssetServerTest.php`:
    ```php
    #[Test]
    public function map_files_serve_with_json_content_type(): void
    {
        header_remove();
        $server = new AssetServer($this->distRoot);
        ob_start();
        $server->serve('test-asset.js.map');
        ob_end_clean();

        $contentType = null;
        foreach (headers_list() as $header) {
            if (str_starts_with($header, 'Content-Type:')) {
                $contentType = $header;
            }
        }
        self::assertSame('Content-Type: application/json; charset=utf-8', $contentType);
    }
    ```
  - Run `vendor/bin/phpunit --filter map_files_serve_with_json_content_type` — fails (falls through to `mime_content_type()`, typically `text/plain` for a `.map` file's JSON content).
  - Implement in `src/AssetServer.php::mimeType()` — add one arm:
    ```php
    return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
        'css' => 'text/css; charset=utf-8',
        'js', 'mjs' => 'application/javascript; charset=utf-8',
        'json', 'map' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml',
        // ... rest unchanged ...
    };
    ```
  - Run `vendor/bin/phpunit --filter AssetServerTest` — all green.

- [x] `resolveFoundationsCssUrl()` newest-wins + warning — failing test first.
  - Create `tests/StyleguideTest.php`:
    ```php
    <?php

    declare(strict_types=1);

    namespace Parisek\Styleguide\Tests;

    use Parisek\Styleguide\Styleguide;
    use PHPUnit\Framework\Attributes\Test;
    use PHPUnit\Framework\TestCase;

    final class StyleguideTest extends TestCase
    {
        private string $templatesPath;
        private string $missingYaml;

        protected function setUp(): void
        {
            $this->templatesPath = __DIR__ . '/fixtures/templates';
            $this->missingYaml = __DIR__ . '/fixtures/nonexistent.yaml';
        }

        private function newStyleguide(array $overrides = []): Styleguide
        {
            return new Styleguide($overrides + [
                'templates_path' => $this->templatesPath,
                'static_path' => __DIR__ . '/fixtures',
                'config_yaml' => $this->missingYaml,
            ]);
        }

        #[Test]
        public function resolve_foundations_css_url_prefers_newest_when_multiple_match(): void
        {
            $sg = $this->newStyleguide();

            $dir = sys_get_temp_dir() . '/styleguide-foundations-' . uniqid();
            mkdir($dir);
            file_put_contents($dir . '/foundations.OLDHASH1.css', 'old');
            touch($dir . '/foundations.OLDHASH1.css', time() - 100);
            file_put_contents($dir . '/foundations.NEWHASH2.css', 'new');
            touch($dir . '/foundations.NEWHASH2.css', time());

            (new \ReflectionProperty(Styleguide::class, 'distRoot'))->setValue($sg, $dir);

            $method = new \ReflectionMethod(Styleguide::class, 'resolveFoundationsCssUrl');
            $url = $method->invoke($sg);

            self::assertSame('/styleguide/assets/foundations.NEWHASH2.css', $url);

            unlink($dir . '/foundations.OLDHASH1.css');
            unlink($dir . '/foundations.NEWHASH2.css');
            rmdir($dir);
        }

        #[Test]
        public function resolve_foundations_css_url_returns_null_when_no_match(): void
        {
            $sg = $this->newStyleguide();
            $dir = sys_get_temp_dir() . '/styleguide-foundations-empty-' . uniqid();
            mkdir($dir);

            (new \ReflectionProperty(Styleguide::class, 'distRoot'))->setValue($sg, $dir);
            $method = new \ReflectionMethod(Styleguide::class, 'resolveFoundationsCssUrl');

            self::assertNull($method->invoke($sg));

            rmdir($dir);
        }
    }
    ```
  - Run `vendor/bin/phpunit --filter StyleguideTest` — `resolve_foundations_css_url_prefers_newest_when_multiple_match` fails (current code returns `basename($matches[0])`, i.e. whatever order the filesystem's `glob()` happens to return — not guaranteed to be the newer file); `resolve_foundations_css_url_returns_null_when_no_match` already passes (pins existing behaviour, no regression risk).
  - Implement in `src/Styleguide.php::resolveFoundationsCssUrl()`:
    ```php
    private function resolveFoundationsCssUrl(): ?string
    {
        $matches = glob($this->distRoot . '/foundations.*.css');
        if ($matches === false || count($matches) === 0) {
            return null;
        }
        if (count($matches) > 1) {
            // A stale hashed file from a previous build that `emptyOutDir`
            // should have removed (interrupted build, manual file copy, a
            // consumer vendoring dist/ oddly). Pick the newest by mtime so a
            // rebuild's fresh CSS wins over debris instead of depending on
            // glob()'s filesystem-order — and leave a breadcrumb, since
            // silently serving a stale bundle is a confusing bug to chase
            // without one.
            usort($matches, static fn(string $a, string $b): int => (int) filemtime($b) <=> (int) filemtime($a));
            error_log(sprintf(
                '[parisek/styleguide] multiple dist/foundations.*.css found (%s) — using newest: %s',
                implode(', ', array_map('basename', $matches)),
                basename($matches[0]),
            ));
        }
        return '/styleguide/assets/' . basename($matches[0]);
    }
    ```
  - Run `vendor/bin/phpunit --filter StyleguideTest` — all green. Run `composer test` — full suite green. Run `composer phpstan` — clean.

- [x] Update `CHANGELOG.md` under `[Unreleased]`:
    ```markdown
    ### Fixed

    - **`.map` files now serve as `application/json`.** `AssetServer` fell through to `mime_content_type()` for `.map` extensions (typically `text/plain`); explicit `application/json; charset=utf-8` matches their actual content.
    - **Foundations CSS glob picks the newest file when several match.** `resolveFoundationsCssUrl()` used to return whatever `glob()` happened to list first when a stale `dist/foundations.*.css` from a previous build wasn't cleaned up; it now picks the newest by mtime and logs a warning via `error_log()`.
    ```

- [x] Commit: `fix(assets): correct .map MIME type; foundations CSS glob picks newest match`.

### Task 6: Optional `auth` config key

**Files:**
- `src/Styleguide.php` — constructor defaults (lines 111-129), `run()` (lines 914-938), new `dispatch()` + `isAuthorized()`
- `tests/StyleguideTest.php` (extends the file created in Task 5)
- `README.md` (§ Constructor config table), `docs/API.md` (§ PHP API constructor table + a security note)
- `CHANGELOG.md`

**Interfaces:**
- Consumes: new optional constructor config key `auth?: callable(array<string,mixed> $route): bool`.
- Produces: `Styleguide::run()` behaviour — when `auth` is set and returns `false` for the parsed route, responds `403 Forbidden` (`text/plain`) before any dispatch (SPA, render, API, or asset) and returns without exiting the rest of the request early via the usual `exit` at the end of `run()`. New private `dispatch(array $route): void` (extracted from the tail of `run()` so it's testable via reflection without triggering `run()`'s unconditional `exit`).

- [x] Extract the dispatch `match` into a private, reflectively-testable method — no behaviour change yet, refactor-only step, run the full suite to prove it's a no-op before adding the auth gate.
  - Edit `src/Styleguide.php::run()`:
    ```php
    public function run(): void
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $route = Router::parse($uri);

        if ($route === null) {
            return;
        }

        $route = Router::synthesizeEmbeddedRoute($route, (string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));

        $this->dispatch($route);

        // After dispatching a styleguide route, halt the project's downstream router.
        exit;
    }

    /**
     * @param array<string, mixed> $route
     */
    private function dispatch(array $route): void
    {
        match ($route['type']) {
            'asset' => $this->assetServer->serve($route['path'] ?? ''),
            'render' => $this->dispatchRender($route),
            'api' => $this->dispatchApi($route),
            default => $this->dispatchSpa($route),
        };
    }
    ```
  - Run `composer test` — full suite green (pure extraction, `run()`'s observable behaviour is unchanged; no test calls `run()` directly today since it unconditionally `exit`s, so there's nothing to update).

- [x] Failing tests for the auth gate.
  - Add to `tests/StyleguideTest.php` (created in Task 5):
    ```php
    #[Test]
    public function auth_callable_returning_false_yields_403_before_any_dispatch(): void
    {
        $sg = $this->newStyleguide([
            'auth' => static fn(array $route): bool => false,
        ]);

        $dispatch = new \ReflectionMethod(Styleguide::class, 'dispatch');
        ob_start();
        $dispatch->invoke($sg, ['type' => 'api', 'endpoint' => 'components']);
        $output = ob_get_clean();

        self::assertSame(403, http_response_code());
        self::assertSame('403 Forbidden', $output);
        http_response_code(200);
    }

    #[Test]
    public function auth_callable_returning_true_lets_dispatch_proceed(): void
    {
        $sg = $this->newStyleguide([
            'auth' => static fn(array $route): bool => true,
        ]);

        $dispatch = new \ReflectionMethod(Styleguide::class, 'dispatch');
        ob_start();
        $dispatch->invoke($sg, ['type' => 'api', 'endpoint' => 'components']);
        $output = ob_get_clean();

        self::assertNotSame(403, http_response_code());
        self::assertIsArray(json_decode($output, true));
        http_response_code(200);
    }

    #[Test]
    public function missing_auth_config_allows_every_route(): void
    {
        $sg = $this->newStyleguide(); // no 'auth' key at all

        $dispatch = new \ReflectionMethod(Styleguide::class, 'dispatch');
        ob_start();
        $dispatch->invoke($sg, ['type' => 'api', 'endpoint' => 'components']);
        $output = ob_get_clean();

        self::assertNotSame(403, http_response_code());
        self::assertIsArray(json_decode($output, true));
        http_response_code(200);
    }
    ```
  - Run `vendor/bin/phpunit --filter StyleguideTest` — the first test fails (`dispatch()` doesn't check `auth` yet, so `output` is the real JSON body from `ComponentsEndpoint`, not `403 Forbidden`); the other two already pass (nothing to deny yet) — confirms they're non-tautological once the gate exists.

- [x] Implement.
  - Add `'auth' => null,` to the config defaults block in `src/Styleguide.php::__construct()` (alongside `'namespaces' => []`), with a comment:
    ```php
    // Optional programmatic gate — callable(array $route): bool. Checked once
    // per request in dispatch(), before ANY handler (SPA/render/api/asset).
    // Null (the default) means "allow everything", i.e. today's behaviour —
    // fully backward compatible. See README § Bootstrap → Constructor config
    // for the recommended alternative (web-server-level HTTP Basic Auth) on
    // publicly reachable deployments.
    'auth' => null,
    ```
  - Update the constructor's `@param array{...}` PHPDoc to add `auth?: callable(array<string,mixed>):bool,`.
  - Edit `dispatch()`:
    ```php
    /**
     * @param array<string, mixed> $route
     */
    private function dispatch(array $route): void
    {
        if (!$this->isAuthorized($route)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo '403 Forbidden';
            return;
        }

        match ($route['type']) {
            'asset' => $this->assetServer->serve($route['path'] ?? ''),
            'render' => $this->dispatchRender($route),
            'api' => $this->dispatchApi($route),
            default => $this->dispatchSpa($route),
        };
    }

    /**
     * @param array<string, mixed> $route
     */
    private function isAuthorized(array $route): bool
    {
        $auth = $this->config['auth'] ?? null;
        if (!is_callable($auth)) {
            return true;
        }
        return (bool) $auth($route);
    }
    ```
  - Run `vendor/bin/phpunit --filter StyleguideTest` — all green. Run `composer test` — full suite green. Run `composer phpstan` — clean (note: `$auth(...)` on a `mixed` narrowed by `is_callable()` should type-check at level 8; if PHPStan complains about the callable's parameter/return shape, annotate `$auth` with an inline `@var callable(array<string,mixed>):bool $auth` comment right before the call).

- [x] Update docs in the same task.
  - `README.md` § Constructor config table — add a row:
    ```
    | `auth` | no | `null` | Optional `callable(array $route): bool` gate checked once per request, before any dispatch (SPA, render, JSON API, or asset). Return `false` to reject with a plain-text `403 Forbidden`; return `true` (or omit the key entirely) to allow. Receives the parsed route array (`type`, plus `slug`/`kind`/`endpoint`/`path`/`theme` depending on route type). For publicly reachable deployments, HTTP Basic Auth at the web-server level is usually simpler and more robust than an in-PHP callable — reach for `auth` when the check needs request context only PHP has access to (e.g. a signed query token, a session check your framework already performs). |
    ```
  - `docs/API.md` § PHP API → `__construct()` optional-keys table — add the matching row, and directly below the table add:
    ```markdown
    **Security note:** `auth` is a convenience hook for programmatic gating, not a substitute for transport-level protection. For any styleguide reachable from the public internet, put HTTP Basic Auth (or your reverse proxy's equivalent) in front of the `/styleguide/*` path first; use `auth` for logic that genuinely needs to run inside PHP.
    ```
  - Run `composer test` once more (docs-only, but confirms nothing regressed).

- [x] Update `CHANGELOG.md` under `[Unreleased]`:
    ```markdown
    ### Added

    - **Optional `auth` config key.** `callable(array $route): bool` checked once per request before any dispatch; return `false` to respond `403 Forbidden` (plain text) before SPA/render/API/asset handling runs. `null` (the default) preserves today's behaviour — no gating. Documented alongside a recommendation to prefer web-server-level HTTP Basic Auth for publicly reachable deployments.
    ```

- [x] Commit: `feat(styleguide): add optional programmatic auth gate`.

### Task 7: Docs sync pass + CHANGELOG archive

**Files:**
- `docs/API.md`, `README.md`, `CHANGELOG.md`
- `CHANGELOG-archive.md` (new)

**Interfaces:** none — documentation-only task, no code changes, no tests (nothing executable to verify beyond re-running `composer test` as a smoke check that nothing in this pass accidentally touched a `.php`/`.twig` file).

- [ ] Audit `docs/API.md` against the current code and fix the concrete drifts found during research for this plan (beyond the ones Tasks 1–3 already fix as part of their own doc updates):
  - **`/api/fields` response shape is stale and wrong.** `docs/API.md` § JSON API endpoints currently documents:
    ```ts
    {
      id: string;
      type: 'component' | 'page';
      name: string;
      fields: object;
    }
    ```
    but `src/Api/FieldsEndpoint.php::handle()` actually emits `{component_id, component_name, fields}` — no `id`/`type` keys at all, and the endpoint only ever aggregates components (docs even say so one paragraph up: "Only components are aggregated — pages and docs are not included"), directly contradicting the `type: 'component' | 'page'` union it documents. `README.md`'s own copy of this shape (§ `GET /styleguide/api/fields`) is already correct. Fix `docs/API.md` to match:
    ```ts
    {
      component_id: string;
      component_name: string;
      fields: object;
    }
    ```
  - Re-check the YAML metadata table (§ Component YAML metadata) against `ComponentParser::normaliseMetadata()` (lines 171-202) — confirmed matching field-for-field (`id, name, category, description, asana, figma, drupal, web, weight, usage, fields, render, body_class, responsive, hasStyleguide`); no fix needed here, note it as verified.
  - Re-check the `styleguide.yaml` top-level key table (§ YAML schemas) against `Styleguide::dispatchRender()`'s `$config['styleguide']` usage and `foundations.twig` — confirmed the documented keys (`project`, `iframe`, `logo`, `favicon`, `typography`, `labels`, `colors`) are still what's read; no fix needed, note it as verified.
  - Confirm the Task 1/2/3/6 doc edits already landed (theme param, health endpoint, auth key) are present and consistent in wording between `README.md` and `docs/API.md` — if any earlier task's doc edit used slightly different phrasing between the two files, reconcile to a single consistent description (copy the more precise wording into both, don't just leave two different explanations of the same contract).

- [ ] Archive old CHANGELOG entries.
  - Current `CHANGELOG.md` spans `[0.1.0]` (2026-05-18) through `[0.6.5]` (2026-06-22) plus `[Unreleased]`. Per the design doc's Phase 2 item 6 ("archive CHANGELOG entries older than the last few minor series"), move everything **older than `[0.4.0]`** — i.e. `[0.3.14]` down through `[0.1.0]` (currently lines ~115 to the end of the file) — into a new `CHANGELOG-archive.md`, keeping `[Unreleased]` through `[0.4.0]` in `CHANGELOG.md`.
  - Create `CHANGELOG-archive.md`:
    ```markdown
    # Changelog archive

    Older `parisek/styleguide` releases, moved out of `CHANGELOG.md` to keep the
    active changelog focused on recent series. Same format
    ([Keep a Changelog](https://keepachangelog.com/en/1.1.0/)); no entries here
    are edited, only relocated.

    <!-- paste [0.3.14] through [0.1.0] verbatim below, unchanged -->
    ```
    then paste the exact content of the `[0.3.14]` … `[0.1.0]` sections (byte-for-byte — this is a move, not a rewrite) below that header.
  - Truncate `CHANGELOG.md` to keep only `[Unreleased]` through `[0.4.0]`, and add a pointer at the top just under the existing intro paragraph:
    ```markdown
    # Changelog

    All notable changes to `parisek/styleguide` are documented here.
    Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
    project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

    Releases before [0.4.0] have moved to [`CHANGELOG-archive.md`](CHANGELOG-archive.md).

    ## [Unreleased]

    ...
    ```
  - Diff-check: `wc -l CHANGELOG.md CHANGELOG-archive.md` combined line count (minus the new archive header + pointer line) should equal the original file's line count — a cheap sanity check that the move didn't drop or duplicate an entry.
  - Run `composer test` — confirms this documentation-only pass didn't touch anything executable (should be a no-op on the suite).

- [ ] Commit: `docs: fix stale /api/fields shape in docs/API.md; archive changelog entries before 0.4.0`.
