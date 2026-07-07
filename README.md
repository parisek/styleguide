# parisek/styleguide

Self-contained Composer package that turns a tree of Twig component templates into a live, browsable styleguide — sidebar, ⌘K search, viewport presets, locale switcher, deep links — without writing any of that chrome yourself.

Drop the package into a project that already renders Twig (Symfony, Drupal, WordPress with Timber, or any standalone Twig setup), wire a 15-line bootstrap into a public PHP file, point a YAML config at the project's CSS/JS bundles, and `/styleguide/...` works.

![Overview screen showing palette, typography, fonts](https://placehold.co/1600x900?text=Overview+preview)

---

## What it does

| Surface | What you get |
|---|---|
| **SPA chrome** | Vue 3 + Pinia + vue-router + Tailwind v4 sidebar with collapsible sections, a keyboard-navigable command palette (`⌘K` / `Ctrl+K` — arrows, Enter, Esc; the sidebar's own inline filter keeps working alongside it), iframe preview with named viewport presets (Mobile 375×667 · Tablet 768×1024 · Desktop 1280×800 · Full 100 %) + smooth drag-resize, live dimension readout, a responsive variant grid — one preview tile per discovered `styleguide.<variant>.twig` sibling, the same viewport preset applied per tile (scaled to fit), a preset-aware Auto | 1-4 tile density control, and click-to-isolate tile headers (see *File-convention variants* below), cs ↔ en locale switcher, deep-link routing via history API. All bundled — zero CDN dependencies, zero JS to write. |
| **Overview** | Auto-generated palette / typography / fonts page driven by the project's `styleguide.yaml`. Colours are click-to-copy hex; typography rolls preview headings + body sample. Lands here by default at `/styleguide/`. |
| **DOKUMENTACE group** | Collapsible sidebar section containing Foundations, Overview, and any `doc` kind entries. `doc` templates live at `templates/doc/<name>/<name>.twig` and render inside the iframe like pages. The group always shows (foundations + overview); the doc entries are optional — absent `templates/doc/` → `/api/docs` returns `[]` and no doc items appear. |
| **Iframe preview** | Each component / page renders inside an iframe that loads the project's real CSS + JS — what you see is what production renders. The package's `Renderer` reuses the project's Twig environment, so component templates keep access to project filters / functions (`component_*`, `_x()`, `placeholder()`, custom helpers). |
| **Cross-references** | Chip panel above each preview: components list "Used in: …", pages list "Components used: …", click to navigate. Driven by per-template `usage:` YAML metadata. |
| **REST endpoints** | `/styleguide/api/components`, `/api/pages`, `/api/docs`, `/api/fields` return JSON for consumers (the SPA itself, plus any external tooling). |
| **Open in new tab** | Each render can be opened standalone — the iframe template auto-reveals a "← back to styleguide" navbar only when it detects it's NOT inside an iframe. |
| **Asset serving** | `AssetServer` serves the bundled SPA + locale files from `vendor/parisek/styleguide/dist/` with path-traversal guard, ETag, and immutable cache headers for hashed filenames. |

The whole package is ~8 PHP classes plus prebuilt JS/CSS — no Node.js required in production.

---

## Install

```bash
composer require parisek/styleguide
```

Local dev against a sibling checkout — register a path repository so the consumer's `vendor/parisek/styleguide` is a live symlink:

```jsonc
// composer.json (in the consuming project)
{
    "repositories": {
        "parisek-styleguide-local": {
            "type": "path",
            "url": "../styleguide",
            "canonical": false,                 // critical: lets Packagist still supply ^1.0 when needed
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
}
```

`canonical: false` is what keeps Packagist visible — without it the path repo would shadow it and the `^1.0` constraint would fail to resolve. The `versions` override pins the local copy to a fixed `dev-local` identifier so the switch scripts have a deterministic string to ask for. See `AGENTS.md` § *Local development against a consuming project* for the full mechanism.

---

## Bootstrap

Add to whichever public PHP file fronts your project (`public/index.php`, `static/index.php`, …):

```php
<?php
require __DIR__ . '/vendor/autoload.php';

(new \Parisek\Styleguide\Styleguide([
    'templates_path' => __DIR__ . '/templates',
    'static_path'    => __DIR__,
    'config_yaml'    => __DIR__ . '/styleguide.yaml',
    'default_locale' => 'cs',
    'twig'           => $twig,        // optional — reuse the project's Twig env
    'twig_context'   => [             // optional — globals merged into every inner render
        'homeUrl'     => '/styleguide/',
        'templateUrl' => '',
        'langcode'    => 'cs',
    ],
]))->run();
```

`run()` parses `$_SERVER['REQUEST_URI']`. If the URI starts with `/styleguide`, it dispatches (SPA, asset, render, or JSON endpoint) and **exits**. Otherwise it returns silently and the rest of your `index.php` continues to handle non-styleguide URLs.

### Constructor config

| Key | Required | Default | Purpose |
|---|---|---|---|
| `templates_path` | yes | — | Absolute path to the project's Twig templates root. Used for the `@project` namespace and for auto-registered subnamespaces (see *Conventional namespaces* below). |
| `static_path` | yes | — | Absolute path to the project's webroot (where `index.php` sits). Used to auto-register `@icons` (`/images/icons`) and `@images` (`/images`) if those directories exist. |
| `config_yaml` | yes | — | Absolute path to `styleguide.yaml`. Missing file ≠ error — yaml just resolves to `[]` and the overview screen renders empty sections. |
| `default_locale` | no | `'en'` | Two-letter code used by the SPA shell and forwarded to `Renderer` as `langcode`. |
| `base_url` | no | `'/styleguide'` | Prefix the router matches against. Change only if you mount the styleguide under a non-default path. |
| `twig` | no | `null` | Pre-built `Twig\Environment`. Pass when component templates need project-specific extensions / filters / functions (`component_*`, `_x()`, `placeholder()`, `|resizer`, …). If omitted, the package builds a pristine environment with sensible defaults (`cache: false`, `debug: true`, `autoescape: false`). See *`twig` config — when to pass it* below. |
| `twig_context` | no | `[]` | Globals merged into every `component_*()` / `page_*()` render. Typical keys: `homeUrl`, `templateUrl`, `langcode`. |
| `twig_options` | no | `[]` | Options merged onto the package defaults when building the pristine env. Ignored when `twig` is provided (the package never mutates a consumer-owned env). |
| `typography_config` | no | `null` | Path to a typography settings yaml consumed by `\Parisek\Twig\TypographyExtension`. Only matters if your templates use `|typography` and you want non-default behavior. |
| `namespaces` | no | `[]` | Extra Twig namespaces (`<name> => <absolute path>`) for paths that live outside `templates_path` and aren't covered by the auto-registered conventional namespaces. |
| `auth` | no | `null` | Optional `callable(array $route): bool` gate checked once per request, before any dispatch (SPA, render, JSON API, or asset). Return `false` to reject with a plain-text `403 Forbidden`; return `true` (or omit the key entirely) to allow. Receives the parsed route array (`type`, plus `slug`/`kind`/`endpoint`/`path`/`theme` depending on route type). Requests loaded inside the styleguide's own iframe (`Sec-Fetch-Dest: iframe`) are re-typed to `type: 'render'` (carrying `kind: 'component'`/`'page'`/`'doc'`/`'foundations'`) before the callable ever sees them — don't gate solely on `type === 'component'`, or every iframe-embedded component render will fall through as `'render'` and bypass that branch. A non-`null`, non-callable value throws `InvalidArgumentException` at construction time (fail loudly at boot) rather than silently allowing every request; a callable that throws is treated as a denial (fail closed) and logged via `error_log()`, never surfaced to the caller. For publicly reachable deployments, HTTP Basic Auth at the web-server level is usually simpler and more robust than an in-PHP callable — reach for `auth` when the check needs request context only PHP has access to (e.g. a signed query token, a session check your framework already performs). |

### `twig` config — when to pass it

If your project's component templates use **functions or filters registered on a specific Twig environment** (`component_*`, `_x()`, `placeholder()`, `|resizer`, custom extensions), pass that environment via the `twig` config key. The package attaches its own template paths to that loader so the project's filters keep working inside the iframe.

If your component templates are self-contained (no project-specific filters), omit `twig` — the package builds a pristine environment with just `@project` namespaced at `templates_path`.

### Apache / Nginx rewrite

The package handles routing in PHP, but the entry script needs to receive `/styleguide/*` requests. Apache:

```apache
# .htaccess
RewriteEngine On
# /styleguide is a virtual path — force it through the entry script
RewriteRule ^styleguide(/.*)?$ /index.php [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
```

Nginx equivalent:

```nginx
location /styleguide { try_files $uri /index.php?$query_string; }
```

For local development without a web server, see [`static/router.php`](https://github.com/portadesign/tailwind-base/blob/main/static/router.php) in the reference integration — `php -S 127.0.0.1:8000 -t public router.php`.

---

## `styleguide.yaml` — project config

The bootstrap reads `config_yaml` (typically `styleguide.yaml` next to `index.php`). Two blocks are package-aware; everything else is passed through to the overview template, so add whatever your project needs.

```yaml
project:
  name: "My Project"                       # shown in SPA chrome + iframe titles
  description: "Visual identity"           # overview lede paragraph
  favicon: "/images/touch/favicon.svg"     # browser tab + sidebar header

# Assets injected into each iframe's <head>. Same paths the production templates
# use — guarantees the styleguide preview matches production. Paths are resolved
# against `twig_context.templateUrl` (see "iframe asset paths" below), so a short
# /dist/... value works whether the static dir is the docroot (standalone) or the
# styleguide is served from a theme (WordPress / Drupal).
iframe:
  # `css` and `fonts` each accept a single string OR a list of stylesheet URLs.
  # A list is handy mid-migration — e.g. a Tailwind bundle plus a legacy sheet.
  css: "/dist/css/style.css"               # string, or e.g. [ "/dist/css/style.css", "/legacy/style.css" ]
  js:  "/dist/js/script.js"                # project's main bundled script (ES module if you build with Vite)
  fonts:                                   # string or list — one entry per @font-face stylesheet
    - "/fonts/poppins/stylesheet.css"
  html_class: ""                           # optional — <html> class for the preview frame
  body_class: ""                           # optional — <body> class
  page_wrapper_class: ""                   # optional — wrapper <div> class for page renders only (see below)
  base_href: "/"                           # optional — affects relative URLs inside the iframe

# Optional data consumed by the overview screen. All keys optional; missing
# blocks simply hide their section.
logo:
  main: { src: "/images/logo.svg", alt: "Logo", label: "Hlavní logo", background: "light" }
  favicon: { src: "/images/touch/favicon.svg", alt: "Favicon", label: "Favicon" }

colors:
  primary:
    name: "Primary"
    css_variable: "primary"
    default: 500
    shades:
      50:  { hex: "#FFEAEA", oklch: "oklch(95.6% 0.022 17.54)" }
      # ...
      950: { hex: "#1F0000", oklch: "oklch(15.32% 0.059 31.48)" }

typography:
  fonts:
    - name: "Poppins"
      type: "Sans-serif"
      stylesheet: "/fonts/poppins/stylesheet.css"
      usage: [Headings, Body]
  headings:
    - { tag: h1, size: "text-4xl md:text-5xl", label: "Heading 1", desc: "48px / 3rem" }
  weights:
    - { name: "Regular", class: "font-normal", value: "400" }
  body_sample: "Lorem ipsum…"

labels:                                    # i18n labels shown on overview cards
  logo: "Logo"
  colors: "Colors"
  typography: "Typography"
  click_to_copy: "Click to copy"
  copied: "Copied!"
```

### iframe asset paths — resolved against `templateUrl`

`iframe.css`, `iframe.js`, and `iframe.fonts[]` are resolved **relative to the `twig_context.templateUrl`** you pass to the bootstrap — the same base your component templates already use for images (`{{ templateUrl }}/images/...`). One short, docroot-agnostic value then works across layouts:

| Layout | `templateUrl` | `css: /dist/css/style.css` resolves to |
|---|---|---|
| **Standalone** (static dir IS the docroot) | `''` | `/dist/css/style.css` (unchanged) |
| **WordPress** (served via rewrite from a theme) | `/wp-content/themes/<theme>/static` | `/wp-content/themes/<theme>/static/dist/css/style.css` |
| **Drupal** | `/themes/custom/<theme>/static` | `/themes/custom/<theme>/static/dist/css/style.css` |

Rules (`Renderer::resolveAssetUrl()`):

- **Relative / root-relative** paths (`dist/...`, `/dist/...`) are rebased onto `templateUrl`.
- **Already-absolute-under-base** paths (you hardcoded the full theme path) are left untouched — no double prefix.
- **External** URLs (`https://…`, `//cdn…`, `data:`) and **anchors** (`#…`) are never rebased.
- An **empty** `templateUrl` (standalone) is a no-op — paths pass through unchanged, byte-for-byte.

So: keep `iframe.css: /dist/css/style.css` in `styleguide.yaml`, pass the right `templateUrl` in your bootstrap, and the preview loads the real asset in every environment — no need to hardcode the theme path.

---

## URL surface

| URL | Served | Purpose |
|---|---|---|
| `/styleguide/` | SPA HTML | Landing (auto-routes to overview) |
| `/styleguide/component/<slug>` | SPA HTML | Deep link — client-side router resolves the right view. Also accepts `?variant=<id>`* |
| `/styleguide/page/<slug>` | SPA HTML | Deep link to a page styleguide. Also accepts `?variant=<id>`* |
| `/styleguide/doc/<slug>` | SPA HTML | Deep link to a doc entry (DOKUMENTACE group). Also accepts `?variant=<id>`* |
| `/styleguide/overview` | SPA HTML | Components & pages master index (grouped by section, optional usage chips) |
| `/styleguide/foundations` | SPA HTML | Colors / typography / fonts / logo preview built from `styleguide.yaml` |
| `/styleguide/fields` | SPA HTML | Field inspector — flattened view of every component's `fields:` metadata |
| `/styleguide/render/<kind>/<slug>` | iframe HTML | Bare render — `<kind>` ∈ `component` \| `page` \| `doc` \| `foundations`. Used as iframe `src`, also browsable directly. Accepts `?theme=light\|dark` (whitelisted, invalid/missing → `light`) to stamp `class="dark"` and a matching `color-scheme` on the iframe `<html>` for consumers that opt into Tailwind dark mode; inert for projects with no dark-mode CSS. When `?theme=` is absent — e.g. a native link click inside the rendered content navigating to another SPA-shell URL — the SPA's own `sg-iframe-theme` cookie (`path=/styleguide`) is consulted as a fallback so the visitor's toggle choice survives in-iframe navigation; an explicit `?theme=` always wins over the cookie. Also accepts `?variant=<id>` (`<id>` matching `[a-z0-9-]+`) to render `styleguide.<id>.twig` instead of the default `styleguide.twig`, for `component`/`page`/`doc` kinds — see `docs/API.md` § Component Twig file conventions. Query-only, no cookie fallback: an absent, invalid, or unknown (deleted/renamed) variant silently falls back to the default `styleguide.twig` → `<slug>.twig` chain rather than 404ing, so a bookmarked deep link to a removed variant keeps working. Composes independently with `?theme=`. |
| `/styleguide/api/components` | JSON | List of components — see [API](#api) below |
| `/styleguide/api/pages` | JSON | List of pages — same shape as components |
| `/styleguide/api/docs` | JSON | List of doc entries — same shape as pages; `[]` when `templates/doc/` is absent |
| `/styleguide/api/fields` | JSON | Field metadata flattened across components |
| `/styleguide/api/health` | JSON | Parse-resilience diagnostics — see [API](#api) below |
| `/styleguide/assets/<path>` | static | SPA bundle + locales + any package asset (immutable cache for hashed filenames, ETag for unhashed) |

\* Same whitelist/fallback rules as the render-endpoint row above (`^[a-z0-9-]+$`, unknown/removed values fall back to the default rather than 404ing); `Router::synthesizeEmbeddedRoute()` forwards the SPA-shell's `?variant=` across the iframe-embed swap so the preview and the deep link agree.

---

## API

Five read-only JSON endpoints under `/styleguide/api/*`. All return `200 OK` with `Content-Type: application/json; charset=utf-8` and `Cache-Control: no-cache`. No auth, no pagination, no query parameters — the dataset is small enough (one read per component template) that the SPA refetches the whole list on demand. Unknown endpoints return `404` with `{"error": "Unknown API endpoint: <name>"}`.

The SPA consumes all five (`frontend/src/stores/catalog.js`); external tooling can do the same — e.g. a CI job that lints fields metadata, a script that mirrors the component list into Notion, a Storybook bridge.

### `GET /styleguide/api/components`

Flat list of every component template under `templates/component/**/<id>.twig` whose first `{# … #}` comment parses as YAML and carries at least a `name:` key. Order: `weight` ascending, then `name` (Czech collation when `intl` is available, otherwise byte-wise `strcmp`).

**Response shape** — `array<Component>`:

```jsonc
[
  {
    "id":            "button",          // directory + filename (without .twig)
    "name":          "Button",          // from metadata `name:`
    "category":      "Basic",           // from `category:`, "" if absent
    "description":   "Primary CTA…",    // from `description:`, "" if absent
    "asana":         "",                // from `asana:`, "" if absent — task URL
    "figma":         "",                // from `figma:`, "" if absent — Figma node URL
    "drupal":        "",                // from `drupal:`, "" if absent — Drupal docs / module link
    "web":           "",                // from `web:`, "" if absent — generic external link
    "weight":        50,                // from `weight:`, default 50, sidebar order
    "usage":         ["404", "article-list"], // from `usage:`, normalised to an array by the parser
    "fields": {                          // from `fields:`, {} if absent
      "url":   { "title": "URL",   "type": "url",  "required": 1 },
      "title": { "title": "Label", "type": "text", "required": 1 }
    },
    "has_styleguide": true              // true when a sibling styleguide.twig exists
                                         // OR metadata declares `styleguide:`
  }
  // …
]
```

**Notes**
- `usage` is **authored as a comma-separated string** in YAML (`usage: 404, article-list`) — looser whitespace is fine — but **normalised to an array** by `ComponentParser` before it reaches the wire, so consumers (the SPA included) work with `string[]` directly instead of re-splitting a CSV.
- `fields` is passed through verbatim from the YAML. Shape is consumer-defined; the bundled SPA assumes `{ title, type, required }` per the convention in *Per-template metadata*, but extra keys are preserved end-to-end.
- Templates without a parseable YAML block, or with YAML missing `name:`, are silently dropped — that's the only way to keep a `.twig` file under `templates/component/` and have the styleguide chrome ignore it.

### `GET /styleguide/api/pages`

Same shape as `/api/components`, scanned from `templates/page/**/<id>.twig` instead. Use this when your project renders entire page templates through Twig (Drupal `page--*.html.twig`, WordPress Timber `page-*.twig`) and you want them to appear in the styleguide alongside components.

If `templates/page/` doesn't exist, response is `[]`. No error.

### `GET /styleguide/api/docs`

Same shape as `/api/pages`, scanned from `templates/doc/**/<id>.twig`. Entries appear in the sidebar's DOKUMENTACE group and render inside the iframe like pages (prefer `styleguide.twig` sibling, fallback `<id>.twig`).

If `templates/doc/` doesn't exist, response is `[]` — the DOKUMENTACE sidebar group still renders its foundations + overview entries. No error.

### `GET /styleguide/api/fields`

Aggregated view of every component's `fields:` metadata, **flattened across components**. Returns one entry per component that has at least one field defined; components with empty / missing `fields` are skipped.

**Response shape** — `array<ComponentFields>`:

```jsonc
[
  {
    "component_id":   "button",
    "component_name": "Button",
    "fields": {
      "url":   { "title": "URL",   "type": "url",  "required": 1 },
      "title": { "title": "Label", "type": "text", "required": 1 }
    }
  }
  // …
]
```

Data source for the SPA's `/styleguide/fields` inspector — useful for one-shot answers like *"where do we use a `richtext` field?"* without walking the whole component list.

### `GET /styleguide/api/health`

Diagnostics for `ComponentParser`'s resilience: `parse()`/`parseAll()` now catch `\Throwable` per file instead of only a YAML parse error, so one pathological template is skipped and recorded rather than 500ing the whole catalogue. This endpoint reports what got skipped, plus how much made it through.

Unlike the four endpoints above, the response is an **object**, not a bare array — there's no additive slot to bolt a `_warnings` field onto a bare-array response without breaking every existing consumer of that shape.

**Response shape**:

```jsonc
{
  "warnings": [
    { "file": "component/broken-widget/broken-widget.twig", "error": "…exception message…" }
    // empty array when nothing was skipped
  ],
  "counts": { "components": 42, "pages": 7, "docs": 3 }
}
```

### Caching

Every endpoint sets `Cache-Control: no-cache`. Responses are recomputed per request because the underlying source (YAML in `.twig` files) changes during dev and there's no invalidation signal. The work is a filesystem walk + one YAML parse per file — acceptable even for large component libraries.

If you need to serve these at scale, wrap them behind your project's own HTTP cache and bust on `templates/**/*.twig` change.

### Adding a new endpoint

The endpoint classes (`src/Api/*Endpoint.php`) share the same shape: constructor takes the `ComponentParser`, `handle()` emits headers + `json_encode()`. New endpoints follow the same pattern:

1. Create `src/Api/<Name>Endpoint.php` mirroring the existing trio.
2. Wire it into `Styleguide::dispatchApi()` (the `match` block on `$route['endpoint']`).
3. Add a test under `tests/Api/<Name>EndpointTest.php`.

There's deliberately no shared base class — near-identical classes are clearer than an abstraction that hides where the headers and encoding happen.

---

## Command-line catalogue (CLI)

After install, `vendor/bin/styleguide` exposes the component catalogue without needing the SPA. Useful for AI coding assistants and scripted tooling.

```bash
vendor/bin/styleguide list                       # all components (compact JSON)
vendor/bin/styleguide list --pretty              # indented for terminals
vendor/bin/styleguide list --type=page           # pages instead of components
vendor/bin/styleguide list --type=doc            # doc entries
vendor/bin/styleguide show button                # one component, full detail
vendor/bin/styleguide show landing --type=page   # one page
vendor/bin/styleguide show intro --type=doc      # one doc entry
```

The CLI wraps `ComponentParser` — it returns the same normalised records as `GET /styleguide/api/components`, but without a running webserver. Run it from the consumer's repo root, or set `STYLEGUIDE_TEMPLATES=<path>` / pass `--templates=<path>` to override the templates directory location.

Stdout is JSON; stderr carries error messages. Pipe to `jq` for filtering:

```bash
vendor/bin/styleguide list | jq '.[] | select(.category == "Block")'
```

`show <id>` exits `1` with an empty stdout when the component is not found, so a missing entry surfaces as a non-zero exit code rather than a parsing error downstream.

### `lint` — metadata quality report

```bash
vendor/bin/styleguide lint                       # scan component + page + doc, text output
vendor/bin/styleguide lint --type=component       # scan just one type
vendor/bin/styleguide lint --format=json --pretty # machine-readable, indented
```

Reports five issue types: templates with no parseable `name:` (dropped from
the catalogue — `unindexed`), a `styleguide:` YAML key carrying content that
the renderer never reads (`dead-styleguide-content` — see *Fixtures &
sample data* below), `usage:` references to ids that don't exist
(`broken-usage-ref`), `render:` values outside the four canonical modes
(`unknown-render`), and empty `description` strings (`empty-description`,
informational only).

Text output is one line per finding: `SEVERITY  file  message`. JSON output
is an array of `{ severity, file, rule, message }` objects. Exit code: `0`
clean (or notice-only), `1` when any `warning`/`error` finding is present,
`2` on a usage/internal error — run it in CI to catch metadata regressions
before they ship.

Replacing a bespoke, hand-rolled styleguide with this package? See
[`docs/MIGRATION.md`](docs/MIGRATION.md) for a step-by-step guide, including
worked per-project notes for the fleet's Tailwind/SCSS, Drupal-Twig, and
Bootstrap 5 stacks.

---

## Conventional Twig namespaces

When the package builds its own Twig environment (or attaches loaders to a project-provided one), it auto-registers these namespaces whenever the matching directory exists. Component templates can rely on them without the consuming project calling `$loader->addPath(…)`:

| Namespace | Source | Notes |
|---|---|---|
| `@project` | `templates_path` | Renderer template lookup. Always registered. |
| `@component` | `templates_path/component` | Resolves `{% include '@component/<name>/<name>.twig' %}` and powers the `component_*()` helper. |
| `@page` | `templates_path/page` | Sibling of `@component`; powers `page_*()`. |
| `@doc` | `templates_path/doc` | Sibling of `@page`. Resolves `{% include '@doc/<name>/<name>.twig' %}` in doc templates; auto-registered only when `templates_path/doc/` exists. |
| `@macro` | `templates_path/macro` | Shared Twig macros. |
| `@static` | `templates_path` | Fallback namespace for templates that live directly under the templates root. |
| `@icons` | `static_path/images/icons` | Inline SVG icons referenced as `@icons/<file>.svg`. |
| `@images` | `static_path/images` | Project image assets. |

Anything else — non-standard image roots, third-party template packs — goes into the `namespaces` config map as `<name> => <absolute path>`. Last write wins, so you can also override a conventional location if your layout is exotic.

---

## Per-template metadata

Each component / page Twig template's **first** `{# … #}` comment is parsed as YAML and becomes the metadata for that entry. The styleguide registrar reads these to build the sidebar, the cross-reference panel, and the API responses.

```twig
{#
name: "Button"
category: "Basic"
weight: 1
usage: 404,article-list,header-menu
description: "Primary CTA — three sizes, primary + secondary skin."
fields:
  url: { title: "URL", type: "url", required: 1 }
  title: { title: "Label", type: "text", required: 1 }
#}
<a href="{{ content.url }}" class="btn …">{{ content.title }}</a>
```

| Key | Used by |
|---|---|
| `name` | sidebar label, iframe title |
| `category` | sidebar bucket — folded into a small set of canonical sections by `sectionOf()` in `frontend/src/stores/catalog.js`. Unknown labels never get dropped, they fall into a default bucket. |
| `weight` | sort order within a bucket (lower = earlier; default `50`) |
| `usage` | authored as comma-separated ids of pages/components that USE this one (component view) or that THIS one uses (page view); normalized to an array by the parser — drives the cross-reference chip panel |
| `description` | sidebar tooltip + overview cards |
| `fields` | `/api/fields` endpoint + the Fields inspector view |
| `asana` | external link chip — Asana task URL |
| `figma` | external link chip — Figma design URL |
| `drupal` | external link chip — Drupal docs / module URL |
| `web` | external link chip — generic external URL |
| `render` | iframe-wrapper rendering mode for components — see *Component render modes* below |
| `styleguide` | legacy presence-only flag — **prefer a sibling `styleguide.twig` file** (the renderer already prefers it; see *Fixtures & sample data* below). Content nested under this YAML key is never read; `vendor/bin/styleguide lint` reports it as `dead-styleguide-content`. |
| `responsive` | `true` (default) — when `false`, the SPA hides the responsive-width toolbar for this entry; use for docs or fixed-layout demos where resizing has no meaning |
| `body_class` | optional class string applied to the render iframe's `<body>`, merged **after** the global `iframe.body_class` — see *Per-entry body class* below |
| `variants` | **legacy fallback** map of display titles (and optional descriptions) for auto-discovered `styleguide.<variant>.twig` sibling files, keyed by id — prefer a `title:`/`description:` annotation in the sibling file itself; see *File-convention variants* below |

**YAML reserved indicator gotcha:** the first comment is parsed as YAML, so avoid `{% %}` tags inside it (`%` is a YAML directive marker). Put usage examples in a second `{# #}` comment block, or in the sibling `styleguide.twig` file.

### Component render modes

By default every component renders inside a 24 px-padded wrapper — right for atomic UI (button, alert, breadcrumb) that would otherwise sit flush against the iframe edge. Hero / slider / page-chrome / modal components want the full viewport instead. The `render` YAML key opts each component into one of four modes:

| Mode | Effect | Use for |
|---|---|---|
| `inset` *(default)* | 24 px padding wrapper, body `min-height` untouched. | Atomic UI: button, alert, breadcrumb, picture, pagination, accordion. |
| `bleed` | No wrapper. Resets `--header-height` to `0px` so consumer "tuck under sticky header" hacks (`margin-top: var(--header-height, 75px) * -1`) collapse cleanly in styleguide isolation. | Hero, slider, page-header — anything that wants to fill the iframe edge-to-edge. |
| `chrome` | Same as `bleed`, plus `body { min-height: 200vh }`. Sticky / fixed elements have room to scroll against. | `header` with sticky variant, `footer`, `cookieconsent`. |
| `overlay` | Same iframe wrapper as `bleed`. Separate label exists so future UI can surface "this is a modal" without a wrapper change. | Native `<dialog>` modals. |

```twig
{#
name: "Slider"
category: "Gutenberg"
render: bleed
fields:
  items:
    type: array
    required: 1
#}
```

Missing key, typo, or non-string value falls back to `inset` — legacy components without `render:` keep their pre-feature wrapper, so adopting the package is a no-op until you opt in.

### Per-entry body class

`iframe.body_class` in `styleguide.yaml` sets one `<body>` class for **every** render. Some pages need their own — a blog/category page whose production `<body>` carries a dark brand background, for example. Declare it per entry with `body_class`:

```twig
{#
name: "Blog"
body_class: "bg-secondary-500 body-secondary"
#}
```

The render iframe builds `<body>` via `create_attribute({ class: [iframe.body_class, <entry>.body_class] })`, so the per-entry value is appended after the global one and empty values are dropped (no stray `class=""`). This mirrors what the production layout puts on `<body>` (e.g. from an ACF `body_background_color`), so the styleguide preview matches production without wrapping the page content in a styleguide-only `<div>`.

### File-convention variants

Drop a `styleguide.<variant>.twig` file next to `styleguide.twig` and it's automatically discovered — no YAML required:

```
component/hero/
├── hero.twig
├── styleguide.twig            ← default variant
├── styleguide.secondary.twig  ← discovered variant "secondary"
└── styleguide.dark-bg.twig    ← discovered variant "dark-bg"
```

The SPA preview area shows every variant at once — a responsive grid of independent preview tiles (default fixture first, then each discovered variant in filename order) — the moment at least one sibling exists; no toolbar switcher, no clicking through.

**Display metadata — annotate the sibling file itself (preferred).** Give the variant a title and optional description with the same front-comment convention every component/page template already uses, right in the sibling file it describes:

```twig
{# styleguide.dark-bg.twig #}
{#
title: "Dark background"
description: "Same hero, tuned for a dark section background."
#}
<div class="hero hero--dark">…</div>
```

```twig
{# styleguide.secondary.twig #}
{#
title: "Secondary style"
#}
<div class="hero hero--secondary">…</div>
```

Metadata lives next to the markup it describes instead of a centralised map you'd otherwise have to keep in sync by id as variants are added, renamed, or removed.

**Legacy fallback — the `variants:` map.** Templates written before per-sibling annotations existed (or not yet migrated) can still supply titles/descriptions from the component's own front comment, keyed by variant id — either a plain string, or a map with an optional `description` too:

```twig
{#
name: "Hero"
variants:
  secondary: "Secondary style"
  dark-bg:
    title: "Dark background"
    description: "Same hero, tuned for a dark section background."
#}
```

(`label:` is also accepted in the map as a legacy alias for `title:` — `title:` wins when both are present.) A sibling's own annotation always wins over its map entry when both exist; an id with no annotation falls back to the map, then to the id itself.

An entry with no matching file is ignored — the filesystem is always the source of truth for which variants exist. `<variant>` must match `[a-z0-9-]+`. The render endpoint itself (`/styleguide/render/component/<slug>`) is unaffected by any of this SPA chrome: with no `?variant=` it renders the single default `styleguide.twig` body, exactly as it always has; `?variant=<id>` isolates that one block; an unknown or since-deleted variant silently falls back to the default body instead of 404ing.

**Default view (SPA).** With no `?variant=` (a bare deep link), the preview area becomes a grid — one independent `<iframe>` tile per variant (the default fixture first, then each discovered variant in filename order), each with its own slim header (title + optional description). This is the whole point of having variants: see every treatment at a glance, no switcher to click through. Deep-linking a specific `?variant=<id>` still shows the classic single, resizable preview of just that one variant. An entry with no discovered variants is unaffected — it renders the single default preview exactly as it always has.

**Device presets, per tile.** The toolbar's viewport preset dropdown (Mobile/Tablet/Desktop/Full, custom width, orientation) stays visible and works the same way in the grid as in the classic single preview — the chosen preset applies to every tile at once. A fixed-width/fixed-height preset renders each tile's iframe at exactly that preset's logical size, then scales the whole tile down (never up) to fit the tile's own available width, with a small scale readout in the tile's header (e.g. `375 × 667 · 84 %`). Full stays fluid — each tile's iframe simply tracks its cell's width with auto content height, no scaling.

**Tile density — Auto | 1 | 2 | 3 | 4.** A segmented toolbar control next to the preset dropdown (visible only while the grid is active) controls how many tiles fit per row. "Auto" (the default) derives the column basis from the *active viewport preset* rather than one fixed number for every preset — a Desktop preset (1280 px) settles on far fewer tiles per row than a Mobile preset (375 px) on the same canvas, always scaled down to fit each tile's own cell as usual. "1"–"4" fix the column count exactly, ignoring the preset — "1" is the direct replacement for the earlier "rows" stacked layout (a single-column grid renders identically). The choice is remembered across visits (`localStorage`, key `sg-variant-columns`); upgrading from a pre-2.0 install migrates an existing `sg-variant-layout` value once (`"rows"` → `1`, `"grid"` → `"auto"`).

**Click-to-isolate.** Clicking (or pressing Enter/Space on) a tile's header jumps straight to that variant's classic single preview — the same as typing `?variant=<id>` by hand. The Default tile's header is the one exception: it has no dedicated single-preview URL of its own (an entry with variants and no `?variant=` always resolves back to the grid), so it isn't clickable. Once a variant is isolated this way, a small "← All" control appears in the toolbar to return to the grid.

### Page wrapper

`body_class` styles the iframe's `<body>`; `iframe.page_wrapper_class` adds the structural **shell** most projects wrap their page in — the `<div class="page-wrapper …">` that owns the sticky-footer flex column and `min-h-dvh` height in the production layout. Set it once in `styleguide.yaml` and **every page render** is wrapped:

```yaml
iframe:
  page_wrapper_class: "page-wrapper flex flex-col relative min-h-dvh w-full h-full"
```

Rules:

- **Page-only.** The wrapper is applied solely to `kind: page` renders — never to component or doc previews, so the full-height shell can't leak into a small component preview.
- **Empty = no wrapper.** The default is `""`, which renders nothing. The package stays framework-agnostic: Bootstrap / custom-CSS consumers simply leave it blank, Tailwind projects set their shell utilities.
- **Built through `create_attribute`** — same class-escaping contract as the `<body>` line, no stray `class=""`.

This completes the production-parity pair: `body_class` reproduces the page's `<body>` styling, `page_wrapper_class` reproduces the wrapper `<div>` around `header + main + footer` — so a page preview matches production without each consumer hand-wrapping every `page/<name>/styleguide.twig`.

---

## Fixtures & sample data

The **only supported convention** for demo content is a sibling
`styleguide.twig` next to the component or page it demos:

```
templates/component/breadcrumb/
├── breadcrumb.twig       # the component itself — receives content.* from the CMS in production
└── styleguide.twig       # sample data, rendered ONLY in the styleguide preview
```

```twig
{# templates/component/breadcrumb/styleguide.twig #}
{{ component_breadcrumb({
    container: 'container',
    items: [
        { title: 'Úvod', url: '#' },
        { title: 'Služby', url: '#' },
        { title: 'Detail služby', url: '#' },
    ],
}) }}
```

`Renderer` auto-detects the sibling file and prefers it — no YAML key
required. The `styleguide:` front-comment key (nested sample data under the
YAML metadata) still works for backward compatibility, but content placed
under it is **never read** — only its presence is checked. Run
`vendor/bin/styleguide lint` to find leftover instances (reported as
`dead-styleguide-content`) and move the data into a `styleguide.twig`
sibling; see `docs/MIGRATION.md` for a worked before/after.

### Placeholder images — no external network calls

Use the bundled `placeholder()` Twig function in `styleguide.twig` files
instead of a service like `picsum.photos`. It's deterministic (the same
`seed` always renders the same image), fully offline (an inline SVG data
URL — no network round-trip, no rate limit, no dead links when a
third-party service changes its API), and returns an image-array shape
most `component_picture`-style helpers already expect:

```twig
{# bare call — abstract subject, pastel mood, 3/2 aspect #}
{{ component_picture({ image: placeholder() }) }}

{# tuned for a hero — landscape subject, warm mood, explicit size #}
{{ component_picture({
    image: placeholder({ subject: 'landscape', mood: 'warm', width: 1920, height: 1080, seed: 'hero-1' }),
}) }}

{# repeatable across a gallery loop — same subject, distinct seed per index avoids visually identical repeats #}
{% for i in 1..4 %}
    {{ component_picture({ image: placeholder({ subject: 'product', seed: 'gallery-' ~ i }) }) }}
{% endfor %}
```

| Option | Values | Default |
|---|---|---|
| `subject` | `abstract` \| `landscape` \| `portrait` \| `product` \| `food` \| `architecture` \| `avatar` | `abstract` |
| `mood` | `pastel` \| `vibrant` \| `monochrome` \| `warm` \| `cold` \| `natural` \| `vintage` | `pastel` |
| `seed` | any string — same seed ⇒ same image | auto-incrementing counter |
| `width` / `height` / `aspect` | pixels, or a `"w/h"` ratio string | `aspect: '3/2'`, 1200px wide |
| `label` | `true` \| a string \| `false` | `false` |

See `docs/API.md` § Twig functions for the full option list (`grain`,
`vignette`, `alt`).

---

## File layout (after install)

```
vendor/parisek/styleguide/
├── src/                              # PHP runtime (PSR-4 Parisek\Styleguide\)
│   ├── Styleguide.php                # public bootstrap
│   ├── Router.php                    # URI → route descriptor
│   ├── Renderer.php                  # component / page / overview → iframe HTML
│   ├── ComponentParser.php           # first-comment YAML parser + sidebar builder
│   ├── AssetServer.php               # path-traversal guard + ETag + immutable cache
│   └── Api/                          # ComponentsEndpoint, PagesEndpoint, FieldsEndpoint
├── templates/                        # Twig templates the package renders
│   ├── render-cell.twig              # iframe HTML wrapper
│   ├── overview.twig                 # palette + typography + fonts
│   └── styleguide-404.twig
├── dist/                             # prebuilt SPA bundle (committed)
│   ├── index.html
│   ├── styleguide.<hash>.js
│   ├── styleguide.<hash>.css
│   └── locales/{cs,en}.json
├── composer.json
├── LICENSE
├── README.md
└── CHANGELOG.md
```

Tests, frontend source, and tooling files (`frontend/`, `tests/`, `phpunit.xml`, `composer.lock`) are present in the [GitHub repo](https://github.com/parisek/styleguide) for contributors but excluded from the Composer tarball via `.gitattributes export-ignore`.

---

## Local development (for package contributors)

```bash
git clone git@github.com:parisek/styleguide.git
cd styleguide

# PHP unit tests (Router, Renderer, ComponentParser, AssetServer)
composer install
vendor/bin/phpunit

# SPA chrome (Vite + Vue 3 + Pinia + Tailwind v4)
cd frontend
npm install
npm run watch          # rebuilds dist/ on every edit
npm test               # Vitest unit suite (src/lib, src/stores, src/composables, src/components)
npm run test:e2e       # Playwright, full-browser parity checklist
```

Changes to PHP `src/` are picked up immediately (no build step). Changes to `frontend/*` require a Vite build — committed `dist/` artifacts are what consumers receive, so always commit the rebuilt bundle when the SPA changes.

---

## Stability & versioning

The package follows [SemVer](https://semver.org/). For an exhaustive list of what's covered by the public API contract (PHP classes/methods, YAML schemas, JSON endpoints, Twig functions, URL surface, CLI), see **[`docs/API.md`](./docs/API.md)**.

PHP classes outside of `Styleguide` itself are marked `@internal` and can change in any minor release. Consumers should only call `new Styleguide([…])->run()` — the rest of the surface is reached via YAML config, JSON endpoints, or Twig functions in component templates.

---

## License

[MIT](./LICENSE) © Petr Parimucha
