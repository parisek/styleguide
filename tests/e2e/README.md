# Styleguide E2E

End-to-end smoke tests for the styleguide, run against the package's **own
fixture** (`tests/fixtures/`). These assert the package's HTTP + SPA behaviour at
the version of the code in this repo — so a behaviour change here is caught here,
not by every downstream project on a version bump.

```bash
composer test:e2e                 # Layer A
PORT=9000 bash tests/e2e/run.sh   # different port
```

`run.sh` boots a `php -S` server with `tests/fixtures/index.php` as the router,
runs the layer, and tears the server down.

## Layers

| | Tool | What it catches | In CI |
|---|---|---|---|
| **A — `smoke-http.sh`** | `curl` + `python3` | Routing, render endpoint, JSON APIs, hashed-asset cache headers, locale JSON, path-traversal guard | ✅ yes |
| **PHPUnit** | `composer test` | Backend units — `Router`, `Renderer`, `ComponentParser`, `AssetServer` | ✅ yes |
| **Playwright** (`tests/e2e/playwright/styleguide.spec.js`) | `cd frontend && npm run test:e2e` | SPA hydration, sidebar buckets, router navigation, iframe `src`, ⌘K focus, viewport presets/drag-resize/rotation, prefix-tree grouping, locale + theme switching, canvas mode, fields drawer, standalone back-bar visibility | ✅ yes (`e2e-playwright` job) |

The Playwright suite superseded a local-only "Layer B" (`smoke-browser.sh`,
driven by the `agent-browser` CLI) that read state directly out of
`window.Alpine.store(...)`. The Vue rewrite (Phase 1 of the Styleguide 2.0
effort) removed that global, so Layer B was deleted rather than ported —
Playwright asserts the same behaviour through the rendered DOM only, and
unlike its predecessor it actually runs in CI.

## Why these live here, not in consuming projects

The styleguide chrome (routing, search, preview, back-bar) ships in this package.
Testing that behaviour in every consumer was redundant **and** brittle — a package
behaviour change broke every downstream's e2e on a version bump even though nothing
in the project changed (e.g. the back-bar visibility check, which read the wrong
property and only passed by luck on older versions). Consumers now keep only a thin
"my styleguide boots + lists my components" canary; the package owns its behaviour.

## Fixture

`tests/fixtures/` ships a minimal project: two components (`sample`, `another`)
and one page (`landing`), plus a `styleguide.yaml` and an `index.php` bootstrap
mirroring what a consuming project's `static/index.php` does. The SPA shell,
`/styleguide/assets/*`, and locale JSON are served from the package's own `dist/`.
