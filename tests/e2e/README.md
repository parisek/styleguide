# Styleguide E2E

End-to-end smoke tests for the styleguide, run against the package's **own
fixture** (`tests/fixtures/`). These assert the package's HTTP + SPA behaviour at
the version of the code in this repo — so a behaviour change here is caught here,
not by every downstream project on a version bump.

```bash
composer test:e2e                 # Layer A (+ B if agent-browser is installed)
bash tests/e2e/run.sh --no-browser  # Layer A only
PORT=9000 bash tests/e2e/run.sh   # different port
```

`run.sh` boots a `php -S` server with `tests/fixtures/index.php` as the router,
runs the layers, and tears the server down.

## Layers

| | Tool | What it catches | In CI |
|---|---|---|---|
| **A — `smoke-http.sh`** | `curl` + `python3` | Routing, render endpoint, JSON APIs, hashed-asset cache headers, locale JSON, path-traversal guard | ✅ yes |
| **B — `smoke-browser.sh`** | `agent-browser` | SPA hydration, sidebar buckets, `sgNavigate()` routing, iframe `src`, ⌘K focus, width preset, locale switch + `<html lang>`, standalone back-bar visibility | local only |
| **C — PHPUnit** | `composer test` | Backend units — `Router`, `Renderer`, `ComponentParser`, `AssetServer` | ✅ yes |

Layer B is browser-only and stays out of CI (needs Chrome + agent-browser). It's
skipped automatically when `agent-browser` isn't installed:

```bash
npm i -g agent-browser && agent-browser install   # one-time, to run Layer B locally
```

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
