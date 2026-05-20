# Dark Mode for Styleguide — Design Spec

**Date:** 2026-05-20
**Branch:** `feat/dark-mode`
**Status:** Implemented (PR #13)

## Goal

Make the Styleguide chrome (sidebar, toolbar, overview, foundations backdrop, fields drawer) usable in both light and dark themes. Use Tailwind v4 native dark mode, ship a toggle in the sidebar header, persist the choice in `localStorage`, and fall back to the OS `prefers-color-scheme` setting when no choice has been made.

The iframe-rendered component previews are explicitly OUT of scope (see decision 3) — that's the consumer's domain and the consumer's theming concern.

## Decisions made during brainstorming

1. **Toggle strategy:** class-based dark mode (`<html class="dark">`), opted into via Tailwind v4 `@custom-variant dark (&:where(.dark, .dark *))`. Not media-query mode — manual override is the whole point.
2. **Toggle position:** sidebar header, next to the project name. Joins the language switcher (sidebar footer) as a global control.
3. **Iframe scope:** dark mode applies ONLY to the Styleguide's own chrome — NOT to the rendered iframe content. (Earlier draft proposed passing `?theme=` to the render endpoint and stamping `class="dark"` on the iframe `<html>`; reversed during implementation review.) Pushing the styleguide's theme into the iframe conflates two concerns: the styleguide's own UX (how the developer browses components) versus the rendered component's appearance (which is the consumer's design decision). Consumers without dark variants would receive a `.dark` html class that means nothing to their CSS; consumers WITH dark variants would have their theme decision overridden by a global SPA toggle rather than per-component intent. Clean separation: the toggle controls the chrome, the iframe content is the consumer's responsibility.
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
      var stored = localStorage.getItem('sg-theme');
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

### Iframe theme propagation — explicitly NOT done

(Earlier draft of this spec proposed pushing `?theme=` through the render endpoint into the iframe HTML. Removed during implementation per decision 3.)

The iframe stays untouched. `src/Router.php`, `src/Renderer.php`, `src/Styleguide.php`, and `templates/render-cell.twig` are unchanged on the backend. `frontend/components/preview.js` doesn't append any theme param to `iframeSrc`. Consumers that want dark mode inside their components implement it themselves — independently of the styleguide chrome's toggle.

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
  inline <head> script → reads localStorage['sg-theme'] → applies .dark on <html> (synchronous, no FOUC)
  Vite CSS loads with .dark already in place → no flash

[Alpine init]
  theme store hydrates from $persist → already matches DOM state (script + store read same key)
  matchMedia listener registers

[user clicks toggle]
  theme.cycle() → store.mode flips → Alpine.effect re-runs apply() → .dark class toggles
  $persist writes localStorage

[OS preference changes while mode==='system']
  matchMedia 'change' event → store.systemDark flips → Alpine.effect re-runs apply() → .dark toggles
```

Iframe content does NOT participate in any of this — the SPA chrome and the iframe are theming-independent.

## Error handling

- **`localStorage` blocked** (Safari private, embedded WKWebView): `try/catch` in the inline script swallows; `$persist` silently degrades; default `'system'` mode applies.
- **`matchMedia` missing** (ancient browsers): inline script's `matchMedia(...)?.matches` defaults to `false` (treated as light). The store's `init()` would throw — wrap in `try/catch` and treat as `systemDark = false`.

## Testing

### PHP (PHPUnit)

No new tests. The 44-test baseline holds — dark mode is a frontend-only concern with no backend surface area.

### Manual browser verification (per `CLAUDE.md`)

After `npm run build` in `frontend/`, verify on `https://tailwind-base.ddev.site/styleguide/`:

1. Toggle cycles through light → dark → system → light. Each click changes the SPA chrome immediately.
2. Reload preserves the chosen mode. Dark mode reload does not flash light.
3. OS dark mode → SPA shows dark when in `system`. Switch OS to light → SPA flips live without reload.
4. Iframe content is unaffected by the toggle (renders in the consumer's own theme regardless).
5. Sidebar, toolbar, description bar, link bar, fields drawer, overview view, and foundations backdrop all read clean in both themes.

## Out of scope

- **Iframe content theming** — explicitly excluded (decision 3). Consumer's domain.
- **Theming `templates/overview.twig` (foundations route content)** — that's consumer-rendered content, not SPA chrome.
- **Custom dark palette / design tokens** — Tailwind v4 default zinc scale is sufficient. No `@theme` block additions.
- **Keyboard shortcut for toggle** — out of scope for v1; can be added later (e.g. `Shift+T`).

## Risks

1. **Large mechanical diff in `index.html`** (~150 lines). Code review burden — the bulk of the PR is the zinc rewrite, which is mechanical but voluminous.
2. **The `@alpinejs/persist` localStorage key format** (`_x_<name>` with JSON-encoded value) — the FOUC script depends on this internal naming. If `@alpinejs/persist` ever changes its key scheme, the FOUC script breaks silently (no JS error, just a flash). Mitigation: comments in both the store and the inline script note the coupling.

## Implementation order

1. CSS: add `@custom-variant dark` to `styleguide.css`
2. New store: `frontend/stores/theme.js` + register in `styleguide.js`
3. Inline FOUC script in `frontend/index.html` `<head>`
4. Sidebar toggle button next to the project-name block
5. i18n keys in `cs.json` / `en.json`
6. Mechanical zinc → dark variant rewrite across `index.html` chrome
7. `npm run build`, manual browser verification

## Release notes (CHANGELOG draft)

```
### Added
- Dark mode for the styleguide chrome (sidebar, toolbar, overview, foundations backdrop, fields drawer). Toggle lives in the sidebar header and cycles light → dark → system. Choice persists in localStorage; system mode follows `prefers-color-scheme` live. The iframe-rendered component preview is intentionally NOT themed — that stays the consumer project's domain.
```
