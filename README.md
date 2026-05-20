# parisek/styleguide

Self-contained Composer package that turns a tree of Twig component templates into a live, browsable styleguide — sidebar, ⌘K search, viewport presets, locale switcher, deep links — without writing any of that chrome yourself.

Drop the package into a project that already renders Twig (Symfony, Drupal, WordPress with Timber, or any standalone Twig setup), wire a 15-line bootstrap into a public PHP file, point a YAML config at the project's CSS/JS bundles, and `/styleguide/...` works.

![Overview screen showing palette, typography, fonts](https://placehold.co/1600x900?text=Overview+preview)

---

## What it does

| Surface | What you get |
|---|---|
| **SPA chrome** | Alpine.js 3 + Tailwind v4 sidebar with collapsible sections, search (`⌘K` / `Ctrl+K`), iframe preview with named viewport presets (Mobile 375×667 · Tablet 768×1024 · Desktop 1280×800 · Full 100 %) + smooth drag-resize, live dimension readout, cs ↔ en locale switcher, deep-link routing via history API. All bundled — zero CDN dependencies, zero JS to write. |
| **Overview** | Auto-generated palette / typography / fonts page driven by the project's `styleguide.yaml`. Colours are click-to-copy hex; typography rolls preview headings + body sample. Lands here by default at `/styleguide/`. |
| **Iframe preview** | Each component / page renders inside an iframe that loads the project's real CSS + JS — what you see is what production renders. The package's `Renderer` reuses the project's Twig environment, so component templates keep access to project filters / functions (`component_*`, `_x()`, `placeholder()`, custom helpers). |
| **Cross-references** | Chip panel above each preview: components list "Used in: …", pages list "Components used: …", click to navigate. Driven by per-template `usage:` YAML metadata. |
| **REST endpoints** | `/styleguide/api/components`, `/api/pages`, `/api/fields` return JSON for consumers (the SPA itself, plus any external tooling). |
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
            "canonical": false,                 // critical: lets Packagist still supply ^0.1 when needed
            "options": {
                "symlink": true,
                "versions": { "parisek/styleguide": "dev-local" }
            }
        }
    },
    "scripts": {
        "styleguide:local":  "@composer require parisek/styleguide:dev-local --no-interaction",
        "styleguide:remote": "@composer require parisek/styleguide:^0.1 --no-interaction"
    }
}
```

`canonical: false` is what keeps Packagist visible — without it the path repo would shadow it and the `^0.1` constraint would fail to resolve. The `versions` override pins the local copy to a fixed `dev-local` identifier so the switch scripts have a deterministic string to ask for. See `AGENTS.md` § *Local development against a consuming project* for the full mechanism.

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
# use — guarantees the styleguide preview matches production.
iframe:
  css: "/dist/css/style.css"               # project's main bundled stylesheet
  js:  "/dist/js/script.js"                # project's main bundled script (ES module if you build with Vite)
  fonts:
    - "/fonts/poppins/stylesheet.css"      # one entry per @font-face stylesheet
  html_class: ""                           # optional — <html> class for the preview frame
  body_class: ""                           # optional — <body> class
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

---

## URL surface

| URL | Served | Purpose |
|---|---|---|
| `/styleguide/` | SPA HTML | Landing (auto-routes to overview) |
| `/styleguide/component/<slug>` | SPA HTML | Deep link — client-side router resolves the right view |
| `/styleguide/page/<slug>` | SPA HTML | Deep link to a page styleguide |
| `/styleguide/overview` | SPA HTML | Components & pages master index (grouped by section, optional usage chips) |
| `/styleguide/foundations` | SPA HTML | Colors / typography / fonts / logo preview built from `styleguide.yaml` |
| `/styleguide/fields` | SPA HTML | Field inspector — flattened view of every component's `fields:` metadata |
| `/styleguide/render/<kind>/<slug>` | iframe HTML | Bare render — `<kind>` ∈ `component` \| `page` \| `foundations`. Used as iframe `src`, also browsable directly. Accepts `?theme=light\|dark` (whitelisted) to stamp `class="dark"` on the iframe `<html>` for consumers that opt into Tailwind dark mode. |
| `/styleguide/api/components` | JSON | List of components — see [API](#api) below |
| `/styleguide/api/pages` | JSON | List of pages — same shape as components |
| `/styleguide/api/fields` | JSON | Field metadata flattened across components |
| `/styleguide/assets/<path>` | static | SPA bundle + locales + any package asset (immutable cache for hashed filenames, ETag for unhashed) |

---

## API

Three read-only JSON endpoints under `/styleguide/api/*`. All return `200 OK` with `Content-Type: application/json; charset=utf-8` and `Cache-Control: no-cache`. No auth, no pagination, no query parameters — the dataset is small enough (one read per component template) that the SPA refetches the whole list on demand. Unknown endpoints return `404` with `{"error": "Unknown API endpoint: <name>"}`.

The SPA consumes all three (`frontend/stores/components.js`); external tooling can do the same — e.g. a CI job that lints fields metadata, a script that mirrors the component list into Notion, a Storybook bridge.

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
    "usage":         "404,article-list",// from `usage:`, raw comma-separated id string
    "fields": {                          // from `fields:`, {} if absent
      "url":   { "title": "URL",   "type": "url",  "required": 1 },
      "title": { "title": "Label", "type": "text", "required": 1 }
    },
    "hasStyleguide": true               // true when a sibling styleguide.twig exists
                                         // OR metadata declares `styleguide:`
  }
  // …
]
```

**Notes**
- `usage` is intentionally **a raw comma-separated string**, not a parsed array — the SPA splits client-side because templates use looser whitespace conventions (`"404, article-list"` vs `"404,article-list"`).
- `fields` is passed through verbatim from the YAML. Shape is consumer-defined; the bundled SPA assumes `{ title, type, required }` per the convention in *Per-template metadata*, but extra keys are preserved end-to-end.
- Templates without a parseable YAML block, or with YAML missing `name:`, are silently dropped — that's the only way to keep a `.twig` file under `templates/component/` and have the styleguide chrome ignore it.

### `GET /styleguide/api/pages`

Same shape as `/api/components`, scanned from `templates/page/**/<id>.twig` instead. Use this when your project renders entire page templates through Twig (Drupal `page--*.html.twig`, WordPress Timber `page-*.twig`) and you want them to appear in the styleguide alongside components.

If `templates/page/` doesn't exist, response is `[]`. No error.

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

### Caching

Every endpoint sets `Cache-Control: no-cache`. Responses are recomputed per request because the underlying source (YAML in `.twig` files) changes during dev and there's no invalidation signal. The work is a filesystem walk + one YAML parse per file — acceptable even for large component libraries.

If you need to serve these at scale, wrap them behind your project's own HTTP cache and bust on `templates/**/*.twig` change.

### Adding a new endpoint

The three endpoint classes (`src/Api/*Endpoint.php`) share the same shape: constructor takes the `ComponentParser`, `handle()` emits headers + `json_encode()`. New endpoints follow the same pattern:

1. Create `src/Api/<Name>Endpoint.php` mirroring the existing trio.
2. Wire it into `Styleguide::dispatchApi()` (the `match` block on `$route['endpoint']`).
3. Add a test under `tests/Api/<Name>EndpointTest.php`.

There's deliberately no shared base class — three near-identical classes are clearer than an abstraction that hides where the headers and encoding happen.

---

## Conventional Twig namespaces

When the package builds its own Twig environment (or attaches loaders to a project-provided one), it auto-registers these namespaces whenever the matching directory exists. Component templates can rely on them without the consuming project calling `$loader->addPath(…)`:

| Namespace | Source | Notes |
|---|---|---|
| `@project` | `templates_path` | Renderer template lookup. Always registered. |
| `@component` | `templates_path/component` | Resolves `{% include '@component/<name>/<name>.twig' %}` and powers the `component_*()` helper. |
| `@page` | `templates_path/page` | Sibling of `@component`; powers `page_*()`. |
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
| `category` | sidebar bucket — folded into a small set of canonical sections by `sectionOf()` in `frontend/stores/components.js`. Unknown labels never get dropped, they fall into a default bucket. |
| `weight` | sort order within a bucket (lower = earlier; default `50`) |
| `usage` | comma-separated ids of pages/components that USE this one (component view) or that THIS one uses (page view) — drives the cross-reference chip panel |
| `description` | sidebar tooltip + overview cards |
| `fields` | `/api/fields` endpoint + the Fields inspector view |
| `asana` | external link chip — Asana task URL |
| `figma` | external link chip — Figma design URL |
| `drupal` | external link chip — Drupal docs / module URL |
| `web` | external link chip — generic external URL |
| `styleguide` | optional flag — when set (or when a sibling `styleguide.twig` exists), the component exposes a separate styleguide-only render variant |

**YAML reserved indicator gotcha:** the first comment is parsed as YAML, so avoid `{% %}` tags inside it (`%` is a YAML directive marker). Put usage examples in a second `{# #}` comment block, or in the sibling `styleguide.twig` file.

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

# SPA chrome (Vite + Tailwind v4 + Alpine)
cd frontend
npm install
npm run watch          # rebuilds dist/ on every edit
```

Changes to PHP `src/` are picked up immediately (no build step). Changes to `frontend/*` require a Vite build — committed `dist/` artifacts are what consumers receive, so always commit the rebuilt bundle when the SPA changes.

---

## License

[MIT](./LICENSE) © Petr Parimucha
