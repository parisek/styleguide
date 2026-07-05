# Styleguide 2.0 — "Storybook lite" optimization roadmap

Date: 2026-07-04
Status: approved (design), pending implementation planning

## Context

`parisek/styleguide` (v0.6.5) turns a tree of Twig component templates into a
browsable styleguide. A deep review of the package, its reference consumer
(`tailwind-base`), and the wider fleet of agency projects surfaced four
investment areas. The user ranked them: **maintainability → backend
robustness → cross-project adoption → Storybook-parity features**, and set two
hard constraints:

1. **Full backward compatibility with the existing Twig format.** No consumer
   template is rewritten. Every new capability is additive and opt-in (new
   optional YAML keys, new optional sibling files, new query parameters).
   Existing conventions — first-comment YAML metadata, sibling
   `styleguide.twig` fixtures, `render:` modes — keep working byte-for-byte.
2. **No live prop-editing controls** in this iteration. Fixture data stays in
   `styleguide.twig` files. Variants + dark mode are the Storybook-parity
   scope.

### Key review findings driving this design

Package code (`src/`, `frontend/`):

- `README.md`/`docs/API.md` document a `?theme=light|dark` render parameter
  that has **no implementation** anywhere in the code (doc drift).
- The iframe is hard-locked to light (`color-scheme: light` in
  `render-cell.twig`); no dark-mode preview exists.
- `Styleguide::dispatchSpa()` patches `dist/index.html` with 6 regex
  substitutions that silently no-op on non-match.
- Helper registration detects "already registered" by matching Twig's
  exception message text (`src/Styleguide.php:666,680`) — version-fragile,
  untested.
- A component that throws during render returns **HTTP 200** with error
  markup; only 404 sets a non-200 status.
- `ComponentParser::parseTwigComment()` catches only YAML `ParseException`;
  any other `Throwable` from one bad template 500s the whole
  `/api/components` catalogue.
- ~2,500 lines of non-trivial Alpine JS (viewport math, prefix tree, search)
  have **zero unit tests**; browser e2e is local-only (excluded from CI);
  CI never verifies that committed `dist/` is reproducible from `frontend/`.
  Recent bugs 0.4.2 (silently broken `x-if` expression), 0.6.3 (orientation
  axis), 0.6.4 (pages tree missing shared logic) are the type this stack
  invites.

Reference consumer (`tailwind-base`, 25 components + 10 pages):

- Two competing demo-content mechanisms confuse authors: the `styleguide:`
  YAML key (presence-only flag) vs. the sibling `styleguide.twig` file
  (what the renderer actually prefers). `breadcrumb` carries dead sample
  data under the YAML key; `cookieconsent` has the flag but no demo file.
- Variants are hand-rolled inside `styleguide.twig` (e.g. `alert` loops 4
  message types) — no first-class concept, no deep links.
- Hard-to-preview components: the `header` family (demo data lives in
  unindexed `page/_partials/header.twig`), client-state-dependent components
  (`cookieconsent`, `header-announcement`), viewport-unit components
  (`project-slider` `h-svh` resolves against the iframe box).

Fleet survey (`~/Sites`):

- Three concrete adoption targets each run a bespoke hand-rolled styleguide
  the package should retire: **centrumocnichvad** (~34 components, Tailwind
  v3 + SCSS), **suys-static** (43, Drupal-backed Twig), **bootstrap-base**
  (46, Bootstrap 5). All three already use a sibling-fixture convention
  compatible with `styleguide.twig`.
- Most fleet templates carry only `name:` — the package must tolerate
  partial metadata gracefully (it largely does) and offer a lint/backfill
  path.
- Components depend on globally loaded third-party JS (Swiper, GSAP,
  lightgallery, cookieconsent), not just Alpine `x-data` — the iframe must
  keep loading the full project JS bundle (it does; keep it that way).
- Non-Twig / non-componentized projects (static HTML, Vue SPAs, raw PHP) are
  out of scope for the package; they need componentization first.

## Deliverables — four phases, four releases

### Phase 1 — SPA rewrite: Vue 3 + Pinia (v0.7.0)

Migration strategy: **big-bang 1:1 parity rewrite** on one branch, no new
features mixed in. The SPA is `@internal`, so consumers see only a new
`dist/` bundle — no BC break. (The incremental "Vue islands inside the
Alpine shell" alternative was rejected: months of dual stack and duplicated
stores for a ~2,500-line internal tool.)

Structure:

```
frontend/
├─ src/
│  ├─ App.vue
│  ├─ components/      # Sidebar, PreviewPane, ViewportToolbar,
│  │                   # SearchPalette, FieldsDrawer, Foundations, Overview…
│  ├─ stores/          # Pinia, 1:1 with today's Alpine stores
│  │  ├─ catalog.js    # components + pages + docs (today: components.js)
│  │  ├─ ui.js
│  │  └─ i18n.js
│  └─ lib/             # pure framework-free functions, each unit-tested
│     ├─ prefixTree.js
│     ├─ viewportMath.js   # zoom / rotation / fit-to-bounds
│     └─ searchMatch.js    # NFKD diacritic folding
└─ vite.config.js      # + @vitejs/plugin-vue
```

PHP↔SPA joint replaced: `dist/index.html` ships
`<script id="sg-config" type="application/json">{}</script>`; PHP injects
config (locale, favicon, project name, title, base_url) into that one
element with a single tested substitution; Vue reads it at boot. This
retires all 6 regex patches in `dispatchSpa()`. Tested on both sides (PHP
test asserts the substitution; a Vitest test asserts the boot reader).

Testing/CI additions:

- Vitest for `src/lib/` + stores in CI.
- Port the local-only `agent-browser` e2e suite to **Playwright** running
  headless in GitHub Actions (parity checklist: viewport presets,
  drag-resize, rotation, prefix tree, search, locale, theme, deep links,
  canvas mode, fields drawer, standalone back-bar).
- New CI job: `npm ci && npm run build && git diff --exit-code dist/` —
  committed `dist/` must be reproducible from `frontend/` source.

Parity is verified against `tailwind-base` via the existing path-repository
symlink workflow before release.

### Phase 2 — Backend robustness (v0.7.x)

1. **Implement `?theme=light|dark`** on the render endpoint (whitelisted in
   `Router`), stamping `class="dark"` + matching `color-scheme` on the
   iframe `<html>`. SPA toolbar gets an iframe-theme toggle independent of
   the chrome theme. Projects without dark-mode CSS are unaffected — the
   class is inert. Closes the doc-drift finding by implementing the
   documented contract.
2. **Render-time exception → HTTP 500** (error markup stays visible).
   `render404()` keeps 404. Health checks stop seeing 200 for broken
   components. Covered by a new `RendererTest` for the throw path
   (currently unreachable from any test).
3. **`ComponentParser` catches `\Throwable` per file** — a pathological
   template is skipped instead of killing the whole catalogue; skipped
   files surface in an additive `_warnings` field on API responses, shown
   unobtrusively by the SPA.
4. **Helper registration stops matching exception message text** — catch
   `LogicException` from `addFunction()`/`addFilter()` generally; add a
   test pinning the contract (a project-pre-registered helper wins).
5. Smaller items: correct MIME for `.map` files in `AssetServer`; warn when
   `dist/foundations.*.css` globs more than one match; document a
   basic-auth recommendation for publicly reachable deployments and add an
   optional `auth` config key (callable returning bool; `false` → 403)
   for programmatic gating.
6. **Docs sync pass**: audit `docs/API.md` + `README.md` against the code;
   archive CHANGELOG entries older than the last few minor series into
   `CHANGELOG-archive.md`.

### Phase 3 — Cross-project adoption (v0.8.0)

1. **Sibling `styleguide.twig` becomes the official fixture convention**
   (the renderer already prefers it). The `styleguide:` YAML key stays
   functional as a presence-only flag (BC), but content under it is dead
   weight — which lint now reports.
2. **New CLI command `styleguide lint`** reporting: templates without
   `name:` (thus unindexed), `styleguide:` keys carrying never-read
   content, `usage:` references to nonexistent ids, unknown `render:`
   values, empty `description` strings. Non-zero exit code for consumer CI.
3. **`docs/MIGRATION.md`** — "replace your hand-rolled styleguide" guide
   targeting centrumocnichvad / suys-static / bootstrap-base: bootstrap
   snippets for Tailwind v3 + SCSS and Bootstrap 5 stacks, mapping their
   existing conventions onto the package.
4. **Fixture image convention documented**: `placeholder()` (deterministic,
   offline) instead of picsum.photos URLs.
5. Metadata backfill (category/description) is delivered as an extension of
   the existing `styleguide-render-tagger` Claude skill, not package code.

### Phase 4 — Variants + Storybook features (v0.9.0)

1. **File-convention variants** (zero YAML required, mirrors the existing
   sibling habit): auto-discover `styleguide.<variant>.twig` siblings
   (e.g. `styleguide.secondary.twig`). When present, the toolbar shows a
   variant switcher; deep link `/component/<id>?variant=<v>`; render URL
   `render/component/<id>?variant=<v>`. Optional YAML `variants:` map
   provides display labels (filename fallback). Plain `styleguide.twig`
   remains the default variant — existing templates unchanged.
2. **Search upgrade**: keyboard-navigable command palette (arrows + Enter),
   matching across name, id, description, and category.
3. **On-demand a11y check**: a toolbar action injects axe-core into the
   iframe and lists findings in a panel. No CI integration, no template
   impact.

## Non-goals (explicitly out of scope)

- Live prop-editing controls (Storybook knobs) and the render-with-user-input
  endpoint they would require.
- Visual regression testing / screenshot diffing.
- HMR for consumer template changes (PHP request-per-render model stands).
- Support for non-Twig or non-componentized projects.

Nothing in this design forecloses adding these later.

## Compatibility contract

- No change to any `@api` PHP surface, YAML schema semantics, JSON endpoint
  shape (only additive fields like `_warnings`), URL surface (only additive
  query params `theme`, `variant`), or Twig conventions.
- `dist/` bundle contents change wholesale in Phase 1; that surface is
  `@internal` and not covered by SemVer per `docs/API.md`.
- Each phase lands with docs updated in the same PR per the AGENTS.md
  documentation gate, and a `CHANGELOG.md` entry.

## Follow-ups (tracked, not executed by this plan)

- **Metadata backfill (category/description) via `styleguide-render-tagger`.**
  Phase 3 item 5 of this roadmap. The skill lives at the user level
  (`~/.claude/skills/styleguide-render-tagger/`), outside this git
  repository, so it cannot be extended by a plan scoped to
  `parisek/styleguide`. Recorded here so the roadmap item isn't silently
  dropped: someone with access to the user-level skills directory needs to
  extend `styleguide-render-tagger` to also propose `category:`/
  `description:` backfills (using the same "classify, present a table,
  apply on approval" pattern it already uses for `render:` tagging),
  likely fed by the gap list `vendor/bin/styleguide lint`'s
  `empty-description` findings now produce (see Task 1 of the Phase 3
  adoption plan, `docs/superpowers/plans/2026-07-04-phase-3-adoption.md`).
  **Not executable inside this repo** — no code or doc change here can
  close this item.
