# Phase 4: Variants + Storybook Features Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship v0.9.0 — the third Storybook-parity capability set: zero-YAML file-convention **variants** (`styleguide.<variant>.twig` siblings) with a toolbar switcher and deep links, a keyboard-navigable **command-palette search** upgrade, and an **on-demand accessibility check** (axe-core injected into the iframe). All three are additive and opt-in; no existing consumer template changes behaviour.

**Architecture:** Variants are discovered the same way `styleguide.twig` already is — a filesystem convention read by `ComponentParser`, surfaced additively on the JSON API, resolved by `Renderer` at render time, and switched client-side via a `?variant=` query param that `Router` whitelists syntactically (existence is resolved downstream against the real file set, never trusted from the query string). Search and a11y are pure SPA-side features layered on the Phase 1 Vue 3 + Pinia rewrite — no backend surface.

**Tech Stack:** PHP 8.3 (PSR-12, strict types, `final`, PHPStan level 8), PHPUnit, Vue 3 + Pinia (Phase 1 output — see *Interface Assumptions* below), Vitest, Playwright, axe-core, Tailwind v4.

---

## Global Constraints

- **Full backward compatibility.** A plain `styleguide.twig` with no `styleguide.<variant>.twig` siblings is the implicit **default** variant — nothing about its render path, API shape, or URL changes. `variants:` YAML is optional and supplies **labels only**; it never creates a variant that has no matching file.
- **Additive API only.** Every new field on `/api/components`, `/api/pages`, `/api/docs` is new keys, never a renamed/removed one. CLI `list`/`show` output inherits the same shape for free (both wrap `ComponentParser::parse()` / `parseAll()`).
- **Variant name whitelist:** `^[a-z0-9-]+$`, applied in two places for two different reasons:
  - `Router::parse()` / the query string — **syntactic** whitelist only (rejects path-traversal / injection shapes before the value goes anywhere near a filesystem path or a Twig template lookup).
  - `ComponentParser` — the **canonical, existence-based** source of truth: a variant "exists" only if `styleguide.<id>.twig` is actually on disk. The query param is never trusted for existence; an unknown/removed variant silently falls back to the default render instead of 404ing (a deleted variant file must not break a bookmarked deep link).
- **PHP**: PSR-12, `declare(strict_types=1)` at the top of every file, `final` classes, PHPStan level 8 (run `composer phpstan` after any `src/` change).
- **TDD**: write the failing test first for every PHP unit in this plan; run it red, then implement, then green. Vitest/Playwright follow the same shape where noted.
- **Docs land in the same PR as the code**, per `AGENTS.md` § *Documentation is part of the change*: any new YAML key, JSON field, or URL param updates `docs/API.md` (and `README.md` if user-facing) before the task is considered done.
- **CHANGELOG.md** `[Unreleased]` gets an entry per feature, not per task.
- English only in code/docs/commits. No emoji anywhere.

---

## Interface Assumptions (Phase 1 / Phase 2 outputs)

Phase 4 builds on top of Phase 1 (SPA rewrite) and Phase 2 (backend robustness incl. `?theme=`), neither of which exists in this checkout yet — the current `frontend/` is still Alpine 3, and `src/Router.php` / `src/Renderer.php` / `templates/render-cell.twig` have **zero** theme or variant handling today (verified by reading all three files fresh for this plan). Every task below that touches SPA code or composes with `?theme=` is written against the *assumed* Phase 1/2 contract, stated explicitly here so whoever executes this plan can diff it against what Phase 1/2 actually shipped and adjust before coding:

1. **SPA layout** (Phase 1, per `2026-07-04-phase-1-vue-rewrite.md`): `frontend/src/{App.vue, main.js, router.js, components/{Sidebar.vue,ViewportToolbar.vue,PreviewPane.vue,FieldsDrawer.vue,UsagePanel.vue,LinkBar.vue}, views/{PreviewView.vue,OverviewView.vue,FoundationsView.vue}, composables/{useViewportPreset.js,useSearchShortcuts.js}, stores/{catalog.js,ui.js,i18n.js,theme.js}, lib/{searchMatch.js,prefixTree.js,viewportMath.js,fieldsTree.js,externalLinks.js,config.js,persistedRef.js,routeInfo.js}}`. Routing IS `vue-router@4` with `createWebHistory('/styleguide/')` in `frontend/src/router.js` — NOT a hand-rolled route object. `App.vue` owns `useViewportPreset()` above `<RouterView/>`.
2. **`stores/catalog.js`** exposes `components`, `pages`, `docs` as the raw arrays returned by `/api/components`, `/api/pages`, `/api/docs` (JSON passed straight through — no re-shaping), plus a lookup helper assumed here as `findEntry(type, id)`. Because the API passthrough is verbatim, the new `variants` field (Task 1) reaches the SPA with **zero catalog.js code change** — only consumers of `findEntry()` need to read the new key.
3. **Route state lives in vue-router, not `stores/ui.js`** — the current route (type/slug) comes from `useRoute()` and the `?variant=` query param must be read via `route.query.variant` and written via `router.replace({ query: { ...route.query, variant: id ?? undefined } })`. Task 3's code snippets that show a `setVariant()` action doing manual `history.pushState` are the FALLBACK shape only — at execution time implement the same behaviour through vue-router (a `useVariant()` composable wrapping `useRoute`/`useRouter` is the natural home; keep the same test assertions). `stores/ui.js` (viewport/persisted chrome state) still gains the ephemeral `a11yResults` / `a11yRunning` state for Task 6.
4. **`components/ViewportToolbar.vue`** already receives the active catalog entry as a prop (it must — it already needs `entry.responsive` to decide whether to show the width controls, a Phase-0 feature). This plan assumes that prop is named `entry`.
5. **Phase 2 `?theme=`**: per `README.md` § URL surface (already documents the *intended* contract even though the code doesn't implement it yet — the exact doc-drift Phase 2 closes): `/styleguide/render/<kind>/<slug>` accepts `?theme=light|dark`, whitelisted, stamping `class="dark"` + matching `color-scheme` on the render-cell `<html>`. This plan assumes Phase 2 implements that whitelist **inside `Router::parse()`**, attached to route arrays the same way this plan attaches `variant` (see Task 2) — so the two params compose through the same mechanism. **If Phase 2 actually implemented `?theme=` elsewhere (e.g. read directly from `$_GET` in `Styleguide::dispatchRender()` and never touching `Router`), Task 2 Step 1 must be re-diffed against the real Phase 2 code before writing the Router changes** — the two params should end up living in the same layer, whichever layer that turns out to be.
6. **Playwright**: this plan assumes Phase 1 already stood up `tests/e2e/playwright/` with a `playwright.config.js` whose `webServer` boots the existing fixture entrypoint (`php -S 127.0.0.1:8421 -t tests/fixtures tests/fixtures/index.php` — the exact command `tests/e2e/run.sh` already uses for Layer A/B today), and a `baseURL` of `http://127.0.0.1:8421`. Task 4/5/6 Playwright specs are written against that assumption; if Phase 1 chose a different port/base, adjust the spec's relative usage (none of the specs hardcode the port beyond `page.goto('/styleguide/...')` against `baseURL`).
7. **Vitest**: assumed already configured by Phase 1 (`frontend/vitest.config.js` or inline in `vite.config.js`, `npm test` script). No new Vitest setup in this plan — only new spec files.

If any assumption above turns out false when this plan is executed, fix the assumption first (small, isolated corrective step) rather than silently reinterpreting a later task around it — a wrong shared assumption compounds across Tasks 3, 5, and 6.

---

### Task 1: `ComponentParser` variant discovery

**Files:**
- Modify: `src/ComponentParser.php`
- Modify: `tests/ComponentParserTest.php`
- Create: `tests/fixtures/templates/component/multi/multi.twig`
- Create: `tests/fixtures/templates/component/multi/styleguide.twig`
- Create: `tests/fixtures/templates/component/multi/styleguide.secondary.twig`
- Create: `tests/fixtures/templates/component/multi/styleguide.dark-bg.twig`
- Create: `tests/Api/ComponentsEndpointTest.php`
- Modify: `tests/Cli/CommandTest.php`
- Modify: `docs/API.md`

**Interfaces:**
- `ComponentParser::normaliseMetadata()` return shape gains one additive key: `'variants' => list<array{id:string,label:string}>`. Empty array when no sibling `styleguide.<variant>.twig` files exist (BC — every existing fixture/consumer template gets `variants: []`).
- New private method `discoverVariants(string $dir, array $metadata): array` — filesystem-driven (glob), NOT YAML-driven. YAML `variants:` (`map<string,string>`) supplies display labels only, keyed by variant id; an id in the YAML map with no matching `styleguide.<id>.twig` file is silently ignored (never fabricates a phantom variant).
- Variant id pattern: `/^styleguide\.([a-z0-9-]+)\.twig$/` matched against the basename — anything else glob-matched by `styleguide.*.twig` that doesn't fit this shape (e.g. a stray `styleguide.old.twig.bak`) is skipped, not errored.
- Ordering: by variant id (== filename order, since id is the only variable segment of the filename) — deterministic regardless of the OS's `glob()` return order.

- [ ] **Step 1: Add the fixture files**

`tests/fixtures/templates/component/multi/multi.twig`:
```twig
{#
name: "Multi"
category: "Block"
weight: 30
variants:
  secondary: "Secondary style"
#}
<div class="multi">Multi default body</div>
```

`tests/fixtures/templates/component/multi/styleguide.twig`:
```twig
<div class="multi multi--demo">Multi demo (default variant)</div>
```

`tests/fixtures/templates/component/multi/styleguide.secondary.twig`:
```twig
<div class="multi multi--secondary">Multi demo (secondary variant)</div>
```

`tests/fixtures/templates/component/multi/styleguide.dark-bg.twig`:
```twig
<div class="multi multi--dark-bg">Multi demo (dark-bg variant, no YAML label)</div>
```

Note the YAML only labels `secondary` — `dark-bg` has a file but no label entry, exercising the id-fallback path. Alphabetically `dark-bg` < `secondary`, so the ordering assertion below also proves ordering is NOT YAML-declaration order (YAML only mentions `secondary`) and NOT glob-arbitrary order, but strictly filename order.

- [ ] **Step 2: Write the failing tests** (`tests/ComponentParserTest.php`, appended)

```php
    #[Test]
    public function discovers_sibling_variant_files_ordered_by_filename(): void
    {
        $parser = new ComponentParser($this->fixturesPath);
        $multi = $parser->parse('component', 'multi');

        self::assertNotNull($multi);
        self::assertSame(
            [
                ['id' => 'dark-bg', 'label' => 'dark-bg'],
                ['id' => 'secondary', 'label' => 'Secondary style'],
            ],
            $multi['variants'],
            'variants are ordered by id/filename (dark-bg before secondary), labels from YAML fall back to the id',
        );
    }

    #[Test]
    public function variants_is_empty_array_when_no_sibling_variant_files_exist(): void
    {
        // BC proof: the pre-existing `sample` fixture has no styleguide.<variant>.twig
        // siblings and must not suddenly grow phantom variants.
        $parser = new ComponentParser($this->fixturesPath);
        $sample = $parser->parse('component', 'sample');

        self::assertNotNull($sample);
        self::assertSame([], $sample['variants']);
    }

    #[Test]
    public function yaml_variants_label_with_no_matching_file_is_ignored(): void
    {
        // Guards the "filesystem is canonical" rule: a YAML `variants:` entry
        // naming a variant that has no styleguide.<id>.twig file must not create
        // a phantom switcher entry. `multi`'s YAML only maps `secondary`+ignores
        // an extra `ghost` mapping we inject via parseTwigComment() directly
        // (cheaper than adding a whole new fixture just for this one assertion).
        $parser = new ComponentParser($this->fixturesPath);
        $metadata = $parser->parseTwigComment(
            "{#\nname: \"X\"\nvariants:\n  ghost: \"Ghost\"\n  secondary: \"Secondary style\"\n#}",
        );
        self::assertNotFalse($metadata);

        $multi = $parser->parse('component', 'multi');
        self::assertNotNull($multi);
        $ids = array_column($multi['variants'], 'id');
        self::assertNotContains('ghost', $ids, 'a YAML label for a file that does not exist must not appear');
    }

    #[Test]
    public function parse_all_includes_variants_field(): void
    {
        $parser = new ComponentParser($this->fixturesPath);
        $components = $parser->parseAll('component');
        $multi = current(array_filter($components, static fn(array $c): bool => $c['id'] === 'multi'));

        self::assertNotFalse($multi);
        self::assertSame(['dark-bg', 'secondary'], array_column($multi['variants'], 'id'));
    }
```

Run: `vendor/bin/phpunit --filter ComponentParserTest` — expect 4 new failures (`Undefined array key "variants"`).

- [ ] **Step 3: Implement `discoverVariants()` and wire it into both call sites**

In `src/ComponentParser.php`, add a class constant and the private method:

```php
    /**
     * Filename shape of a file-convention variant sibling: `styleguide.<id>.twig`.
     * The captured group is the canonical variant id — same character class as
     * the `?variant=` query-string whitelist in Router::parse(), so a value
     * ComponentParser can ever produce is exactly the set a URL can ever name.
     */
    private const VARIANT_FILE_PATTERN = '/^styleguide\.([a-z0-9-]+)\.twig$/';

    /**
     * Discover `styleguide.<variant>.twig` siblings in a component/page/doc
     * directory. Filesystem is canonical — the optional YAML `variants:` map
     * supplies DISPLAY LABELS only, keyed by id; a label for an id with no
     * matching file is silently dropped (never fabricates a phantom variant).
     * Plain `styleguide.twig` (no captured group) is the implicit default and
     * is never itself listed here — callers add the default separately (or,
     * for the SPA, prepend it client-side; see docs/API.md).
     *
     * @param array<string,mixed> $metadata
     * @return list<array{id:string,label:string}>
     */
    private function discoverVariants(string $dir, array $metadata): array
    {
        $labels = is_array($metadata['variants'] ?? null) ? $metadata['variants'] : [];

        $variants = [];
        foreach (glob($dir . '/styleguide.*.twig') ?: [] as $file) {
            if (!preg_match(self::VARIANT_FILE_PATTERN, basename($file), $m)) {
                continue; // not a canonical variant filename (e.g. a stray .bak) — skip, don't error
            }
            $id = $m[1];
            $label = is_string($labels[$id] ?? null) ? $labels[$id] : $id;
            $variants[] = ['id' => $id, 'label' => $label];
        }

        // Sort by id — equivalent to filename order (id is the only variable
        // segment) and deterministic across filesystems/OSes, unlike glob()'s
        // platform-dependent return order.
        usort($variants, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));

        return $variants;
    }
```

Update `normaliseMetadata()` signature and return array:

```php
    /**
     * @param array<string,mixed> $metadata
     * @param list<array{id:string,label:string}> $variants
     * @return array<string,mixed>
     */
    private function normaliseMetadata(string $id, array $metadata, bool $hasStyleguide, array $variants): array
    {
        return [
            'id' => $id,
            'name' => $metadata['name'],
            'category' => $metadata['category'] ?? '',
            'description' => $metadata['description'] ?? '',
            'asana' => $metadata['asana'] ?? '',
            'figma' => $metadata['figma'] ?? '',
            'drupal' => $metadata['drupal'] ?? '',
            'web' => $metadata['web'] ?? '',
            'weight' => isset($metadata['weight']) ? (int) $metadata['weight'] : 50,
            'usage' => $metadata['usage'] ?? '',
            'fields' => $metadata['fields'] ?? [],
            'render' => self::normaliseRender($metadata['render'] ?? null),
            'body_class' => $metadata['body_class'] ?? '',
            'responsive' => ($metadata['responsive'] ?? true) !== false,
            'hasStyleguide' => $hasStyleguide,
            // Additive (v0.9.0). Auto-discovered styleguide.<variant>.twig
            // siblings; [] when none exist — every pre-Phase-4 template keeps
            // this BC default. Default variant is implicit, never listed here.
            'variants' => $variants,
        ];
    }
```

Update both call sites — `parse()`:

```php
        $hasStyleguide = file_exists($dir . '/styleguide.twig')
            || isset($metadata['styleguide']);
        $variants = $this->discoverVariants($dir, $metadata);

        return $this->normaliseMetadata($id, $metadata, $hasStyleguide, $variants);
```

and `parseAll()`'s loop body:

```php
            $id = $file->getBasename('.twig');
            $hasStyleguide = file_exists($file->getPath() . '/styleguide.twig')
                || isset($metadata['styleguide']);
            $variants = $this->discoverVariants($file->getPath(), $metadata);

            $items[] = $this->normaliseMetadata($id, $metadata, $hasStyleguide, $variants);
```

- [ ] **Step 4: Run PHP tests + phpstan**

Run: `vendor/bin/phpunit --filter ComponentParserTest`
Expected: all pass, including the 4 new ones.

Run: `composer phpstan`
Expected: no new errors (the new method has full param/return docblocks; `glob()` return is nullable-array-coerced via `?: []`).

- [ ] **Step 5: `/api/components` passthrough test** (`tests/Api/ComponentsEndpointTest.php`, new file — none existed before this task)

```php
<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests\Api;

use Parisek\Styleguide\Api\ComponentsEndpoint;
use Parisek\Styleguide\ComponentParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ComponentsEndpointTest extends TestCase
{
    #[Test]
    public function handle_passes_through_the_variants_field(): void
    {
        $parser = new ComponentParser(__DIR__ . '/../fixtures/templates');
        $endpoint = new ComponentsEndpoint($parser);

        ob_start();
        $endpoint->handle();
        $output = ob_get_clean();

        $data = json_decode((string) $output, true);
        self::assertIsArray($data);

        $multi = current(array_filter($data, static fn(array $c): bool => $c['id'] === 'multi'));
        self::assertNotFalse($multi, 'multi fixture must appear in /api/components');
        self::assertSame(['dark-bg', 'secondary'], array_column($multi['variants'], 'id'));

        $sample = current(array_filter($data, static fn(array $c): bool => $c['id'] === 'sample'));
        self::assertNotFalse($sample);
        self::assertSame([], $sample['variants'], 'a component with no variant siblings gets variants: []');
    }
}
```

Run: `vendor/bin/phpunit --filter ComponentsEndpointTest` — expect pass (no PHP change needed here; `ComponentsEndpoint::handle()` already serializes `parseAll()` verbatim — this test documents/locks the passthrough).

- [ ] **Step 6: CLI passthrough — extend existing test, no CLI code change**

`ComponentParser::parse()`/`parseAll()` already back `vendor/bin/styleguide list`/`show` (per `docs/API.md` § CLI: "Shape matches `/api/components`"). Add one assertion to `tests/Cli/CommandTest.php`'s existing `show` test (the one asserting `$decoded['category']` etc. — see the method around line 71-79) confirming the new field survives the CLI's own JSON round-trip:

```php
        // Added for v0.9.0 file-convention variants — no CLI code changed;
        // this locks the passthrough the same way the endpoint test does.
        self::assertArrayHasKey('variants', $decoded);
```

(Insert this line into the existing `show` test body right after the existing `assertSame` lines for the `sample` id — no new test method needed, this is a one-line addition to an existing one to avoid a near-duplicate CLI process spawn.)

Run: `vendor/bin/phpunit --filter CommandTest` — expect pass.

- [ ] **Step 7: Update `docs/API.md`**

In § *Component YAML metadata*, add a row to the table (after `body_class`):

```markdown
| `variants` | no | map `<variant-id>: <label>` | `[]` (absent) | Display labels for auto-discovered `styleguide.<variant>.twig` sibling files — see *Component Twig file conventions*. Filesystem is canonical: a label entry with no matching sibling file is ignored, never fabricates a variant. |
```

In § *Component Twig file conventions*, add a bullet after the existing `styleguide.twig` one:

```markdown
- `<id>/styleguide.<variant>.twig` — OPTIONAL, zero or more. `<variant>` matches `[a-z0-9-]+`. Auto-discovered (no YAML required); when at least one exists, the SPA toolbar shows a variant switcher and `?variant=<id>` becomes a valid query param on the SPA deep link and the render endpoint. Plain `styleguide.twig` remains the implicit default variant.
```

In § *JSON API endpoints* → `GET /styleguide/api/components` ts shape, add (after `hasStyleguide`):

```ts
  variants: Array<{ id: string; label: string }>; // [] when no sibling styleguide.<variant>.twig files exist
```

Note in the same section that `/api/pages` and `/api/docs` inherit the identical additive field (already true by construction — same `normaliseMetadata()`).

- [ ] **Step 8: Commit**

`git add src/ComponentParser.php tests/ComponentParserTest.php tests/Api/ComponentsEndpointTest.php tests/Cli/CommandTest.php tests/fixtures/templates/component/multi docs/API.md`
`git commit -m "feat(parser): discover styleguide.<variant>.twig sibling files"`

---

### Task 2: `Router` + `Renderer` variant rendering

**Files:**
- Modify: `src/Router.php`
- Modify: `src/Renderer.php`
- Modify: `src/Styleguide.php` (`dispatchRender()`)
- Modify: `tests/RouterTest.php`
- Modify: `tests/RendererTest.php`

**Interfaces:**
- `Router::parse()` return shape gains two **optional** keys, `theme?:string` and `variant?:string`, attached only to route types `render`, `component`, `page`, `doc` (the four kinds that ever reach `Renderer::render()`, directly or via `synthesizeEmbeddedRoute()`), and only when the corresponding query param is present AND matches its whitelist regex. Absent/invalid → key omitted entirely (not `null` — preserves every existing `assertSame` in `RouterTest` byte-for-byte).
- `Router::synthesizeEmbeddedRoute()` forwards `theme`/`variant` from the original route onto the synthesized `render` route — today it drops every key except `type`/`kind`/`slug`, which would silently strip the query param the instant a consumer's own iframe embed triggers the `Sec-Fetch-Dest: iframe` swap.
- `Renderer::render()`'s `$config` array gains one more optional key, `variant?:string`, read the same defensive way `render`/`body_class` already are (`$config['variant'] ?? null`), threaded into `renderInner()`.
- `Renderer::renderInner()` gains a third param `?string $variant`. Resolution order becomes: `styleguide.<variant>.twig` (only if `$variant` is non-null AND passes the same `^[a-z0-9-]+$` regex Router already checked — re-checked here defensively, since `Renderer` is unit-tested and called directly, bypassing `Router`, in this very test suite) → `styleguide.twig` → `<slug>.twig`. An unknown/invalid variant never reaches the candidate list — it behaves exactly as if `$variant` were `null`, which is the "fall back silently" contract from the Global Constraints.
- **Assumption re: `?theme=` composition** (see *Interface Assumptions* #5): this task's last test step asserts a `theme` + `variant` combination renders correctly assuming Phase 2 landed `?theme=` exactly as `README.md` already documents (`class="dark"` on the render-cell `<html>`, driven by a `theme` key in `Renderer`'s `$config`). **Before writing that sub-step, confirm this against whatever Phase 2 actually shipped** — if the real mechanism differs, adapt the assertion's markup expectation only; do not change the variant-resolution logic to match a guess.

- [ ] **Step 1: Write the failing `Router` tests** (`tests/RouterTest.php`, appended)

```php
    #[Test]
    public function whitelists_variant_query_param_on_render_route(): void
    {
        self::assertSame(
            ['type' => 'render', 'kind' => 'component', 'slug' => 'multi', 'variant' => 'secondary'],
            Router::parse('/styleguide/render/component/multi?variant=secondary'),
        );
    }

    #[Test]
    public function whitelists_variant_query_param_on_spa_shell_routes(): void
    {
        // component/page/doc SPA routes also carry it — needed so
        // synthesizeEmbeddedRoute() can forward it when a consumer embeds
        // one of these URLs directly in an iframe.
        self::assertSame(
            ['type' => 'component', 'slug' => 'multi', 'variant' => 'secondary'],
            Router::parse('/styleguide/component/multi?variant=secondary'),
        );
        self::assertSame(
            ['type' => 'page', 'slug' => 'homepage', 'variant' => 'secondary'],
            Router::parse('/styleguide/page/homepage?variant=secondary'),
        );
        self::assertSame(
            ['type' => 'doc', 'slug' => 'changelog', 'variant' => 'secondary'],
            Router::parse('/styleguide/doc/changelog?variant=secondary'),
        );
    }

    #[Test]
    public function drops_invalid_variant_query_param(): void
    {
        // Uppercase, whitespace, dot-segments, slashes — none of these can ever
        // be a real filename ComponentParser discovers, so they're dropped at
        // parse time rather than reaching Renderer at all.
        foreach (['UPPER', 'has space', '../../etc', 'a/b', ''] as $bad) {
            self::assertSame(
                ['type' => 'component', 'slug' => 'multi'],
                Router::parse('/styleguide/component/multi?variant=' . rawurlencode($bad)),
                "variant='$bad' must be dropped",
            );
        }
    }

    #[Test]
    public function variant_and_theme_query_params_coexist(): void
    {
        // Phase 2 assumption — see plan's Interface Assumptions #5. Both
        // whitelists are independent regexes over the same query string.
        self::assertSame(
            ['type' => 'render', 'kind' => 'component', 'slug' => 'multi', 'theme' => 'dark', 'variant' => 'secondary'],
            Router::parse('/styleguide/render/component/multi?theme=dark&variant=secondary'),
        );
    }

    #[Test]
    public function unrelated_query_params_are_still_ignored(): void
    {
        // Existing BC proof (mirrors strips_query_string above) extended to
        // confirm an unrelated param never leaks a stray key onto the route.
        self::assertSame(
            ['type' => 'component', 'slug' => 'hero'],
            Router::parse('/styleguide/component/hero?lang=cs'),
        );
    }

    #[Test]
    public function synthesize_embedded_carries_variant_and_theme_through(): void
    {
        self::assertSame(
            ['type' => 'render', 'kind' => 'component', 'slug' => 'multi', 'theme' => 'dark', 'variant' => 'secondary'],
            Router::synthesizeEmbeddedRoute(
                ['type' => 'component', 'slug' => 'multi', 'theme' => 'dark', 'variant' => 'secondary'],
                'iframe',
            ),
        );
    }

    #[Test]
    public function synthesize_embedded_omits_variant_and_theme_when_absent(): void
    {
        // Existing behaviour (asserted already by
        // synthesize_embedded_swaps_component_route_for_render above) must
        // stay byte-for-byte identical when neither param was present.
        self::assertSame(
            ['type' => 'render', 'kind' => 'component', 'slug' => 'hero'],
            Router::synthesizeEmbeddedRoute(['type' => 'component', 'slug' => 'hero'], 'iframe'),
        );
    }
```

Run: `vendor/bin/phpunit --filter RouterTest` — expect the new tests to fail (undefined `theme`/`variant` keys never appear today).

- [ ] **Step 2: Implement the `Router` changes**

Rename today's `parse()` body (everything from `$uri = (string) strtok($uri, '?');` through the final `return ['type' => 'landing'];`) into a new private `parseRoute(string $uri): ?array`, taking an already-trimmed, query-stripped path. Add a new public `parse()` that extracts the query string first, delegates, then whitelists:

```php
    /**
     * @return array{type:string,slug?:string,kind?:string,endpoint?:string,path?:string,theme?:string,variant?:string}|null
     */
    public static function parse(string $uri): ?array
    {
        $queryPos = strpos($uri, '?');
        $queryString = $queryPos === false ? '' : substr($uri, $queryPos + 1);
        $path = $queryPos === false ? $uri : substr($uri, 0, $queryPos);
        $path = rtrim($path, '/');

        $route = self::parseRoute($path);

        return $route === null ? null : self::applyQueryWhitelist($route, $queryString);
    }

    /**
     * @return array{type:string,slug?:string,kind?:string,endpoint?:string,path?:string}|null
     */
    private static function parseRoute(string $uri): ?array
    {
        if ($uri === '/styleguide') {
            return ['type' => 'landing'];
        }

        if (!str_starts_with($uri, '/styleguide/')) {
            return null;
        }

        $path = substr($uri, strlen('/styleguide/'));
        $parts = explode('/', $path);

        if ($parts[0] === 'assets') {
            return ['type' => 'asset', 'path' => implode('/', array_slice($parts, 1))];
        }

        if ($parts[0] === 'render' && count($parts) >= 3) {
            return ['type' => 'render', 'kind' => $parts[1], 'slug' => $parts[2]];
        }

        if ($parts[0] === 'api' && isset($parts[1])) {
            return ['type' => 'api', 'endpoint' => $parts[1]];
        }

        if (in_array($parts[0], ['component', 'page', 'doc'], true) && isset($parts[1])) {
            return ['type' => $parts[0], 'slug' => $parts[1]];
        }

        if (in_array($parts[0], ['overview', 'foundations', 'fields'], true)) {
            return ['type' => $parts[0]];
        }

        return ['type' => 'landing'];
    }

    /**
     * Whitelist `?theme=` / `?variant=` onto route types that ever reach
     * Renderer::render() (directly, or via synthesizeEmbeddedRoute()).
     * Syntactic only — existence (does this variant's file actually exist?)
     * is resolved downstream by Renderer against ComponentParser's discovered
     * set, never trusted from the query string here.
     *
     * @param array{type:string,slug?:string,kind?:string,endpoint?:string,path?:string} $route
     * @return array{type:string,slug?:string,kind?:string,endpoint?:string,path?:string,theme?:string,variant?:string}
     */
    private static function applyQueryWhitelist(array $route, string $queryString): array
    {
        if (!in_array($route['type'], ['render', 'component', 'page', 'doc'], true)) {
            return $route;
        }

        parse_str($queryString, $query);

        if (isset($query['theme']) && is_string($query['theme']) && preg_match('/^(light|dark)$/', $query['theme']) === 1) {
            $route['theme'] = $query['theme'];
        }

        if (isset($query['variant']) && is_string($query['variant']) && preg_match('/^[a-z0-9-]+$/', $query['variant']) === 1) {
            $route['variant'] = $query['variant'];
        }

        return $route;
    }
```

Update `synthesizeEmbeddedRoute()` to forward the two keys:

```php
    /**
     * @param array{type:string,slug?:string,kind?:string,endpoint?:string,path?:string,theme?:string,variant?:string} $route
     * @return array{type:string,slug?:string,kind?:string,endpoint?:string,path?:string,theme?:string,variant?:string}
     */
    public static function synthesizeEmbeddedRoute(array $route, string $secFetchDest): array
    {
        if ($secFetchDest !== 'iframe') {
            return $route;
        }
        if (!in_array($route['type'] ?? null, ['component', 'page', 'doc', 'foundations'], true)) {
            return $route;
        }

        $synthesized = [
            'type' => 'render',
            'kind' => $route['type'],
            'slug' => $route['slug'] ?? 'index',
        ];

        // theme (Phase 2) / variant (Phase 4) were attached to the ORIGINAL
        // component/page/doc route at parse() time; without forwarding them
        // here, the Sec-Fetch-Dest: iframe swap would silently drop back to
        // the default theme/variant the moment a consumer embeds one of
        // these URLs directly (see Global Constraints — a deleted variant
        // file already falls back gracefully; losing the param here would be
        // the same UX bug for a param that's still perfectly valid).
        foreach (['theme', 'variant'] as $key) {
            if (isset($route[$key])) {
                $synthesized[$key] = $route[$key];
            }
        }

        return $synthesized;
    }
```

- [ ] **Step 3: Run `RouterTest`**

Run: `vendor/bin/phpunit --filter RouterTest`
Expected: all pass (new + every pre-existing assertion, unchanged).

- [ ] **Step 4: Write the failing `Renderer` tests** (`tests/RendererTest.php`, appended — reuses the `multi` fixture from Task 1)

```php
    #[Test]
    public function renders_named_variant_when_it_exists(): void
    {
        $html = $this->renderer->render('component', 'multi', [
            'project' => ['name' => 'TestProject'],
            'iframe' => [],
            'variant' => 'secondary',
        ], 'en');

        self::assertStringContainsString('multi--secondary', $html);
        self::assertStringNotContainsString('multi--demo', $html);
    }

    #[Test]
    public function falls_back_to_default_variant_for_unknown_variant(): void
    {
        // A deleted/renamed variant file must not 404 a bookmarked deep link —
        // it falls through to the same default chain as no variant at all.
        $html = $this->renderer->render('component', 'multi', [
            'project' => ['name' => 'TestProject'],
            'iframe' => [],
            'variant' => 'retired',
        ], 'en');

        self::assertSame(200, http_response_code());
        self::assertStringContainsString('multi--demo', $html);
        http_response_code(200);
    }

    #[Test]
    public function falls_back_to_default_variant_when_variant_key_is_malformed(): void
    {
        // Renderer re-validates the same regex Router already checked —
        // defensive because Renderer is called directly here, bypassing
        // Router entirely, matching the existing normaliseRender() precedent.
        $html = $this->renderer->render('component', 'multi', [
            'project' => ['name' => 'TestProject'],
            'iframe' => [],
            'variant' => '../../etc/passwd',
        ], 'en');

        self::assertStringContainsString('multi--demo', $html);
    }

    #[Test]
    public function renders_default_variant_when_no_variant_requested(): void
    {
        $html = $this->renderer->render('component', 'multi', [
            'project' => ['name' => 'TestProject'],
            'iframe' => [],
        ], 'en');

        self::assertStringContainsString('multi--demo', $html);
    }

    #[Test]
    public function variant_composes_with_theme(): void
    {
        // Interface Assumption #5 — adjust the `theme`-side assertion if
        // Phase 2 shipped a different mechanism than README.md documents.
        $html = $this->renderer->render('component', 'multi', [
            'project' => ['name' => 'TestProject'],
            'iframe' => [],
            'variant' => 'secondary',
            'theme' => 'dark',
        ], 'en');

        self::assertStringContainsString('multi--secondary', $html, 'variant still resolves with theme set');
        self::assertStringContainsString('class="dark"', $html, 'theme still stamps the <html> class with variant set');
    }
```

Run: `vendor/bin/phpunit --filter RendererTest` — expect the first four to fail (`multi` fixture doesn't resolve any variant yet — `render()` doesn't read `$config['variant']`); the fifth (`variant_composes_with_theme`) fails today regardless, since `?theme=` doesn't exist in this checkout — leave it red until Phase 2's actual theme mechanism is confirmed per the Step 0 callout above, then adjust and turn it green as part of this task.

- [ ] **Step 5: Implement the `Renderer` changes**

In `renderInner()`:

```php
    /**
     * Look up the actual component/page Twig template and render it with the
     * styleguide context.
     *
     * Resolution order: the requested variant sibling (if `$variant` is a
     * valid id AND the file exists) → the default `styleguide.twig` demo file
     * → the component's own `<slug>.twig`. An invalid or unknown variant
     * behaves exactly as if none were requested — see Global Constraints.
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
```

Update `renderBody()`'s dispatch to pass the variant through:

```php
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
```

No change needed to `render()` itself — `$config` already flows into `renderBody($kind, $slug, $config)` unmodified.

- [ ] **Step 6: Run `RendererTest` + phpstan**

Run: `vendor/bin/phpunit --filter RendererTest`
Expected: all pass (adjust the `variant_composes_with_theme` assertion first if Phase 2's real mechanism differs from the README-documented one — see Step 4 note).

Run: `composer phpstan`
Expected: no new errors.

- [ ] **Step 7: Wire `Styleguide::dispatchRender()`**

In `src/Styleguide.php`, inside the existing `if (in_array($route['kind'], ['component', 'page', 'doc'], true))` block of `dispatchRender()` (the block that already sets `component_name`, `body_class`, `render`), add:

```php
            // File-convention variant (v0.9.0) — Router::parse() has already
            // syntactically whitelisted this; Renderer re-validates existence
            // against the actual styleguide.<variant>.twig files and falls
            // back to the default variant for anything that doesn't resolve.
            if (isset($route['variant']) && is_string($route['variant'])) {
                $config['variant'] = $route['variant'];
            }
```

- [ ] **Step 8: Full-stack smoke via the fixture server**

Run:
```bash
php -S 127.0.0.1:8433 -t tests/fixtures tests/fixtures/index.php &
sleep 1
curl -s http://127.0.0.1:8433/styleguide/render/component/multi | grep -o 'multi--[a-z-]*'
curl -s "http://127.0.0.1:8433/styleguide/render/component/multi?variant=secondary" | grep -o 'multi--[a-z-]*'
curl -s "http://127.0.0.1:8433/styleguide/render/component/multi?variant=retired" | grep -o 'multi--[a-z-]*'
kill %1
```
Expected output: `multi--demo`, then `multi--secondary`, then `multi--demo` again (unknown variant falls back).

- [ ] **Step 9: Commit**

`git add src/Router.php src/Renderer.php src/Styleguide.php tests/RouterTest.php tests/RendererTest.php`
`git commit -m "feat(render): resolve ?variant= against discovered styleguide.<variant>.twig files"`

---

### Task 3: SPA variant switcher

**Files:**
- Modify: `frontend/src/stores/ui.js` (per Interface Assumptions)
- Modify: `frontend/src/components/ViewportToolbar.vue`
- Create: `frontend/src/components/ViewportToolbar.spec.js` (Vitest)
- Create: `frontend/src/stores/ui.spec.js` (Vitest, if not already covering `route`)
- Modify: `frontend/public/locales/en.json`, `frontend/public/locales/cs.json`

**Interfaces:**
- New i18n keys, exact names and values:

  | Key | `en.json` | `cs.json` |
  |---|---|---|
  | `toolbar.variant_label` | `"Variant"` | `"Varianta"` |
  | `toolbar.variant_default` | `"Default"` | `"Výchozí"` |

- `ui.js` store gains `route.variant` (`string \| null`, default `null`) and an action assumed here as `setVariant(id: string \| null): void` — merges into `route`, pushes `?variant=<id>` (or removes the param when `id` is `null`) onto the current URL via the same `history.pushState` mechanism `setRoute()` already uses for `type`/`slug`.
- `ViewportToolbar.vue` reads `props.entry.variants` (the `Array<{id,label}>` from Task 1's API field, `[]` default) and `ui.route.variant`. The switcher renders only when `entry.variants.length > 0`.
- Navigating to a different `entry` resets `ui.route.variant` to `null` UNLESS the incoming URL itself carries a valid `?variant=` for that entry (the deep-link case) — "reset silently" per the design doc, i.e. no confirmation, no flash of the old variant's content.

- [ ] **Step 1: `ui.js` store — variant state + URL sync (Vitest first)**

`frontend/src/stores/ui.spec.js` (new; if Phase 1 already ships a `route`-focused spec file, add these as new `describe` blocks in it instead of a new file):

```js
import { describe, it, expect, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useUiStore } from './ui.js';

describe('ui store — variant', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        history.replaceState(null, '', '/styleguide/component/multi');
    });

    it('defaults route.variant to null', () => {
        const ui = useUiStore();
        expect(ui.route.variant).toBeNull();
    });

    it('setVariant writes the id into route and the URL query', () => {
        const ui = useUiStore();
        ui.setRoute({ type: 'component', slug: 'multi' });
        ui.setVariant('secondary');
        expect(ui.route.variant).toBe('secondary');
        expect(new URL(location.href).searchParams.get('variant')).toBe('secondary');
    });

    it('setVariant(null) removes the query param', () => {
        const ui = useUiStore();
        ui.setRoute({ type: 'component', slug: 'multi' });
        ui.setVariant('secondary');
        ui.setVariant(null);
        expect(ui.route.variant).toBeNull();
        expect(new URL(location.href).searchParams.has('variant')).toBe(false);
    });

    it('setRoute to a different entry resets variant unless the URL already carries one', () => {
        const ui = useUiStore();
        ui.setRoute({ type: 'component', slug: 'multi' });
        ui.setVariant('secondary');

        ui.setRoute({ type: 'component', slug: 'other' }); // no variant in the patch
        expect(ui.route.variant).toBeNull();

        ui.setRoute({ type: 'component', slug: 'multi', variant: 'secondary' }); // deep link case
        expect(ui.route.variant).toBe('secondary');
    });
});
```

Run: `cd frontend && npm test -- ui.spec.js` — expect failures (`useUiStore` has no `setVariant`, `route.variant` undefined).

Implement in `stores/ui.js` (adjust to the store's real existing shape once Phase 1 lands; sketch against the assumed shape from Interface Assumptions #3):

```js
// route.variant: string|null — file-convention variant selected for the
// current entry (Task 1: ComponentParser discovery). null = the implicit
// default variant. Reset on every setRoute() call UNLESS the incoming patch
// itself specifies one (the deep-link case: `?variant=` was already on the
// URL when the SPA booted or the user pasted a link).
setRoute(patch) {
    this.route = { ...this.route, variant: null, ...patch };
    this.syncUrl();
},

setVariant(id) {
    this.route = { ...this.route, variant: id };
    this.syncUrl();
},

// (assumed existing) syncUrl() already pushes type/slug into location —
// extend it to also read/write `variant`:
syncUrl() {
    const url = new URL(location.href);
    if (this.route.variant) {
        url.searchParams.set('variant', this.route.variant);
    } else {
        url.searchParams.delete('variant');
    }
    history.pushState(null, '', url);
},
```

Run: `cd frontend && npm test -- ui.spec.js` — expect pass.

- [ ] **Step 2: i18n keys**

`frontend/public/locales/en.json` — add to the existing `"toolbar"` object (after `"more_actions"`):
```json
        "more_actions": "More actions",
        "variant_label": "Variant",
        "variant_default": "Default"
```

`frontend/public/locales/cs.json` — same keys, Czech values (mirror the existing object's position):
```json
        "variant_label": "Varianta",
        "variant_default": "Výchozí"
```

- [ ] **Step 3: `ViewportToolbar.vue` — segmented switcher (Vitest for the component)**

`frontend/src/components/ViewportToolbar.spec.js` (new):

```js
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { createTestingPinia } from '@pinia/testing';
import ViewportToolbar from './ViewportToolbar.vue';

function mountToolbar(entry) {
    return mount(ViewportToolbar, {
        props: { entry },
        global: { plugins: [createTestingPinia({ createSpy: () => () => {} })] },
    });
}

describe('ViewportToolbar — variant switcher', () => {
    it('is absent when the entry has no variants', () => {
        const wrapper = mountToolbar({ id: 'sample', variants: [] });
        expect(wrapper.find('[data-testid="variant-switcher"]').exists()).toBe(false);
    });

    it('renders Default + each discovered variant when variants exist', () => {
        const wrapper = mountToolbar({
            id: 'multi',
            variants: [
                { id: 'dark-bg', label: 'dark-bg' },
                { id: 'secondary', label: 'Secondary style' },
            ],
        });
        const switcher = wrapper.find('[data-testid="variant-switcher"]');
        expect(switcher.exists()).toBe(true);
        const labels = switcher.findAll('button').map((b) => b.text());
        expect(labels).toEqual(['Default', 'dark-bg', 'Secondary style']);
    });

    it('clicking a variant button calls ui.setVariant with its id', async () => {
        const wrapper = mountToolbar({
            id: 'multi',
            variants: [{ id: 'secondary', label: 'Secondary style' }],
        });
        const ui = wrapper.vm.$pinia._s.get('ui'); // testing-pinia store instance
        const buttons = wrapper.find('[data-testid="variant-switcher"]').findAll('button');
        await buttons[1].trigger('click'); // index 0 = Default
        expect(ui.setVariant).toHaveBeenCalledWith('secondary');
    });
});
```

Run: `cd frontend && npm test -- ViewportToolbar.spec.js` — expect failures (no switcher markup yet).

Add to `ViewportToolbar.vue` template (near the existing viewport-preset controls — same toolbar row):

```html
<div
    v-if="entry.variants && entry.variants.length > 0"
    data-testid="variant-switcher"
    role="group"
    :aria-label="$t('toolbar.variant_label')"
    class="inline-flex items-center gap-0.5 rounded-md bg-zinc-100 p-0.5 dark:bg-zinc-800"
>
    <button
        type="button"
        class="rounded px-2 py-1 text-xs font-medium transition"
        :class="!ui.route.variant ? 'bg-white shadow-sm dark:bg-zinc-700' : 'text-zinc-500 hover:text-zinc-900 dark:hover:text-white'"
        @click="ui.setVariant(null)"
    >{{ $t('toolbar.variant_default') }}</button>
    <button
        v-for="v in entry.variants"
        :key="v.id"
        type="button"
        class="rounded px-2 py-1 text-xs font-medium transition"
        :class="ui.route.variant === v.id ? 'bg-white shadow-sm dark:bg-zinc-700' : 'text-zinc-500 hover:text-zinc-900 dark:hover:text-white'"
        @click="ui.setVariant(v.id)"
    >{{ v.label }}</button>
</div>
```

and in the `<script setup>` block, ensure `ui` (the Pinia store) and `entry` (prop) are in scope — both already assumed present per Interface Assumptions #4.

The iframe `src` computation (wherever `PreviewPane`/`ViewportToolbar` builds the `render/<kind>/<slug>` URL) appends `variant` when set:

```js
const iframeSrc = computed(() => {
    const url = new URL(`/styleguide/render/${props.entry.type}/${props.entry.id}`, location.origin);
    if (ui.route.variant) url.searchParams.set('variant', ui.route.variant);
    return url.pathname + url.search;
});
```

(Adjust to the real existing `iframeSrc`/equivalent computed once Phase 1 lands — the only new line is the `variant` query append; everything else in that computed already exists for `?theme=` per Interface Assumptions #5 and should follow the identical pattern.)

Run: `cd frontend && npm test -- ViewportToolbar.spec.js` — expect pass.

- [ ] **Step 4: Build + manual smoke against the fixture**

Run: `cd frontend && npm run build`
Expected: build succeeds, `dist/` updates.

Per `AGENTS.md` § *Browser Verification*: load the `multi` fixture's styleguide page (`/styleguide/component/multi`) against the fixture PHP server or the `tailwind-base` symlink workflow, confirm the switcher shows "Default / dark-bg / Secondary style", clicking each swaps the iframe body and updates the address bar's `?variant=`.

- [ ] **Step 5: Commit**

`git add frontend/src frontend/public/locales frontend/dist 2>/dev/null; git add dist`
`git commit -m "feat(spa): variant switcher in the preview toolbar"`

---

### Task 4: Playwright e2e — variants

**Files:**
- Create: `tests/e2e/playwright/variants.spec.js`
- Modify: `tests/fixtures/templates/component/multi/*` (reuse Task 1's fixture — no new fixture files needed)

**Interfaces:** per Interface Assumptions #6 — `playwright.config.js`'s `webServer` already boots `php -S 127.0.0.1:8421 -t tests/fixtures tests/fixtures/index.php`; specs use `page.goto('/styleguide/...')` against the configured `baseURL`.

- [ ] **Step 1: Write the spec**

```js
// tests/e2e/playwright/variants.spec.js
import { test, expect } from '@playwright/test';

test.describe('file-convention variants', () => {
    test('switcher is hidden for a single-variant component (no siblings)', async ({ page }) => {
        await page.goto('/styleguide/component/sample');
        await expect(page.getByTestId('variant-switcher')).toHaveCount(0);
    });

    test('switcher is visible for a multi-variant component', async ({ page }) => {
        await page.goto('/styleguide/component/multi');
        const switcher = page.getByTestId('variant-switcher');
        await expect(switcher).toBeVisible();
        await expect(switcher.getByRole('button')).toHaveText(['Default', 'dark-bg', 'Secondary style']);
    });

    test('clicking a variant reloads the iframe with ?variant= and shows its content', async ({ page }) => {
        await page.goto('/styleguide/component/multi');
        const iframe = page.frameLocator('iframe');
        await expect(iframe.locator('.multi')).toContainText('default variant');

        await page.getByTestId('variant-switcher').getByRole('button', { name: 'Secondary style' }).click();

        await expect(page).toHaveURL(/variant=secondary/);
        await expect(page.locator('iframe')).toHaveAttribute('src', /variant=secondary/);
        await expect(iframe.locator('.multi')).toContainText('secondary variant');
    });

    test('deep link with ?variant= lands with that variant selected', async ({ page }) => {
        await page.goto('/styleguide/component/multi?variant=secondary');
        const switcher = page.getByTestId('variant-switcher');
        await expect(switcher.getByRole('button', { name: 'Secondary style' })).toHaveAttribute('class', /bg-white/);
        const iframe = page.frameLocator('iframe');
        await expect(iframe.locator('.multi')).toContainText('secondary variant');
    });
});
```

- [ ] **Step 2: Run**

Run (from `tests/e2e/playwright/`): `npx playwright test variants.spec.js`
Expected: 4 passed. If the fixture server / config path differs from the Interface Assumption, fix the config first (do not hand-roll a second server-boot mechanism inside the spec).

- [ ] **Step 3: Commit**

`git add tests/e2e/playwright/variants.spec.js`
`git commit -m "test(e2e): variant switcher + deep links"`

---

### Task 5: Search upgrade — command palette

**Files:**
- Modify: `frontend/src/lib/searchMatch.js`
- Create: `frontend/src/lib/searchMatch.spec.js`
- Create: `frontend/src/components/SearchPalette.vue` (replaces/wraps whatever thin `⌘K` shell Phase 1 ported from today's `components/search.js`)
- Modify: `frontend/src/components/Sidebar.vue` (keep the existing filter input, re-point it at the same scoring function)
- Create: `frontend/src/components/SearchPalette.spec.js`
- Create: `tests/e2e/playwright/search-palette.spec.js`
- Modify: `frontend/public/locales/en.json`, `frontend/public/locales/cs.json`

**Interfaces:**
- Per Interface Assumptions #1, `lib/searchMatch.js` already exports (from Phase 1's port of today's diacritic-handling) something equivalent to `foldDiacritics(str): string` (NFKD fold + lowercase + strip combining marks) and a boolean `isMatch(query, text): boolean` built on it. This task ADDS a new export, `scoreEntry(query, entry): number`, and leaves the existing two untouched (the sidebar's plain filter input keeps using whichever one it already used — only the palette needs ranked, multi-field scoring).
- `scoreEntry(query, entry)` — pure function, no Vue/DOM. Returns `0` for "no match on any field" (both callers filter out zero-scores when `query` is non-empty); higher is better. Field weights, exact/prefix/substring tiers spelled out in Step 1.
- New i18n keys:

  | Key | `en.json` | `cs.json` |
  |---|---|---|
  | `search.no_results` | `"No results"` | `"Žádné výsledky"` |
  | `search.group_components` | `"Components"` | `"Komponenty"` |
  | `search.group_pages` | `"Pages"` | `"Stránky"` |
  | `search.group_docs` | `"Documentation"` | `"Dokumentace"` |
  | `search.hint_navigate` | `"↵ open"` | `"↵ otevřít"` |
  | `search.hint_close` | `"Esc close"` | `"Esc zavřít"` |

- [ ] **Step 1: Scoring function (Vitest first)**

`frontend/src/lib/searchMatch.spec.js` (new — add alongside whatever Phase 1 already tests `foldDiacritics`/`isMatch` under):

```js
import { describe, it, expect } from 'vitest';
import { scoreEntry } from './searchMatch.js';

const entry = (overrides) => ({
    id: 'hlavicka-sticky',
    name: 'Hlavička - sticky',
    category: 'Blocks',
    description: '<p>Sticky <strong>header</strong> variant</p>',
    ...overrides,
});

describe('scoreEntry', () => {
    it('returns 0 when no field matches', () => {
        expect(scoreEntry('zzz', entry())).toBe(0);
    });

    it('matches diacritic-folded query against name (accent-insensitive)', () => {
        expect(scoreEntry('hlavicka', entry())).toBeGreaterThan(0);
    });

    it('weighs name matches higher than description matches', () => {
        const nameHit = scoreEntry('hlavička', entry());
        const descOnly = scoreEntry('header', entry({ name: 'Something else', id: 'x' }));
        expect(nameHit).toBeGreaterThan(descOnly);
    });

    it('strips HTML before matching description', () => {
        expect(scoreEntry('strong', entry({ name: 'X', id: 'x' }))).toBe(0); // the TAG text itself isn't content
        expect(scoreEntry('header', entry({ name: 'X', id: 'x' }))).toBeGreaterThan(0);
    });

    it('ranks an exact field match above a prefix match above a substring match', () => {
        const exact = scoreEntry('hlavička - sticky', entry());
        const prefix = scoreEntry('hlavička', entry());
        const substring = scoreEntry('sticky', entry());
        expect(exact).toBeGreaterThan(prefix);
        expect(prefix).toBeGreaterThan(substring);
    });

    it('matches against id as well as name', () => {
        expect(scoreEntry('hlavicka-sticky', entry({ name: 'Different display name' }))).toBeGreaterThan(0);
    });

    it('empty query matches nothing meaningfully (score 0, caller decides display)', () => {
        expect(scoreEntry('', entry())).toBe(0);
    });
});
```

Run: `cd frontend && npm test -- searchMatch.spec.js` — expect failures (`scoreEntry` doesn't exist).

Implement in `frontend/src/lib/searchMatch.js` (appended — existing `foldDiacritics`/`isMatch` untouched):

```js
// Field weights for the command-palette scoring — name is the strongest
// signal (what a human types to find "the button component"), id is a close
// second (developers search by slug too), category groups loosely, and
// description is the weakest (long-form text with the most incidental hits).
const FIELD_WEIGHTS = { name: 10, id: 6, category: 3, description: 1 };

function stripHtml(html) {
    return html.replace(/<[^>]*>/g, ' ');
}

/**
 * Score a catalog entry against a search query across name/id/category/
 * description. 0 = no match on any field (caller drops it from results when
 * query is non-empty). Higher is better; exact > prefix > substring per
 * field, and the field's weight multiplies each tier so e.g. a substring hit
 * on `name` (weight 10) can still outrank a prefix hit on `description`
 * (weight 1) — matching the intuition that names matter most regardless of
 * match quality.
 */
export function scoreEntry(query, entry) {
    const q = foldDiacritics(query).trim();
    if (q === '') return 0;

    let score = 0;
    for (const [field, weight] of Object.entries(FIELD_WEIGHTS)) {
        const raw = field === 'description' ? stripHtml(entry.description ?? '') : String(entry[field] ?? '');
        const folded = foldDiacritics(raw);
        if (folded === '') continue;
        if (folded === q) score = Math.max(score, weight * 3);
        else if (folded.startsWith(q)) score = Math.max(score, weight * 2);
        else if (folded.includes(q)) score = Math.max(score, weight);
    }
    return score;
}
```

Run: `cd frontend && npm test -- searchMatch.spec.js` — expect pass.

- [ ] **Step 2: i18n keys** — add the six keys from the Interfaces table to both locale files under the existing `"search"` object.

- [ ] **Step 3: `SearchPalette.vue` — keyboard nav (Vitest)**

`frontend/src/components/SearchPalette.spec.js` (new):

```js
import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createTestingPinia } from '@pinia/testing';
import SearchPalette from './SearchPalette.vue';

const catalogState = {
    components: [{ id: 'multi', name: 'Multi', category: 'Block', description: '' }],
    pages: [{ id: 'homepage', name: 'Homepage', category: '', description: '' }],
    docs: [],
};

function mountPalette() {
    return mount(SearchPalette, {
        global: {
            plugins: [createTestingPinia({
                initialState: { catalog: catalogState },
                createSpy: () => () => {},
            })],
        },
    });
}

describe('SearchPalette', () => {
    beforeEach(() => { document.body.innerHTML = ''; });

    it('opens on Cmd+K / Ctrl+K', async () => {
        const wrapper = mountPalette();
        await wrapper.trigger('keydown', { key: 'k', metaKey: true });
        expect(wrapper.find('[role="dialog"]').exists()).toBe(true);
    });

    it('closes on Escape', async () => {
        const wrapper = mountPalette();
        await wrapper.trigger('keydown', { key: 'k', metaKey: true });
        await wrapper.trigger('keydown', { key: 'Escape' });
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    });

    it('groups results and moves the active index with ArrowDown/ArrowUp', async () => {
        const wrapper = mountPalette();
        await wrapper.trigger('keydown', { key: 'k', metaKey: true });
        await wrapper.find('input').setValue('o'); // matches both "Multi"? no — matches "Homepage" (o) and not "Multi"
        await wrapper.trigger('keydown', { key: 'ArrowDown' });
        const active = wrapper.find('[data-active="true"]');
        expect(active.exists()).toBe(true);
        await wrapper.trigger('keydown', { key: 'ArrowUp' });
        expect(wrapper.findAll('[data-active="true"]')).toHaveLength(1); // wraps, still exactly one active row
    });

    it('Enter navigates to the active result and closes the palette', async () => {
        const wrapper = mountPalette();
        const ui = wrapper.vm.$pinia._s.get('ui');
        await wrapper.trigger('keydown', { key: 'k', metaKey: true });
        await wrapper.find('input').setValue('homepage');
        await wrapper.trigger('keydown', { key: 'ArrowDown' });
        await wrapper.trigger('keydown', { key: 'Enter' });
        expect(ui.setRoute).toHaveBeenCalledWith(expect.objectContaining({ type: 'page', slug: 'homepage' }));
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
    });
});
```

Run: `cd frontend && npm test -- SearchPalette.spec.js` — expect failures (component doesn't exist yet).

Implement `frontend/src/components/SearchPalette.vue`:

```html
<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { useCatalogStore } from '../stores/catalog.js';
import { useUiStore } from '../stores/ui.js';
import { scoreEntry } from '../lib/searchMatch.js';

const catalog = useCatalogStore();
const ui = useUiStore();
const isOpen = ref(false);
const query = ref('');
const activeIndex = ref(0);

const groups = computed(() => {
    const rank = (type, list) => list
        .map((entry) => ({ type, entry, score: scoreEntry(query.value, entry) }))
        .filter((r) => query.value === '' || r.score > 0)
        .sort((a, b) => b.score - a.score);
    return [
        { key: 'components', labelKey: 'search.group_components', rows: rank('component', catalog.components) },
        { key: 'pages', labelKey: 'search.group_pages', rows: rank('page', catalog.pages) },
        { key: 'docs', labelKey: 'search.group_docs', rows: rank('doc', catalog.docs) },
    ].filter((g) => g.rows.length > 0);
});

const flatRows = computed(() => groups.value.flatMap((g) => g.rows));

function open() { isOpen.value = true; query.value = ''; activeIndex.value = 0; }
function close() { isOpen.value = false; }

function move(delta) {
    if (flatRows.value.length === 0) return;
    const n = flatRows.value.length;
    activeIndex.value = (activeIndex.value + delta + n) % n;
}

function commit() {
    const row = flatRows.value[activeIndex.value];
    if (!row) return;
    ui.setRoute({ type: row.type, slug: row.entry.id });
    close();
}

function onKeydown(e) {
    if ((e.key === 'k') && (e.metaKey || e.ctrlKey)) { e.preventDefault(); open(); return; }
    if (!isOpen.value) return;
    if (e.key === 'Escape') { close(); return; }
    if (e.key === 'ArrowDown') { e.preventDefault(); move(1); return; }
    if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); return; }
    if (e.key === 'Enter') { e.preventDefault(); commit(); }
}

watch(query, () => { activeIndex.value = 0; });
onMounted(() => window.addEventListener('keydown', onKeydown));
onUnmounted(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <div v-if="isOpen" role="dialog" aria-modal="true" class="fixed inset-0 z-50 flex items-start justify-center bg-black/40 pt-24" @keydown="onKeydown">
        <div class="w-full max-w-lg rounded-lg bg-white shadow-xl dark:bg-zinc-900">
            <input
                v-model="query"
                autofocus
                :placeholder="$t('search.placeholder')"
                class="w-full border-b border-zinc-200 bg-transparent px-4 py-3 text-sm outline-none dark:border-zinc-800"
            >
            <div v-if="flatRows.length === 0" class="px-4 py-6 text-center text-sm text-zinc-500">{{ $t('search.no_results') }}</div>
            <ul v-else class="max-h-96 overflow-y-auto py-1">
                <template v-for="group in groups" :key="group.key">
                    <li class="px-4 pt-2 text-xs font-medium uppercase text-zinc-400">{{ $t(group.labelKey) }}</li>
                    <li
                        v-for="row in group.rows"
                        :key="group.key + ':' + row.entry.id"
                        :data-active="flatRows.indexOf(row) === activeIndex"
                        class="cursor-pointer px-4 py-2 text-sm"
                        :class="flatRows.indexOf(row) === activeIndex ? 'bg-zinc-100 dark:bg-zinc-800' : ''"
                        @mouseenter="activeIndex = flatRows.indexOf(row)"
                        @click="commit"
                    >{{ row.entry.name ?? row.entry.id }}</li>
                </template>
            </ul>
            <div class="flex justify-end gap-3 border-t border-zinc-200 px-4 py-2 text-xs text-zinc-400 dark:border-zinc-800">
                <span>{{ $t('search.hint_navigate') }}</span>
                <span>{{ $t('search.hint_close') }}</span>
            </div>
        </div>
    </div>
</template>
```

Run: `cd frontend && npm test -- SearchPalette.spec.js` — expect pass.

- [ ] **Step 4: Keep the sidebar filter input on the same scoring lib**

In `Sidebar.vue`, wherever the existing filter predicate calls `isMatch(query, item.name)` (Phase 1's port of today's substring filter), leave that call alone — it's a different UX (inline filter, not ranked) and the task's own instruction is "keep the sidebar filter input working as today". No change required here beyond confirming (by reading the ported file) that it still imports from the same `lib/searchMatch.js` module Task 5 just extended — both consumers sharing one lib file is the whole point; if Phase 1 instead duplicated the folding logic inline in `Sidebar.vue`, replace that inline copy with an import of `foldDiacritics`/`isMatch` from `lib/searchMatch.js` as a small dedupe fix, in the same commit.

- [ ] **Step 5: Playwright — palette keyboard flow**

`tests/e2e/playwright/search-palette.spec.js` (new):

```js
import { test, expect } from '@playwright/test';

test('command palette: open, type, arrow, enter navigates', async ({ page }) => {
    await page.goto('/styleguide/');
    await page.keyboard.press('Meta+k');
    await expect(page.getByRole('dialog')).toBeVisible();

    await page.getByPlaceholder(/search/i).fill('multi');
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');

    await expect(page).toHaveURL(/\/styleguide\/component\/multi/);
    await expect(page.getByRole('dialog')).toBeHidden();
});
```

Run: `npx playwright test search-palette.spec.js` — expect pass.

- [ ] **Step 6: Commit**

`git add frontend/src/lib/searchMatch.js frontend/src/lib/searchMatch.spec.js frontend/src/components/SearchPalette.vue frontend/src/components/SearchPalette.spec.js frontend/src/components/Sidebar.vue frontend/public/locales tests/e2e/playwright/search-palette.spec.js`
`git commit -m "feat(spa): command-palette search with weighted scoring"`

---

### Task 6: On-demand accessibility check

**Files:**
- Modify: `frontend/package.json` (new dependency)
- Modify: `frontend/vite.config.js`
- Create: `frontend/src/lib/a11yFormat.js`
- Create: `frontend/src/lib/a11yFormat.spec.js`
- Create: `frontend/src/lib/axeInject.js`
- Create: `frontend/src/components/A11yPanel.vue`
- Modify: `frontend/src/components/ViewportToolbar.vue`
- Create: `tests/fixtures/templates/component/a11y-demo/a11y-demo.twig`
- Create: `tests/e2e/playwright/a11y-check.spec.js`
- Modify: `frontend/public/locales/en.json`, `frontend/public/locales/cs.json`

**Interfaces:**
- `axe-core` becomes a real `dependencies` entry in `frontend/package.json` (not just a CDN reference) — its `axe.min.js` is copied into `dist/` at build time by a small Vite plugin, served at the STABLE (unhashed) path `/styleguide/assets/axe.min.js` (`AssetServer` already serves any file under `dist/` by name — see `src/AssetServer.php`; unhashed filenames just get the shorter `max-age=3600` cache instead of `immutable`, which is fine for a debug tool).
- `formatAxeResults(axeResults)` — pure function, input shape mirrors axe-core's real `axe.run()` resolution value (`{ violations: [{ id, impact, description, help, helpUrl, nodes: [{ target, html }] }], … }`); output groups violations by `impact` into a stable key order (`critical`, `serious`, `moderate`, `minor`) plus a `total` count.
- New i18n keys:

  | Key | `en.json` | `cs.json` |
  |---|---|---|
  | `a11y.check_action` | `"Accessibility check"` | `"Kontrola přístupnosti"` |
  | `a11y.panel_title` | `"Accessibility results"` | `"Výsledky kontroly přístupnosti"` |
  | `a11y.running` | `"Running check…"` | `"Probíhá kontrola…"` |
  | `a11y.no_violations` | `"No issues found"` | `"Žádné problémy nenalezeny"` |
  | `a11y.impact_critical` | `"Critical"` | `"Kritická"` |
  | `a11y.impact_serious` | `"Serious"` | `"Závažná"` |
  | `a11y.impact_moderate` | `"Moderate"` | `"Střední"` |
  | `a11y.impact_minor` | `"Minor"` | `"Menší"` |

- [ ] **Step 1: Add the dependency + Vite copy plugin**

`frontend/package.json` — add to `dependencies`:
```json
        "axe-core": "^4.10.0"
```

`frontend/vite.config.js` — add a tiny `writeBundle` plugin (imports at top of the file):

```js
import { copyFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

// Vendors axe-core's UMD bundle into dist/ as a stable, unhashed asset served
// at /styleguide/assets/axe.min.js. Not run through Rollup (it's a
// self-contained UMD script meant to be <script>-injected into the iframe
// document directly, not imported as an ES module) — a plain file copy after
// the main build, so a version bump of axe-core is a one-line package.json
// change with no build-graph wiring.
function copyAxeCore() {
    return {
        name: 'copy-axe-core',
        writeBundle() {
            copyFileSync(
                fileURLToPath(new URL('./node_modules/axe-core/axe.min.js', import.meta.url)),
                fileURLToPath(new URL('../dist/axe.min.js', import.meta.url)),
            );
        },
    };
}
```

Add `copyAxeCore()` to the `plugins: [tailwindcss(), ...]` array.

- [ ] **Step 2: `formatAxeResults()` (Vitest first)**

`frontend/src/lib/a11yFormat.spec.js` (new):

```js
import { describe, it, expect } from 'vitest';
import { formatAxeResults } from './a11yFormat.js';

const axeResults = (violations) => ({ violations, passes: [], incomplete: [], inapplicable: [] });

describe('formatAxeResults', () => {
    it('groups violations by impact in a stable critical→minor order', () => {
        const result = formatAxeResults(axeResults([
            { id: 'color-contrast', impact: 'serious', description: 'Contrast', help: 'Fix contrast', helpUrl: '#', nodes: [] },
            { id: 'image-alt', impact: 'critical', description: 'Alt text', help: 'Add alt', helpUrl: '#', nodes: [{ target: ['img'], html: '<img src="x">' }] },
        ]));
        expect(Object.keys(result.byImpact)).toEqual(['critical', 'serious', 'moderate', 'minor']);
        expect(result.byImpact.critical).toHaveLength(1);
        expect(result.byImpact.critical[0].id).toBe('image-alt');
        expect(result.byImpact.moderate).toEqual([]);
        expect(result.total).toBe(2);
    });

    it('preserves node targets for locating the offending element', () => {
        const result = formatAxeResults(axeResults([
            { id: 'image-alt', impact: 'critical', description: 'Alt text', help: 'Add alt', helpUrl: '#', nodes: [{ target: ['img.hero'], html: '<img class="hero" src="x">' }] },
        ]));
        expect(result.byImpact.critical[0].nodes[0].target).toEqual(['img.hero']);
    });

    it('returns all-empty groups and total 0 for a clean page', () => {
        const result = formatAxeResults(axeResults([]));
        expect(result.total).toBe(0);
        expect(Object.values(result.byImpact).every((g) => g.length === 0)).toBe(true);
    });

    it('treats a null/undefined impact as "minor" rather than dropping the violation', () => {
        const result = formatAxeResults(axeResults([
            { id: 'unknown-rule', impact: null, description: 'x', help: 'x', helpUrl: '#', nodes: [] },
        ]));
        expect(result.byImpact.minor).toHaveLength(1);
        expect(result.total).toBe(1);
    });
});
```

Run: `cd frontend && npm test -- a11yFormat.spec.js` — expect failures (module doesn't exist).

Implement `frontend/src/lib/a11yFormat.js`:

```js
const IMPACT_ORDER = ['critical', 'serious', 'moderate', 'minor'];

/**
 * Reshape axe-core's `axe.run()` resolution into a display-ready grouping.
 * Pure function — no DOM, no iframe access — so the injection/run mechanics
 * in axeInject.js can stay untested-by-Vitest (they're DOM/iframe-heavy and
 * covered instead by the Playwright spec) while this formatting logic is
 * fully unit-tested.
 */
export function formatAxeResults(results) {
    const byImpact = { critical: [], serious: [], moderate: [], minor: [] };
    for (const violation of results.violations ?? []) {
        const impact = IMPACT_ORDER.includes(violation.impact) ? violation.impact : 'minor';
        byImpact[impact].push(violation);
    }
    return { byImpact, total: results.violations?.length ?? 0 };
}
```

Run: `cd frontend && npm test -- a11yFormat.spec.js` — expect pass.

- [ ] **Step 3: Injection helper (not unit-tested — DOM/iframe-heavy, covered by Playwright)**

`frontend/src/lib/axeInject.js` (new):

```js
/**
 * Inject axe-core into a same-origin iframe's document (if not already
 * present) and run it. Same-origin because the render endpoint
 * (/styleguide/render/...) is served from the same origin as the SPA shell —
 * contentWindow/contentDocument access works without a postMessage bridge.
 * Runs ONLY on explicit call — no automatic/background scanning.
 */
export function runAxeCheck(iframeEl) {
    const win = iframeEl.contentWindow;
    const doc = iframeEl.contentDocument;
    if (!win || !doc) return Promise.reject(new Error('iframe not accessible'));

    if (win.axe) {
        return win.axe.run();
    }

    return new Promise((resolve, reject) => {
        const script = doc.createElement('script');
        script.src = '/styleguide/assets/axe.min.js';
        script.onload = () => win.axe.run().then(resolve, reject);
        script.onerror = () => reject(new Error('failed to load axe-core into the iframe'));
        doc.head.appendChild(script);
    });
}
```

(If Phase 1's SPA config carries a non-default `base_url`, per Interface Assumptions #1, build the script `src` from that instead of the hardcoded `/styleguide/` prefix — check whichever config-read helper the SPA already uses for its own asset base and reuse it here rather than hardcoding a second time.)

- [ ] **Step 4: `A11yPanel.vue` + toolbar action**

`frontend/src/components/A11yPanel.vue` (new):

```html
<script setup>
defineProps({ results: { type: Object, default: null }, running: { type: Boolean, default: false } });
</script>

<template>
    <div class="border-t border-zinc-200 p-4 text-sm dark:border-zinc-800">
        <h3 class="mb-2 font-medium">{{ $t('a11y.panel_title') }}</h3>
        <p v-if="running">{{ $t('a11y.running') }}</p>
        <p v-else-if="results && results.total === 0">{{ $t('a11y.no_violations') }}</p>
        <template v-else-if="results">
            <div v-for="impact in ['critical', 'serious', 'moderate', 'minor']" :key="impact">
                <template v-if="results.byImpact[impact].length > 0">
                    <h4 class="mt-3 text-xs font-semibold uppercase text-zinc-500">{{ $t('a11y.impact_' + impact) }}</h4>
                    <ul class="space-y-1">
                        <li v-for="v in results.byImpact[impact]" :key="v.id">
                            <strong>{{ v.help }}</strong>
                            <code class="ml-1 text-xs text-zinc-500">{{ v.nodes.map(n => n.target.join(' ')).join(', ') }}</code>
                        </li>
                    </ul>
                </template>
            </div>
        </template>
    </div>
</template>
```

In `ViewportToolbar.vue`, add the trigger button (near the existing "More actions" overflow per `CHANGELOG.md` [0.6.5]):

```html
<button type="button" @click="runA11yCheck" :aria-label="$t('a11y.check_action')" :title="$t('a11y.check_action')">
    <!-- icon -->
</button>
```

```js
import { runAxeCheck } from '../lib/axeInject.js';
import { formatAxeResults } from '../lib/a11yFormat.js';

async function runA11yCheck() {
    ui.a11yRunning = true;
    try {
        const raw = await runAxeCheck(previewIframeRef.value); // assumed existing ref to the <iframe>, per Interface Assumptions #4-adjacent
        ui.a11yResults = formatAxeResults(raw);
    } finally {
        ui.a11yRunning = false;
    }
}
```

- [ ] **Step 5: Fixture + Playwright**

`tests/fixtures/templates/component/a11y-demo/a11y-demo.twig` (new):

```twig
{#
name: "A11y Demo"
category: "Block"
description: "Deliberately fails axe's image-alt rule — used by the on-demand a11y check Playwright spec."
#}
<img src="/placeholder.png" width="100" height="60">
```

`tests/e2e/playwright/a11y-check.spec.js` (new):

```js
import { test, expect } from '@playwright/test';

test('accessibility check lists a known violation', async ({ page }) => {
    await page.goto('/styleguide/component/a11y-demo');
    await page.getByRole('button', { name: /accessibility check/i }).click();

    const panel = page.getByText('Accessibility results').locator('..');
    await expect(panel.getByText(/critical/i)).toBeVisible();
    await expect(panel).toContainText(/alt/i);
});
```

Run: `npx playwright test a11y-check.spec.js` — expect pass.

- [ ] **Step 6: i18n keys** — add the eight keys from the Interfaces table to both locale files under a new top-level `"a11y"` object.

- [ ] **Step 7: Build + smoke**

Run: `cd frontend && npm run build` — expect `dist/axe.min.js` to appear alongside the hashed `styleguide.*.js`/`.css`.
Run: `curl -sI http://127.0.0.1:8421/styleguide/assets/axe.min.js | grep -i cache-control` (against the fixture server) — expect `max-age=3600` (unhashed-file branch, not `immutable`).

- [ ] **Step 8: Commit**

`git add frontend/package.json frontend/package-lock.json frontend/vite.config.js frontend/src/lib/a11yFormat.js frontend/src/lib/a11yFormat.spec.js frontend/src/lib/axeInject.js frontend/src/components/A11yPanel.vue frontend/src/components/ViewportToolbar.vue frontend/public/locales tests/fixtures/templates/component/a11y-demo tests/e2e/playwright/a11y-check.spec.js dist`
`git commit -m "feat(spa): on-demand accessibility check via axe-core"`

---

### Task 7: Release chores

**Files:**
- Modify: `CHANGELOG.md`
- Modify: `README.md`
- Modify: `docs/API.md` (if any residual gaps from Task 1/2 remain — final pass)
- Modify: `frontend/package.json` (`version`, if the frontend package tracks it separately — confirm; the root package has no `version` field per `AGENTS.md` § Release workflow)

- [ ] **Step 1: `CHANGELOG.md`** — under `## [Unreleased]`:

```markdown
## [Unreleased]

### Added

- **File-convention variants.** Sibling `styleguide.<variant>.twig` files (e.g. `styleguide.secondary.twig`) are auto-discovered per component/page/doc — zero YAML required. When at least one exists, the preview toolbar shows a variant switcher and `?variant=<id>` becomes a valid query param on the SPA deep link (`/component/<id>?variant=<v>`) and the render endpoint (`/render/component/<id>?variant=<v>`). Optional YAML `variants:` map supplies display labels (falls back to the id). Plain `styleguide.twig` stays the implicit default — every existing template is unaffected. An unknown/removed variant falls back to the default render instead of 404ing.
- **Command-palette search.** `⌘K` / `Ctrl+K` opens a keyboard-navigable modal (arrows + Enter + Esc) searching across name, id, category, and description with field-weighted ranking and diacritic folding. The sidebar's inline filter keeps working as before, sharing the same scoring library.
- **On-demand accessibility check.** A new toolbar action injects axe-core into the preview iframe and lists violations grouped by impact in a results panel. Runs only when clicked — no CI integration, no automatic scanning, no template impact.

### Fixed

(carry forward any Phase 1/2/3 unreleased entries that predate this phase, if still present)
```

- [ ] **Step 2: `README.md` § URL surface**

Amend the render-endpoint row (§ *URL surface*):

```markdown
| `/styleguide/render/<kind>/<slug>` | iframe HTML | Bare render — `<kind>` ∈ `component` \| `page` \| `doc` \| `foundations`. Used as iframe `src`, also browsable directly. Accepts `?theme=light\|dark` (whitelisted) to stamp `class="dark"` on the iframe `<html>` for consumers that opt into Tailwind dark mode, and `?variant=<id>` (whitelisted syntactically, resolved against the discovered `styleguide.<variant>.twig` files — an unknown value falls back to the default variant rather than 404ing). |
```

Add a note to the `component`/`page`/`doc` SPA deep-link rows (or a shared footnote) that they also accept `?variant=<id>` for the same reason.

- [ ] **Step 3: `README.md` § Per-template metadata**

Add the table row (matches the row added to `docs/API.md` in Task 1, § *Per-template metadata* table):

```markdown
| `variants` | display labels for auto-discovered `styleguide.<variant>.twig` sibling files — see *File-convention variants* below |
```

Add a new subsection after § *Per-entry body class*, mirroring the existing § *Component render modes* structure:

````markdown
### File-convention variants

Drop a `styleguide.<variant>.twig` file next to `styleguide.twig` and it's automatically discovered — no YAML required:

```
component/hero/
├── hero.twig
├── styleguide.twig            ← default variant
├── styleguide.secondary.twig  ← discovered variant "secondary"
└── styleguide.dark-bg.twig    ← discovered variant "dark-bg"
```

The preview toolbar shows a switcher (Default + each discovered variant, ordered by filename) the moment at least one sibling exists. Optional YAML supplies display labels:

```twig
{#
name: "Hero"
variants:
  secondary: "Secondary style"
#}
```

A label with no matching file is ignored — the filesystem is always the source of truth for which variants exist. `<variant>` must match `[a-z0-9-]+`. Deep link with `?variant=<id>`; an unknown or since-deleted variant silently falls back to the default instead of 404ing.
````

- [ ] **Step 4: Rebuild `dist/`**

Run: `cd frontend && npm run build`
Expected: no diff beyond what Tasks 3/5/6 already produced (this step just confirms the final combined build is committed).

- [ ] **Step 5: Full verification pass**

Run: `composer test && composer phpstan`
Expected: green, no PHPStan errors.

Run: `cd frontend && npm test`
Expected: all Vitest specs pass (Tasks 3/5/6).

Run: `cd tests/e2e/playwright && npx playwright test`
Expected: all specs pass (Tasks 4/5/6), including the pre-existing Phase 1 parity suite (no regression).

Run: `bash tests/e2e/run.sh`
Expected: Layer A (HTTP smoke) green — confirms the PHP-side render/API changes didn't break the package's own smoke coverage.

- [ ] **Step 6: Commit**

`git add CHANGELOG.md README.md docs/API.md`
`git commit -m "docs(release): v0.9.0 — variants, search palette, a11y check"`

(Version tag / Packagist publish per `AGENTS.md` § *Release workflow* is a separate, deliberate step outside this plan's scope — this task only prepares the `[Unreleased]` → release-ready state.)

---

## Self-Review

- **Spec coverage:** file-convention variants ✓ (Task 1 discovery + Task 2 render/route resolution + Task 3 SPA switcher + Task 4 e2e); command palette with arrows/Enter/Esc across name/id/description/category ✓ (Task 5); on-demand a11y with impact grouping, click-only, no template impact ✓ (Task 6); docs-in-same-PR gate ✓ (Task 1 Step 7, Task 7 Steps 2-3); CHANGELOG ✓ (Task 7 Step 1); full BC (plain `styleguide.twig` unaffected, additive API fields, `^[a-z0-9-]+$` filesystem-derived whitelist never trusting the query param) ✓ (Task 1 `variants: []` BC test, Task 2 unknown-variant fallback test + Router's syntax-only whitelist + Renderer's own re-validation).
- **Placeholder scan:** every task step carries runnable code (PHP, JS/Vue, Twig fixtures) or an exact shell command with an expected output — no `// TODO: implement` or `<!-- fill in -->` markers anywhere in this plan.
- **Cross-task signature consistency for `variants`:** Task 1's `ComponentParser::normaliseMetadata()` emits `variants: list<array{id:string,label:string}>` → Task 1's `ComponentsEndpointTest` locks the same shape over HTTP → Task 3's `ViewportToolbar.vue` consumes `entry.variants` as exactly that `Array<{id,label}>` shape with no reshaping in `stores/catalog.js` (JSON passthrough, per Interface Assumptions #2) → Task 4's Playwright spec reads the switcher's rendered button labels, which trace back to the same `label` field. No task introduces a second, divergent shape for the same concept.
- **`variant` (singular, the selected id) vs `variants` (plural, the discovered list) naming is kept distinct and consistent everywhere**: `ComponentParser`/API/YAML always use plural `variants` for the list; `Router`/`Renderer`/`?variant=`/`ui.route.variant` always use singular `variant` for the currently-selected one. Mixing these up was the most likely cross-task inconsistency risk in a plan this size, so it's called out explicitly here.
- **Interface Assumptions are flagged, not silently hard-coded**: Task 2 explicitly gates its `?theme=` composition test on Phase 2's actual implementation; Task 3/5/6 all cite the specific store/component shapes assumed from Phase 1 and where to look if they're wrong, rather than presenting guesses as ground truth.
