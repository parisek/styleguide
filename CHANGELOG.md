# Changelog

All notable changes to `parisek/styleguide` are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Initial scaffolding (composer.json, README, LICENSE, PHPUnit).
- PHP backend:
  - `Styleguide` — public bootstrap; dispatches `/styleguide/*` requests to asset,
    render, API, or SPA handlers and exits.
  - `Router` — parses landing, deep-link, render, API, and asset URIs.
  - `AssetServer` — serves files from `dist/` with path-traversal guard, ETag,
    and an immutable cache header for hashed filenames.
  - `ComponentParser` — reads styleguide metadata from Twig component files.
  - `Renderer` — wraps a component / page render in the iframe HTML chrome
    (`iframe.css`, `iframe.js`, `iframe.fonts` from project's `styleguide.yaml`).
  - `Api\ComponentsEndpoint`, `Api\PagesEndpoint`, `Api\FieldsEndpoint` —
    JSON endpoints consumed by the SPA.
- SPA chrome (Alpine.js + Tailwind v4 CSS-first, bundled via Vite — no CDN):
  - `stores/i18n.js` — locale detection priority URL > localStorage > config >
    navigator > `en` fallback; reactive `t()` with dot-path lookup.
  - `stores/ui.js` — sidebar / preview width / route state.
  - `stores/components.js` — parallel fetch of `/api/components` + `/api/pages`,
    section routing for basic / blocks / gutenberg.
  - `router.js` — history API + popstate; `window.sgNavigate(path)` public API.
  - `components/sidebar.js` — collapsible sections persisted via Alpine `$persist`.
  - `components/search.js` — ⌘K / Ctrl+K focus, Escape clears.
  - `components/preview.js` — iframe with width buttons + drag-resize handle.
  - `components/languageSwitcher.js` — `cs · en` switcher in sidebar footer.
- i18n: `cs` + `en` locales served from `dist/locales/`.
- iframe-based preview with width controls (mobile / tablet / desktop / full)
  and drag-resize, plus `⌘K` filter.
- Deep linking via history API — `/styleguide/component/<slug>`,
  `/styleguide/page/<slug>`, `/styleguide/overview`, `/styleguide/fields`.
- Configuration via the consuming project's `styleguide.yaml`
  (`project.name`, `project.favicon`, `iframe.css`, `iframe.js`, `iframe.fonts`).
- Vite build outputs hashed filenames at `/styleguide/assets/styleguide.[hash].(js|css)`,
  matching `AssetServer`'s asset route so consumers get immutable cache for free.
