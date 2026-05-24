# Component render modes

## Problem

`render-cell.twig` wraps every `kind=component` preview in a `<div style="padding:1.5rem">`. The inset is right for atomic components (button, alert, breadcrumb) — without it they sit flush against the iframe's top-left corner, visually colliding with the styleguide chrome above.

Full-bleed components (`project-slider`, `page-header-*`) and page-bound chrome (`header`, `footer`, `cookieconsent`) don't want that inset. The slider already uses `h-svh lg:h-screen` internally to fill the viewport; the inset wrapper kills 48 px of width and 48 px of height around it, breaking the 1:1 expectation with the iframe frame.

A second, related problem: the slider uses `margin-top: var(--header-height, 75px) * -1` to pull its hero under a sticky site header. In styleguide isolation no such header exists, so the fallback `75px` shifts the hero off-screen, leaving a ~50 px white band at the bottom even when the wrapper is removed.

A binary `fullBleed: true` solves the slider but misses other rendering contexts (sticky header demos, overlays). The right shape is a small enum of named render modes.

## Solution

Introduce a YAML metadata key on components:

```yaml
render: inset | bleed | chrome | overlay
```

Default (key absent or unrecognised): `inset` — current behaviour, zero impact on legacy projects.

The key is **component-only**. Pages already render their own layout and skip the inset wrapper; setting `render` on a page is silently ignored.

### Mode semantics

Four named modes, three distinct iframe behaviours:

| Mode | Inset wrapper | `--header-height` | `min-height` on body | When to use |
|---|---|---|---|---|
| `inset` | yes, 24 px on all sides | unchanged | unchanged | Atomic UI pieces (button, alert, breadcrumb, picture, pagination, accordion). |
| `bleed` | none | `0px` injected at `:root` | unchanged | Hero, slider, page-header — fills the iframe edge-to-edge. |
| `chrome` | none | `0px` injected at `:root` | `200vh` on `<body>` | Sticky / fixed page chrome (`header-*`, `footer`, `cookieconsent`) that needs scrollable host content to demo its sticky behaviour. |
| `overlay` | none | `0px` injected at `:root` | unchanged | Modals / dialogs. Functionally identical to `bleed`; the separate label exists so future UI can surface "this is an overlay" without changing the iframe wrapper. |

The `overlay` ≡ `bleed` collapse is deliberate. Backdrops, body `overflow:hidden`, "open by default" — all of those belong in the consumer's `styleguide.twig` demo file, where they have access to component-specific markup and state. The iframe wrapper stays minimal.

### Wire-up

Four touch points:

1. **`src/ComponentParser.php`** — `normaliseMetadata()` adds a `render` field. Reads `$metadata['render']`, validates against the allowed set, falls back to `'inset'` for missing or unknown values. Unknown values are coerced silently (no exception) — a typo shouldn't break the catalog.

2. **`src/Styleguide.php`** — when resolving component metadata for the iframe render, forwards `$meta['render']` into `$config['render']`.

3. **`src/Renderer.php`** — exposes the mode to Twig as `component.render` on the render-cell context.

4. **`templates/render-cell.twig`** — branches on `component.render`:
   - `inset`: keep current `<div style="padding:1.5rem">` wrapper.
   - `bleed` / `overlay`: skip the wrapper, inject `:root { --header-height: 0px }` in the inline `<style>`.
   - `chrome`: same as `bleed`, plus `body { min-height: 200vh }` so sticky/fixed elements have content to scroll against.

Inline CSS — not utility classes — keeps the behaviour framework-agnostic (Tailwind / Bootstrap / custom).

## Migration

No consumer change is required. Existing components without `render:` continue to render with the 24 px inset (the current behaviour). Components that *want* a different mode opt in by adding a single line to their YAML front-comment.

The `fullBleed: true` shape I prototyped before this spec is discarded. The current uncommitted diff (in `src/`, `templates/render-cell.twig`, and `tailwind-base`'s `project-slider.twig`) is rewritten against the enum during implementation.

## What's explicitly NOT in scope

- **UI chip / badge** surfacing the render mode in the sidebar, overview, or toolbar. We can add it later if there's a real "I can't tell which mode is on" pain point.
- **Project-level default** in `styleguide.yaml`. Single hardcoded default (`inset`) is simpler; per-component opt-in is enough granularity for the foreseeable workload.
- **Per-mode validation** beyond the allowed enum. We don't enforce that `chrome`-mode components actually use `position: sticky/fixed`, or that `overlay` components actually overlay — the YAML key is a hint, not a contract.
- **Automatic overlay backdrop / body lock** for `overlay` mode. Belongs in the consumer's `styleguide.twig` demo file.

## Tests

`tests/ComponentParserTest.php`:
- `parses_render_inset` — explicit `render: inset` produces `render => 'inset'`.
- `parses_render_bleed` — explicit `render: bleed` produces `render => 'bleed'`.
- `parses_render_chrome` — explicit `render: chrome` produces `render => 'chrome'`.
- `parses_render_overlay` — explicit `render: overlay` produces `render => 'overlay'`.
- `defaults_to_inset_when_missing` — no `render` key → `'inset'`.
- `falls_back_to_inset_for_unknown_value` — `render: hero` (or other typo) → `'inset'`.

`tests/RendererTest.php`:
- `renders_inset_wrapper_for_default_component` — confirms `padding:1.5rem` div is present.
- `omits_inset_wrapper_for_bleed_render` — confirms wrapper is absent; confirms `--header-height: 0px` style is emitted.
- `injects_min_height_for_chrome_render` — confirms `min-height: 200vh` on body is emitted; confirms `--header-height: 0px` is also emitted.
- `overlay_render_matches_bleed_iframe_shape` — sanity check that `overlay` and `bleed` emit identical iframe markup (semantic label only).

PHPStan stays at its current level.

## Manual verification

After implementation, on `tailwind-base` with `composer styleguide:local`:

1. `/styleguide/component/project-slider` with `render: bleed` in its YAML — verify the slider fills the iframe in **all five** preset states (mobile / tablet / desktop / custom / full) and in both light + dark chrome themes.
2. `/styleguide/component/accordion` (no `render:` key) — verify the 24 px inset is still applied, behaviour identical to before this change.
3. Add `render: chrome` to a header component, verify body becomes scrollable (sticky header stays visible while scrolling within the iframe).
