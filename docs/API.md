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

### `Parisek\Styleguide\ComponentParser::normaliseUsage()` (`@api`)

```php
public static function normaliseUsage(mixed $value): array // list<string>
```

Coerces a `usage:` YAML value (comma-separated string, the authoring convention — or an already-array value, accepted gracefully) into the `list<string>` shape the `usage` field emits on the wire. Shared with `Cli\Linter`'s `broken-usage-ref` rule so the catalogue and the linter can never disagree about how a `usage:` value splits into ids; used by the CLI and any downstream consumer that wants the same coercion.

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
| `iframe` | yes for live preview | `{ css, js, fonts: [], html_class, body_class, page_wrapper_class, base_href }` | Assets injected into each component iframe. `page_wrapper_class` (optional, `''`) wraps **page** renders only in `<div class="…">` — the project's structural shell (sticky-footer flex column); empty renders no wrapper, keeping the package framework-agnostic. `body_class` (this site-wide class) is applied to `component`/`page` render bodies but is **skipped for `foundations` and `doc`** — both are read-heavy, package/author-owned content where a consumer's site-wide class (e.g. a dark brand background) can break readability; see the `body_class` row in *Component YAML metadata* below for the doc-specific per-entry escape hatch |
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
| `usage` | no | `string` (comma-separated) — normalised to `string[]` on the wire | `[]` | Cross-reference between pages and components |
| `fields` | no | recursive map | `[]` | Fields inspector view + `/api/fields` |
| `asana` / `figma` / `drupal` / `web` | no | URL string | `''` | External link chips |
| `render` | no | enum `inset \| bleed \| chrome \| overlay` | `inset` | Iframe wrapper mode |
| `styleguide` | no | flag (presence-only) — **legacy** | absent | Forces a separate `styleguide.twig` demo file. **Convention going forward: use a sibling `styleguide.twig`** (auto-detected, no YAML key needed) — this key exists for templates written before that convention. Content placed under it (anything beyond a bare boolean) is never read by the renderer; `vendor/bin/styleguide lint` reports it as `dead-styleguide-content`. See README § Fixtures & sample data. |
| `responsive` | no | `bool` | `true` | When `false`, the SPA hides the responsive-width toolbar for this entry (use for fixed-layout demos where resizing has no meaning). **Ignored for `doc` entries** — a doc is prose, never a widget meant to be previewed at different breakpoints, so `ComponentParser` forces `responsive: false` for every doc regardless of this key; even an explicit `responsive: true` in a doc's front-comment has no effect |
| `body_class` | no | `string` | `''` | Class string applied to the render iframe's `<body>`, merged **after** the global `iframe.body_class` (empty values dropped — no stray `class=""`). Lets a page mirror what its production layout puts on `<body>` (e.g. a dark brand background) without a styleguide-only wrapper `<div>`. For `doc` entries this is the *only* body class that ever applies — `render-cell.twig` skips the global `iframe.body_class` for docs (readability guard, same rationale as `foundations`), but keeps honouring this per-entry key as the author's explicit escape hatch — see `iframe.body_class` in the `styleguide.yaml` table above |
| `variants` | no — **legacy fallback** | map `<variant-id>: <title-string \| {title?, label?, description?}>` | `[]` (absent) | Legacy display-metadata map for auto-discovered `styleguide.<variant>.twig` sibling files — see *Component Twig file conventions*. **The primary authoring convention is a `{# title: … description: … #}` front-comment annotation directly in the sibling file itself** (same convention as this table); this map only exists for variants that predate that convention or haven't been migrated yet. Each entry accepts either a plain string (the title, original shape) or a map with optional `title`, `label` (legacy alias for `title` — `title` wins when both are present), and `description` keys; a non-string/non-map value (or a missing entry) falls back to the id as title and an empty description — never throws. Filesystem is canonical: an entry with no matching sibling file is ignored, never fabricates a variant. A malformed sibling annotation degrades the same way — it never fails the whole component, it just falls through to this map, then to the id. |

Adding new optional keys: **non-breaking**. Changing the default of `render`, or the canonical list of `render` values: **breaking** (consumers may rely on the current set).

> **Extending external links.** `asana`/`figma`/`drupal`/`web` are four flat, individually-named keys — a deliberate closed set. Any FUTURE external-link type should NOT become a fifth flat key; add a generic `links: { <key>: <url> }` map instead, so the wire shape (and the chip-rendering code that walks it) grows by adding map entries rather than by repeating the same four-field pattern a fifth time.

### Component Twig file conventions — `@api`

- `<id>.twig` at `<templates_path>/component/<id>/<id>.twig` — REQUIRED. The component itself.
- `<id>/styleguide.twig` — OPTIONAL. If present, the styleguide preview renders THIS file (instead of `<id>.twig`). Used for "demo" variants with prepared context data.
- `<id>/styleguide.<variant>.twig` — OPTIONAL, zero or more. `<variant>` matches `[a-z0-9-]+`. Auto-discovered (no YAML required); when at least one exists, `?variant=<id>` becomes a valid query param on the SPA deep link and the render endpoint, and the SPA preview area renders a grid of independent tiles — one per variant (default fixture first) — instead of a single preview; see the render endpoint row below for the render endpoint's own (SPA-independent) `?variant=` semantics. Plain `styleguide.twig` remains the implicit default variant. Display metadata (`title`, `description`) is authored directly in the sibling's own first `{# … #}` comment — the same convention every component/page front-comment already uses:

  ```twig
  {#
  title: "Dark background"
  description: "Same hero, tuned for a dark section background."
  #}
  <div class="hero hero--dark">…</div>
  ```

  A sibling with no annotation (or one that fails to parse) falls back to the component's legacy `variants:` map entry for that id, then to the id itself — see the `variants` row in *Component YAML metadata* above.
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
| `placeholder(opts)` | function | Generate a placeholder image URL — see README § Fixtures & sample data for the main option table (`subject`, `mood`, `seed`, `width`, `height`, `aspect`, `label`) and migration examples away from `picsum.photos`-style URLs. Three finishing options exist beyond that table: `grain` (bool, default `true` — film-grain overlay), `vignette` (bool, default `true` — edge darkening), `alt` (string, default `"<subject> placeholder"`). |
| `resizer(image, …tuples)` | filter | Image resize URL from variadic tuples OR orientation-keyed map (`{landscape, portrait, square}`) |
| `merge_resizer(image, mode, …tuples)` | filter | Null-safe `resizer` for optionally-empty images |
| `cachebust(url)` | filter | Appends `?v=<filemtime>` (or `&v=…` if the URL already has a query string) to a root-relative URL that resolves to a real file, walking up from `static_path` to find it. Non-string, empty, non-root-relative, or unresolvable URLs pass through unchanged. Used internally on `iframe.css` / `iframe.js` / `iframe.fonts[]`, also callable from any component template |
| `format_date(timestamp, type, format)` | filter | Locale-light date formatter. Default output is `j. n. Y` (Czech short date); pass `type: 'custom'` with a `format` for any PHP `date()` pattern. Accepts an int timestamp or a string `strtotime()` can parse; unparseable strings are returned unchanged |
| `custom_price_format(value)` | filter | Formats a `{ number, currency_code }` map into the project's canonical price string (`CZK` → `1 234 Kč`, `EUR` → `€ 1 234,56`); any other currency code returns the raw `number` unchanged |
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
  usage: string[];       // normalised from the YAML comma-separated `usage:` string (or an already-array YAML value) by ComponentParser::normaliseUsage() — see § PHP API
  fields: object;        // recursive map mirroring YAML
  render: 'inset' | 'bleed' | 'chrome' | 'overlay';
  body_class: string;    // from YAML, '' if absent — applied to the render iframe's <body>
  responsive: boolean;   // from YAML, true unless explicitly `responsive: false`; ALWAYS false for /api/docs entries regardless of YAML — see § Component YAML metadata
  has_styleguide: boolean; // true if <id>/styleguide.twig exists, OR YAML has `styleguide:` key, OR (additive, v1.1.0) at least one styleguide.<variant>.twig sibling exists — a component may ship ONLY named variants with no bare default and still surface as a renderable entry
  has_default_variant: boolean; // additive (v1.1.0). true only when <id>/styleguide.twig itself exists on disk — narrower than has_styleguide above, which also goes true from the legacy `styleguide:` flag or from named variants alone. The SPA's variant grid uses this (not has_styleguide) to decide whether to show a synthetic "Default" tile
  variants: Array<{ id: string; title: string; description: string }>; // [] when no sibling styleguide.<variant>.twig files exist; title/description come from the sibling's own front-comment annotation first, falling back to the component's legacy `variants:` map, then to the id (title only)
}
```

Field order is **not** part of the contract. Adding new fields is non-breaking. Removing or renaming fields is breaking.

`/api/pages` and `/api/docs` inherit the identical additive `variants` and `has_default_variant` fields (already true by construction — same `normaliseMetadata()`).

### `GET /styleguide/api/pages`

Same shape as `/api/components` but reads from `templates_path/page/` and the `has_styleguide` field is interpreted accordingly. Pages may carry a `usage:` value indicating which components they use.

### `GET /styleguide/api/docs`

Same shape as `/api/pages` but reads from `templates_path/doc/`. Renders as a doc kind in the iframe (prefer `styleguide.twig`, fallback `<id>.twig`). If `templates_path/doc/` does not exist the response is `[]` — no error.

Two fields are special-cased for this endpoint: `responsive` is always `false` (the YAML key is ignored — see § Component YAML metadata), and the render iframe's `<body>` never receives the consumer's global `iframe.body_class` (the per-entry `body_class` YAML key still applies) — see the `body_class` rows in § YAML schemas.

### `GET /styleguide/api/fields`

Flat list of every component / page that exposes a `fields:` map. Only components are aggregated — pages and docs are not included in `/api/fields`. Object shape:

```ts
{
  component_id: string;
  component_name: string;
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
| `/styleguide/render/<kind>/<slug>` | Render endpoint — HTML document of a single component / page / doc in isolation (no SPA chrome); `<kind>` ∈ `component \| page \| doc \| foundations`. Accepts an additive `?theme=light\|dark` query param (whitelisted server-side, default `light`) — stamps `class="dark"` + `color-scheme: dark` on the rendered `<html>`. Also accepts an additive `?variant=<id>` query param (whitelisted server-side against `^[a-z0-9-]+$`) for `component \| page \| doc` kinds — resolves `styleguide.<id>.twig` in place of the default `styleguide.twig` when that file exists; absent, invalid, or unknown-but-well-formed values silently fall back to the default `styleguide.twig` → `<slug>.twig` chain (never a 404), so a bookmarked deep link survives a deleted/renamed variant. Query-only — no cookie fallback, unlike `theme`. Composes independently with `?theme=`. This endpoint always renders exactly ONE block regardless of how many variants an entry has — no `?variant=` means the default fixture, a resolvable `?variant=<id>` means that one variant, full stop; there is no server-side "show every variant" response. The SPA is what assembles multiple isolated renders (one iframe per tile, each hitting this same endpoint with its own `?variant=`) into the variant grid described in *Component Twig file conventions* above. Also accepts an additive, **presence-based** `?canvas` query param — any presence of the key (`?canvas`, `?canvas=1`, `?canvas=`, …; the value is never inspected) suppresses the standalone back-bar the render endpoint otherwise shows when its document is the top-level window, so the SPA's own "Canvas" toolbar action can render a truly clean, full-viewport document. Absent → bar shows (top-level) or stays hidden (embedded in an iframe, unaffected either way). Composes independently with `?theme=`/`?variant=`. |
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
| `lint [--type=component\|page\|doc] [--format=text\|json] [--templates=<path>] [--pretty]` | Report metadata quality issues (unindexed templates, dead `styleguide:` content, broken `usage:` refs, unknown `render:` values, empty descriptions). See README § Command-line catalogue. |
| `--help` / `-h` | Usage |

`lint` has its own exit-code contract, distinct from `list`/`show`: `0`
clean (or notice-only findings), `1` when a `warning`/`error` finding is
present, `2` on a usage/internal error (bad flag, templates dir not
found). `list` and `show` keep their existing `0`/`1` contract.

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
