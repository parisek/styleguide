# Changelog

All notable changes to `parisek/styleguide` are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
