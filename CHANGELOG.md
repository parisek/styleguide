# Changelog

All notable changes to `parisek/styleguide` are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Canvas navigation mode.** A toolbar button next to "Open in new tab"
  drops the iframe entirely and navigates the parent window in-place to
  `/styleguide/render/<kind>/<slug>?canvas=1`. In that view viewport
  units (`vh` / `svh` / `dvh` / Tailwind `h-screen`) resolve against the
  real browser viewport, not the iframe's — fixes the truncated-hero
  problem where `h-svh` collapsed to inner-document height inside an
  iframe. The `?canvas=1` flag tells `render-cell.twig` to suppress its
  standalone back-bar so the hero exactly fills the viewport; the
  browser back button is the return path.

- **Per-component render modes.** New YAML metadata key on components:

  ```yaml
  render: inset | bleed | chrome | overlay
  ```

  Drives the iframe wrapper in `render-cell.twig`:

  | Mode | Inset wrap | `--header-height` | Body `min-height` | Use for |
  |---|---|---|---|---|
  | `inset` (default) | 24 px on all sides | unchanged | unchanged | Atomic UI (button, alert, breadcrumb, picture). |
  | `bleed` | none | `0px` injected at `:root` | unchanged | Hero, slider, page-header — fills the iframe edge-to-edge. |
  | `chrome` | none | `0px` injected at `:root` | `200vh` on `<body>` | Sticky / fixed page chrome (`header`, `footer`, `cookieconsent`) that needs scrollable host content to demo sticky behaviour. |
  | `overlay` | none | `0px` injected at `:root` | unchanged | Modals / dialogs. Functionally identical to `bleed` at the iframe level today; the separate label exists for future UI surfacing. |

  Default is `inset`, so legacy components without the new key keep
  their pre-feature padding wrapper — **zero breaking change**. The
  `--header-height: 0px` reset for `bleed/chrome/overlay` collapses
  consumer "tuck under sticky header" hacks like
  `margin-top: var(--header-height, 75px) * -1` to a no-op in
  styleguide isolation (no sticky chrome above to hide behind).

  Validated against the canonical set: `ComponentParser::normaliseRender()`
  coerces typos and non-string values silently to `inset`, never throws.

- **Device chassis frames** for mobile / tablet presets. An abstract
  rounded bezel with speaker slot + home indicator (mobile) or camera
  dot (tablet) wraps the preview iframe so the device reads as a
  device, not a white box. Pure Tailwind ring + absolute-positioned
  decoration; the iframe itself still carries the preset's exact
  logical CSS pixel dimensions, so `@media`, `vw`, `vh`, and breakpoints
  inside resolve identically to the real device. Desktop keeps a clean
  card; Full + Custom stay frame-free. Adapts to light + dark chrome.

- **Desktop 2K preset** (`2560 × 1440`) — the QHD/2K resolution commonly
  emulated for 27" monitor design work, alongside the existing Mobile S/M/L,
  Tablet/L, Desktop/L/XL, and Full presets.

- **Fit-to-bounds preview zoom.** The zoom getter now picks the smaller
  of the width-ratio and the height-ratio (`Math.min(widthRatio,
  heightRatio, 1)`), so a 2560 × 1440 preset on a 1280 × 800 chrome
  pane shrinks proportionally in **both** axes — preserving the device's
  aspect ratio — instead of overflowing the vertical edge. Custom
  widths (no logical height) fall through via `Infinity` so the
  width-only path is unchanged.

- **Vertically centred chassis + dimension badge.** Non-Full presets
  now sit centred in the canvas (`items-center` instead of `items-start`)
  with a small font-mono `W × H` badge underneath the chassis. Helpful
  when a wide preset is scaled down to 15 % and the visual size no
  longer matches the logical CSS pixel size — the badge always reports
  the emulated viewport, not the visible scale.

- **Responsive toolbar dropdown.** Below `xl` (1280 px) the 10-button
  segmented viewport pill collapses into a single trigger button that
  opens a popover listing the same presets. The Custom-width input
  stays next to the trigger on both layouts so typing-then-Enter never
  costs an extra click. Removes the toolbar's historical
  `overflow-x-auto` escape hatch — the dropdown keeps the row narrow
  enough that overflow no longer happens, and the missing overflow
  lets the popover break out of the toolbar's flow without getting
  clipped.

- **Logical-pixel readouts everywhere.** Toolbar readout, dropdown
  trigger label, and drag-to-resize all read the emulated CSS pixel
  width from the store, not the scaled wrapper DOM box. Drag math now
  compensates for the active zoom factor so a 50-screen-px cursor move
  on a 0.5×-scaled 2K preset applies a 100-logical-px width change
  (was 50). `currentWidth` is now a getter sourced from `previewWidth`
  with a fallback to the ResizeObserver-tracked DOM measurement only
  for Full mode (where the wrapper truly carries the real viewport).

- **`render-cell.twig`** wrapper height now falls back to
  `iframeContentHeight` × `zoom` when the active mode has no logical
  height (Custom widths), eliminating a blank gap below the visible
  iframe equal to `unscaledH * (1 - zoom)` that previously appeared
  when a Custom width was wider than the chrome pane.

### Accessibility

- Icon-only toolbar controls (rotate, sidebar toggle, open-in-new-tab)
  now carry `aria-label` alongside `title` so screen readers get an
  accessible name independent of hover tooltips.

### Changed

- `|resizer` is now **polymorphic**: in addition to the historical
  variadic-tuples shape, it also accepts a single orientation-keyed
  map as its argument. When the input has a `landscape`, `portrait`,
  or `square` key, the filter classifies the source image's aspect
  (±10 % tolerance band around 1:1) and dispatches to the matched
  bucket, falling through to `landscape` when the matched bucket is
  empty / absent. Tolerance is hardcoded at `0.1` — the styleguide
  has no WP-filter-equivalent override mechanism, and YAGNI applies
  until a real demand for stricter classification surfaces.

  Two shapes, one filter:

  ```twig
  {# Tuples mode — historical, unchanged #}
  {{ image|resizer(['960', '720', '1280', 'crop'], ['480', '360', '', 'crop']) }}

  {# Orientation-aware mode — new #}
  {{ component_picture({
      image: item.image|resizer({
          landscape: [['960', '720', '1280', 'crop'], ['480', '360', '', 'crop']],
          portrait:  [['720', '960', '1280', 'crop'], ['360', '480', '', 'crop']],
          square:    [['800', '800', '1280', 'crop'], ['400', '400', '', 'crop']],
      })
  }) }}
  ```

  Detection: a single arg that's an associative array carrying at
  least one of the orientation keys flips dispatch into orientation
  mode. Tuples have integer keys (width / height / min-width / op),
  so the two shapes can't collide on a realistic call — fully
  backward compatible with existing variadic calls.

  Square-band check uses cross-multiplication
  (`abs(w - h) <= tolerance * h`) instead of `abs(w/h - 1) <= tolerance`
  to keep the boundary inclusive under IEEE 754 (1100 ÷ 1000 in float
  is `1.1 + 8.88e-17`, which would trip the naïve `<=` to false at
  the exact 10 % edge for integer-dimensioned sources).

  Mirrors the upstream `parisek/timber-kit` unification — a single
  Twig template renders identically against the WordPress runtime
  and the styleguide preview.

## [0.2.1] - 2026-05-20

### Changed

- Overview index column order is now **Pages → Blocks → Gutenberg →
  Basic elements** (was Pages → Basic → Blocks → Gutenberg). Reading
  flow puts the page-level surface first, then the composite component
  buckets, and the fine-grained atomic elements ("Ostatní") last —
  matches how readers usually scan a styleguide overview (top-down from
  whole pages to leaf primitives). Empty buckets still hide; only the
  ordering of present sections changed.

## [0.2.0] - 2026-05-20

### Documentation

- README expanded with a full **API reference** for the three JSON
  endpoints (`/styleguide/api/components`, `/api/pages`, `/api/fields`)
  — response shape, ordering, caching behavior, and the pattern for
  adding a fourth endpoint. Also adds a **Constructor config** table
  covering every key accepted by `Styleguide::__construct()` (including
  the previously undocumented `twig_options`, `typography_config`, and
  `namespaces`), a **Conventional Twig namespaces** section listing
  every auto-registered namespace and its source directory, and fills
  the previously missing metadata keys (`asana`, `figma`, `drupal`,
  `web`, `styleguide`) into the Per-template metadata table. The URL
  surface table now lists `/styleguide/foundations`, `/styleguide/fields`,
  and documents the whitelisted `?theme=light|dark` query param on
  `/render/*`. The local-dev path repository snippet is rewritten to
  match the canonical mechanism from `AGENTS.md` (`canonical: false` +
  `versions` override + switch scripts).

### Added

- New **/styleguide/overview** page (label „Přehled" / „Overview") —
  Components & Pages master index that lists every component and every
  page shipped by the consumer project, grouped by sidebar section
  (Základní prvky / Bloky / Gutenberg / Stránky), with a persisted
  „Zobrazit použití" / „Show usage" toggle (`localStorage` key
  `sg-overview-show-usage`) that reveals forward usage (page →
  components used) and reverse usage (component → where it's used) as
  clickable chips. A compact 4-column directory grid at the bottom
  lists everything alphabetically per section as a fast-jump. Renders
  directly in the SPA shell (NOT inside the iframe) so the visual
  chrome ships with the package; visuals are unified across consuming
  projects regardless of their own CSS. Data source is the existing
  `/api/components` + `/api/pages` payloads parsed by `ComponentParser`
  — no new backend endpoint, no new YAML metadata semantics. Reverse
  usage is built lazily into a `Map<id, ids[]>` on first access and
  then looked up in O(1).
- `Styleguide` now auto-registers the conventional Twig namespaces
  whenever the matching directory exists: `@component`, `@macro`,
  `@page` and `@static` under `templates_path`; `@icons` and `@images`
  under `static_path` (i.e. `images/icons/` and `images/` as siblings
  of `templates/`). Consumers no longer need to enumerate any
  `$loader->addPath(__DIR__ . '/templates/component', 'component')` or
  `$loader->addPath(__DIR__ . '/images', 'images')` lines in their
  `static/index.php` — the package walks both roots once at
  construction and wires the standard layout itself. Projects with a
  non-standard image root (or any extra namespace) can still use the
  new `namespaces` config key as `<name> => <absolute path>`; missing
  directories are silently skipped so a stray entry doesn't take the
  whole styleguide down. Re-running the constructor on the same env is
  a no-op via `realpath()`-based deduplication, which matters for
  projects that share one Twig environment across requests.

### Changed

- The Fields drawer is redrawn as a four-column tree
  (Field / Type / Title / Description) with depth-indented child rows
  (`└` glyph at depth > 0), a colour-coded Type pill per field-type
  family (`array`/`object` purple-pink, `text` blue, `textarea` indigo,
  `image` emerald, `link` orange, anything else neutral zinc), and a
  red-dot Required indicator with a localised footer legend. Default
  state stays collapsed; the trigger badge shows the recursive node
  count.
- `Styleguide::buildOwnTwig()` (the pristine env built when the consumer
  omits the `twig` config key) now defaults `autoescape: false` alongside
  the existing `cache: false` + `debug: true`. Previously the env fell
  back to Twig's own default of `autoescape: 'html'`, which meant every
  consumer that didn't pass its own `Environment` had to either accept
  HTML-mangled output from `|typography` / WYSIWYG / `|raw`-style filters
  OR pre-build a one-off env just to flip that single option. Both
  branches of the package's pristine-vs-provided-env contract now share
  the same project-wide defaults; consumers can drop the boilerplate
  `new Environment(..., ['cache' => false, 'debug' => true, 'autoescape'
  => false])` block from their bootstrap if it was only there to override
  this one flag. Behavior is unchanged for consumers that pass an
  explicit `twig` Environment — the package never touches options on a
  provided env, it just attaches loaders. Consumers that need different
  pristine-env defaults can pass a `twig_options` config map — values are
  merged on top of the three defaults, so a partial override
  (`['cache' => '…']`) keeps `debug` and `autoescape` at the package's
  defaults. Projects that expose their styleguide outside trusted local /
  dev contexts and want Twig's XSS protection back on can opt in with
  `'twig_options' => ['autoescape' => 'html']`.
- **BREAKING (URL):** The `/styleguide/overview` URL now serves the new
  Components & Pages index above. The previous logo + colors +
  typography page has moved to **/styleguide/foundations** and its
  internal `kind` likewise became `foundations` in `Router.php`,
  `Renderer.php`, `Styleguide.php`, `templates/foundations.twig` (was
  `templates/overview.twig`) and the corresponding frontend route-type
  checks (`stores/ui.js`, `components/preview.js`, `index.html`,
  `styleguide.js`). The page's user-visible label was simultaneously
  renamed from „Přehled" / „Overview" to **„Základy" / „Foundations"**
  to reflect what the page actually shows — the foundational design
  layer beneath components — and to free up „Přehled" for the new
  overview index. The i18n key in `frontend/public/locales/{cs,en}.json`
  moved from `nav.overview` to `nav.foundations`; a fresh `nav.overview`
  key now carries the new label. Bookmarks pointing at the OLD
  `/styleguide/overview` will land on the new index page; the
  pre-rename meaning was only in production for the duration of the
  current `[Unreleased]` window so practical impact is limited to
  in-progress dev branches.
- CI workflows (`tests.yml`, `release.yml`, `release-stamp.yml`) bumped
  `actions/checkout@v4` → `actions/checkout@v5`. The v4 line runs on Node 20, which
  GitHub started flagging as deprecated in Actions logs; v5 moves to
  Node 24. No behavior change in the workflows themselves — the bump
  exists only to clear the runtime-deprecation warning. Other actions
  (`actions/cache@v4`, `shivammathur/setup-php@v2`) are still on their
  current latest majors and were left alone.

### Fixed

- **Overview index** restores the per-row external-link icons that the
  pre-redesign styleguide carried — Asana / Figma / Drupal / Web SVG
  badges fed from each component's parsed YAML metadata (`asana:`,
  `figma:`, `drupal:`, `web:`). Icons render in two places: the row
  header (full item, larger 28px badges) and every "Used in" / "Components"
  chip (decorated chip from the forward / reverse map, smaller 24px
  badges). `_buildForwardMap` and `_buildReverseMap` now copy the four
  link fields onto each chip, so the chip-level row needs no second
  store lookup. New `linksFor(item)` helper on the overview component
  returns the same `{key, url, label}[]` shape the per-component
  `linkBar` already uses above the iframe, so the four SVG `<template>`
  blocks stay structurally identical across both surfaces.
- **/styleguide/foundations** now ships its own Tailwind utility bundle.
  Consumer projects scan only their own `templates/**/*.twig` in their
  Tailwind v4 `@source` directives — the package's own
  `templates/foundations.twig` is in `vendor/parisek/styleguide/templates/`
  and therefore invisible to the consumer build. Without its layout
  utilities (`h-32`, `max-w-48`, `min-h-32`, `prose-*`, etc.) the
  foundations route rendered with broken sizing: the logo overflowed the
  card, swatches collapsed to zero height, body samples lost their
  responsive `prose` scale. The package now builds a dedicated
  `dist/foundations.[hash].css` from a new `frontend/foundations.css`
  Tailwind entry that explicitly `@source`s the foundations template;
  `render-cell.twig` links it alongside `iframe.css` only on the
  foundations route, after the consumer stylesheet so consumer overrides
  on shared classes still win. Consumers don't add any `@source` path of
  their own — pulling the new package version + running their existing
  `composer update` is enough.
- Per-component **Fields drawer** now actually renders for real-world
  components. The previous incarnation gated on `Array.isArray(fields)`
  but `ComponentParser` passes the YAML `fields:` map straight through
  as a PHP associative array (JSON object on the wire), so the drawer
  silently hid itself on every component with field metadata. The
  drawer now walks the nested map via DFS in JS (Alpine 3 templates
  can't self-recurse) and renders a flat, depth-tagged list — arbitrary
  nesting depth supported.

## [0.1.3] - 2026-05-20

### Fixed

- `Styleguide::registerBundledHelpers()` no longer initializes the Twig
  extension set before adding functions/filters. Previously, the
  idempotency check `getFunction(...) === null` triggered
  `ExtensionSet::initExtensions()` which locks `addFunction`/`addFilter`
  for the remainder of the env's life — every subsequent
  `addFunction(...)` call raised `LogicException: Unable to add function
  "<name>" as extensions have already been initialized.` The bug went
  undetected through CI because the existing suite (Renderer / Router /
  AssetServer / ComponentParser tests) didn't instantiate `Styleguide`
  itself; only a downstream project bootstrapping the package would have
  blown up at construction time. Fixed by switching to a `tryAdd…()`
  pattern that catches the duplicate-name throw (preserving the
  "project pre-registration wins" override path) without doing any
  pre-read that triggers initialization.

### Added

- `Symfony\Bridge\Twig\Extension\DumpExtension` is now bundled —
  `Styleguide::registerBundledExtensions()` registers it alongside the
  existing five (Intl / String / Common / Attribute / Typography) so
  consuming projects no longer need their own
  `$twig->addExtension(new DumpExtension(new VarCloner()))` line in
  the bootstrap. The earlier split ("project keeps DumpExtension to
  avoid packaged production leak") turned out orthogonal to the actual
  risk: a `{{ dump(var) }}` leaking into production is caught by the
  `DumpRule` `twig-cs-fixer` lint at commit time, not by withholding
  the extension itself. New deps: `symfony/twig-bridge` and
  `symfony/var-dumper` (both versions `^5.4 || ^6.2 || ^7.0`, same
  constraints downstream projects already used).
- Bundled Twig helpers — every consuming project previously duplicated
  the same `component_*` / `page_*` / `__` / `_x` / `_n` / `_nx` /
  `merge_resizer` registrations plus the `placeholder` / `resizer` /
  `format_date` / `custom_price_format` filters in its own `index.php`.
  The bundled `merge_resizer` adds null-arg tolerance the originals
  lacked: callers can pass any positional arg as `null` (typically a
  Twig `content.image_xl ?? null` when that breakpoint's image is unset
  for the given record) and the helper now silently drops it instead of
  raising a `TypeError` on the typed-variadic signature.
  The `Styleguide` bootstrap now registers all of them on the Twig env
  it receives, idempotently (`getFunction('foo') === null` and
  `getFilter('foo') === null` checks so projects can override any
  individual helper before constructing the bootstrap and their
  version wins).
- New optional config key on `Styleguide(...)`:
  - `typography_config` — path to the project's `typography.yml`.
    When set, `Parisek\Twig\TypographyExtension` is auto-registered
    with that path instead of the empty default.
- Bundled deterministic SVG placeholder generator — `Parisek\Styleguide\Placeholder`
  (lazy-loaded via the standard PSR-4 autoloader the moment Twig
  resolves the `placeholder()` function or the `|resizer` filter).
  Previously every project carried its own near-identical ~390-line
  global-function copy in `static/inc/placeholder.php`; now the package
  ships it as a regular namespaced class (`Placeholder::generate(opts)`)
  and unconditionally wires the matching `placeholder` Twig function +
  `|resizer` filter. Projects that need a tuned palette / subject set
  register their own `placeholder` Twig function on the environment
  before constructing `Styleguide`; the function-registration
  idempotency check (`getFunction('placeholder') === null`) then
  leaves the project's version in place.
- `error_log()` instead of `dump()` in the `component_*` / `page_*`
  error-fallback paths. The previous duplicate-in-project versions
  used Symfony VarDumper's `dump()` because the project's env had
  `DumpExtension` + `'debug' => true`; calling `dump()` unconditionally
  in a packaged helper would leak HTML var-dump output in production.
  Errors go to the server log instead.
- `AGENTS.md` + `CLAUDE.md` at the repo root — full doctrine for
  contributors and AI coding assistants. AGENTS.md is the shared
  AgentMD contract (overview, dev commands, local-development-against-
  a-consuming-project switch mechanism, repo layout, feature-addition
  flow per layer, testing, release workflow, conventions). CLAUDE.md
  is a thin pointer that imports AGENTS.md plus Claude-only runtime
  notes — same pattern `tailwind-base` uses.
- Component-kind iframe previews now render inside a `padding: 1.5rem`
  wrapper so short components (button, breadcrumb, alert badges, …)
  don't sit flush against the iframe's top edge underneath the
  styleguide chrome. Inline style (not a utility class) so the inset
  works regardless of which CSS framework the consuming project
  ships. Pages render their own full layout and are left untouched.
- Toolbar above the iframe now prints a full breadcrumb —
  `[KOMPONENTA] Section / Component name (slug)` — instead of the raw
  slug alone. Section label feeds through `i18n.t('sections.<key>')`
  so cs/en both work; the section segment hides while the components
  API is still loading to avoid an `(undefined)` flash.
- Sidebar header now substitutes `{project.name}` from `styleguide.yaml`
  into the `<div id="sg-project-name">` placeholder at request time,
  same regex-substitution pattern that already handled
  `<title>` / `<body data-project-name>` / favicon nodes.
- Sidebar search input now actually filters the component list.
  Previously `<input>` bound to a local `query` field on the `search`
  Alpine component which the sidebar's sibling `x-for` couldn't read;
  state now lives on `Alpine.store('ui').searchQuery`. The matcher
  does NFKD-folded case-insensitive substring match against `name`
  and `id`, so `drobeckova` finds `Drobečková navigace`. Sections
  whose filter result is empty hide from the sidebar; an active
  search force-opens otherwise-collapsed sections.
- External-link icon row above the iframe — clickable chips for
  `asana` / `figma` / `drupal` / `web` fields declared in a
  component's YAML metadata. New `linkBar` Alpine component reads
  the parsed metadata already exposed by
  `ComponentParser::normaliseMetadata` and hides itself when nothing
  is declared. Ports the four-icon badge row from the pre-migration
  in-tree styleguide.
- `document.title` follows the current route via `Alpine.effect` —
  `{component name} — {project}` for component / page,
  `{Overview label} — {project}` for overview, plain
  `Styleguide — {project}` otherwise. Re-runs when the route flips,
  the components API resolves, or the locale switches; project name
  is read once from `document.body.dataset.projectName`.
- Sidebar hides skeleton-only templates that have neither a
  `styleguide.twig` sibling nor a `styleguide:` block in their YAML
  metadata. `ComponentParser` already marked these with
  `hasStyleguide: false`; the sidebar's `bySection()` just wasn't
  honouring it. Components keep showing when they have either a
  `styleguide.twig` file or the `styleguide:` YAML block.
- Sidebar open / closed state persists across page reloads via
  `Alpine.$persist` (the plugin was already in the bundle for the
  per-section collapse state). LocalStorage key `sg-sidebar-open`
  follows the existing `sg-*` namespace convention.

## [0.1.2] - 2026-05-18

### Fixed

- Packagist can now actually import tagged releases. The Phase 1
  `composer.json` carried `"version": "0.1.0"` as a hardcoded field
  to keep the local path repository constraint satisfied before any
  git tag existed. After v0.1.0 was tagged that field stopped serving
  any purpose — and on the v0.1.1 push Packagist logged
  `Skipped tag v0.1.1, tag (0.1.1.0) does not match version (0.1.0.0)
  in composer.json`, refusing to import every future tag. The field
  is gone now; Packagist derives versions from `git tag` exclusively
  as is convention for libraries.

## [0.1.1] - 2026-05-18

### Fixed

- Stale iframe content no longer flashes when switching between
  components / pages. A solid white overlay now covers the iframe
  during navigation (synchronously, set in `setRoute()` before the
  iframe `src` changes — `$watch` was lost to cached-response races
  where `load` fired before the watcher could flip the flag). The
  pulsing loading dot fades in after 120 ms so fast cached responses
  don't visibly flash it.

## [0.1.0] - 2026-05-18

### Added

- PHP backend (PSR-4 `Parisek\Styleguide\`):
  - `Styleguide` — public bootstrap; dispatches `/styleguide/*` requests to asset,
    render, API, or SPA handlers and exits. Optional `twig` config so the project's
    existing env (with project-registered filters / functions) is reused inside the
    iframe.
  - `Router` — parses landing, deep-link, render, API, and asset URIs.
  - `Renderer` — wraps a component / page / overview render in the iframe HTML
    chrome with project CSS / JS / fonts injected from `styleguide.yaml`.
  - `ComponentParser` — reads first-comment YAML metadata from every `.twig`
    template; drives sidebar + API responses.
  - `AssetServer` — serves `dist/` assets with path-traversal guard, ETag, and
    immutable cache for hashed filenames (base64url alphabet, matches Vite output).
  - `Api\{Components,Pages,Fields}Endpoint` — JSON endpoints consumed by the SPA.
- Templates:
  - `render-cell.twig` — iframe HTML wrapper with standalone-mode back-bar that
    auto-reveals when not in an iframe.
  - `overview.twig` — palette + typography + fonts preview driven by
    `styleguide.yaml`.
  - `styleguide-404.twig`.
- SPA chrome (prebuilt in `dist/`):
  - Alpine.js 3 + Tailwind v4 CSS-first, bundled via Vite.
  - Sidebar with collapsible sections persisted via `@alpinejs/persist`.
  - ⌘K / Ctrl+K search with name-substring filter.
  - Iframe preview with named viewport presets (Mobile 375×667, Tablet 768×1024,
    Desktop 1280×800, Full) + rAF-batched smooth drag-resize + live dimension
    readout via `ResizeObserver`.
  - cs ↔ en locale switcher (URL `?lang=` > localStorage > navigator > `'en'`
    fallback) with `<html lang>` propagation.
  - Deep-link routing via history API.
  - Usage cross-reference chip panel — components list "Used in", pages list
    "Components used", click navigates.
  - Open-in-new-tab + standalone back-bar pair.
- Locales — `cs` + `en`, served from `dist/locales/`.
- Configuration via the consuming project's `styleguide.yaml`
  (`project.name`, `project.favicon`, `iframe.css`, `iframe.js`, `iframe.fonts`,
  optional `logo` / `colors` / `typography` / `labels` blocks for the overview).
- Composer wiring:
  - PHP `^8.3` minimum (matches resolved dependency graph).
  - Runtime: `twig/twig`, `symfony/yaml`, `twig/intl-extra`, `twig/string-extra`,
    `parisek/twig-{attribute,common,typography}`. Extensions auto-registered in
    `Styleguide` bootstrap, idempotently — projects that already registered them
    keep their tuned instance.
  - Dev: `phpunit/phpunit` (23 tests, 59 assertions).
- `.gitattributes` `export-ignore` strips dev files from the Composer tarball.
- CI: GitHub Actions runs PHPUnit on PHP 8.3 against every push + PR.

[Unreleased]: https://github.com/parisek/styleguide/compare/v0.2.1...HEAD
[0.2.1]: https://github.com/parisek/styleguide/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/parisek/styleguide/compare/v0.1.3...v0.2.0
[0.1.3]: https://github.com/parisek/styleguide/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/parisek/styleguide/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/parisek/styleguide/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/parisek/styleguide/releases/tag/v0.1.0
