# AGENTS.md

Project instructions for AI coding assistants (Claude Code, Codex CLI, Cursor, Copilot, …). This is the shared AgentMD contract — Claude Code imports it from `CLAUDE.md`. Human contributors: see `README.md`.

## Overview

`parisek/styleguide` is a self-contained Composer package that turns a tree of Twig component templates into a live, browsable styleguide — sidebar, ⌘K search, viewport presets, locale switcher, deep links — with no chrome code in the consuming project. The package ships:

- **PHP backend** (`src/`, PSR-4 `Parisek\Styleguide\`) — `Styleguide` bootstrap + `Router` + `Renderer` + `ComponentParser` + `AssetServer` + 3 API endpoints.
- **Templates** (`templates/`) — `render-cell.twig` (iframe wrapper), `overview.twig`, `styleguide-404.twig`.
- **Frontend SPA** (`frontend/` → built into `dist/`) — Vue 3 + Pinia + vue-router + Tailwind v4. Sidebar, search, preview chrome, locale switcher, deep-link routing.

Consumers wire `\Parisek\Styleguide\Styleguide::run()` into a public PHP file and the package handles every `/styleguide/*` request. See `README.md` § *Bootstrap*.

## Configuration

```yaml
PACKAGE_NAME: "parisek/styleguide"
PHP_REQUIRES: "^8.3"
NODE_DIR: "frontend"
DIST_DIR: "dist"
TEMPLATES_DIR: "templates"
TESTS_DIR: "tests"
SIBLING_CONSUMER: "../tailwind-base"   # used for local end-to-end verification
PACKAGIST_URL: "https://packagist.org/packages/parisek/styleguide"
```

## Development Commands

PHP commands run from the repo root, frontend commands from `frontend/`. Node is needed only for the SPA build — runtime in consuming projects requires only PHP.

```bash
# Tests + static analysis (PHP)
composer test                                # phpunit (208 tests, ~0.4 s)
composer phpstan                             # phpstan analyse (level configured in phpstan.neon)
vendor/bin/phpunit --filter <pattern>        # single test method

# Frontend (SPA chrome — only when JS/CSS/HTML in frontend/ changes)
cd frontend && npm install                   # first time / lock-file change
cd frontend && npm run build                 # one-shot build → ../dist/
cd frontend && npm run watch                 # rebuild on save (use during dev)
cd frontend && npm test                      # Vitest — src/lib, src/stores, src/composables, src/components
cd frontend && npm run test:e2e              # Playwright — full-browser parity checklist against tests/fixtures
```

After PHP changes inside `src/` you don't need to rebuild — Composer's autoloader picks them up. After Twig changes inside `templates/` you don't need to rebuild either. After **anything** in `frontend/` (JS, HTML, CSS, locale JSON) you MUST run `npm run build`, otherwise the consuming project still ships the previous bundle from `dist/`.

## Local development against a consuming project

Real-world iteration happens against a downstream project (typically `tailwind-base`). The package ships through Packagist by default, but for development we use a **Composer path repository** that symlinks the local checkout into the consumer's `vendor/parisek/styleguide`. Every file change in this repo is then visible immediately on the consumer's styleguide URL — no `composer update`, no version bump, no release.

### Switch mechanism (consumer side)

In the consumer's `composer.json` (already wired in `tailwind-base`):

```jsonc
"repositories": {
    "parisek-styleguide-local": {
        "type": "path",
        "url": "../styleguide",
        "canonical": false,             // critical: lets Packagist supply ^1.0 when needed
        "options": {
            "symlink": true,
            "versions": { "parisek/styleguide": "dev-local" }
        }
    }
},
"scripts": {
    "styleguide:local":  "@composer require parisek/styleguide:dev-local --no-interaction",
    "styleguide:remote": "@composer require parisek/styleguide:^1.0 --no-interaction"
}
```

Why `canonical: false`: Composer treats path repos as canonical by default, which blocks lower-priority repositories (Packagist) from supplying versions. Without `canonical: false` the `^1.0` constraint would fail because Packagist is shadowed and the path repo only carries `dev-local`. With it set, both versions can satisfy the constraint depending on which one you ask for.

Why `versions` override: a path repo derives the package version from the local `composer.json` `version` field or (if missing) from git. Pinning it to `dev-local` makes the local copy unambiguous — the `^1.0` constraint never accidentally resolves against it, and `dev-local` is the only string a switch command needs to know.

### Workflow

```bash
# From the consumer (tailwind-base) repo root:
composer styleguide:local      # vendor/parisek/styleguide → symlink to ../styleguide
composer styleguide:remote     # vendor/parisek/styleguide → extracted v1.x from Packagist
```

The single line that changes in `composer.json` is `"parisek/styleguide": "dev-local"` ↔ `"^1.0"`. `composer.lock` updates too. Both are intended to land in commits when needed.

After `composer styleguide:local`, edit files freely in `/Users/pari/Sites/styleguide/`. PHP/Twig changes are picked up on the next request to the consumer. For frontend changes, run `cd frontend && npm run watch` so `dist/` rebuilds on save and the consumer's iframe chrome stays current.

### When to switch back

- Before tagging a release of the package (verify the consumer still works against the published Packagist version).
- When debugging an issue that might be specific to the symlinked dev copy (caching, autoload, file permissions).
- When handing the consumer repo to CI — CI installs from Packagist normally; the `repositories` block stays in `composer.json` but the `^1.0` constraint keeps it inert.

## Repo layout

```
/
├── src/                       # PHP backend (PSR-4 Parisek\Styleguide\)
│   ├── Styleguide.php         # public entry point — bootstrap + run()
│   ├── Router.php             # /styleguide/* URI parser
│   ├── Renderer.php           # iframe HTML wrapper for one component / page
│   ├── ComponentParser.php    # reads first-comment YAML metadata from .twig files
│   ├── AssetServer.php        # serves dist/ with ETag + immutable cache
│   └── Api/                   # JSON endpoints consumed by the SPA
├── templates/                 # Twig templates shipped to consumers
│   ├── render-cell.twig       # iframe wrapper (HTML doc with project CSS/JS)
│   ├── overview.twig          # auto-generated palette / typography / fonts page
│   └── styleguide-404.twig
├── frontend/                  # SPA source (Vite + Vue 3 + Pinia + Tailwind v4)
│   ├── index.html             # SPA shell — sidebar, toolbar, iframe preview
│   ├── styleguide.css         # Tailwind v4 with @import / @source
│   ├── src/
│   │   ├── main.js            # entrypoint — boots Pinia + vue-router + mounts App.vue
│   │   ├── App.vue            # shell: sidebar, mobile backdrop, shared toolbar/description/usage/link/fields chrome
│   │   ├── router.js          # vue-router instance + route table
│   │   ├── views/             # OverviewView, FoundationsView, PreviewView (renders PreviewPane)
│   │   ├── components/        # Sidebar, ViewportToolbar, PreviewPane, FieldsDrawer, UsagePanel, LinkBar
│   │   ├── composables/       # useViewportPreset, useSearchShortcuts
│   │   ├── stores/            # Pinia: catalog, ui, i18n, theme
│   │   └── lib/                # framework-free: searchMatch, prefixTree, viewportMath, fieldsTree, externalLinks, persistedRef, routeInfo, config
│   └── public/locales/        # cs.json, en.json
├── dist/                      # built SPA bundle (committed — consumers ship without npm)
├── tests/                     # phpunit
└── README.md                  # consumer-facing install + bootstrap
```

## Adding a feature

The package surface has three independent layers; pick the smallest one that fits.

### Pure backend feature (router, renderer, parser, API)

1. Edit code under `src/`.
2. Add or extend a test under `tests/<Class>Test.php`. Tests live in pure PHPUnit — no fixture project needed; `tests/fixtures/templates/` already carries a minimal `component/sample/` for renderer integration tests.
3. `composer test` (and `composer phpstan` if signatures/types change).
4. Verify on the consumer (with `composer styleguide:local` active) — load `/styleguide/...` and confirm the change is live.

### Twig template change (`templates/render-cell.twig`, `overview.twig`)

1. Edit the template.
2. If the change affects the iframe HTML shape, extend `RendererTest::renders_component_with_iframe_chrome` to assert the new markup.
3. `composer test`.
4. Verify on the consumer.

### SPA chrome change (anything under `frontend/`)

1. Run `cd frontend && npm run watch` so `dist/` updates on save.
2. Edit `frontend/src/components/*.vue` / `frontend/src/views/*.vue` (templates + logic), `frontend/src/stores/*.js` (Pinia state), `frontend/src/lib/*.js` (pure logic — write a Vitest spec first), `frontend/public/locales/*.json` (i18n).
3. Reload the consumer's styleguide URL — Vite has already rebuilt `dist/`.
4. Run `cd frontend && npm test` — every store/lib/component change needs a passing spec before commit (see `docs/superpowers/plans/2026-07-04-phase-1-vue-rewrite.md` for the test-first pattern this codebase now follows).
5. Verify the touched feature plus one adjacent feature (smoke) against the consumer via `composer styleguide:local`.
6. Commit source files, specs, AND the rebuilt `dist/` artifacts — consumers receive `dist/` verbatim; CI's `dist-reproducible` job (Task 13) fails the build if you forget.

### Documentation is part of the change — not a follow-up

A PR that touches the public surface is **not complete until its docs land in the same PR**, exactly like the `CHANGELOG.md` entry. Treat this as a merge gate, not a nice-to-have: docs that lag the code are how `docs/API.md` silently drifts from the actual API record (a YAML key or `/api/*` field ships, but its row in the schema table or its entry in the `ts` shape never does — and nothing in CI catches it).

When a change adds, renames, removes, or alters the default of any of the following, update the matching doc **in the same PR**:

| You changed… | Update… |
|---|---|
| A YAML metadata key / `styleguide.yaml` key, or its default | `docs/API.md` (§ YAML schemas / Component YAML metadata) **and** `README.md` if it's user-facing |
| A field in an `/api/*` response | `docs/API.md` — the matching `ts` shape |
| A Twig function / filter | `docs/API.md` (§ Twig functions & filters) |
| A `Styleguide` constructor config key | `docs/API.md` (§ PHP API) **and** `README.md` § Bootstrap |
| Any consumer-visible behaviour | `CHANGELOG.md` `[Unreleased]` (+ `README.md` if it changes how consumers wire things) |
| An `@api`-marked surface | re-confirm the breaking / non-breaking note in `docs/API.md` still holds |
| An architectural decision (API contract shape, doctrine choice, boundary) | add an ADR in `docs/adr/` (see `docs/adr/README.md`) |

Internal-only work (refactors, test tooling, static-analysis fixes) needs no doc change beyond an optional `CHANGELOG.md` note. When unsure whether a surface is public, check the `@api` / `@internal` markers in `src/` and the SemVer tables in `docs/API.md`.

## Testing

```bash
composer test                                # full suite
vendor/bin/phpunit --filter Renderer         # one class
vendor/bin/phpunit --filter renders_404      # one method group
```

Tests are deterministic and offline — no network, no DDEV, no consumer required. The renderer suite carries its own minimal Twig fixture under `tests/fixtures/templates/`.

Static analysis: `composer phpstan`. PHPStan config lives in `phpstan.neon`.

## Release workflow

Full procedure: **[`RELEASING.md`](RELEASING.md)**.

Short version: land everything on `main` with its notes under `[Unreleased]`,
then Actions → **Stamp Release** → Run workflow → `X.Y.Z`. The workflow stamps
the CHANGELOG, tags, pushes and dispatches `release.yml`; Packagist picks the tag
up via webhook.

**Don't hand-stamp a heading or tag by hand.** That path still works, and this
section documented it until 2026-07 — from before `release-stamp.yml` existed —
but it skips the workflow's guards (version format, non-empty `[Unreleased]`,
test + PHPStan before the tag).

`composer.json` carries no `version` field; Packagist derives versions from git
tags exclusively (see CHANGELOG `[0.1.2]`). Don't reintroduce it.

## Conventions

- **PHP**: PSR-12, strict types declared at the top of every file, `final` classes by default.
- **Twig**: tabs for indentation (matches consumer projects); first `{# ... #}` comment in a component template carries YAML metadata parsed by `ComponentParser`.
- **JS**: ES modules, 4-space indent, no transpiler beyond what Vite ships. Components live in `frontend/src/components/<Name>.vue` / `frontend/src/views/<Name>View.vue` (Vue SFCs, `<script setup>`). Stores live in `frontend/src/stores/<name>.js` and register via Pinia's `defineStore('<name>', {...})`.
- **CSS**: Tailwind v4 `@import` + `@source` in `frontend/styleguide.css`. No preflight disable (preflight runs for SPA chrome only; iframe content is isolated by the iframe boundary).
- **Comments**: WHY, not WHAT — explain hidden constraints, subtle invariants, workarounds for specific bugs. Don't reference PRs, issues, or call sites; those rot.

## Coordination with consuming projects

This package is upstream of every project that calls `Styleguide::run()`. The reference consumer is `tailwind-base` (sibling checkout at `../tailwind-base`); WordPress and Drupal skeletons inherit from it. When a feature ships here:

- Tagged release → consumers pick it up via `composer update`.
- Breaking change → bump the constraint range, document migration in CHANGELOG, give consumer maintainers a heads-up.
- Consumer-specific quirk discovered during local dev → fix it in the package (the whole point of being upstream), not in the consumer.

Documentation for tailwind-base's side of this contract lives in its own `AGENTS.md`; sync any cross-cutting workflow updates there too.
