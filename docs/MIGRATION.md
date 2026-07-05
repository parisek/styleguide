# Migration guide: replacing a hand-rolled styleguide

Three projects in the fleet ship a **bespoke** styleguide today: routing PHP
plus a `styleguide/templates/` set of chrome Twig templates, hand-rolled per
project. All three already use the convention this package expects — a
sibling `styleguide.twig` next to the component/page it demos — so migration
is mostly deletion, not rewriting.

This guide covers the common steps once, then the per-project deltas.

## Common steps

### 1. Require the package

```bash
composer require parisek/styleguide
```

### 2. Wire the bootstrap

Add this near the top of whichever public PHP file already fronts every
request, right after Twig is built and before any legacy `$prefix =
'styleguide'` routing block. In the fleet, that file is `static/index.php`
for `suys-static` and `bootstrap-base` (both wrap their public entry point
in a `static/` directory); `centrumocnichvad` has no `static/` wrapper — its
`index.php` lives directly at the repo root, alongside `templates/` and
`styleguide/`. The paths below are relative to wherever that entry file
actually lives, not to a fixed `static/` prefix:

```php
(new \Parisek\Styleguide\Styleguide([
    'templates_path' => __DIR__ . '/templates',
    'static_path'    => __DIR__,
    'config_yaml'    => __DIR__ . '/styleguide.yaml',
    'default_locale' => 'cs',
    'twig'           => $twig,   // reuse the project's Twig env — component_*()/_x()/placeholder() must resolve
    'twig_context'   => [
        'homeUrl'     => '/styleguide/',
        'templateUrl' => '',
        'langcode'    => 'cs',
    ],
]))->run();
```

`run()` exits for any `/styleguide/*` request; everything else falls through
unchanged, so the legacy router still handles the rest of the site while you
verify the new one side by side.

### 3. Minimal `styleguide.yaml`

```yaml
project:
  name: "<Project name>"

iframe:
  css: "/dist/css/style.css"
  js:  "/dist/js/script.js"
```

Every other `styleguide.yaml` block (`logo`, `colors`, `typography`,
`labels`) is optional — add them incrementally; see `README.md` §
`styleguide.yaml` for the full schema.

### 4. Web-server rewrite

```apache
RewriteRule ^styleguide(/.*)?$ /index.php [L]
```

```nginx
location /styleguide { try_files $uri /index.php?$query_string; }
```

### 5. Run the gap report

```bash
vendor/bin/styleguide lint
```

Expect a wall of `NOTICE` lines on first run — most fleet templates carry
only `name:`, and `lint` reports that as the informational, non-blocking
`empty-description` rule:

```
NOTICE  component/footer/footer.twig  No description set — sidebar tooltip and Overview card will be blank.
```

That's fine — notices don't fail CI (exit code stays `0` unless a
`WARNING`/`ERROR` finding is present; `2` is reserved for a usage/internal
error). Fix the warnings/errors first — unindexed templates
(`unindexed`), dead `styleguide:` content (`dead-styleguide-content`),
broken `usage:` refs (`broken-usage-ref`), unknown `render:` values
(`unknown-render`) — and treat the description backfill as an incremental
follow-up (see § Partial metadata is fine below).

### 6. Delete the legacy styleguide

Once `/styleguide/...` renders correctly against the package for a sample of
components, pages, and at least one 404, delete the bespoke router block and
its chrome templates (per-project paths below) and drop the legacy Twig
namespace registration for it.

---

## centrumocnichvad (Tailwind v3 + SCSS)

- Stack: Tailwind CSS 3.4 + SCSS, built to `dist/css/style.css` and a
  **second**, separate bundle `dist/css/gutenberg.css` (Gutenberg/editor
  block styles, built from `src/scss/gutenberg.scss`) — both need to load in
  the iframe, or content-heavy components (e.g. `content`) will preview
  unstyled. The legacy bespoke styleguide never loaded `gutenberg.css`
  either, so this has likely gone unnoticed:

  ```yaml
  iframe:
    css:
      - "/dist/css/style.css"
      - "/dist/css/gutenberg.css"
    js: "/dist/js/script.js"
  ```

  (`iframe.css` accepts a string or a list — see README § `styleguide.yaml`.)
- Fixtures are **mostly already compatible**: `templates/component/<name>/styleguide.twig`
  siblings already exist for several components that need sample data (e.g.
  `footer`, `alert`, `faq`) — nothing to rewrite there. Three components,
  however, carry the same dead-weight pattern described in the
  `suys-static` section below — a `styleguide:` YAML key with content, but
  **no** sibling `styleguide.twig` to render it: `404`, `content`,
  `cookieconsent`. `styleguide lint` reports each as
  `dead-styleguide-content`; fix them the same way (move the data into a
  sibling `styleguide.twig` — see the worked `breadcrumb` example below).
- Delete `styleguide/templates/` (the bespoke `styleguide-{base,layout,page,
  homepage,component,sidebar,404}.twig` set) and whatever `index.php` block
  registers the legacy `@styleguide` namespace and dispatches
  `$prefix = 'styleguide'` requests to it, once step 5's `lint` pass and a
  manual spot-check both look clean.

## suys-static (Drupal-backed Twig)

- Stack: Drupal-backed Twig, `static/templates/component/<name>/<name>.twig`
  + `static/templates/page/<name>/<name>.twig` — the same `<id>/<id>.twig`
  convention this package expects. Same as `centrumocnichvad`, this project
  also builds a second `dist/css/gutenberg.css` bundle (from
  `src/scss/gutenberg.scss`) alongside `dist/css/style.css`; list both under
  `iframe.css` for the same reason:

  ```yaml
  iframe:
    css:
      - "/dist/css/style.css"
      - "/dist/css/gutenberg.css"
    js: "/dist/js/script.js"
  ```
- **`styleguide:` content is dead weight.** Five components carry sample
  data under the front-comment `styleguide:` key with **no** sibling
  `styleguide.twig` (so nothing ever renders it beyond a bare component
  preview): `breadcrumb`, `content`, `pagination`, `cookieconsent`, `404`.
  `styleguide lint` reports each as `dead-styleguide-content`. Move the data
  into a sibling file. Concrete example —
  `static/templates/component/breadcrumb/breadcrumb.twig`:

  Before (dead YAML, front comment):
  ```yaml
  styleguide:
    content:
      items:
        - { title: "Úvod", url: '#' }
        - { title: "Služby", url: '#' }
        - { title: "Detail služby", url: '#' }
      container: "container"
  ```

  After — delete that block from the front comment, add
  `static/templates/component/breadcrumb/styleguide.twig`:
  ```twig
  {{ component_breadcrumb({
    container: 'container',
    items: [
      { title: 'Úvod', url: '#' },
      { title: 'Služby', url: '#' },
      { title: 'Detail služby', url: '#' },
    ],
  }) }}
  ```
  (`tailwind-base`'s `breadcrumb` already made this exact move — its
  `templates/component/breadcrumb/styleguide.twig` is a ready-made
  reference.)
- Several existing `styleguide.twig` siblings already use `picsum.photos`
  URLs for fixture images (`article-featured`, `jumbotron-image`, …) — see
  README § Per-template metadata / `docs/API.md` § Twig functions & filters
  for the `placeholder()` replacement (deterministic, offline SVG
  generator — no network dependency in the preview path).
- Delete `static/styleguide/` (the bespoke `styleguide-{404,page,sidebar,
  base,homepage,component,layout}.twig` set) once migrated.

## bootstrap-base (Bootstrap 5)

- Stack: Bootstrap 5 — no Tailwind assumptions needed anywhere in this
  package. Like the other two projects, this stack also builds a second CSS
  bundle from `src/scss/wordpress/gutenberg.scss` (plus `print.css` and a
  `styleguide.css` that exists purely for the bespoke chrome being deleted
  in step 6 — don't carry that one over):

  ```yaml
  iframe:
    css:
      - "/dist/css/style.css"
      - "/dist/css/gutenberg.css"
    js:  "/dist/js/script.js"
  ```
- Metadata is bare `name:` (+ occasional `description`/`weight`) across the
  fleet — that's fine as-is (see § Partial metadata is fine). Category
  backfill is optional, not a migration blocker.
- **Same dead-`styleguide:`-content pattern as `suys-static`.** Six
  components carry sample data under the front-comment `styleguide:` key
  with no sibling `styleguide.twig`: `404`, `breadcrumb`, `content`,
  `cookieconsent`, `footer`, `pagination`. Fix them the same way — see the
  `breadcrumb` worked example in the `suys-static` section above.
- The existing bespoke router (`static/index.php`) already prefers a
  sibling `styleguide.twig` and falls back to a `styleguide.content` YAML
  key — the **same** precedence this package uses — so no template changes
  are needed purely to satisfy this package's conventions. The one thing
  worth fixing while you're in there: the project's own `README.md`
  recommends `picsum.photos` for fixture images (§ *Images for
  styleguide*) — swap those for `placeholder()` per this guide's
  `suys-static` asset-migration note, since `picsum.photos` is an external
  network dependency the styleguide preview shouldn't need.
- Delete `static/styleguide/` (same `styleguide-*.twig` chrome set as the
  other two) once migrated.

## Partial metadata is fine

The package tolerates a template with **only** `name:` — it still renders,
still appears in the sidebar, still shows up in `/api/components`. What you
lose by skipping the optional keys:

| Key you skip | What you lose |
|---|---|
| `category` | Falls into the sidebar's default bucket instead of a named one. |
| `description` | Sidebar tooltip + Overview card are blank. Flagged by `lint` as `empty-description` (notice, non-blocking). |
| `weight` | Sorts at the default `50` alongside every other unweighted entry (then falls back to alphabetical). |
| `usage` | No cross-reference chips on that entry's preview. |
| `render` | Defaults to `inset` (24px-padded wrapper) — wrong for a hero/slider/page-chrome component, fine for everything else. |
| `fields` | No entry in the Fields inspector / `/api/fields`. |
| `styleguide` / sibling `styleguide.twig` | The bare component/page template renders directly — fine if it doesn't need CMS-shaped sample data to render meaningfully. |

None of these block adoption. Backfilling `category`/`description` at
scale is intentionally **not** part of this package — it's handled by
extending the `styleguide-render-tagger` Claude skill, a separate piece of
tooling outside this repo (tracked as item 5 under *Phase 3 — Cross-project
adoption* in
[`docs/superpowers/specs/2026-07-04-storybook-lite-2.0-design.md`](superpowers/specs/2026-07-04-storybook-lite-2.0-design.md)).
