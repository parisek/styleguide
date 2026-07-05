# Public API contract

`parisek/styleguide` follows [SemVer](https://semver.org/). This document defines what counts as a **breaking change** (= major version bump) and what is free to evolve in any minor / patch.

The surface is intentionally narrow: most of `src/` is implementation detail. Consumer code generally only touches `Styleguide::run()`. The wider surface area belongs to **YAML schemas** (consumer-edited config) and **JSON API endpoints** (consumed by the SPA / external tools).

## Versioning policy

| Change | Impact | Bump |
|---|---|---|
| Add an `@api` method, property, config key, YAML field, JSON field, Twig function | Backward-compatible | **patch** if internal-only; **minor** if user-visible |
| Mark an `@api` method / config key as `@deprecated` | Backward-compatible; pre-warning for next major | **minor** |
| Remove an `@api` method / config key / YAML field / JSON field / Twig function | Breaking | **major** |
| Change an `@api` method signature (add required param, narrow return type) | Breaking | **major** |
| Refactor `@internal` code, even publicly | Not breaking | **patch** |
| New CLI command or option | Backward-compatible | **minor** |
| Remove / rename CLI command | Breaking | **major** |
| `dist/` SPA chrome rebuild (different bundle hash, new chrome UI) | Not breaking — consumer's component markup unchanged | **patch** |

A deprecated API must be retained for **at least 2 minor releases** before removal. Deprecation is signalled via `@deprecated` PHPDoc + warning emitted at boot when the deprecated path is used (when feasible).

## PHP API — public surface

### `Parisek\Styleguide\Styleguide` (`@api`)

The only class consumers instantiate directly.

```php
new Styleguide(array $config): self
$this->run(): void
```

#### `__construct(array $config)`

Required keys:

| Key | Type | Description |
|---|---|---|
| `templates_path` | `string` | Absolute path to the project's `templates/` directory (root of `@project` namespace) |
| `static_path` | `string` | Absolute path to the project's static-asset root (siblings of `templates/` — usually the dir hosting `static/index.php`) |
| `config_yaml` | `string` | Absolute path to the project's `styleguide.yaml` (see § YAML schemas) |

Optional keys (with their defaults):

| Key | Default | Description |
|---|---|---|
| `default_locale` | `'en'` | Two-letter locale code; drives `<html lang>` + locale fallback for `_x()` shim |
| `base_url` | `'/styleguide'` | URL prefix the package serves under (must match the web server rewrite) |
| `twig_context` | `[]` | Map of variables added to every Twig render — typically `homeUrl`, `templateUrl`, `langcode` |
| `twig` | `null` | Pre-built `Twig\Environment` to reuse. When null, the package builds a pristine env (autoescape: false, cache: false, debug: true) |
| `twig_options` | `[]` | Map merged on top of pristine env defaults (ignored if `twig` is provided) |
| `typography_config` | `null` | Absolute path to a `typography.yml` for the bundled TypographyExtension |
| `namespaces` | `[]` | Map of `<namespace> => <absolute path>` Twig namespaces beyond the conventional ones |
| `auth` | `null` | Optional `callable(array<string,mixed> $route): bool` gate checked once per request in `dispatch()`, before any handler (SPA, render, JSON API, or asset). Returning `false` responds `403 Forbidden` (plain text) and skips dispatch entirely. Returning `true`, or omitting the key (`null`), preserves today's behaviour — no gating. A non-`null`, non-callable value throws `InvalidArgumentException` from the constructor instead of being silently treated as "allow everything". |

**Security note:** `auth` is a convenience hook for programmatic gating, not a substitute for transport-level protection. For any styleguide reachable from the public internet, put HTTP Basic Auth (or your reverse proxy's equivalent) in front of the `/styleguide/*` path first; use `auth` for logic that genuinely needs to run inside PHP. Requests rendered inside the styleguide's own iframe are re-typed to `type: 'render'` (with a `kind` of `'component'`/`'page'`/`'doc'`/`'foundations'`) by `Router::synthesizeEmbeddedRoute()` *before* the callable is invoked, so policies must not branch solely on `type === 'component'` — an iframe-embedded component render arrives as `type: 'render', kind: 'component'`, not `type: 'component'`. If the callable itself throws, `isAuthorized()` catches it, logs via `error_log()`, and denies the request (fail closed) rather than letting the exception (and any stack trace it carries) reach the response.

The config array shape is **`@api`**. Adding new optional keys is a minor bump. Renaming or removing keys is a major bump.

`dist_path` also exists on the config array (points `dispatchSpa()` at an alternate `dist/` directory) but is **`@internal` for tests only** (see `SpaConfigTest`) — it is not covered by SemVer and consumers must never set it.

#### `run(): void`

Inspects `$_SERVER['REQUEST_URI']` via `Router::parse()`, dispatches to the right handler, and calls `exit` on routes the package handles. Returns silently for non-`/styleguide/*` URLs — the caller's own routing continues.

Behaviour is **`@api`**. The internal dispatch table can change, but the outward effect (route detection + handler dispatch + exit semantics) is preserved.

### `Parisek\Styleguide\ComponentParser::RENDER_MODES` (`@api`)

```php
public const RENDER_MODES = ['inset', 'bleed', 'chrome', 'overlay'];
```

The canonical list of values for the `render:` YAML key. **`@api`** because the CLI (`vendor/bin/styleguide`) and downstream tooling assert against it.

### `Parisek\Styleguide\ComponentParser::normaliseRender()` (`@api`)

```php
public static function normaliseRender(mixed $value): string
```

Returns one of the four `RENDER_MODES`. Used by the CLI and any downstream consumer that wants to coerce YAML values.

### Other PHP classes & methods — `@internal`

These are public PHP visibility for autoload / framework reasons, but **not** part of the SemVer contract:

| Class | Why public-but-internal |
|---|---|
| `Router` | Implementation detail of `Styleguide::run()` |
| `Renderer` | Same |
| `AssetServer` | Same |
| `Placeholder` | Same |
| `ComponentParser` (other methods) | Wrapped by JSON API endpoints; consumer access is through the API, not direct PHP |
| `Api\ComponentsEndpoint` | Same — consumed via HTTP |
| `Api\PagesEndpoint` | Same |
| `Api\DocsEndpoint` | Same |
| `Api\FieldsEndpoint` | Same |
| `Api\HealthEndpoint` | Same — consumed via HTTP |

Refactor of internal classes (rename, split, merge, change method signatures) is free in any minor release. Consumers depending on internal classes do so at their own risk.

## YAML schemas — `@api`

### `styleguide.yaml`

The project-level config consumed by `Styleguide::__construct(['config_yaml' => …])`. Top-level keys are the contract; nested shapes are listed for each section.

| Top-level key | Required | Shape | Purpose |
|---|---|---|---|
| `project` | yes | `{ name, slug, description, locale, body_classes, favicon }` | Shown in sidebar header + render-cell title |
| `iframe` | yes for live preview | `{ css, js, fonts: [], html_class, body_class, page_wrapper_class, base_href }` | Assets injected into each component iframe. `page_wrapper_class` (optional, `''`) wraps **page** renders only in `<div class="…">` — the project's structural shell (sticky-footer flex column); empty renders no wrapper, keeping the package framework-agnostic |
| `logo` | optional | `{ main: { src, alt, label, background }, favicon: { src, alt, label, size } }` | Foundations view |
| `favicon` | optional | `{ svg, png_96, ico, apple_touch, manifest, theme_color }` | Foundations view |
| `typography` | optional | `{ fonts: [{ name, type, stylesheet, url, usage, alphabet }], headings, weights, body_sample }` | Foundations view |
| `labels` | optional | `{ logo, colors, typography, headings, font_weights, body_text, font_family, click_to_copy, copied, click_swatch }` | i18n strings for foundations view |
| `colors` | optional | `{ <name>: { name, css_variable, default, shades: { <shade>: { hex, oklch } } } }` | Foundations colour palette |

Adding new optional top-level keys or new optional sub-keys is **non-breaking**. Renaming or removing existing keys is **breaking**.

### Component YAML metadata (front comment in `<id>.twig`)

The first `{# … #}` comment in each component / page / doc Twig template is parsed as YAML.

| Key | Required | Type | Default | Purpose |
|---|---|---|---|---|
| `name` | yes | `string` | — | Human-readable label; without this, the parser drops the component |
| `category` | no | `string` | `''` | Sidebar bucket |
| `description` | no | `string` (HTML allowed) | `''` | Sidebar tooltip + Overview card |
| `weight` | no | `int` | `50` | Sort order within bucket (lower = earlier) |
| `usage` | no | `string` (comma-separated) | `''` | Cross-reference between pages and components |
| `fields` | no | recursive map | `[]` | Fields inspector view + `/api/fields` |
| `asana` / `figma` / `drupal` / `web` | no | URL string | `''` | External link chips |
| `render` | no | enum `inset \| bleed \| chrome \| overlay` | `inset` | Iframe wrapper mode |
| `styleguide` | no | flag (presence-only) | absent | Forces a separate `styleguide.twig` demo file |
| `responsive` | no | `bool` | `true` | When `false`, the SPA hides the responsive-width toolbar for this entry (use for docs or fixed-layout demos where resizing has no meaning) |
| `body_class` | no | `string` | `''` | Class string applied to the render iframe's `<body>`, merged **after** the global `iframe.body_class` (empty values dropped — no stray `class=""`). Lets a page mirror what its production layout puts on `<body>` (e.g. a dark brand background) without a styleguide-only wrapper `<div>` |

Adding new optional keys: **non-breaking**. Changing the default of `render`, or the canonical list of `render` values: **breaking** (consumers may rely on the current set).

### Component Twig file conventions — `@api`

- `<id>.twig` at `<templates_path>/component/<id>/<id>.twig` — REQUIRED. The component itself.
- `<id>/styleguide.twig` — OPTIONAL. If present, the styleguide preview renders THIS file (instead of `<id>.twig`). Used for "demo" variants with prepared context data.
- The `@component`, `@page`, `@doc`, `@macro`, `@icons`, `@images`, `@static` Twig namespaces are auto-registered when the matching directory exists under `templates_path`.

### Doc Twig file conventions — `@api`

- `<id>.twig` at `<templates_path>/doc/<id>/<id>.twig` — REQUIRED. The doc page itself.
- `<id>/styleguide.twig` — OPTIONAL. If present, the render endpoint serves THIS file instead of `<id>.twig` (same fallback pattern as components/pages).
- `templates_path/doc/` missing → `/api/docs` returns `[]`; the DOKUMENTACE sidebar group still appears (foundations + overview items remain). No error.

## Twig functions & filters — `@api`

The package registers these on its pristine Twig env (or layers them on top of a consumer-supplied env). Removing or renaming any of them is **breaking**.

| Name | Type | Purpose |
|---|---|---|
| `component_<name>(content = {})` | function | Render `@component/<name>/<name>.twig` with the given content array. Generated dynamically per-component-id discovered under `templates_path/component/` |
| `page_<name>(content = {})` | function | Same but for `@page/<name>/<name>.twig` |
| `placeholder(opts)` | function | Generate a placeholder image URL — see `Placeholder::generate()` for opts |
| `resizer(image, …tuples)` | filter | Image resize URL from variadic tuples OR orientation-keyed map (`{landscape, portrait, square}`) |
| `merge_resizer(image, mode, …tuples)` | filter | Null-safe `resizer` for optionally-empty images |
| `_x(text, context, domain)` | function | i18n shim — falls through to the project's `_x` if one is already registered |
| `__(text, domain)` / `_n(single, plural, number, domain)` / `_nx(single, plural, number, context, domain)` | function | Same i18n shim family — fall through to the project's WP-compatible translators when present |
| `_xt` / `__t` / `_nt` / `_nxt` | function | Typography-aware translation: same signatures as `_x` / `__` / `_n` / `_nx`, but the result is piped through `\|typography`. Opt-in is a one-character edit (`_x` → `_xt`) so long-form copy gets consistent typographic treatment without `\|typography` on every callsite. `is_safe: ['html']` |
| `typography(text)` | filter | Czech-aware typographic post-processing (nbsp, dashes, etc.) — from `parisek/twig-typography` |
| `create_attribute(map)` | function | HTML attribute builder — from `parisek/twig-attribute` |
| `dump(...)` | function | `symfony/var-dumper` style debug output |
| `uniqueId()` | function | Per-render unique DOM id |

## JSON API endpoints — `@api`

The SPA chrome (and any external tooling) consumes these. Response shapes are stable.

### `GET /styleguide/api/components`

Returns array of all components, one object per. Object shape:

```ts
{
  id: string;            // dir/file basename
  name: string;          // from YAML
  category: string;      // from YAML, '' if absent
  description: string;
  asana: string;
  figma: string;
  drupal: string;
  web: string;
  weight: number;        // int, default 50
  usage: string;         // CSV
  fields: object;        // recursive map mirroring YAML
  render: 'inset' | 'bleed' | 'chrome' | 'overlay';
  body_class: string;    // from YAML, '' if absent — applied to the render iframe's <body>
  responsive: boolean;   // from YAML, true unless explicitly `responsive: false`
  hasStyleguide: boolean; // true if <id>/styleguide.twig exists OR YAML has `styleguide:` key
}
```

Field order is **not** part of the contract. Adding new fields is non-breaking. Removing or renaming fields is breaking.

### `GET /styleguide/api/pages`

Same shape as `/api/components` but reads from `templates_path/page/` and the `hasStyleguide` field is interpreted accordingly. Pages may carry a `usage:` value indicating which components they use.

### `GET /styleguide/api/docs`

Same shape as `/api/pages` but reads from `templates_path/doc/`. Renders as a doc kind in the iframe (prefer `styleguide.twig`, fallback `<id>.twig`). If `templates_path/doc/` does not exist the response is `[]` — no error.

### `GET /styleguide/api/fields`

Flat list of every component / page that exposes a `fields:` map. Only components are aggregated — pages and docs are not included in `/api/fields`. Object shape:

```ts
{
  id: string;
  type: 'component' | 'page';
  name: string;
  fields: object;
}
```

### `GET /styleguide/api/health`

Diagnostics for `ComponentParser`'s per-file resilience (added alongside the `\Throwable`-catching change — see CHANGELOG). Not part of the four catalogue endpoints' bare-array shape; this one is deliberately an object.

**Response shape:**

```ts
{
  warnings: Array<{ file: string; error: string }>; // relative to templates_path; empty when nothing was skipped
  counts: { components: number; pages: number; docs: number };
}
```

## URL surface — `@api`

| Pattern | Purpose |
|---|---|
| `/styleguide/` | SPA landing (= Overview) |
| `/styleguide/component/<slug>` | SPA — component detail with iframe |
| `/styleguide/page/<slug>` | SPA — page detail |
| `/styleguide/doc/<slug>` | SPA — doc detail (DOKUMENTACE group) |
| `/styleguide/foundations` | SPA — foundations (logo/colors/typography) |
| `/styleguide/fields` | SPA — fields inspector |
| `/styleguide/overview` | SPA — Components & Pages catalog |
| `/styleguide/render/<kind>/<slug>` | Render endpoint — HTML document of a single component / page / doc in isolation (no SPA chrome); `<kind>` ∈ `component \| page \| doc \| foundations`. Accepts an additive `?theme=light\|dark` query param (whitelisted server-side, default `light`) — stamps `class="dark"` + `color-scheme: dark` on the rendered `<html>`. |
| `/styleguide/api/docs` | JSON — list of doc entries (same shape as `/api/pages`) |
| `/styleguide/api/health` | JSON — parse-resilience diagnostics (warnings + counts) |
| `/styleguide/api/<endpoint>` | JSON API endpoints (see above) |
| `/styleguide/assets/<path>` | Pre-built SPA bundle (CSS/JS) |

Adding new SPA routes or render-endpoint kinds: **non-breaking**. Removing or renaming: **breaking**.

The `Sec-Fetch-Dest: iframe` request header on `/styleguide/{component,page,foundations}/<slug>` causes the server to serve render-endpoint contents instead of the SPA shell — see `Router::synthesizeEmbeddedRoute()`. This behaviour is `@api` and consumer-observable: consumer markup linking with SPA URLs continues to work correctly inside iframes.

## CLI — `@api`

`vendor/bin/styleguide`

| Command | Purpose |
|---|---|
| `list [--type=component\|page\|doc] [--templates=<path>] [--pretty]` | List all components / pages / docs as JSON. Shape matches `/api/components` / `/api/pages` / `/api/docs`. |
| `show <id> [--type=component\|page\|doc] [--templates=<path>] [--pretty]` | Same but for a single id. |
| `--help` / `-h` | Usage |

Env: `STYLEGUIDE_TEMPLATES` overrides the default templates directory.

Adding new commands or new optional flags: **non-breaking**. Removing or renaming commands: **breaking**.

## Web-server rewrite — convention, not contract

Consumer is responsible for routing `/styleguide/*` to the project's `static/index.php`. The package does not ship `.htaccess` snippets — see README § Bootstrap for the canonical Apache + Nginx rules.

## What's NOT covered by SemVer

- `dist/` bundle contents. The SPA chrome can be rebuilt with a completely new UI shell in any minor release; only the **observable URL surface** + **JSON API shapes** are contractual. Consumers must not bundle `dist/` themselves or assume specific class names exist in the SPA HTML.
- Internal CSS class names in `dist/index.html` (`#sg-favicon`, `#sg-project-name`, `[x-data="sidebar"]`, …). These are reachable via DOM but are implementation detail.
- The structure of templates in `vendor/parisek/styleguide/templates/`. The package can rename or restructure them at any time as long as the rendered output remains semantically equivalent.
- Symbol exports from `frontend/` source. The Vite build can split / inline / rename freely.

## Tracked deprecations

(none yet — first deprecations will be filed here.)
