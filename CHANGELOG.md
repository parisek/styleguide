# Changelog

All notable changes to `parisek/styleguide` are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/parisek/styleguide/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/parisek/styleguide/releases/tag/v0.1.0
