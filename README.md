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

The whole package is ~5 PHP classes plus prebuilt JS/CSS — no Node.js required in production.

---

## Install

```bash
composer require parisek/styleguide
```

Pre-Packagist (local dev, sibling checkout, etc.) — register a path repository:

```jsonc
// composer.json
{
    "repositories": [
        { "type": "path", "url": "../styleguide", "options": { "symlink": true } }
    ],
    "require": {
        "parisek/styleguide": "^0.1"
    }
}
```

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
    'twig_context'   => [             // optional — globals for inner template renders
        'homeUrl'     => '/styleguide/',
        'templateUrl' => '',
        'langcode'    => 'cs',
    ],
]))->run();
```

`run()` parses `$_SERVER['REQUEST_URI']`. If the URI starts with `/styleguide`, it dispatches (SPA, asset, render, or JSON endpoint) and **exits**. Otherwise it returns silently and the rest of your `index.php` continues to handle non-styleguide URLs.

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
| `/styleguide/overview` | SPA HTML | Colors / typography / fonts preview |
| `/styleguide/render/<kind>/<slug>` | iframe HTML | Bare component / page / overview render — used as iframe src, also browsable directly |
| `/styleguide/api/components` | JSON | List of components (id, name, category, usage, fields) |
| `/styleguide/api/pages` | JSON | List of pages (same shape) |
| `/styleguide/api/fields` | JSON | Field metadata aggregated across components |
| `/styleguide/assets/<path>` | static | SPA bundle + locales + any package asset (immutable cache for hashed filenames) |

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
| `category` | sidebar bucket — `Basic` / `Blocks` / `Layout` → basic bucket, `Gutenberg` → gutenberg bucket, everything else folds into basic so nothing is silently dropped |
| `weight` | sort order within a bucket (lower = earlier) |
| `usage` | comma-separated ids of pages/components that USE this one (component view) or that THIS one uses (page view) — drives the cross-reference chip panel |
| `description` | shown in the sidebar tooltip / overview cards |
| `fields` | rendered by the `/api/fields` endpoint and the Fields overview |

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
