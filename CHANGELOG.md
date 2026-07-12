# Changelog

All notable changes to `parisek/styleguide` are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Releases before [0.4.0] have moved to [`CHANGELOG-archive.md`](CHANGELOG-archive.md).

## [Unreleased]

### Added

- **Flexible color model (#71).** `colors:` palettes accept a free `swatches:` list
  (named colors, optional per-swatch `css_variable`) alongside the existing Tailwind-style
  `shades:` map — projects without a 50–950 scale finally render a sane overview. Both
  shapes normalize through the new `ColorPalettes`/`ColorUtil` layer; swatch text color
  (light/dark) is now computed from the hex via WCAG relative luminance instead of the
  hardcoded shade-name list, so dark 100s and light 700s stop guessing wrong. Foundations
  markup adapts to small palettes (capped swatch tiles, wrapping strip, truncating labels).
  All swatch data is `html_attr`-escaped into the Alpine attributes in `foundations.twig`
  (autoescape is off in this package), so free-form swatch names carrying apostrophes or
  quotes render safely. OKLCH values are computed from the hex when the yaml omits them and
  are the preferred lightness basis.

- **Contrast / a11y layer (#72).** Every swatch on the overview now grades white/black
  text against WCAG AA (4.5:1) — dot badges on the tile, exact ratios in the tooltip and
  hero — and an expandable matrix grades every color × color pair (all palettes + white +
  black) with AAA / AA / AA-large / fail verdicts. Computed server-side from the hex via
  the #71 normalization layer; zero yaml changes needed (new `labels.contrast_matrix*`
  keys are optional with English defaults).

- **Favicon audit section (#73).** Foundations gains a `#favicon` section: the favicon
  rendered in simulated contexts (browser tab light/dark, iOS home-screen icon, Android/PWA
  maskable icon) plus a server-side audit checklist — file existence, real pixel dimensions
  vs. expectations (`png_96` 96×96, `apple_touch` 180×180), parsed ICO contents, manifest
  JSON validity with per-icon existence checks, and the `theme_color` swatch. Audited by the
  new `FaviconAudit` class, which runs entirely server-side against `static_path`; every
  configured path (and every manifest icon `src`) is containment-checked to stay under
  `static_path` before any filesystem read. Checklist labels are overridable via optional
  `labels.favicon*` keys, all with English defaults — no yaml changes are required beyond
  the existing `favicon:` block.

### Changed

- **Foundations interactivity no longer requires the consumer to ship Alpine (#79).**
  `templates/foundations.twig` drops all Alpine directives; swatch switching, copy-to-clipboard
  and the contrast-matrix toggle now live in a package-shipped, dependency-free
  `dist/foundations.[hash].js`, injected for foundations renders exactly like the existing
  foundations CSS bundle. The hero server-renders the default swatch, so the no-JS view shows
  real data. Consumer component demos inside the iframe are unaffected — they keep using
  whatever the consumer bundles.

- **`|resizer` now emits tuple-declared variants for real fixture images too, not only
  `placeholder()` fakes** ([#70](https://github.com/parisek/styleguide/issues/70)). Every tuple
  becomes an entry reusing the ORIGINAL `src` (no image pipeline — the browser downloads one
  file) with the tuple's declared `width`/`height`/`media`, so the styleguide `<picture>` DOM
  mirrors the multi-source markup the CMS resizer emits in production — what DOM-structural
  checks (tailwind-base's `picture.contract.js` sharpness contract) assert against. With
  real-content fixtures (the `picture.md` "prefer real content" default) previously passing
  through untouched, the contract silently skipped exactly the fixtures projects prefer.
  Missing tuple axes derive from the fixture's `width`/`height` metadata; without metadata the
  provided axis is kept and the other omitted (never invented). `.gif` fixtures pass through
  whole — parity with timber-kit `Resizer::$skip_animated` (animation can't be cheaply proven
  from a URL, and flattening an animated preview is the dangerous direction). Verified against
  a real consumer (pm-a): 220 visual baselines unchanged, behavior picture-contract coverage
  grew from 16 to 26 renders with no failures.

- **Malformed metadata YAML now surfaces in `GET /styleguide/api/health` instead of silently
  dropping the component from the catalogue.** `ComponentParser::parseTwigComment()` throws
  `ParseException` on invalid YAML (previously degraded to `false`); the existing resilience
  paths in `parse()`/`parseAll()` catch it and record a `getWarnings()` entry, so the walk
  continues and the broken file is named. Variant sibling annotations keep the old
  fall-back-silently behaviour (caught at the `discoverVariants()` call-site). Real-world
  trigger: an unquoted `{ padding-top: 0 }` in a field description made two sloneek
  components vanish with no trace. Behavioural note for direct `parseTwigComment()`
  consumers: wrap in `try/catch (ParseException)` if you relied on the `false` return.
- **`styleguide lint` reports `metadata-yaml-invalid` (Error) for malformed metadata YAML** instead
  of crashing on the propagated `ParseException` — distinct from `unindexed` (no metadata comment
  at all), because the author DID write metadata and the component is guaranteed missing from the
  catalogue.

## [1.2.0] - 2026-07-09

### Added

- **`styleguide.data.yaml` / `styleguide.data-<name>.yaml` sidecars + `styleguide_data()` Twig function.** A component/page/doc directory may now ship one or more pure-YAML sidecars — `styleguide.data.yaml` (the DEFAULT set, no-arg `styleguide_data()`) and any number of `styleguide.data-<name>.yaml` NAMED sets (`styleguide_data('<name>')`), `<name>` matching the SAME `[a-z0-9-]+` id rule already used for `styleguide.<variant>.twig` variant ids — a flat naming family living directly in the component directory, mirroring the variant-sibling convention rather than introducing a second nested `data/` concept. `'default'` is a RESERVED set name — `styleguide_data('default')` throws an `\InvalidArgumentException` before touching the filesystem, pointing at the no-arg call instead, so a stray `styleguide.data-default.yaml` can never be loaded through either door. Solves a real gap: Twig's `{% include %}` can't export variables back to the caller, so several `styleguide.<variant>.twig` siblings sharing bulky demo data had no clean way to share it short of duplicating the data or an `{% extends %}`-based "data template" trick (still valid as an escape hatch for expression-heavy demos that can't be plain YAML). Resolution is always scoped to the CURRENTLY rendering component's own directory — there is no cross-component lookup — and its "currently rendering" context is cleared (reset to `null`) immediately after each render completes, so a `styleguide_data()` call reaching the environment between renders throws instead of silently reusing a stale directory. Any `{ placeholder: {...} }` node anywhere in the tree — at any depth — is recursively resolved into the exact shape `placeholder()` itself returns, with `ratio:` (colon-separated, e.g. `"16:9"`) accepted as a YAML-only alias for `Placeholder::generate()`'s own `aspect:` option (slash-separated; the alias converts the separator too, and an explicit `aspect:` always wins over `ratio:` when both are present); `src:`/`url:` string values are recursively rebased onto `templateUrl`/`homeUrl` (same rules as `resolveAssetUrl()` — including root-relative paths like `/dist/foo.png`, which ARE rebased, not treated as absolute). An invalid `<name>` (doesn't match `[a-z0-9-]+`, which also rejects traversal-shaped values like `'../x'`, `'a/b'`, `'%2e%2e'`) is rejected before the filesystem is touched; a missing set → `RuntimeException` naming the expected path (relative to `templates_path` — the absolute path is logged via `error_log()` instead, never leaked into rendered 500-page markup) AND enumerating every data set actually present in that directory (a typo aid); an empty/`null`/`{}`/`[]` sidecar resolves to `[]`, while a bare-scalar top-level node throws a `RuntimeException` naming the actual shape found; malformed YAML → Symfony's `ParseException` propagates unchanged (same uncaught contract as `styleguide.yaml` itself), and since no object/custom-tag parse flags are ever passed, a `!php/object`-tagged node resolves to `null` (never a real PHP object) and an arbitrary custom tag hits the same `ParseException`. Never collides with variant-sibling discovery (`.yaml` vs. the `.twig`-only glob). Discovered while wiring `mairateam`'s shared hero demo data. See `README.md` § *YAML sidecar data* and `docs/API.md` § `styleguide_data()`.

## [1.1.2] - 2026-07-08

### Fixed

- **Doc entries are never responsive.** A doc page is prose — like `foundations`/`overview` — never a widget meant to be previewed at different breakpoints, but its `responsive` YAML key still defaulted to `true` like a component/page, so a doc got the viewport-preset/width toolbar unless every author remembered to set `responsive: false` by hand. `ComponentParser` now forces `responsive: false` for every `doc` entry regardless of the YAML key — even an explicit `responsive: true` in a doc's front-comment has no effect. Component/page kinds are unaffected (still default `true`, opt out with `responsive: false`).
- **Consumer `iframe.body_class` no longer bleeds onto doc pages.** Same bug shape as the foundations fix in [1.1.0]: `render-cell.twig` applied the consumer's site-wide `iframe.body_class` to `<body>` for `doc` renders too — a consumer with a dark `iframe.body_class` broke prose readability on a real doc page, reported after a consumer's doc rendered with the site's dark brand background. The global class is now skipped for `kind == 'doc'`, mirroring `foundations`. Deliberate difference from `foundations`: the **per-entry** `body_class` (authored in the doc's own YAML front-comment) still applies — it's an explicit per-doc opt-in by the author, not a site-wide bleed, so it remains the doc's escape hatch.

## [1.1.1] - 2026-07-08

### Fixed

- **Sidebar favicon gets protective padding.** The project favicon in the sidebar header rendered edge-to-edge inside its rounded box — icons without their own safe-area looked glued to the corners. The image now sits inset (`p-1`, `object-contain`) so any favicon shape breathes.
- **Sidebar-toggle icon stays a hamburger.** The open-state morph into an X read as "close/dismiss" rather than "menu" and was misleading next to the sidebar's own close affordances; the icon is now static in both states (`aria-expanded` still carries the state for assistive tech).

## [1.1.0] - 2026-07-08

### Added

- **Components may ship ONLY named variant fixtures — no bare `styleguide.twig` required.** A component/page/doc directory with `styleguide.<id>.twig` siblings and no bare `styleguide.twig` now still surfaces as a renderable entry: `has_styleguide` goes `true` from named variants alone, not just from the bare sibling or the legacy `styleguide:` flag, so an all-named component is no longer silently filtered out of the sidebar/palette/overview. New additive `has_default_variant` field on `/api/components`/`/api/pages`/`/api/docs` (`true` only when `<id>/styleguide.twig` itself exists) lets the SPA's variant grid tell "has some fixture" apart from "has the unnamed default fixture" — `VariantGrid.vue` now shows its synthetic "Default" tile only when `has_default_variant` isn't explicitly `false`, so a variants-only component's grid shows exactly its named tiles (all click-to-isolate, the first included) with no broken/empty tile pointing at the component's raw production template. See `docs/API.md` § `/api/components` and `README.md` § *File-convention variants → All named, no bare default*.

### Fixed

- **Consumer `iframe.body_class` no longer bleeds onto the foundations page.** `render-cell.twig` applied the consumer's site-wide `iframe.body_class` (and the per-entry `component.body_class`) to `<body>` for every kind, including `foundations` — a package-owned page with a fixed zinc-on-white palette. A consumer with a dark `iframe.body_class` (e.g. a dark brand background) made the foundations page's headings/cards nearly invisible, discovered in the `mairateam` project after the 1.0 bump. Both classes are now skipped for `kind == 'foundations'`; component/page kinds are unaffected. The existing light/dark `body { background-color }` safety net in `<head>` is untouched.


## [1.0.0] - 2026-07-07

### Added

- **`styleguide lint` CLI subcommand.** Reports metadata quality issues across `templates/`: unindexed templates (no parseable `name:`), dead `styleguide:` YAML content, broken `usage:` cross-references, unknown `render:` values, and empty `description` strings. `--type=component|page|doc` (default: all three), `--format=text|json` (default: text), reuses `--templates`/`--pretty`. Exit `0` clean, `1` warning/error findings present, `2` usage/internal error — a three-tier contract specific to `lint` (`list`/`show` keep their existing `0`/`1` codes). See `docs/API.md` § CLI and `README.md` § Command-line catalogue. The `broken-usage-ref` check and the catalogue itself now share one `ComponentParser::normaliseUsage()` helper, so a `usage:` value — comma-separated string as authored, or an already-array YAML value — is parsed into ids exactly once; `/api/components`/`/api/pages`/`/api/docs` emit the result as `usage: string[]` rather than the raw CSV.
- **New `GET /styleguide/api/health` endpoint.** Reports per-file parse warnings (`ComponentParser` now catches `\Throwable`, not just YAML `ParseException`, so one pathological template no longer 500s the whole `/api/components` catalogue — it's skipped and recorded instead) plus component/page/doc counts. A separate endpoint rather than a `_warnings` field on the existing four, which each emit a bare JSON array with no additive slot for a sibling field.
- **`?theme=light|dark` on the render endpoint.** Implements the contract `README.md`/`docs/API.md` already documented but the code never enforced (doc drift closed). Whitelisted server-side (`Router::whitelistTheme()`) — anything other than the literal string `dark` resolves to `light`. Stamps `class="dark"` + a matching `color-scheme` on the rendered `<html>`; inert for projects without dark-mode CSS. SPA toolbar gained an iframe-theme toggle independent of the chrome theme.
- **Optional `auth` config key.** `callable(array $route): bool` checked once per request before any dispatch; return `false` to respond `403 Forbidden` (plain text) before SPA/render/API/asset handling runs. `null` (the default) preserves today's behaviour — no gating. A non-`null`, non-callable value throws `InvalidArgumentException` at construction (fail loudly at boot instead of silently allowing every request), and an `auth` callable that throws is treated as a denial and logged via `error_log()` rather than letting the exception reach the response (fail closed). Documented alongside a recommendation to prefer web-server-level HTTP Basic Auth for publicly reachable deployments.
- **Auto-discovered `styleguide.<variant>.twig` sibling files.** `ComponentParser` now globs a component/page/doc directory for `styleguide.<id>.twig` files (`<id>` matching `[a-z0-9-]+`) and surfaces them as an additive `variants: [{id, title, description}]` field ([] when none exist — every pre-existing template keeps this BC default). The filesystem is canonical: an id with no matching file is silently dropped rather than fabricating a phantom variant. Display metadata (`title`/`description`) is authored directly in the sibling file's own first `{# … #}` comment — the same front-comment convention every component/page template already uses — with the component's YAML `variants:` map (`<id>: <title-string \| {title?, label?, description?}>`, `label:` a legacy alias for `title:`) kept only as a fallback for templates written before per-sibling annotations existed; a missing or malformed sibling annotation falls through to the map, then to the id, without ever failing the whole component. Ordered by id (== filename order). Passed through verbatim by `/api/components`, `/api/pages`, `/api/docs`, and the `list`/`show` CLI commands, alongside the object's own sibling-detection flag, now named `has_styleguide` for casing consistency with `body_class` and the rest of the object. See `docs/API.md` § Component YAML metadata and § Component Twig file conventions.
- **`?variant=<id>` on the render endpoint** resolves the auto-discovered `styleguide.<id>.twig` sibling in place of the default `styleguide.twig`, for `component`/`page`/`doc` kinds. Whitelisted server-side against `^[a-z0-9-]+$` (`Router::whitelistVariant()`) on both the render endpoint and the SPA-shell deep links (`/component/<slug>`, `/page/<slug>`, `/doc/<slug>`) so `Router::synthesizeEmbeddedRoute()` can forward it across the iframe-embed swap. Absent, invalid, or unknown-but-well-formed values fall back silently to the default `styleguide.twig` → `<slug>.twig` chain — never a 404 — so a bookmarked deep link survives a deleted/renamed variant file. Query-only, no cookie fallback (unlike `theme`): a variant is scoped to one deep link, not a visitor preference worth persisting, so an in-iframe native navigation to a link without its own `?variant=` loses it on the swap — an accepted, documented gap. Composes independently with `?theme=`. `?variant=<id>` is deep-linkable via the SPA's own `?variant=` query param, silently reset on navigation to a different entry unless the incoming URL itself carries a valid one for that entry — see the variant grid bullet below for how the SPA surfaces this today.

- **Variant grid — every variant as its own preview screen, tiled to fit the canvas (SPA-only; prototype).** The toolbar pill switcher is gone, and so is the short-lived server-side stacked view: the render endpoint's `?variant=` semantics are unchanged from the file-convention variants feature above (no `?variant=` → the default `styleguide.twig` body only, single block, no headings; `?variant=<id>` → isolate that one block). Instead, whenever the SPA's current entry has discovered variants and no `?variant=` is selected, the preview area becomes a responsive grid of independent `<iframe>` tiles — the default fixture first, then each discovered variant in order. Each tile gets a slim header (the variant's title, plus its `description` when supplied). A deep-linked `?variant=<id>` still shows the classic single preview of just that variant (an unknown/removed id falls back to the grid, not a 404 and not a stale "selected" pill — there is no pill any more).
  - **Device presets now apply per tile, with one shared scale readout in the toolbar.** The toolbar's responsive-width preset dropdown (+ custom width + orientation toggle), previously hidden while the grid was active, now stays visible and functional: a fixed-width/fixed-height preset (Mobile, Tablet, …) renders every tile's iframe at exactly that preset's logical size, then scales the whole tile down (never up) to fit that tile's own measured cell width. Since the shared preset and (uniform cell widths) the resulting zoom are identical across every tile, the scale no longer repeats in each tile's header — the toolbar's viewport trigger label now shows it once instead (e.g. `Mobile 375 × 667 (84 %)`, the same `(NN %)` convention the classic single preview already used), and only when the shared zoom is below 100%. Full stays fluid (100% cell width, auto content height, no scaling) — the grid's original behavior. Drag-to-resize handles remain single-preview-only (the grid has none to hide).
  - **Tile density — Auto | 1 | 2 | 3 | 4.** A new toolbar dropdown (grid mode only, matching the device-preset trigger's own rounded-pill/icon/label/chevron shape) replaces the earlier rows/grid layout toggle with five density options — inspired by Histoire's single/grid story layout, extended to be preset-aware. "Auto" (the default) derives the `auto-fit` `minmax()` column basis from the shared viewport preset's effective width instead of one fixed constant for every preset — a Desktop preset (1280 px) settles on far fewer tiles per row than Mobile (375 px) on the same canvas; Full keeps the original fixed 420px basis. "1"–"4" fix the column count exactly (`repeat(N, minmax(0, 1fr))`), ignoring the preset — "1" is the direct visual replacement for the old "rows" stacked layout (a single-column grid with the same subgrid header-height sharing renders identically). Persisted under `sg-variant-columns` in localStorage; a pre-existing `sg-variant-layout` value migrates once (`"rows"` → `1`, `"grid"` → `"auto"`) and the legacy key is removed. Tiles no longer stretch to the tallest one in their row, and the pre-load placeholder height dropped from a visibly over-tall 320px to a much smaller 96px.
  - **Click-to-isolate, with a subtle expand-icon hint.** A variant tile's header is now a click/keyboard affordance that navigates straight to `?variant=<id>` (the classic single, resizable preview) — no more re-selecting it from a dropdown. A small expand icon at the right edge of every clickable header (muted, revealed on hover and on keyboard focus) makes the click-through discoverable; the Default tile's header carries neither the affordance nor the icon — there is no route that shows it alone in the classic single preview without also satisfying the grid's own activation rule (any entry with variants and no `?variant=` selected always lands back on the grid).
  - **Breadcrumb-based return to the grid, replacing the earlier "← All" toolbar back control.** Once a variant is isolated, the toolbar breadcrumb gains a trailing Variant segment ("Section / Component name (slug) / Variant title") and its component-name crumb itself becomes a click/keyboard link back to the grid — standard breadcrumb semantics instead of a dedicated button. The crumb stays a plain, non-interactive label whenever no variant is isolated (nothing to go back to). The description bar (between the toolbar and the usage panel) also picks up variant context: while isolated, it shows the variant's own `description` (prefixed with its title, styled like the grid's own tile-header titles) in place of the component/page's general description — replaced, not appended, since the variant's own description is the more specific of the two; a variant with no description of its own leaves the bar showing nothing rather than falling back to the general blurb.

### Changed

- **Sidebar prefix-tree groups drop the chevron.** The component-section and Pages-section group rows (e.g. "Widget — 4") no longer render a rotating arrow glyph before the label — the count badge alone signals a group, and the label now sits flush at the same left padding as every flat sibling item instead of being indented out of line. The whole row is still the expand/collapse toggle; `aria-expanded` keeps the state programmatically discoverable now that the visual chevron cue is gone.
- **Light motion layer added across the SPA chrome**, all gated behind `@media (prefers-reduced-motion: no-preference)` (Tailwind's `motion-safe:` variant, or a dedicated CSS keyframe for anything Tailwind can't express) so a reduced-motion preference gets an instant state change instead of a tween: the toolbar's hamburger button morphs its three bars into an X (~200ms) when the sidebar toggles; the mobile sidebar slide-over now uses a 240ms `cubic-bezier(0.32,0.72,0,1)` easing plus a backdrop opacity fade (~200ms) instead of a hard show/hide; sidebar sections and prefix-groups expand/collapse via a CSS `grid-template-rows` 0fr↔1fr tween instead of snapping open/closed; the variant grid's tiles get a staggered entrance (opacity/translateY, ~180ms, 30ms stagger per tile) on first render; the command palette fades and scales in (~120ms) on open.
- **Preview navigation no longer flashes the previous entry's content.** The preview `<iframe>` is now keyed by its own src identity, so navigating to a different component/page/doc (or reloading, or toggling the iframe theme, or switching a variant) unmounts the old iframe and mounts a genuinely fresh one instead of just repointing one persistent element's `src` — a real browser used to keep painting the old document until the new one finished loading. The pane's measured content height also resets immediately on navigation instead of holding the previous entry's height until the new document reports its own, which used to make the preview pane visibly jump. The variant grid applies the same fix per tile.
- **⌘K/Ctrl+K now opens a command palette instead of focusing the sidebar's filter input.** The palette (`SearchPalette.vue`) ranks components/pages/docs by a new weighted multi-field score (`lib/searchMatch.js`'s `scoreEntry()` — name > id > category > description, exact > prefix > substring), groups results by section, and is fully keyboard-navigable (arrows with wraparound, Enter to open, Esc to close; a second ⌘K/Ctrl+K also closes it). The sidebar's own inline filter input is unchanged and still works as before. One behavior narrowed as a side effect: Escape-to-clear-the-filter used to be global (any focus state); it's now scoped to firing only while that filter input itself has focus, so it no longer fights with the palette's own Escape-to-close.
- **SPA chrome rewritten from Alpine.js 3 to Vue 3 + Pinia + vue-router** (Phase 1 of the Styleguide 2.0 roadmap). 1:1 feature parity — no new user-facing behavior. The `dist/` bundle is `@internal` and not covered by SemVer, but for transparency: every viewport preset/zoom/rotation, the sidebar prefix-tree grouping, search, locale switching, theme cycling, the fields drawer, and the usage/link cross-reference panels now ship with unit tests (Vitest) and a headless-browser parity suite (Playwright, running in CI for the first time — the previous Alpine-era browser suite was local-only).
- `Styleguide::dispatchSpa()` now injects a single `<script id="sg-config" type="application/json">` payload into `dist/index.html` instead of 6 separate regex substitutions, and throws when that injection point is missing instead of silently shipping a half-patched shell.
- New CI job asserts committed `dist/` is byte-for-byte reproducible from `frontend/` source (`npm run build && git diff --exit-code dist/`).
- **Helper registration no longer matches Twig exception text.** `tryAddFunction()`/`tryAddFilter()` used to rethrow unless the `LogicException` message contained the literal substring `"already registered"` — version-fragile and untested. They now always swallow (never crash a consumer's boot over a Twig-internal message change) and log to `error_log()` when the message doesn't match the expected collision, so the rare genuine-misuse case still leaves a trace.

### Fixed

- **Iframe dark theme no longer resets on in-iframe navigation.** A native link click inside dark-toggled render content (e.g. a nav link to another `/styleguide/page/...`) re-issues a `Sec-Fetch-Dest: iframe` request carrying no `?theme=` of its own, so `Router::synthesizeEmbeddedRoute()` used to hardcode `light`, silently reverting the visitor's choice. The SPA toolbar's iframe-theme toggle now also writes an `sg-iframe-theme=dark|light` cookie (`path=/styleguide`, `SameSite=Lax`); the router reads it as a fallback — through the same whitelist as the query param — whenever the request's own `?theme=` is absent. An explicit `?theme=` still wins over the cookie.
- **`#sg-config` JSON injection hardened against script-breakout XSS.** `dispatchSpa()` now encodes with `JSON_HEX_TAG` in addition to its existing flags, so a consumer-controlled value containing a literal `</script>` can no longer terminate the `#sg-config` element early.
- **Render-time exceptions now return HTTP 500.** A component/page/doc template that throws during render used to respond `200 OK` with the error markup embedded in the body — a health check or CI canary hitting `/render/<kind>/<slug>` couldn't distinguish a broken component from a working one. `Renderer` now calls `http_response_code(500)` before returning the (still-visible) error markup. `render404()` is unaffected.
- **`.map` files now serve as `application/json`.** `AssetServer` fell through to `mime_content_type()` for `.map` extensions (typically `text/plain`); explicit `application/json; charset=utf-8` matches their actual content.
- **Foundations CSS glob picks the newest file when several match.** `resolveFoundationsCssUrl()` used to return whatever `glob()` happened to list first when a stale `dist/foundations.*.css` from a previous build wasn't cleaned up; it now picks the newest by mtime and logs a warning via `error_log()`.
- **Variant grid tiles no longer grid-blow-out to 100% zoom for any fixed-width preset wider than the tile's own cell.** Real browsers (unlike the jsdom-based unit tests, which stub `clientWidth` and never actually lay anything out) default a CSS grid item's automatic minimum size to its content's min-content size — the tile card and its content-area wrapper had neither set `min-width: 0`, so a scaled-down device preset's own fixed-width iframe kept forcing its ancestor tracks to grow back to the iframe's full logical size, measuring that inflated width right back into the next zoom calculation and permanently pinning it at 100%. The "scale down to fit this tile's own measured cell width, never up" behavior documented since the original device-presets feature never actually engaged in a real browser as a result. Both the tile card and its content-area wrapper now carry `min-w-0`.
- **A fixed-width preset's scaled tile screen no longer overflows and gets clipped on its right edge.** `VariantGrid.vue`'s per-tile cell-width measurement read the content-area wrapper's `clientWidth` — its padding-box width, which includes the wrapper's own `p-3` — so `computeTileGeometry()`'s zoom fit against a cellWidth ~24px too generous; the resulting `wrapperWidth` (and the scaled iframe inside it) rendered past the wrapper's true content box and lost its right edge to the wrapper's `overflow: hidden`. `registerCell()` now measures the wrapper's content-box width (its `clientWidth` minus its own padding) instead, matching what its `ResizeObserver` already reported on every subsequent tick — both the initial synchronous read and every resize now agree on the same number, and the scaled screen renders centered with equal left/right gaps and no cropped edge.

### Documentation

- **`docs/API.md` fixes.** The `/api/fields` response shape documented an `{id, type, name, fields}` union that never existed — `FieldsEndpoint::handle()` has always emitted `{component_id, component_name, fields}` (matching `README.md`'s copy, which was already correct). Also documented three bundled Twig filters (`cachebust`, `format_date`, `custom_price_format`) that shipped since 0.1.3/0.3.8 but were never added to the § Twig functions & filters table.
- **CHANGELOG archived.** Entries older than `[0.4.0]` moved to `CHANGELOG-archive.md` (byte-for-byte relocation, nothing edited) to keep the active changelog focused on recent series.
- **Stale e2e Layer B removed.** `tests/e2e/smoke-browser.sh` reached into `window.Alpine.store(...)`, a global the Vue rewrite removed — it had been dead code since Phase 1. Deleted the script and its `run.sh`/CI wiring; the Playwright suite (`tests/e2e/playwright/styleguide.spec.js`) already covers the same behaviour and, unlike its predecessor, runs in CI.
- **`docs/MIGRATION.md` added.** Step-by-step guide for replacing a bespoke, hand-rolled styleguide with this package: the common bootstrap/config/rewrite/lint/cleanup steps once, then per-project deltas for the fleet's `centrumocnichvad` (Tailwind v3 + SCSS, dual CSS bundle), `suys-static` (Drupal-backed Twig, dead `styleguide:` YAML content), and `bootstrap-base` (Bootstrap 5, `picsum.photos` fixture images) migrations. Linked from `README.md` § `lint` — metadata quality report.
- **Sibling `styleguide.twig` is now documented as the official fixture convention** (`README.md` § Fixtures & sample data); the `styleguide:` YAML key stays functional as a presence-only flag for backward compatibility, but content under it is flagged by `lint` (`dead-styleguide-content`).

## [0.6.5] - 2026-06-22

### Fixed

- **Toolbar crowding on narrow viewports.** Below `lg` the preview toolbar's secondary actions (Canvas / open-in-new / reload) now collapse into a single ⋮ overflow menu instead of colliding with the breadcrumb and the viewport dropdown; inline icons stay on `lg+`, and the viewport dropdown remains one-tap at every width. New i18n key `toolbar.more_actions`. (#61)

## [0.6.4] - 2026-06-22

### Fixed

- **Pages section now groups by name prefix.** The prefix-tree grouping (≥3 items sharing a `"<Prefix> - <Suffix>"` name collapse under a `<Prefix>` group) applied only to component sections — the Pages list rendered flat, because the tree builder walked `this.items` (components) and never `this.pages`. Added `components.pagesTree` and gave the Pages section the same search-vs-tree rendering, so e.g. "Hlavička - static / sticky / absolute / fixed" collapse under a "Hlavička" group (prefixes with < 3 members stay flat, same rule as components). (#59)

## [0.6.3] - 2026-06-22

### Fixed

- **Orientation toggle showed the wrong axis for Desktop presets.** The portrait/landscape switch bound its active state to `previewRotated` (relative to the preset's canonical shape); since Desktop presets are landscape-canonical, `rotated=true` renders portrait, so the toggle highlighted "landscape" while the preview was visibly portrait — and the mismatch survived a refresh. The toggle now reads/sets **absolute** orientation (`ui.isPortrait` / `ui.setPortrait()`, derived from the effective display dimensions), correct for both portrait-canonical (Mobile / Tablet) and landscape-canonical (Desktop) presets. (#57)

## [0.6.2] - 2026-06-22

### Fixed

- **Last indigo removed from the chrome.** The Fields-drawer `textarea` field-type badge (a categorical palette) was the only remaining indigo; flipped to red to match the Porta-red accent, so the SPA chrome is fully indigo-free. (#55)

## [0.6.1] - 2026-06-22

### Fixed

- **Tall / rotated preset scaling.** Device presets with a canonical height (including rotated ones — e.g. a rotated Desktop at 800×1280) overflowed the preview pane vertically and tucked their top edge under the toolbar where it couldn't be scrolled into view. Restored **fit-to-bounds** scaling: `zoom = min(1, availW/w, availH/h)` for fixed-height presets, so the whole emulated device — top edge included — stays visible. Full / Custom (no canonical height) remain width-only; scaling only ever shrinks, never upscales. (#53)

## [0.6.0] - 2026-06-22

### Changed

- **SPA chrome redesign — sidebar + toolbar.** Cleaner, birdclaw-influenced visual language with a Porta-red accent. Sidebar: active item is a red pill (across docs / components / pages / prefix-tree children), bullet dots dropped, airier rows, a "SEARCH" label + pill input, and a circular outlined theme toggle (stroke sun/moon/monitor). Toolbar: the desktop segmented viewport bar and the separate mobile menu are unified into **one labelled dropdown at every width** (`<word> · <W×H> ▾`, red active row); the KOMPONENTA badge + page usage chip move from indigo to Porta red. Collapsible sections, count badges, and the automatic prefix-tree are all retained. (#51)

### Added

- **Mobile sidebar overlay.** Below `lg`, the sidebar becomes a fixed slide-over (default-closed) opened by the hamburger over a backdrop; selecting a nav item closes it — so the preview gets full width on phones instead of being squeezed to ~100px. New i18n key `search.label` (cs `Hledat` / en `Search`). (#51)

### Fixed

- **Sidebar favicon fallback.** The SPA sidebar `#sg-favicon` swaps in a generic glyph via `onerror` when the configured favicon 404s, instead of the browser's broken-image icon (companion to the standalone-bar fallback from 0.5.0). (#51)

## [0.5.0] - 2026-06-22

### Added

- **Asset paths resolve against `twig_context.templateUrl`.** Every consumer-supplied asset path the package emits — `iframe.css` / `iframe.js` / `iframe.fonts[]`, `project.favicon` (the `<link rel="icon">`, the standalone-bar `<img>`, and the SPA sidebar `#sg-favicon`), and `styleguide.logo[*].src` (the foundations overview) — is now rebased onto the bootstrap's `templateUrl` at render time via the new `Renderer::resolveAssetUrl()`. This lets `styleguide.yaml` keep one short, docroot-agnostic path (`/dist/css/style.css`) that resolves correctly in **every layout**: standalone (empty `templateUrl` → unchanged), WordPress (`/wp-content/themes/<theme>/static/…`), and Drupal (`/themes/custom/<theme>/static/…`) — no per-CMS hardcoding, no `STYLEGUIDE_ASSET_PREFIX`-style docroot guessing. Backward compatible: an empty base is a no-op (byte-for-byte the old output); already-absolute-under-base paths are left untouched (no double prefix); external (`https://…`, `//cdn…`, `data:`) and anchors (`#…`) are never rebased. Fixes blank / CSS-less page renders (and the slow `/dist/...` 302-redirect loop) when the styleguide is served from a theme rather than from the static dir as docroot. (#46, #49)

### Fixed

- **Standalone-bar favicon fallback.** The "← back to styleguide" bar (shown when a render is opened in its own tab) now swaps a generic glyph in via `onerror` when the configured favicon 404s, instead of painting the browser's broken-image icon next to the page title — mirroring the SPA sidebar's favicon fallback. (#47)

### Documentation

- README gains an **"iframe asset paths — resolved against `templateUrl`"** section: a per-layout resolution table (standalone / WordPress / Drupal) and the `resolveAssetUrl()` rules. (#48)

## [0.4.5] - 2026-06-08

### Added

- **`iframe.page_wrapper_class` config.** Set it in `styleguide.yaml` and **every page render** is wrapped in `<div class="…">` — the project's structural shell (the `page-wrapper` sticky-footer flex column / `min-h-dvh` that the production layout puts around `header + main + footer`). Applied to `kind: page` only (never component / doc previews, so the full-height shell can't leak into a small preview), built through `create_attribute` (same class-escaping contract as the `<body>` line). Default `''` renders no wrapper, keeping the package framework-agnostic — Tailwind projects opt in, Bootstrap / custom consumers leave it blank. Completes the production-parity pair with `body_class`: `body_class` reproduces the page's `<body>`, `page_wrapper_class` reproduces the wrapper `<div>` — so a page preview matches production without each consumer hand-wrapping every `page/<name>/styleguide.twig`. Flows through the existing `iframe.*` passthrough (`Styleguide → Renderer → render-cell.twig`), no PHP changes.

- **Per-entry `body_class` metadata.** A component / page / doc can declare `body_class: "…"` in its front-comment metadata; the render iframe applies it to `<body>`, merged **after** the global `iframe.body_class` via `create_attribute({ class: [iframe.body_class, <entry>.body_class] })` (empty values dropped — no stray `class=""`). Lets a page mirror what its production layout puts on `<body>` (e.g. a dark brand background from an ACF `body_background_color`) instead of wrapping the page body in a styleguide-only `<div class="bg-… body-…">`. Parsed into the component record (so it also surfaces in `/api/*` + the CLI) and threaded through `Styleguide → Renderer → render-cell.twig`.

### Fixed

- **Static analysis & PHP 8.4 deprecations.** The typography translation helpers (`_xt` / `__t` / `_nt` / `_nxt`) now resolve their underlying Twig function callable through a guarded helper, clearing 9 PHPStan level-8 errors (`getFunction()` / `getCallable()` are nullable in Twig's signature) with no behaviour change — the fallback mirrors the identity stubs registered just above. PHPUnit now defines `<source>` and sets `ignoreIndirectDeprecations`, muting the implicitly-nullable-parameter deprecations emitted by the upstream `mundschenk-at/php-typography` (its latest release v6.7.0 is the ceiling of our `^6.0` constraint, so the deprecations are unfixable here); our own `src/` deprecations still surface.

## [0.4.4] - 2026-06-05

### Added

- **Typography-aware translation helpers `_xt` / `__t` / `_nt` / `_nxt`.** Same signatures as the WP `_x` / `__` / `_n` / `_nx` originals — `_xt`/`_nxt` require `context` and `_nt`/`_nxt` require `number` (no silent defaults that could mask a missing required argument) — but the translated result is piped through `|typography`, so long-form copy gets consistent typographic treatment without remembering `|typography` on every callsite (`_x` → `_xt` is a one-character opt-in). Each composes via `getFunction()/getFilter()->getCallable()` at call time, so the project's real translator (WP `_x()` etc.) and tuned typography settings win automatically; `is_safe: ['html']` mirrors the filter's contract. The WP-production (Timber) and Drupal sides land in parisek/timber-kit#42 and parisek/custom-components#87 with the same four signatures, so authoring stays portable across CMSes. (#21)

## [0.4.3] - 2026-06-04

### Added

- **Sidebar prefix tree.** Components whose display name shares a `<Prefix> - <Suffix>` shape are grouped into collapsible sidebar nodes (a prefix with ≥ 3 members), and children show the suffix only (`Article ▸ content / image / links`). Singletons and names without ` - ` stay flat with their full name. Groups are expanded by default, collapse state persists (`sg-groups`), and the active item's group auto-expands; a search query flattens the tree to full-name results. Purely presentational — derived from names at render time, no metadata/API change, so it benefits every downstream project automatically. (#38)

## [0.4.2] - 2026-06-04

### Fixed

- **Critical: the viewport-width toolbar never rendered for `responsive: true` entries** (regressed in 0.4.0). The `<template x-if>` gate called `$store.components.find(...)` directly inside the Alpine expression, which silently broke the render (no console error, gate logically true) — every component/page lost its resolution controls. Moved the lookup into a `toolbarVisible` getter so the template only reads a plain identifier. Added a browser e2e regression that asserts the toolbar actually renders (the previous suite only called `setPreset()` as a method, so a missing toolbar went unnoticed). (#36)

## [0.4.1] - 2026-06-04

### Fixed

- Entries with `responsive: false` now hide the viewport toolbar and pin the iframe preview to Full width, even when a fixed preview width was persisted from a previous route. (#34)

### Added

- **Search shortcut hint + toolbar reload.** The sidebar search box now renders the `⌘ K` hint (the Cmd/Ctrl+K focus keybind already existed); a new toolbar **reload** button re-fetches the `/api/*` catalogue and force-reloads the preview iframe (handy in local dev when a template changes). New `toolbar.reload` i18n key (cs/en).

## [0.4.0] - 2026-06-04

### Added

- **`doc` content kind** — a new first-class template kind alongside `component` and `page`. Doc templates live at `templates/doc/<name>/<name>.twig` (prefer `styleguide.twig` sibling, fallback `<name>.twig`). URL surface: `/styleguide/doc/<slug>` (SPA), `/styleguide/render/doc/<slug>` (bare iframe), `/styleguide/api/docs` (JSON list — same shape as `/api/pages`). The `@doc` Twig namespace is auto-registered when `templates/doc/` exists (doc templates reference `@doc` via `{% include %}` directly — there is no `doc_*()` helper). The kind is **optional**: absent `templates/doc/` → `/api/docs` returns `[]`, the DOKUMENTACE sidebar group still shows foundations + overview. New `Api\DocsEndpoint` class (`@internal`).
- **DOKUMENTACE sidebar group** — collapsible sidebar section grouping Foundations, Overview, and doc entries. Controlled by a new `nav.docs` i18n key (cs: `Dokumentace`, en: `Documentation`). The group is always present in the sidebar; doc entries appear below foundations + overview when `templates/doc/` is populated.
- **General `responsive` front-comment flag** — new optional boolean YAML metadata key applicable to component, page, and doc templates (default `true`). When set to `false`, the SPA hides the responsive-width toolbar for that entry, useful for docs or fixed-layout demos where viewport resizing has no meaning.

[Unreleased]: https://github.com/parisek/styleguide/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/parisek/styleguide/compare/v1.1.2...v1.2.0
[1.1.2]: https://github.com/parisek/styleguide/compare/v1.1.1...v1.1.2
[1.1.1]: https://github.com/parisek/styleguide/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/parisek/styleguide/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/parisek/styleguide/compare/v0.6.5...v1.0.0
[0.4.0]: https://github.com/parisek/styleguide/compare/v0.3.14...v0.4.0
