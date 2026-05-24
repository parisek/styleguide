# Component Render Modes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the prototyped `fullBleed` boolean with a four-value YAML enum (`render: inset|bleed|chrome|overlay`) that drives the iframe wrapper's padding, `--header-height` reset, and body `min-height` in `render-cell.twig`.

**Architecture:** Component YAML metadata gains a `render` key. `ComponentParser` normalises it (validates against the allowed enum, falls back to `inset` for missing/unknown). `Styleguide` forwards the normalised value into the Renderer config, `Renderer` exposes it as `component.render` on the Twig context, and `render-cell.twig` branches on the mode. Three iframe behaviours: padded inset (default), edge-to-edge bleed (`bleed`+`overlay`), edge-to-edge with body `min-height:200vh` for sticky/fixed demos (`chrome`).

**Tech Stack:** PHP 8.3 / PSR-12, PHPUnit (PHPUnit attributes), Twig 3, Symfony YAML. No frontend SPA changes — this work lives entirely in PHP/Twig.

**Spec:** `docs/superpowers/specs/2026-05-24-component-render-modes-design.md`

---

## File Structure

**Modified:**
- `src/ComponentParser.php` — adds `RENDER_MODES` constant + `normaliseRender()` public static method; `normaliseMetadata()` emits a normalised `render` field instead of `fullBleed`.
- `src/Styleguide.php` — forwards `meta.render` to `$config['render']` (replaces the `fullBleed` forward).
- `src/Renderer.php` — exposes `render` on the `component` Twig var (replaces `fullBleed`).
- `templates/render-cell.twig` — branches on `component.render` for inset wrapper + CSS injection (replaces `component.fullBleed`).
- `tests/ComponentParserTest.php` — adds 7 tests for the new `render` normalisation.
- `tests/RendererTest.php` — adds 3 tests covering bleed, chrome, overlay output.
- `tests/fixtures/templates/component/sample/sample.twig` — extended YAML metadata (adds `render: inset` so the existing test stays explicit).
- `../tailwind-base/static/templates/component/project-slider/project-slider.twig` — replaces `fullBleed: true` with `render: bleed`.

**Created:** none.

---

## Task 1: Roll back the fullBleed prototype

The brainstorm replaced `fullBleed: true` with the `render: inset|bleed|chrome|overlay` enum. Discard the uncommitted prototype before writing the new code so the diff stays clean.

**Files:**
- Modify: `src/ComponentParser.php` (revert)
- Modify: `src/Styleguide.php` (revert)
- Modify: `src/Renderer.php` (revert)
- Modify: `templates/render-cell.twig` (revert)
- Modify: `../tailwind-base/static/templates/component/project-slider/project-slider.twig` (revert)

- [ ] **Step 1: Revert the package-side prototype**

Run from the styleguide repo root:
```bash
git checkout -- src/ComponentParser.php src/Renderer.php src/Styleguide.php templates/render-cell.twig
```

- [ ] **Step 2: Revert the consumer-side prototype**

Run:
```bash
cd /Users/pari/Sites/tailwind-base
git checkout -- static/templates/component/project-slider/project-slider.twig
cd -
```

- [ ] **Step 3: Verify clean state**

Run: `git status`

Expected: no `src/`, `templates/`, or consumer files listed as modified. `dogfood-output/` may remain untracked (it's local screenshots, not part of this work). Spec doc commit `0e3ecd4` stays in history.

- [ ] **Step 4: Run baseline tests to confirm nothing's broken**

Run: `composer test`

Expected: all tests pass (the prototype was never committed; we're back to the last green state).

No commit in this task — the rollback returns the tree to an already-committed state.

---

## Task 2: ComponentParser — `render` mode normalisation

Add a public static `normaliseRender()` helper that accepts any value (string, null, mistyped) and returns one of the four canonical modes. Wire it into `normaliseMetadata()` so every parsed component carries a guaranteed-valid `render` field.

**Files:**
- Modify: `src/ComponentParser.php:130-146` (add constant, add helper, wire into normaliseMetadata)
- Test: `tests/ComponentParserTest.php` (add 7 tests)
- Test: `tests/fixtures/templates/component/sample/sample.twig` (add `render: inset` to existing fixture)

- [ ] **Step 1: Write the failing helper tests**

Append to `tests/ComponentParserTest.php` (before the closing brace of the class):

```php
    #[Test]
    public function normalise_render_accepts_inset(): void
    {
        self::assertSame('inset', ComponentParser::normaliseRender('inset'));
    }

    #[Test]
    public function normalise_render_accepts_bleed(): void
    {
        self::assertSame('bleed', ComponentParser::normaliseRender('bleed'));
    }

    #[Test]
    public function normalise_render_accepts_chrome(): void
    {
        self::assertSame('chrome', ComponentParser::normaliseRender('chrome'));
    }

    #[Test]
    public function normalise_render_accepts_overlay(): void
    {
        self::assertSame('overlay', ComponentParser::normaliseRender('overlay'));
    }

    #[Test]
    public function normalise_render_defaults_inset_for_null(): void
    {
        self::assertSame('inset', ComponentParser::normaliseRender(null));
    }

    #[Test]
    public function normalise_render_falls_back_to_inset_for_unknown(): void
    {
        // YAML typo or stale value — coerce silently instead of throwing.
        self::assertSame('inset', ComponentParser::normaliseRender('hero'));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter normalise_render`

Expected: 6 errors of the form `Error: Call to undefined method Parisek\Styleguide\ComponentParser::normaliseRender()`.

- [ ] **Step 3: Add the constant and the helper to `ComponentParser.php`**

Open `src/ComponentParser.php`. Above the existing `public function parse(...)` (around line 30, right after the class opening brace and any existing properties), add:

```php
    /**
     * Allowed render modes. See docs/superpowers/specs/2026-05-24-component-render-modes-design.md.
     *
     * @var list<string>
     */
    public const RENDER_MODES = ['inset', 'bleed', 'chrome', 'overlay'];

    /**
     * Coerce an arbitrary YAML value into one of the canonical render modes.
     * Null / missing / typos all fall back to 'inset' (the safe default that
     * matches pre-feature behaviour). Strict-equals against the allowed list
     * so e.g. integers or arrays from a malformed YAML can't slip through.
     */
    public static function normaliseRender(mixed $value): string
    {
        return is_string($value) && in_array($value, self::RENDER_MODES, true)
            ? $value
            : 'inset';
    }
```

- [ ] **Step 4: Run the helper tests to verify they pass**

Run: `vendor/bin/phpunit --filter normalise_render`

Expected: 6 tests pass.

- [ ] **Step 5: Wire `render` into `normaliseMetadata()`**

In `src/ComponentParser.php`, find `normaliseMetadata()` (around line 130). Inside the returned array, just before the `'hasStyleguide' => $hasStyleguide,` line, add:

```php
            // Canonical render mode for the iframe wrapper. See spec
            // 2026-05-24-component-render-modes-design.md — drives the
            // padding wrapper, --header-height reset, and body min-height
            // in render-cell.twig.
            'render' => self::normaliseRender($metadata['render'] ?? null),
```

- [ ] **Step 6: Add `render: inset` to the existing `sample` fixture**

Open `tests/fixtures/templates/component/sample/sample.twig` and replace the YAML header so it reads:

```twig
{#
name: "Sample"
description: "Sample component for ComponentParser tests"
category: "Block"
weight: 20
render: inset
#}
<div class="sample">{{ content.title }}</div>
```

- [ ] **Step 7: Add a parse-level integration test**

Append to `tests/ComponentParserTest.php`:

```php
    #[Test]
    public function parse_emits_normalised_render_mode(): void
    {
        $parser = new ComponentParser($this->fixturesPath);
        $sample = $parser->parse('component', 'sample');
        self::assertNotNull($sample);
        self::assertSame('inset', $sample['render']);
    }

    #[Test]
    public function parse_defaults_render_to_inset_when_missing(): void
    {
        // The `another` fixture has no `render:` key in its YAML.
        $parser = new ComponentParser($this->fixturesPath);
        $another = $parser->parse('component', 'another');
        self::assertNotNull($another);
        self::assertSame('inset', $another['render']);
    }
```

- [ ] **Step 8: Run the full ComponentParser test class**

Run: `vendor/bin/phpunit --filter ComponentParserTest`

Expected: all tests (8 new + the original ones) pass.

- [ ] **Step 9: Run PHPStan**

Run: `composer phpstan`

Expected: no errors.

- [ ] **Step 10: Commit**

```bash
git add src/ComponentParser.php tests/ComponentParserTest.php tests/fixtures/templates/component/sample/sample.twig
git commit -m "$(cat <<'EOF'
feat(parser): normalise render mode from component YAML metadata

Add ComponentParser::RENDER_MODES + normaliseRender() so every parsed
component carries a guaranteed-valid `render` field (inset / bleed /
chrome / overlay). Missing keys, typos, and non-string values all
fall back to `inset` so legacy projects without the new key keep
their pre-feature behaviour.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Forward `render` through `Styleguide.php` → `Renderer.php` → Twig

Now the parser carries `render` in its metadata. Pipe it through into the Twig render-cell context so the template can branch on it.

**Files:**
- Modify: `src/Styleguide.php:888-893` (forward `meta.render` into `$config['render']`)
- Modify: `src/Renderer.php:55-67` (add `'render' => ...` to the `component` Twig var)

- [ ] **Step 1: Update `Styleguide.php` to forward the render mode**

Open `src/Styleguide.php`. Find the block (around line 888):

```php
        if (in_array($route['kind'], ['component', 'page'], true)) {
            // Resolve human-readable component name from parsed metadata, if available.
            $meta = $this->parser->parse($route['kind'], $route['slug']);
            if ($meta !== null && !empty($meta['name'])) {
                $config['component_name'] = $meta['name'];
            }
        } elseif ($route['kind'] === 'foundations') {
```

Add the render forward just after the `$config['component_name'] = $meta['name'];` line. The block becomes:

```php
        if (in_array($route['kind'], ['component', 'page'], true)) {
            // Resolve human-readable component name from parsed metadata, if available.
            $meta = $this->parser->parse($route['kind'], $route['slug']);
            if ($meta !== null && !empty($meta['name'])) {
                $config['component_name'] = $meta['name'];
            }
            // Render mode lives on the component (not the page) per spec
            // 2026-05-24-component-render-modes-design.md. Pages render their
            // own layout and don't go through render-cell's inset wrapper, so
            // the mode is forwarded only when kind == component.
            if ($meta !== null && $route['kind'] === 'component') {
                $config['render'] = $meta['render'] ?? 'inset';
            }
        } elseif ($route['kind'] === 'foundations') {
```

- [ ] **Step 2: Update `Renderer.php` to expose `render` on the Twig context**

Open `src/Renderer.php`. Find the `return $this->twig->render('render-cell.twig', [...])` block (around line 55). Locate the `'component' => [...]` entry. Replace it with:

```php
            'component' => [
                'id' => $slug,
                'name' => $config['component_name'] ?? $slug,
                // Re-normalise defensively: callers other than Styleguide.php
                // (notably tests) may pass an unvalidated string. ComponentParser
                // owns the canonical list, so we route the coercion through it.
                'render' => ComponentParser::normaliseRender($config['render'] ?? null),
            ],
```

- [ ] **Step 3: Add the import for `ComponentParser`**

At the top of `src/Renderer.php`, just below the existing `use` statements (or below `namespace Parisek\Styleguide;` if there are no `use` lines), add:

```php
use Parisek\Styleguide\ComponentParser;
```

If a `use Parisek\Styleguide\ComponentParser;` line already exists, skip this step.

- [ ] **Step 4: Run the existing renderer tests — they should still pass**

Run: `vendor/bin/phpunit --filter RendererTest`

Expected: existing tests still pass — no `render` is passed in config, so it defaults to `inset`, which produces the existing `<div style="padding:1.5rem">` markup the existing test asserts on.

- [ ] **Step 5: Run PHPStan**

Run: `composer phpstan`

Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Styleguide.php src/Renderer.php
git commit -m "$(cat <<'EOF'
feat(renderer): forward render mode from metadata to render-cell context

Styleguide reads `meta.render` (parsed + normalised by ComponentParser
in the previous commit) and forwards it to Renderer. Renderer exposes
it as `component.render` on the Twig context, with a defensive
re-normalisation so direct test callers can pass arbitrary strings
without bypassing the canonical mode list.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: `render-cell.twig` branching on render mode

Now the template gets `component.render`. Wire the four modes into three iframe behaviours: inset padding, `--header-height: 0` for bleed/overlay, and `--header-height: 0` + `body { min-height: 200vh }` for chrome.

**Files:**
- Modify: `templates/render-cell.twig` (inline `<style>` block and the component body wrap)
- Test: `tests/RendererTest.php` (add 3 new tests)

- [ ] **Step 1: Write the new RendererTest cases**

Append to `tests/RendererTest.php` before the closing brace of the class:

```php
    #[Test]
    public function bleed_render_drops_inset_wrapper_and_resets_header_height(): void
    {
        $html = $this->renderer->render('component', 'sample', [
            'project' => ['name' => 'TestProject'],
            'iframe' => ['css' => '/dist/style.css'],
            'render' => 'bleed',
        ], 'cs');

        // No inset wrapper — the component renders edge-to-edge.
        self::assertStringNotContainsString('<div style="padding:1.5rem">', $html);
        // --header-height is reset so consumer hacks like
        // `margin-top: var(--header-height, 75px) * -1` collapse to 0 in
        // styleguide isolation (no sticky chrome above to hide behind).
        self::assertStringContainsString('--header-height: 0px', $html);
        // Bleed leaves body min-height alone.
        self::assertStringNotContainsString('min-height: 200vh', $html);
    }

    #[Test]
    public function chrome_render_adds_body_min_height_for_sticky_demos(): void
    {
        $html = $this->renderer->render('component', 'sample', [
            'project' => ['name' => 'TestProject'],
            'iframe' => ['css' => '/dist/style.css'],
            'render' => 'chrome',
        ], 'cs');

        self::assertStringNotContainsString('<div style="padding:1.5rem">', $html);
        self::assertStringContainsString('--header-height: 0px', $html);
        // 200vh on body gives sticky / fixed page chrome something to scroll
        // against so the sticky behaviour is demonstrable in isolation.
        self::assertStringContainsString('min-height: 200vh', $html);
    }

    #[Test]
    public function overlay_render_matches_bleed_iframe_shape(): void
    {
        $bleedHtml = $this->renderer->render('component', 'sample', [
            'project' => ['name' => 'TestProject'],
            'iframe' => ['css' => '/dist/style.css'],
            'render' => 'bleed',
        ], 'cs');

        $overlayHtml = $this->renderer->render('component', 'sample', [
            'project' => ['name' => 'TestProject'],
            'iframe' => ['css' => '/dist/style.css'],
            'render' => 'overlay',
        ], 'cs');

        // overlay ≡ bleed at the iframe-wrapper level (see spec § Mode semantics).
        // The separate label exists for future UI surfacing; both modes must emit
        // identical render-cell output today.
        self::assertSame($bleedHtml, $overlayHtml);
    }
```

- [ ] **Step 2: Run the three new tests — expect them to fail**

Run: `vendor/bin/phpunit --filter "bleed_render_drops_inset_wrapper_and_resets_header_height|chrome_render_adds_body_min_height_for_sticky_demos|overlay_render_matches_bleed_iframe_shape"`

Expected: all three fail. The bleed/chrome ones fail on the `--header-height` assertion (currently absent); the overlay one fails because bleed and overlay both still render the inset wrapper today (`render` mode is not yet consumed by the template).

- [ ] **Step 3: Update the inline `<style>` block in `render-cell.twig`**

Open `templates/render-cell.twig`. Find the existing `<style>` block near the top of `<head>` (around lines 20-23):

```twig
	<style>
		:root { color-scheme: light; }
		body { background-color: #fff; }
	</style>
```

Replace it with:

```twig
	<style>
		:root { color-scheme: light; }
		body { background-color: #fff; }
		{# Per-mode CSS injections for the iframe wrapper. See spec
		   2026-05-24-component-render-modes-design.md § Mode semantics.
		   `bleed` / `chrome` / `overlay` all reset `--header-height` to 0
		   so consumer "tuck under sticky header" hacks collapse cleanly
		   in styleguide isolation; `chrome` additionally forces body to
		   200vh so sticky / fixed chrome has scroll-room to demo. #}
		{% if kind == 'component' and component.render in ['bleed', 'chrome', 'overlay'] %}:root { --header-height: 0px; }{% endif %}
		{% if kind == 'component' and component.render == 'chrome' %}body { min-height: 200vh; }{% endif %}
	</style>
```

- [ ] **Step 4: Replace the component-kind inset wrapper with a render-aware branch**

Still in `templates/render-cell.twig`. Find the existing block (around lines 80-82):

```twig
	{# Component-kind previews render a single small piece (button, breadcrumb, …)
	   that would otherwise sit flush against the iframe's top edge — visually
	   touching the styleguide chrome above. Pages render their own full layout
	   (which already controls spacing), so they don't get the inset. Inline
	   styles instead of utility classes so the inset works regardless of the
	   project's CSS framework (Tailwind / Bootstrap / custom). #}
	{% if kind == 'component' %}<div style="padding:1.5rem">{% endif %}
	{{ body|raw }}
	{% if kind == 'component' %}</div>{% endif %}
```

Replace it with:

```twig
	{# Component-kind previews render a single small piece (button, breadcrumb, …)
	   that would otherwise sit flush against the iframe's top edge — visually
	   touching the styleguide chrome above. Pages render their own full layout
	   (which already controls spacing), so they don't get the inset. Inline
	   styles instead of utility classes so the inset works regardless of the
	   project's CSS framework (Tailwind / Bootstrap / custom). Only `inset`
	   mode (the default for unannotated components) gets the wrapper;
	   `bleed` / `chrome` / `overlay` render edge-to-edge so hero / slider /
	   sticky / modal components can fill the iframe. See spec
	   2026-05-24-component-render-modes-design.md § Mode semantics. #}
	{% set use_inset = kind == 'component' and component.render == 'inset' %}
	{% if use_inset %}<div style="padding:1.5rem">{% endif %}
	{{ body|raw }}
	{% if use_inset %}</div>{% endif %}
```

- [ ] **Step 5: Run the three new tests — they should pass**

Run: `vendor/bin/phpunit --filter "bleed_render_drops_inset_wrapper_and_resets_header_height|chrome_render_adds_body_min_height_for_sticky_demos|overlay_render_matches_bleed_iframe_shape"`

Expected: all three pass.

- [ ] **Step 6: Run the full test suite — confirm no regression**

Run: `composer test`

Expected: all 23+ existing tests still pass, plus the new ComponentParser / Renderer tests added in Tasks 2-4 (around 32 total).

- [ ] **Step 7: Run PHPStan**

Run: `composer phpstan`

Expected: no errors.

- [ ] **Step 8: Commit**

```bash
git add templates/render-cell.twig tests/RendererTest.php
git commit -m "$(cat <<'EOF'
feat(template): branch render-cell.twig on component.render mode

render-cell.twig now skips the 24px inset wrapper for bleed / chrome /
overlay components, injects `--header-height: 0` for all three of those
modes so consumer margin-pull-under-header hacks collapse cleanly in
isolation, and adds `body { min-height: 200vh }` for chrome so sticky /
fixed page chrome (header, footer, cookieconsent) has scroll-room to
demo its position behaviour.

Inset mode is the default for unannotated components, preserving the
pre-feature look on every legacy project.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Opt the project-slider into `render: bleed` in the consumer

The package-side work is done. The slider needs a one-line YAML change to actually use the new mode.

**Files:**
- Modify: `../tailwind-base/static/templates/component/project-slider/project-slider.twig` (YAML header)

- [ ] **Step 1: Add the render key to the slider's YAML metadata**

Open `/Users/pari/Sites/tailwind-base/static/templates/component/project-slider/project-slider.twig`. The current header reads:

```twig
{#
name: "Projekty - slider"
description: "Full-screen HP slider — 3 fotky se střídají v 3s intervalu fade přechodem, s jemným Ken-Burns zoomem. Tmavé bandy nahoře (pod menu) a dole (pod nadpisem) zachovají čitelnost, střed obrázku zůstává v plné barvě."
usage: homepage
category: "Gutenberg"
fields:
```

Update it to:

```twig
{#
name: "Projekty - slider"
description: "Full-screen HP slider — 3 fotky se střídají v 3s intervalu fade přechodem, s jemným Ken-Burns zoomem. Tmavé bandy nahoře (pod menu) a dole (pod nadpisem) zachovají čitelnost, střed obrázku zůstává v plné barvě."
usage: homepage
category: "Gutenberg"
render: bleed
fields:
```

- [ ] **Step 2: Confirm `composer styleguide:local` is active in the consumer**

Run:
```bash
cd /Users/pari/Sites/tailwind-base
grep -E '"parisek/styleguide"' composer.json
```

Expected output (one match):
```
        "parisek/styleguide": "dev-local",
```

If the value is `^0.1` (Packagist version), run `composer styleguide:local` first — otherwise the consumer is still on the released package and won't pick up the changes from Tasks 2-4 yet.

- [ ] **Step 3: Commit the consumer change**

```bash
cd /Users/pari/Sites/tailwind-base
git add static/templates/component/project-slider/project-slider.twig
git commit -m "$(cat <<'EOF'
chore(slider): opt into render: bleed for full-bleed styleguide preview

parisek/styleguide now honours a `render` enum on component YAML
metadata. `render: bleed` tells the styleguide iframe to skip its
default 24px inset wrapper and reset `--header-height` to 0, so the
slider's `-mt-(--header-height,75px)` collapses cleanly and the hero
fills the iframe edge-to-edge across every viewport preset.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
cd -
```

---

## Task 6: Manual verification in the browser

Confirm the slider fills the iframe across every preset + theme, atomic components keep their inset, and a chrome candidate behaves as expected.

**Files:** none (manual smoke test).

- [ ] **Step 1: Load the slider in the consumer styleguide**

Open `https://tailwind-base.ddev.site/styleguide/component/project-slider` in a browser.

- [ ] **Step 2: Cycle through all five preset modes**

For each preset — Mobile (375), Tablet (768), Desktop (1280), Custom (e.g. 900), Full — confirm:
- The slider hero **fills the iframe edge-to-edge** (no 24 px gutter on left/right, no white band at the bottom).
- The dimensions placeholder ("`1280 × 900`" or equivalent) is roughly centred and the caption ("Bytový dům …") sits near the bottom.

If the slider is still inset or white-band-bottomed, debug:
- `grep render: vendor/parisek/styleguide/templates/render-cell.twig` — confirm the new branching is present (i.e. `composer styleguide:local` is wiring through to the local checkout).
- DevTools: inspect the iframe's `<style>` block — `--header-height: 0px` should appear.

- [ ] **Step 3: Verify both themes**

Toggle the styleguide chrome between light and dark mode (top-right toggle). The slider's appearance should change with its own internal styling, but the iframe wrapper (chassis bezel from the previous feature) keeps its `ring-zinc-800 dark:ring-zinc-700` adaptive look. No regression expected.

- [ ] **Step 4: Smoke-check an inset component**

Open `https://tailwind-base.ddev.site/styleguide/component/accordion`. Confirm:
- The 24 px inset wrapper is still present (accordion isn't flush against the iframe edge).
- No `--header-height: 0` injection in the iframe's `<style>` (DevTools → Elements → iframe → `<head>` → `<style>`).

- [ ] **Step 5 (optional, only if a sticky-header component exists): Verify chrome mode**

If the consumer has a sticky-position header component (e.g. `header-menu` with sticky positioning) and adds `render: chrome` to its YAML:
- Open its styleguide URL.
- Confirm the iframe's body has `min-height: 200vh` (DevTools).
- Scroll inside the iframe — the header should stay pinned to the top.

Skip this step if no such consumer component currently exists; the chrome path is covered by the PHP test in Task 4.

- [ ] **Step 6: No-op smoke test of legacy components**

Click through 3-4 atomic components (button, breadcrumb, alert, pagination) and confirm they look identical to how they did before this feature shipped. No visual regressions expected — they all default to `inset`.

---

## Self-Review Notes

**Spec coverage check:**
- ✅ Spec § Solution / "YAML key" → Task 2 (parser-level normalisation) + Task 3 (forwarding)
- ✅ Spec § Mode semantics table (inset / bleed / chrome / overlay) → Task 4 (template branching) + Task 4's three RendererTests
- ✅ Spec § Wire-up four touch points → Tasks 2, 3, 4 (one task per layer)
- ✅ Spec § Migration → Task 1 (rollback prototype) + Task 5 (apply new opt-in)
- ✅ Spec § Tests → Task 2 covers 7 parser tests, Task 4 covers 3 renderer tests (one per non-default mode plus the bleed≡overlay equivalence)
- ✅ Spec § Manual verification → Task 6

**Placeholder scan:** No TBD / TODO / "implement later" / vague handling left. Every code step shows full code; every command shows expected output.

**Type consistency:** `normaliseRender` signature is consistent across declaration (Task 2 Step 3) and call sites (Task 2 Step 5, Task 3 Step 2). `component.render` Twig var consistent between Renderer (Task 3) and template (Task 4). `RENDER_MODES` constant consistent.

**Scope check:** Single subsystem (component render modes), single coherent plan. No decomposition needed.
