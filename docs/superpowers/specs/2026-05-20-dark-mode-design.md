# Dark Mode for Styleguide — Design Spec

**Date:** 2026-05-20
**Branch:** `feat/dark-mode`
**Status:** Draft — pending implementation

## Goal

Make the entire Styleguide (SPA chrome + iframe-rendered component previews) usable in both light and dark themes. Use Tailwind v4 native dark mode, ship a toggle in the sidebar header, persist the choice in `localStorage`, and fall back to the OS `prefers-color-scheme` setting when no choice has been made.

## Decisions made during brainstorming

1. **Toggle strategy:** class-based dark mode (`<html class="dark">`), opted into via Tailwind v4 `@custom-variant dark (&:where(.dark, .dark *))`. Not media-query mode — manual override is the whole point.
2. **Toggle position:** sidebar header, next to the project name. Joins the language switcher (sidebar footer) as a global control.
3. **Iframe propagation:** SPA passes `?theme=light|dark` to the render endpoint as a query parameter. `render-cell.twig` stamps `class="dark"` on `<html>` accordingly. Consumers that use Tailwind dark mode pick it up for free; consumers that don't are unaffected.
4. **Three-state toggle:** `light` / `dark` / `system`. The `system` state is the default and live-reacts to `matchMedia('(prefers-color-scheme: dark)')` changes.

## Architecture

### Theme store (`frontend/stores/theme.js`) — new file

Single source of truth for the user's theme preference and the currently-applied theme.

```js
Alpine.store('theme', {
    mode: Alpine.$persist('system').as('sg-theme'),  // 'light' | 'dark' | 'system'
    systemDark: false,                                // mirror of matchMedia

    init() {
        const mq = window.matchMedia('(prefers-color-scheme: dark)');
        this.systemDark = mq.matches;
        mq.addEventListener('change', (e) => { this.systemDark = e.matches; });
        Alpine.effect(() => this.apply());
    },

    get resolved() {
        return this.mode === 'system' ? (this.systemDark ? 'dark' : 'light') : this.mode;
    },

    apply() {
        document.documentElement.classList.toggle('dark', this.resolved === 'dark');
    },

    cycle() {
        const next = { light: 'dark', dark: 'system', system: 'light' };
        this.mode = next[this.mode];
    },
});
```

The `Alpine.effect` wrapper means the class on `<html>` re-syncs automatically whenever `mode` or `systemDark` changes — no manual wiring needed downstream.

### FOUC prevention — inline `<head>` script

`Styleguide::dispatchSpa()` (or whichever path renders `frontend/index.html` for the consumer) injects a tiny synchronous script in `<head>`, BEFORE the stylesheet link:

```html
<script>
  (function() {
    try {
      var stored = localStorage.getItem('_x_sg-theme');
      var mode = stored ? JSON.parse(stored) : 'system';
      var dark = mode === 'dark' || (mode === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
      if (dark) document.documentElement.classList.add('dark');
    } catch (e) {}
  })();
</script>
```

Note: `@alpinejs/persist` stores values as JSON, hence the `JSON.parse`. The key prefix is `_x_` (Alpine's namespace) + the `.as()` name. This script runs in <1 ms and prevents the flash of light SPA on reload when the user has dark selected.

Open question: this script can either live inside `frontend/index.html` directly (it's a static SPA shell) or be injected by PHP. Static is simpler — putting it in `frontend/index.html` means it ships in the Vite build as part of `dist/index.html`. **Decision: put it in `frontend/index.html` directly.**

### Tailwind v4 dark variant config

`frontend/styleguide.css`, near the top after `@import "tailwindcss"`:

```css
@custom-variant dark (&:where(.dark, .dark *));
```

This rewires `dark:*` utilities to react to the `.dark` class instead of the default media query.

### Toggle UI — sidebar header

Replaces the current static project name block. Sun / moon / monitor icon swap (Heroicons mini, inline SVG):

- `mode === 'light'`: sun icon — "Light theme, click to switch to dark"
- `mode === 'dark'`: moon icon — "Dark theme, click to switch to system"
- `mode === 'system'`: monitor icon — "Following OS, click to switch to light"

Single button, cycles through the three states on click. `title` and `aria-label` come from i18n.

### SPA chrome — class rewrites

Mechanical pass over `frontend/index.html` (~150 lines touched). Pattern:

| Current (dark-only)    | After (dual-mode)                              |
|------------------------|------------------------------------------------|
| `bg-zinc-950`          | `bg-white dark:bg-zinc-950`                    |
| `bg-zinc-900`          | `bg-zinc-50 dark:bg-zinc-900`                  |
| `bg-zinc-900/40`       | `bg-zinc-100/60 dark:bg-zinc-900/40`           |
| `bg-zinc-800`          | `bg-zinc-200 dark:bg-zinc-800`                 |
| `border-zinc-800`      | `border-zinc-200 dark:border-zinc-800`         |
| `border-zinc-700`      | `border-zinc-300 dark:border-zinc-700`         |
| `text-zinc-100`        | `text-zinc-900 dark:text-zinc-100`             |
| `text-zinc-300`        | `text-zinc-700 dark:text-zinc-300`             |
| `text-zinc-400`        | `text-zinc-600 dark:text-zinc-400`             |
| `text-zinc-500`        | `text-zinc-500 dark:text-zinc-500` (no change) |
| `text-zinc-600`        | `text-zinc-400 dark:text-zinc-600`             |
| `hover:bg-zinc-800`    | `hover:bg-zinc-100 dark:hover:bg-zinc-800`     |
| `hover:text-zinc-100`  | `hover:text-zinc-900 dark:hover:text-zinc-100` |

The overview view (currently light-only, `bg-zinc-50` / `text-zinc-900`) needs its own dark variants — it's the inverse: today it's `bg-zinc-50`, becomes `bg-zinc-50 dark:bg-zinc-900`. Accent badges (`bg-violet-100 text-violet-600`, `bg-amber-100 text-amber-700`, etc.) get dark-mode counterparts like `dark:bg-violet-500/20 dark:text-violet-300`.

The description bar component styles in `styleguide.css` also get dark variants:

```css
.sg-description-bar a {
    @apply text-zinc-700 underline underline-offset-2 decoration-zinc-400 transition-colors
           dark:text-zinc-200 dark:decoration-zinc-600;
}
.sg-description-bar a:hover {
    @apply text-zinc-900 decoration-zinc-700 dark:text-zinc-50 dark:decoration-zinc-300;
}
```

### Iframe theme propagation — backend

**`src/Router.php`** — extend the `?theme=` query parsing:

```php
$theme = $_GET['theme'] ?? null;
if (!in_array($theme, ['light', 'dark'], true)) {
    $theme = null;
}
```

Whitelist enforced at parse time. `null` means "let the consumer's own default win" — no class is stamped.

**`src/Renderer.php`** — accept the `theme` value and pass it into the render context for `render-cell.twig` (and equivalents for foundations / page routes). Each call site that renders the iframe HTML wrapper gains a `theme` key in its context array.

**`templates/render-cell.twig`** — opening `<html>` tag:

```twig
<html lang="{{ locale }}"{% if theme == 'dark' %} class="dark"{% endif %}>
```

### Iframe theme propagation — frontend

**`frontend/components/preview.js`** — when computing `iframeSrc`, append the resolved theme as a query param:

```js
get iframeSrc() {
    // existing logic ...
    const url = new URL(rawSrc, location.origin);
    url.searchParams.set('theme', Alpine.store('theme').resolved);
    return url.toString();
}
```

`Alpine.effect` already re-renders the iframe `src` binding when `resolved` flips → iframe gets a new URL → browser reloads it → new `class="dark"` on iframe `<html>`. Brief flash (~100-200ms cached), accepted tradeoff vs. a JS messaging dance that would only work for consumers that opt into a listener.

### i18n — new keys

`frontend/public/locales/cs.json` and `en.json` gain:

```json
"theme": {
    "light": "Světlé téma",
    "dark": "Tmavé téma",
    "system": "Podle systému",
    "toggle": "Přepnout vzhled"
}
```

(English equivalents in `en.json`.)

## Data flow

```
[boot]
  inline <head> script → reads localStorage['_x_sg-theme'] → applies .dark on <html> (synchronous, no FOUC)
  Vite CSS loads with .dark already in place → no flash

[Alpine init]
  theme store hydrates from $persist → already matches DOM state (script + store read same key)
  matchMedia listener registers

[user clicks toggle]
  theme.cycle() → store.mode flips → Alpine.effect re-runs apply() → .dark class toggles
  $persist writes localStorage
  preview.js iframeSrc effect re-runs → iframe src changes → iframe reloads with new ?theme= → render-cell.twig stamps new class

[OS preference changes while mode==='system']
  matchMedia 'change' event → store.systemDark flips → Alpine.effect re-runs apply() → .dark toggles
  iframeSrc re-renders if resolved value changed
```

## Error handling

- **`localStorage` blocked** (Safari private, embedded WKWebView): `try/catch` in the inline script swallows; `$persist` silently degrades; default `'system'` mode applies.
- **`matchMedia` missing** (ancient browsers): inline script's `matchMedia(...)?.matches` defaults to `false` (treated as light). The store's `init()` would throw — wrap in `try/catch` and treat as `systemDark = false`.
- **Invalid `?theme=` value**: Router whitelist rejects it; no class stamped on iframe `<html>`.
- **Consumer overrides `<html>` class in their own template**: out of scope — they take precedence by virtue of being downstream.

## Testing

### PHP (PHPUnit)

- `tests/RouterTest.php` — new tests:
  - `?theme=dark` parses to `theme === 'dark'`
  - `?theme=light` parses to `theme === 'light'`
  - `?theme=foobar` parses to `theme === null` (whitelist)
  - missing `theme` parses to `theme === null`

- `tests/RendererTest.php` — new tests:
  - `renders_with_dark_class_when_theme_is_dark` — assert iframe HTML contains `<html lang="…" class="dark">`
  - `renders_without_dark_class_when_theme_is_light` — assert iframe HTML contains `<html lang="…">` with no `class="dark"`
  - `renders_without_dark_class_when_theme_is_null` — same as above

### Manual browser verification (per `CLAUDE.md`)

After `npm run build` in `frontend/`, verify on `https://tailwind-base.ddev.site/styleguide/`:

1. Toggle cycles through light → dark → system → light. Each click changes the SPA chrome immediately.
2. Reload preserves the chosen mode. Dark mode reload does not flash light.
3. OS dark mode → SPA shows dark when in `system`. Switch OS to light → SPA flips live without reload.
4. Iframe reloads with new `class="dark"` on each toggle (verifiable via DevTools → iframe inspect).
5. Open-in-new-tab link (`render-cell.twig` standalone) inherits the current theme via the query param.
6. Sidebar, toolbar, description bar, link bar, fields drawer, overview view, and foundations view all read clean in both themes.

## Out of scope

- **Theming `templates/overview.twig` (foundations route content)** — that's consumer-rendered content, not SPA chrome. If the consumer wants dark foundations, they handle it via their own Tailwind dark utilities and read `class="dark"` we pass down.
- **Custom dark palette / design tokens** — Tailwind v4 default zinc scale is sufficient. No `@theme` block additions.
- **postMessage-based iframe theming** — rejected during brainstorming in favor of query param. Reload flash is acceptable.
- **Keyboard shortcut for toggle** — out of scope for v1; can be added later (e.g. `Shift+T`).

## Risks

1. **Large mechanical diff in `index.html`** (~150 lines). Code review burden; consider splitting into two commits: "wire up dark mode infrastructure" (store + CSS + script + toggle UI + backend + tests) and "rewrite chrome utilities for dark variants" (the mechanical zinc-rewrite). One-PR is fine, two commits inside it helps reviewers.
2. **Iframe flash on toggle** (~100-200ms). Cached, but visible. Accepted tradeoff.
3. **Consumer with conflicting `.dark` class** — extremely unlikely, but possible. Documented as a known interaction in CHANGELOG when shipped.
4. **The `@alpinejs/persist` localStorage key format** (`_x_<name>` with JSON-encoded value) — the FOUC script depends on this internal naming. If `@alpinejs/persist` ever changes its key scheme, the FOUC script breaks silently (no JS error, just a flash). Mitigation: add a comment to both the store and the inline script noting the coupling.

## Implementation order

1. CSS: add `@custom-variant dark` to `styleguide.css`
2. New store: `frontend/stores/theme.js` + register in `styleguide.js`
3. Inline FOUC script in `frontend/index.html` `<head>`
4. Sidebar toggle button (replacing/extending the project-name block)
5. i18n keys in `cs.json` / `en.json`
6. Mechanical zinc → dark variant rewrite across `index.html` chrome
7. Backend: Router whitelist + Renderer context + `render-cell.twig` class stamp
8. PHP tests for Router + Renderer
9. Frontend preview.js: append `?theme=` to iframeSrc
10. `npm run build`, manual browser verification

## Release notes (CHANGELOG draft)

```
### Added
- Dark mode for the entire styleguide chrome (sidebar, toolbar, overview, foundations, fields drawer). Toggle lives in the sidebar header and cycles light → dark → system. Choice persists in localStorage; system mode follows `prefers-color-scheme` live.
- Iframe preview receives a `?theme=light|dark` query param so consumer projects using Tailwind dark mode pick up the theme automatically via `class="dark"` on the iframe `<html>`.
```
