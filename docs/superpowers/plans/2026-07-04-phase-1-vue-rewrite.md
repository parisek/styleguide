# Phase 1: Vue 3 + Pinia SPA Rewrite Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the ~2,500-line untested Alpine.js SPA chrome (`frontend/`) with a Vue 3 + Pinia rewrite that is byte-for-byte behaviorally identical for consumers, unit-tested, and no longer patched into existence via 6 regex substitutions from PHP.

**Architecture:** Big-bang 1:1 parity rewrite on one feature branch — no Alpine/Vue dual-stack period. Pure logic (search matching, prefix-tree grouping, viewport/zoom math, fields-tree flattening, external-link resolution) moves into framework-free `src/lib/*.js` modules with Vitest coverage first; stateful concerns move into 4 Pinia stores (`catalog`, `ui`, `i18n`, `theme`) that preserve every existing `localStorage` key byte-for-byte; view chrome moves into Vue SFCs under `src/components/` and `src/views/`, wired by `vue-router`. The PHP↔SPA joint collapses from 6 silently-no-op regexes to one `<script id="sg-config" type="application/json">` substitution that throws on non-match.

**Tech Stack:** Vue 3 (Composition API, `<script setup>`), Pinia, vue-router 4, Vite 5 + `@vitejs/plugin-vue`, Tailwind v4 (`@tailwindcss/vite`, unchanged), Vitest + `@vue/test-utils` + jsdom, Playwright (new, Task 12), PHP 8.3+/PHPUnit (unchanged backend).

## Global Constraints

- Full backward compatibility with the existing Twig format — no consumer template is rewritten.
- The SPA is `@internal`; `dist/` bundle contents may change wholesale in this phase — that surface is not covered by SemVer.
- Migration strategy is 1:1 feature parity — no new features mixed into this rewrite.
- JS is ES modules, 4-space indent, no transpiler beyond what Vite ships.
- Documentation is written in English.
- Never add emoji to code, docs, or commit messages.
- Every consumer-visible change lands a `CHANGELOG.md` entry under `[Unreleased]` in the same PR as the code.
- All work happens on a feature branch off `main`; nothing here lands directly on `main`.

---

### Task 0: Branch setup

**Files:** none (git only).

- [x] **Step 1: Create the feature branch**

```bash
cd /Users/pari/Sites/styleguide
# Branch off docs/storybook-lite-2.0-spec — that local branch carries the
# approved spec and all four phase plans (not yet on main).
git checkout docs/storybook-lite-2.0-spec
git checkout -b feature/styleguide-2.0
```

All four phases execute sequentially on this ONE branch (`feature/styleguide-2.0`) — later phases build on earlier phases' code, so per-phase branches would only add merge friction. Phase boundaries are marked by their CHANGELOG commits.

- [x] **Step 2: Confirm clean baseline**

Run: `composer test && composer phpstan`
Expected: both exit 0 (23 PHPUnit tests green, 0 PHPStan errors) before any frontend change — this is the regression baseline every later PHP-touching task diffs against.

---

### Task 1: Toolchain — Vue/Pinia/vue-router/Vitest dependencies

**Files:**
- Modify: `frontend/package.json`
- Modify: `frontend/vite.config.js`
- Create: `frontend/vitest.config.js`

**Interfaces:**
- Produces: `npm run build` (unchanged output contract: `dist/index.html`, `dist/styleguide.[hash].js`, `dist/styleguide.[hash].css`, `dist/foundations.[hash].css`), `npm test` (new — `vitest run`).
- Consumes: nothing yet (no Vue source exists until Task 4).

- [x] **Step 1: Add dependencies**

Edit `frontend/package.json` to:

```json
{
    "name": "parisek-styleguide-frontend",
    "private": true,
    "type": "module",
    "version": "0.1.0",
    "scripts": {
        "build": "vite build",
        "watch": "vite build --watch",
        "test": "vitest run"
    },
    "devDependencies": {
        "vite": "^5.4.0",
        "@tailwindcss/vite": "^4.2.0",
        "tailwindcss": "^4.2.0",
        "@vitejs/plugin-vue": "^5.2.0",
        "vitest": "^2.1.0",
        "@vue/test-utils": "^2.4.0",
        "jsdom": "^25.0.0"
    },
    "dependencies": {
        "vue": "^3.5.0",
        "pinia": "^2.3.0",
        "vue-router": "^4.5.0"
    }
}
```

Note: `alpinejs`, `@alpinejs/collapse`, `@alpinejs/persist` stay in `package.json` until Task 14 (Cleanup) — removing them now would break `npm run build` for every intermediate task since `frontend/styleguide.js` still imports them until the App shell lands in Task 4.

- [x] **Step 2: Install**

Run: `cd frontend && npm install`
Expected: exit 0, `frontend/package-lock.json` updated with the 6 new packages + transitive deps.

- [x] **Step 3: Wire the Vue plugin into Vite**

Edit `frontend/vite.config.js` — add the import and register the plugin:

```js
import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    base: '/styleguide/assets/',
    plugins: [vue(), tailwindcss()],
    publicDir: 'public',
    build: {
        outDir: '../dist',
        emptyOutDir: true,
        rollupOptions: {
            input: {
                index: 'index.html',
                foundations: 'foundations.html',
            },
            output: {
                entryFileNames: 'styleguide.[hash].js',
                assetFileNames: (info) => {
                    const name = info.name ?? '';
                    if (name === 'foundations.css' || name.endsWith('/foundations.css')) {
                        return 'foundations.[hash][extname]';
                    }
                    return 'styleguide.[hash][extname]';
                },
            },
        },
    },
});
```

(Only the `import vue` line and `vue()` in the `plugins` array are new — `rollupOptions`/`base`/`publicDir` are unchanged from today, confirming the `dist/` output contract Task 4's PHP substitution and Task 13's reproducibility check both depend on.)

- [x] **Step 4: Add a standalone Vitest config**

Create `frontend/vitest.config.js` (kept separate from `vite.config.js` so `vite build` never picks up test-only config, and so `environment: 'jsdom'` doesn't leak into the production build):

```js
import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'jsdom',
        include: ['src/**/*.spec.js'],
        globals: false,
    },
});
```

- [x] **Step 5: Verify build still passes with zero Vue source**

Run: `cd frontend && npm run build`
Expected: exit 0, identical `dist/` output shape (Vue plugin has nothing to compile yet — `index.html`/`foundations.html` still reference only `styleguide.js`/`styleguide.css`).

Run: `cd frontend && npm test`
Expected: `No test files found` message, exit code 1 (Vitest's default behavior on an empty suite) — this is EXPECTED at this point; do not treat it as a task failure. Task 2 makes it pass for real.

- [x] **Step 6: Commit**

```bash
git add frontend/package.json frontend/package-lock.json frontend/vite.config.js frontend/vitest.config.js
git commit -m "build(frontend): add Vue 3, Pinia, vue-router, Vitest toolchain

Phase 1 of the Styleguide 2.0 roadmap (docs/superpowers/specs/2026-07-04-storybook-lite-2.0-design.md)."
```

---

### Task 2: Pure lib extraction — framework-free, Vitest-first

Ports the non-trivial, currently zero-test-coverage logic (search matching, prefix-tree grouping, viewport/zoom math, fields-tree flattening, external-link resolution) into `src/lib/*.js`. Every module is pure — no DOM, no Vue reactivity, no Pinia — so it is testable in plain Node/jsdom with no mounting. Deviates from the outline's 3-module list (`searchMatch`, `prefixTree`, `viewportMath`) by adding `fieldsTree.js` and `externalLinks.js`: both are pure functions duplicated 2-3× across the legacy `components/*.js` files (`flattenFieldsTree` in `preview.js`; the four-key link-filter list in `linkBar.js`, `overview.js`, and again inline in `overview.js`'s reverse/forward map builders) and are natural candidates for the same test-first treatment.

**Files:**
- Create: `frontend/src/lib/searchMatch.js` + `frontend/src/lib/searchMatch.spec.js`
- Create: `frontend/src/lib/prefixTree.js` + `frontend/src/lib/prefixTree.spec.js`
- Create: `frontend/src/lib/viewportMath.js` + `frontend/src/lib/viewportMath.spec.js`
- Create: `frontend/src/lib/fieldsTree.js` + `frontend/src/lib/fieldsTree.spec.js`
- Create: `frontend/src/lib/externalLinks.js` + `frontend/src/lib/externalLinks.spec.js`

**Porting source of truth:** `frontend/components/sidebar.js` (`matchSearch`/`filterItems`), `frontend/stores/components.js` (`buildTree`), `frontend/components/preview.js` (viewport getters: `effectiveWidth`/`effectiveHeight`/`zoom`/`isPortrait`/`setPortrait`, and `flattenFieldsTree`), `frontend/stores/ui.js` (`parseWidthParam`), `frontend/components/linkBar.js` + `frontend/components/overview.js` (`linksFor`/link-filter shape).

**Interfaces:**
- `searchMatch.js` — `normalizeForSearch(value: string|null|undefined): string`, `matchesQuery(item: {name?, id}, query: string): boolean`, `filterItems(items: Array, query: string): Array`.
- `prefixTree.js` — `GROUP_MIN = 3` (exported const), `buildTree(list: Array<{id, name?}>): Array<{type:'group', label, sortKey, children}|{type:'item', item, sortKey}>`.
- `viewportMath.js` — `VIEWPORTS` (exported array, 10 entries), `CUSTOM_WIDTH_MIN = 100`, `CUSTOM_WIDTH_MAX = 4000`, `findPresetByWidth(width: number|null)`, `parseWidthParam(raw: string|null): string|null`, `effectiveDims({width, height, rotated}): {width, height}`, `fitZoom({width, height, availWidth, availHeight}): number`, `isPortraitOrientation({width, height, rotated}): boolean`, `rotationForPortrait({width, height, portrait}): boolean`.
- `fieldsTree.js` — `flattenFieldsTree(map: object|null, depth?: number, parentPath?: string): Array<{path, key, depth, type, title, description, required}>`.
- `externalLinks.js` — `externalLinksFor(item: {asana?, figma?, drupal?, web?}|null): Array<{key, url, label}>`.

- [x] **Step 1: Write failing tests for `searchMatch.js`**

Create `frontend/src/lib/searchMatch.spec.js`:

```js
import { describe, it, expect } from 'vitest';
import { normalizeForSearch, matchesQuery, filterItems } from './searchMatch.js';

describe('normalizeForSearch', () => {
    it('folds Czech diacritics and lowercases', () => {
        expect(normalizeForSearch('Drobečková navigace')).toBe('drobeckova navigace');
    });

    it('returns empty string for null/undefined', () => {
        expect(normalizeForSearch(null)).toBe('');
        expect(normalizeForSearch(undefined)).toBe('');
    });
});

describe('matchesQuery', () => {
    it('matches a diacritics-free query against a diacritics-bearing name', () => {
        expect(matchesQuery({ id: 'header', name: 'Hlavička' }, 'hlavicka')).toBe(true);
    });

    it('matches against id when name does not match', () => {
        expect(matchesQuery({ id: 'cta-block', name: 'Výzva k akci' }, 'cta')).toBe(true);
    });

    it('is case-insensitive', () => {
        expect(matchesQuery({ id: 'hero', name: 'Hero' }, 'HERO')).toBe(true);
    });

    it('returns true for an empty/whitespace-only query (no filter)', () => {
        expect(matchesQuery({ id: 'x', name: 'X' }, '   ')).toBe(true);
    });

    it('returns false for a non-matching query', () => {
        expect(matchesQuery({ id: 'hero', name: 'Hero' }, 'footer')).toBe(false);
    });
});

describe('filterItems', () => {
    it('filters a list down to matching items only', () => {
        const items = [
            { id: 'hero', name: 'Hero' },
            { id: 'footer', name: 'Footer' },
            { id: 'header', name: 'Hlavička' },
        ];
        expect(filterItems(items, 'hlavicka').map((i) => i.id)).toEqual(['header']);
    });
});
```

- [x] **Step 2: Run and confirm the expected failure**

Run: `cd frontend && npx vitest run src/lib/searchMatch.spec.js`
Expected: fails with `Cannot find module './searchMatch.js'` (module does not exist yet).

- [x] **Step 3: Implement `searchMatch.js`**

Create `frontend/src/lib/searchMatch.js`:

```js
// Substring match against name (locale-tuned label) AND id (raw slug) so users
// can find a component by either spelling. Diacritics-insensitive via
// NFKD-normalise so "drobeckova" matches "Drobečková navigace". Ported
// verbatim from frontend/components/sidebar.js `matchSearch`/`filterItems`.

export function normalizeForSearch(value) {
    return (value ?? '')
        .toString()
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
}

export function matchesQuery(item, query) {
    const q = normalizeForSearch(query).trim();
    if (!q) return true;
    return normalizeForSearch(item?.name).includes(q) || normalizeForSearch(item?.id).includes(q);
}

export function filterItems(items, query) {
    return items.filter((item) => matchesQuery(item, query));
}
```

- [x] **Step 4: Run and confirm pass**

Run: `cd frontend && npx vitest run src/lib/searchMatch.spec.js`
Expected: `Test Files 1 passed`, `Tests 7 passed`.

- [x] **Step 5: Write failing tests for `prefixTree.js`**

Create `frontend/src/lib/prefixTree.spec.js`:

```js
import { describe, it, expect } from 'vitest';
import { buildTree, GROUP_MIN } from './prefixTree.js';

describe('GROUP_MIN', () => {
    it('is 3', () => {
        expect(GROUP_MIN).toBe(3);
    });
});

describe('buildTree', () => {
    it('groups a >=3 prefix cluster into a collapsible group node with suffix-only children', () => {
        const list = [
            { id: 'widget-one', name: 'Widget - one' },
            { id: 'widget-two', name: 'Widget - two' },
            { id: 'widget-three', name: 'Widget - three' },
        ];
        const tree = buildTree(list);
        expect(tree).toHaveLength(1);
        expect(tree[0].type).toBe('group');
        expect(tree[0].label).toBe('Widget');
        expect(tree[0].children.map((c) => c.leaf)).toEqual(['One', 'Three', 'Two']);
    });

    it('keeps a below-threshold (2-member) prefix cluster flat with full names', () => {
        const list = [
            { id: 'gadget-a', name: 'Gadget - a' },
            { id: 'gadget-b', name: 'Gadget - b' },
        ];
        const tree = buildTree(list);
        expect(tree.every((n) => n.type === 'item')).toBe(true);
        expect(tree.map((n) => n.item.name)).toEqual(['Gadget - a', 'Gadget - b']);
    });

    it('keeps a no-dash singleton flat with its full name', () => {
        const list = [{ id: 'gizmo', name: 'Gizmo' }];
        const tree = buildTree(list);
        expect(tree).toEqual([{ type: 'item', item: list[0], sortKey: 'Gizmo' }]);
    });

    it('sorts group and item nodes together by label/name using cs collation', () => {
        const list = [
            { id: 'z-item', name: 'Zebra' },
            { id: 'w1', name: 'Widget - one' },
            { id: 'w2', name: 'Widget - two' },
            { id: 'w3', name: 'Widget - three' },
            { id: 'a-item', name: 'Alfa' },
        ];
        const tree = buildTree(list);
        expect(tree.map((n) => n.sortKey)).toEqual(['Alfa', 'Widget', 'Zebra']);
    });

    it('falls back to id-keyed bucketing for items with no name', () => {
        const list = [{ id: 'no-name-item' }];
        const tree = buildTree(list);
        expect(tree[0]).toEqual({ type: 'item', item: list[0], sortKey: 'no-name-item' });
    });
});
```

- [x] **Step 6: Run and confirm failure, then implement**

Run: `cd frontend && npx vitest run src/lib/prefixTree.spec.js` → fails (module missing).

Create `frontend/src/lib/prefixTree.js`:

```js
// Group a section's items into a prefix tree. A display name shaped
// "<Prefix> - <Suffix>" joins a bucket keyed by <Prefix>; a bucket with >=
// GROUP_MIN members becomes a collapsible `group` node (each child carries
// `leaf` = the suffix), otherwise its members spill back to flat `item` nodes
// carrying the full name. Names without " - " are always flat. Pure
// derivation from `name` — no metadata involved. Ordered by label/name;
// children ordered by suffix (cs collation). Ported verbatim from
// frontend/stores/components.js `buildTree`.

export const GROUP_MIN = 3;

export function buildTree(list) {
    const buckets = new Map();
    for (const it of list) {
        const name = it.name ?? it.id;
        const sep = name.indexOf(' - ');
        const prefix = sep > 0 ? name.slice(0, sep) : null;
        const key = prefix ?? ` ${it.id}`;
        if (!buckets.has(key)) buckets.set(key, { prefix, items: [] });
        buckets.get(key).items.push(it);
    }
    const nodes = [];
    for (const b of buckets.values()) {
        if (b.prefix && b.items.length >= GROUP_MIN) {
            const children = b.items
                .map((it) => {
                    const name = it.name ?? it.id;
                    const suffix = name.slice(name.indexOf(' - ') + 3);
                    return { ...it, leaf: suffix.charAt(0).toUpperCase() + suffix.slice(1) };
                })
                .sort((a, c) => a.leaf.localeCompare(c.leaf, 'cs'));
            nodes.push({ type: 'group', label: b.prefix, sortKey: b.prefix, children });
        } else {
            for (const it of b.items) nodes.push({ type: 'item', item: it, sortKey: it.name ?? it.id });
        }
    }
    return nodes.sort((a, c) => a.sortKey.localeCompare(c.sortKey, 'cs'));
}
```

Run: `cd frontend && npx vitest run src/lib/prefixTree.spec.js`
Expected: `Tests 5 passed`.

- [x] **Step 7: Write failing tests for `viewportMath.js`**

Create `frontend/src/lib/viewportMath.spec.js`:

```js
import { describe, it, expect } from 'vitest';
import {
    VIEWPORTS,
    CUSTOM_WIDTH_MIN,
    CUSTOM_WIDTH_MAX,
    findPresetByWidth,
    parseWidthParam,
    effectiveDims,
    fitZoom,
    isPortraitOrientation,
    rotationForPortrait,
} from './viewportMath.js';

describe('VIEWPORTS', () => {
    it('has 10 presets including the unconstrained Full preset', () => {
        expect(VIEWPORTS).toHaveLength(10);
        expect(VIEWPORTS.find((v) => v.key === 'full')).toEqual({
            key: 'full', label: 'Full', category: 'full', width: null, height: null,
        });
    });
});

describe('findPresetByWidth', () => {
    it('finds the Desktop preset by exact width', () => {
        expect(findPresetByWidth(1280)?.key).toBe('desktop');
    });

    it('returns null for a non-preset width', () => {
        expect(findPresetByWidth(999)).toBeNull();
    });
});

describe('parseWidthParam', () => {
    it('accepts a valid integer width in range', () => {
        expect(parseWidthParam('375')).toBe('375px');
    });

    it('accepts "full" and "100%" as the Full preset', () => {
        expect(parseWidthParam('full')).toBe('100%');
        expect(parseWidthParam('100%')).toBe('100%');
    });

    it('rejects a decimal (would otherwise silently truncate via parseInt)', () => {
        expect(parseWidthParam('375.5')).toBeNull();
    });

    it('rejects a value below the minimum', () => {
        expect(parseWidthParam('99')).toBeNull();
    });

    it('rejects a value above the maximum', () => {
        expect(parseWidthParam('4001')).toBeNull();
    });

    it('rejects garbage suffixed onto digits', () => {
        expect(parseWidthParam('375px')).toBeNull();
    });

    it('returns null for empty/falsy input', () => {
        expect(parseWidthParam('')).toBeNull();
        expect(parseWidthParam(null)).toBeNull();
    });
});

describe('effectiveDims', () => {
    it('swaps width/height when rotated (Desktop 1280x800 -> 800x1280)', () => {
        expect(effectiveDims({ width: 1280, height: 800, rotated: true })).toEqual({ width: 800, height: 1280 });
    });

    it('keeps canonical order when not rotated', () => {
        expect(effectiveDims({ width: 1280, height: 800, rotated: false })).toEqual({ width: 1280, height: 800 });
    });

    it('is a no-op for Full mode (width null)', () => {
        expect(effectiveDims({ width: null, height: null, rotated: true })).toEqual({ width: null, height: null });
    });

    it('is a no-op for Custom widths (height null) even when rotated is true', () => {
        expect(effectiveDims({ width: 500, height: null, rotated: true })).toEqual({ width: 500, height: null });
    });
});

describe('fitZoom', () => {
    it('caps at 1 when the preset fits within the container', () => {
        expect(fitZoom({ width: 375, height: 667, availWidth: 1200, availHeight: 900 })).toBe(1);
    });

    it('fits both axes uniformly (Desktop 2K on a 1280x800 laptop pane)', () => {
        const z = fitZoom({ width: 2560, height: 1440, availWidth: 1280, availHeight: 800 });
        expect(z).toBeCloseTo(0.5, 5);
    });

    it('returns 1 for Full mode (width falsy)', () => {
        expect(fitZoom({ width: null, height: null, availWidth: 1280, availHeight: 800 })).toBe(1);
    });

    it('returns 1 when the container has not been measured yet (availWidth 0)', () => {
        expect(fitZoom({ width: 1920, height: 1080, availWidth: 0, availHeight: 0 })).toBe(1);
    });
});

describe('isPortraitOrientation', () => {
    it('reports portrait for a rotated Desktop 1280x800 (-> 800x1280)', () => {
        expect(isPortraitOrientation({ width: 1280, height: 800, rotated: true })).toBe(true);
    });

    it('reports landscape for an un-rotated Desktop 1280x800', () => {
        expect(isPortraitOrientation({ width: 1280, height: 800, rotated: false })).toBe(false);
    });

    it('reports portrait for a canonically-portrait Mobile 375x667', () => {
        expect(isPortraitOrientation({ width: 375, height: 667, rotated: false })).toBe(true);
    });

    it('returns false (landscape) when there is no canonical height (Full/Custom)', () => {
        expect(isPortraitOrientation({ width: 500, height: null, rotated: false })).toBe(false);
    });
});

describe('rotationForPortrait', () => {
    it('computes rotated=false to reach portrait on a portrait-canonical Mobile preset', () => {
        expect(rotationForPortrait({ width: 375, height: 667, portrait: true })).toBe(false);
    });

    it('computes rotated=true to reach portrait on a landscape-canonical Desktop preset', () => {
        expect(rotationForPortrait({ width: 1280, height: 800, portrait: true })).toBe(true);
    });

    it('is a no-op (false) when there is no canonical height', () => {
        expect(rotationForPortrait({ width: 500, height: null, portrait: true })).toBe(false);
    });
});

describe('constants', () => {
    it('CUSTOM_WIDTH_MIN/MAX match the legacy sanity range', () => {
        expect(CUSTOM_WIDTH_MIN).toBe(100);
        expect(CUSTOM_WIDTH_MAX).toBe(4000);
    });
});
```

- [x] **Step 8: Run and confirm failure, then implement**

Run: `cd frontend && npx vitest run src/lib/viewportMath.spec.js` → fails (module missing).

Create `frontend/src/lib/viewportMath.js`:

```js
// Pure viewport/zoom/orientation math, ported from
// frontend/components/preview.js (effectiveWidth/effectiveHeight/zoom/
// isPortrait/setPortrait getters) and frontend/stores/ui.js
// (parseWidthParam). No DOM, no store access — callers supply the raw
// numbers (current width/height/rotation flag, measured container size).

export const VIEWPORTS = [
    { key: 'mobile-s',   label: 'Mobile S',   category: 'mobile',  width: 320,  height: 568  },
    { key: 'mobile',     label: 'Mobile',     category: 'mobile',  width: 375,  height: 667  },
    { key: 'mobile-l',   label: 'Mobile L',   category: 'mobile',  width: 425,  height: 812  },
    { key: 'tablet',     label: 'Tablet',     category: 'tablet',  width: 768,  height: 1024 },
    { key: 'tablet-l',   label: 'Tablet L',   category: 'tablet',  width: 1024, height: 1366 },
    { key: 'desktop',    label: 'Desktop',    category: 'desktop', width: 1280, height: 800  },
    { key: 'desktop-l',  label: 'Desktop L',  category: 'desktop', width: 1536, height: 960  },
    { key: 'desktop-xl', label: 'Desktop XL', category: 'desktop', width: 1920, height: 1080 },
    { key: 'desktop-2k', label: 'Desktop 2K', category: 'desktop', width: 2560, height: 1440 },
    { key: 'full',       label: 'Full',       category: 'full',    width: null, height: null },
];

export const CUSTOM_WIDTH_MIN = 100;
export const CUSTOM_WIDTH_MAX = 4000;

export function findPresetByWidth(width) {
    return VIEWPORTS.find((v) => v.width === width) ?? null;
}

// Accepts 'full' / '100%' / a strict positive integer in [MIN, MAX]. The
// all-digits regex pre-check rejects '375.5' / '375px' / '375junk' (all of
// which `parseInt` would silently coerce to 375) so a malformed input never
// quietly resolves to an unintended width.
export function parseWidthParam(raw) {
    if (!raw) return null;
    if (raw === 'full' || raw === '100%') return '100%';
    if (!/^\d+$/.test(raw)) return null;
    const px = Number(raw);
    if (Number.isInteger(px) && px >= CUSTOM_WIDTH_MIN && px <= CUSTOM_WIDTH_MAX) return `${px}px`;
    return null;
}

// Effective (post-rotation) display dimensions. Rotation only applies when a
// canonical height exists (device presets) — Full (width null) and Custom
// (height null) pass through unchanged.
export function effectiveDims({ width, height, rotated }) {
    if (width === null || height === null) return { width, height };
    if (rotated) return { width: height, height: width };
    return { width, height };
}

// Fit-to-bounds scale factor: shrink (never enlarge — capped at 1) so the
// whole effective box fits inside the available container on both axes.
export function fitZoom({ width, height, availWidth, availHeight }) {
    if (!width || !availWidth) return 1;
    let z = availWidth / width;
    if (height && availHeight) z = Math.min(z, availHeight / height);
    return Math.min(1, z);
}

// Absolute portrait/landscape, derived from EFFECTIVE (post-rotation)
// dimensions — not the raw `rotated` flag, which is relative to the
// preset's own canonical shape (a landscape-canonical Desktop with
// rotated=true is actually portrait). Returns false when there is no
// canonical height (Full/Custom).
export function isPortraitOrientation({ width, height, rotated }) {
    if (height === null) return false;
    const dispW = rotated ? height : width;
    const dispH = rotated ? width : height;
    return dispH > dispW;
}

// Inverse of isPortraitOrientation: given a desired ABSOLUTE orientation,
// compute the `rotated` flag relative to the preset's canonical shape.
export function rotationForPortrait({ width, height, portrait }) {
    if (height === null) return false;
    const canonicalLandscape = width > height;
    return portrait ? canonicalLandscape : !canonicalLandscape;
}
```

Run: `cd frontend && npx vitest run src/lib/viewportMath.spec.js`
Expected: `Tests 21 passed`.

- [x] **Step 9: Write failing tests for `fieldsTree.js`**

Create `frontend/src/lib/fieldsTree.spec.js`:

```js
import { describe, it, expect } from 'vitest';
import { flattenFieldsTree } from './fieldsTree.js';

describe('flattenFieldsTree', () => {
    it('flattens a nested fields map into a depth-first list with dotted paths', () => {
        const fields = {
            title: { type: 'text', title: 'Title', required: true },
            items: {
                type: 'array',
                fields: {
                    label: { type: 'text', title: 'Label' },
                },
            },
        };
        const rows = flattenFieldsTree(fields);
        expect(rows).toEqual([
            { path: 'title', key: 'title', depth: 0, type: 'text', title: 'Title', description: '', required: true },
            { path: 'items', key: 'items', depth: 0, type: 'array', title: '', description: '', required: false },
            { path: 'items.label', key: 'label', depth: 1, type: 'text', title: 'Label', description: '', required: false },
        ]);
    });

    it('coerces a truthy/falsy YAML `required` (1/0) to a real boolean', () => {
        const rows = flattenFieldsTree({ a: { required: 1 }, b: { required: 0 } });
        expect(rows.map((r) => r.required)).toEqual([true, false]);
    });

    it('returns an empty array for null, non-object, or array input', () => {
        expect(flattenFieldsTree(null)).toEqual([]);
        expect(flattenFieldsTree(undefined)).toEqual([]);
        expect(flattenFieldsTree('nope')).toEqual([]);
        expect(flattenFieldsTree([])).toEqual([]);
    });

    it('skips a map entry whose value is not an object', () => {
        const rows = flattenFieldsTree({ bogus: 'not-an-object', real: { type: 'text' } });
        expect(rows.map((r) => r.key)).toEqual(['real']);
    });
});
```

- [x] **Step 10: Run and confirm failure, then implement**

Run: `cd frontend && npx vitest run src/lib/fieldsTree.spec.js` → fails (module missing).

Create `frontend/src/lib/fieldsTree.js`:

```js
// Depth-first walk over the YAML `fields:` map. Returns a flat list so
// callers can render linearly without recursive templates. Each row's
// `depth` drives indentation. Ported verbatim from
// frontend/components/preview.js `flattenFieldsTree`.

export function flattenFieldsTree(map, depth = 0, parentPath = '') {
    if (!map || typeof map !== 'object' || Array.isArray(map)) return [];
    const rows = [];
    for (const [key, field] of Object.entries(map)) {
        if (!field || typeof field !== 'object') continue;
        const path = parentPath ? `${parentPath}.${key}` : key;
        rows.push({
            path,
            key,
            depth,
            type: typeof field.type === 'string' ? field.type : '',
            title: typeof field.title === 'string' ? field.title : '',
            description: typeof field.description === 'string' ? field.description : '',
            required: !!field.required,
        });
        if (field.fields && typeof field.fields === 'object') {
            rows.push(...flattenFieldsTree(field.fields, depth + 1, path));
        }
    }
    return rows;
}
```

Run: `cd frontend && npx vitest run src/lib/fieldsTree.spec.js`
Expected: `Tests 4 passed`.

- [x] **Step 11: Write failing tests for `externalLinks.js`**

Create `frontend/src/lib/externalLinks.spec.js`:

```js
import { describe, it, expect } from 'vitest';
import { externalLinksFor } from './externalLinks.js';

describe('externalLinksFor', () => {
    it('returns links in Asana -> Figma -> Drupal -> Web order, filtering empty ones', () => {
        const item = { asana: 'https://asana/x', figma: '', drupal: 'https://drupal/y', web: 'https://web/z' };
        expect(externalLinksFor(item)).toEqual([
            { key: 'asana', url: 'https://asana/x', label: 'Asana' },
            { key: 'drupal', url: 'https://drupal/y', label: 'Drupal' },
            { key: 'web', url: 'https://web/z', label: 'Web' },
        ]);
    });

    it('returns an empty array when no link fields are set', () => {
        expect(externalLinksFor({ id: 'x' })).toEqual([]);
    });

    it('returns an empty array for null/undefined input', () => {
        expect(externalLinksFor(null)).toEqual([]);
        expect(externalLinksFor(undefined)).toEqual([]);
    });
});
```

- [x] **Step 12: Run and confirm failure, then implement**

Run: `cd frontend && npx vitest run src/lib/externalLinks.spec.js` → fails (module missing).

Create `frontend/src/lib/externalLinks.js`:

```js
// Resolves an item's external-link metadata (`asana`, `figma`, `drupal`,
// `web` YAML keys) into a renderable list. Order matches the legacy badge
// row: Asana -> Figma -> Drupal -> Web. Consolidates three near-identical
// copies from frontend/components/linkBar.js and frontend/components/overview.js
// (`linksFor`, and the inline decorate() shape in _buildForwardMap/_buildReverseMap).

export function externalLinksFor(item) {
    if (!item) return [];
    return [
        { key: 'asana', url: item.asana, label: 'Asana' },
        { key: 'figma', url: item.figma, label: 'Figma' },
        { key: 'drupal', url: item.drupal, label: 'Drupal' },
        { key: 'web', url: item.web, label: 'Web' },
    ].filter((l) => l.url);
}
```

Run: `cd frontend && npx vitest run src/lib/externalLinks.spec.js`
Expected: `Tests 3 passed`.

- [x] **Step 13: Full lib suite + commit**

Run: `cd frontend && npm test`
Expected: `Test Files 5 passed`, `Tests 40 passed` (7 + 5 + 21 + 4 + 3).

```bash
git add frontend/src/lib
git commit -m "test(frontend): port pure viewport/search/tree/fields logic to framework-free lib with Vitest coverage

Zero-test-coverage logic (viewport math, prefix-tree grouping, search
matching, fields-tree flattening, external-link resolution) now has 40
unit tests before a single line of it moves into Vue."
```

---

### Task 3: Pinia stores — catalog, ui, i18n, theme + persistence composable

Ports the 4 Alpine stores (`components`, `ui`, `i18n`, `theme`) to Pinia. `catalog.js` replaces `components.js` per the spec's naming (`stores/catalog.js # components + pages + docs (today: components.js)`); `ui.js`/`i18n.js`/`theme.js` keep their names. A shared `usePersistedRef` composable replaces `Alpine.$persist(...).as(key)` for the JSON-encoded keys; `i18n`'s locale key is plain-string (not JSON-encoded) in the legacy code and must stay that way — using `usePersistedRef` for it would double-JSON-encode and break the existing `localStorage.getItem('sg-locale')` value on first load after the rewrite ships.

**Every localStorage key that must survive the rewrite, enumerated exhaustively** (grep of `Alpine.$persist(...).as(` + the one plain `localStorage` key across `frontend/`):

| Key | Legacy source | Shape | New owner |
|---|---|---|---|
| `sg-sections` | `components/sidebar.js` | `{docs, basic, blocks, gutenberg, pages}` booleans | `Sidebar.vue` local state (Task 5) |
| `sg-groups` | `components/sidebar.js` | `{ "<section>/<prefix>": bool, ... }` | `Sidebar.vue` local state (Task 5) |
| `sg-sidebar-open` | `stores/ui.js` | boolean | `stores/ui.js` |
| `sg-preview-width` | `stores/ui.js` | string (`"100%"` or `"<n>px"`) | `stores/ui.js` |
| `sg-preview-height` | `stores/ui.js` | number or `null` | `stores/ui.js` |
| `sg-preview-rotated` | `stores/ui.js` | boolean | `stores/ui.js` |
| `sg-theme` | `stores/theme.js` | `"light"` \| `"dark"` \| `"system"` | `stores/theme.js` |
| `sg-overview-show-usage` | `components/overview.js` | boolean | `OverviewView.vue` local state (Task 11) |
| `sg-locale` | `stores/i18n.js` | plain string, **not** JSON-encoded | `stores/i18n.js` |

`sg-sections`/`sg-groups`/`sg-overview-show-usage` are not store state in the legacy code either (they live on the `sidebar`/`overview` Alpine components) — they stay component-local in the rewrite too, via the same composable, just instantiated inside the owning `.vue` file rather than a store. Listed here so the enumeration is complete in one place.

**Files:**
- Create: `frontend/src/lib/persistedRef.js` + `frontend/src/lib/persistedRef.spec.js`
- Create: `frontend/src/lib/routeInfo.js` + `frontend/src/lib/routeInfo.spec.js`
- Create: `frontend/src/stores/catalog.js` + `frontend/src/stores/catalog.spec.js`
- Create: `frontend/src/stores/ui.js` + `frontend/src/stores/ui.spec.js`
- Create: `frontend/src/stores/i18n.js` + `frontend/src/stores/i18n.spec.js`
- Create: `frontend/src/stores/theme.js` + `frontend/src/stores/theme.spec.js`

**Interfaces:**
- `persistedRef.js` — `usePersistedRef(key: string, defaultValue: any): Ref` — JSON round-trips through `localStorage`, exactly matching `@alpinejs/persist`'s bare-key, JSON-encoded convention (the FOUC-prevention inline script in `index.html` reads `sg-theme` with `JSON.parse` — this composable must produce values that script can keep reading unchanged).
- `routeInfo.js` — `routeInfo(route: RouteLocationNormalized): {type: string, slug: string|null}` — maps a vue-router route to the legacy `{type, slug}` shape every other module keys off.
- `stores/catalog.js` (Pinia `defineStore('catalog', ...)`) — state: `items`, `pages`, `docs`, `loading`; actions: `init(): Promise<void>`; getters: `sectionOf(item, type)`, `bySection(section)`, `treeOf(section)`, `pagesTree`, `docEntries`, `find(type, slug)`, `reverseUsageFor(id)`, `forwardUsageFor(itemOrId)`.
- `stores/ui.js` (Pinia `defineStore('ui', ...)`) — state: `sidebarOpen`, `previewWidth`, `previewHeight`, `previewRotated`, `isDragging`, `isPreviewLoading`, `searchQuery`, `routeType`, `routeSlug`; actions: `setWidth(w, h?)`, `toggleRotation()`, `setOrientation(rotated)`, `setPortrait(portrait)`, `toggleSidebar()`, `setRoute(type, slug?)`, `initFromUrl()`; getters: `isPortrait`, `displayWidth`, `displayHeight`, `widthLabel`.
- `stores/i18n.js` (Pinia `defineStore('i18n', ...)`) — state: `locale`, `strings`; actions: `init()`, `load(locale)`; getters: `t(path)` (implemented as an action-like function, not a computed, since it takes an argument — Pinia getters can be functions returning functions, see implementation).
- `stores/theme.js` (Pinia `defineStore('theme', ...)`) — state: `mode`, `systemDark`; actions: `init()`, `cycle()`; getters: `resolved`.

- [x] **Step 1: Write failing tests for `persistedRef.js`**

Create `frontend/src/lib/persistedRef.spec.js`:

```js
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { usePersistedRef } from './persistedRef.js';

beforeEach(() => {
    localStorage.clear();
});

describe('usePersistedRef', () => {
    it('reads the default value when nothing is stored', () => {
        const state = usePersistedRef('sg-test-a', 42);
        expect(state.value).toBe(42);
    });

    it('reads back a previously JSON-encoded value (matches @alpinejs/persist convention)', () => {
        localStorage.setItem('sg-test-b', JSON.stringify('dark'));
        const state = usePersistedRef('sg-test-b', 'system');
        expect(state.value).toBe('dark');
    });

    it('writes through to localStorage as JSON on change', async () => {
        const state = usePersistedRef('sg-test-c', false);
        state.value = true;
        await Promise.resolve();
        expect(localStorage.getItem('sg-test-c')).toBe('true');
    });

    it('deep-persists a plain-object value (e.g. sg-groups shape)', async () => {
        const state = usePersistedRef('sg-test-d', {});
        state.value['basic/Widget'] = false;
        await Promise.resolve();
        expect(JSON.parse(localStorage.getItem('sg-test-d'))).toEqual({ 'basic/Widget': false });
    });

    it('falls back to the default when localStorage throws (Safari private mode)', () => {
        const spy = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('SecurityError');
        });
        const state = usePersistedRef('sg-test-e', 'fallback');
        expect(state.value).toBe('fallback');
        spy.mockRestore();
    });
});
```

- [x] **Step 2: Run and confirm failure, then implement**

Run: `cd frontend && npx vitest run src/lib/persistedRef.spec.js` → fails (module missing).

Create `frontend/src/lib/persistedRef.js`:

```js
import { ref, watch } from 'vue';

// Vue equivalent of `Alpine.$persist(defaultValue).as(key)`: a reactive ref
// that round-trips through localStorage as JSON, under the bare key name
// (no `_x_` prefix — matches @alpinejs/persist's actual convention, which
// the FOUC-prevention inline script in index.html also depends on for
// `sg-theme`). `deep: true` on the watcher covers plain-object values like
// `sg-groups` (`{ "<section>/<prefix>": bool }`) where a nested key changes
// without the ref's own identity changing.
export function usePersistedRef(key, defaultValue) {
    let initial = defaultValue;
    try {
        const stored = localStorage.getItem(key);
        if (stored !== null) initial = JSON.parse(stored);
    } catch (e) {
        // Safari private mode throws on localStorage access; fall back silently.
    }

    const state = ref(initial);

    watch(state, (value) => {
        try {
            localStorage.setItem(key, JSON.stringify(value));
        } catch (e) {
            // Safari private mode — persistence is best-effort.
        }
    }, { deep: true });

    return state;
}
```

Run: `cd frontend && npx vitest run src/lib/persistedRef.spec.js`
Expected: `Tests 5 passed`.

- [x] **Step 3: Write failing tests for `routeInfo.js`**

Create `frontend/src/lib/routeInfo.spec.js`:

```js
import { describe, it, expect } from 'vitest';
import { routeInfo } from './routeInfo.js';

describe('routeInfo', () => {
    it('maps component/page/doc routes with their slug param', () => {
        expect(routeInfo({ name: 'component', params: { slug: 'hero' } })).toEqual({ type: 'component', slug: 'hero' });
        expect(routeInfo({ name: 'page', params: { slug: 'homepage' } })).toEqual({ type: 'page', slug: 'homepage' });
        expect(routeInfo({ name: 'doc', params: { slug: 'sample-doc' } })).toEqual({ type: 'doc', slug: 'sample-doc' });
    });

    it('maps overview with no slug', () => {
        expect(routeInfo({ name: 'overview', params: {} })).toEqual({ type: 'overview', slug: null });
    });

    it('maps foundations, landing, and the not-found fallback all to type foundations (legacy landing-maps-to-foundations behavior)', () => {
        expect(routeInfo({ name: 'foundations', params: {} })).toEqual({ type: 'foundations', slug: null });
        expect(routeInfo({ name: 'landing', params: {} })).toEqual({ type: 'foundations', slug: null });
        expect(routeInfo({ name: 'not-found-fallback', params: {} })).toEqual({ type: 'foundations', slug: null });
    });

    it('maps the dead-but-preserved fields route to type fields with no slug', () => {
        expect(routeInfo({ name: 'fields', params: {} })).toEqual({ type: 'fields', slug: null });
    });

    it('falls back to foundations for an unrecognised route name', () => {
        expect(routeInfo({ name: undefined, params: {} })).toEqual({ type: 'foundations', slug: null });
    });
});
```

- [x] **Step 4: Run and confirm failure, then implement**

Run: `cd frontend && npx vitest run src/lib/routeInfo.spec.js` → fails (module missing).

Create `frontend/src/lib/routeInfo.js`:

```js
// Maps a vue-router route to the legacy {type, slug} shape every store/
// component keys off (mirrors frontend/router.js `parse()` + the
// landing-maps-to-foundations rule from its `apply()`).
export function routeInfo(route) {
    const name = route?.name;
    if (name === 'component' || name === 'page' || name === 'doc') {
        return { type: name, slug: route.params.slug ?? null };
    }
    if (name === 'overview') return { type: 'overview', slug: null };
    if (name === 'fields') return { type: 'fields', slug: null };
    // 'foundations', 'landing' (bare "/"), and 'not-found-fallback' (any
    // unmatched path) all render the foundations view with the URL left
    // untouched — see frontend/router.js: "Landing ... maps to foundations
    // ... URL stays /styleguide (no history pushState)".
    return { type: 'foundations', slug: null };
}
```

Run: `cd frontend && npx vitest run src/lib/routeInfo.spec.js`
Expected: `Tests 5 passed`.

- [x] **Step 5: Write failing tests for `stores/theme.js`**

Create `frontend/src/stores/theme.spec.js`:

```js
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useThemeStore } from './theme.js';

beforeEach(() => {
    localStorage.clear();
    setActivePinia(createPinia());
    vi.stubGlobal('matchMedia', vi.fn().mockReturnValue({
        matches: false,
        addEventListener: vi.fn(),
        addListener: vi.fn(),
    }));
});

describe('useThemeStore', () => {
    it('defaults to system mode', () => {
        const theme = useThemeStore();
        expect(theme.mode).toBe('system');
    });

    it('resolves to light when mode is system and OS is light', () => {
        const theme = useThemeStore();
        theme.init();
        expect(theme.resolved).toBe('light');
    });

    it('resolves to the explicit mode when not system', () => {
        const theme = useThemeStore();
        theme.mode = 'dark';
        expect(theme.resolved).toBe('dark');
    });

    it('cycles light -> dark -> system -> light', () => {
        const theme = useThemeStore();
        theme.mode = 'light';
        theme.cycle();
        expect(theme.mode).toBe('dark');
        theme.cycle();
        expect(theme.mode).toBe('system');
        theme.cycle();
        expect(theme.mode).toBe('light');
    });

    it('persists mode to the sg-theme localStorage key as JSON', async () => {
        const theme = useThemeStore();
        theme.mode = 'dark';
        await Promise.resolve();
        expect(localStorage.getItem('sg-theme')).toBe('"dark"');
    });
});
```

- [x] **Step 6: Run and confirm failure, then implement**

Run: `cd frontend && npx vitest run src/stores/theme.spec.js` → fails (module missing).

Create `frontend/src/stores/theme.js`:

```js
import { defineStore } from 'pinia';
import { usePersistedRef } from '../lib/persistedRef.js';

// Single source of truth for the user's theme preference and the resolved
// (light/dark) theme. Three modes: 'light' / 'dark' / 'system' ('system'
// follows prefers-color-scheme live). Ported from frontend/stores/theme.js.
//
// Coupling note: the FOUC-prevention inline script in index.html reads the
// SAME localStorage key — bare `sg-theme`, JSON-encoded. If you rename the
// key here, update that inline script too.
export const useThemeStore = defineStore('theme', {
    state: () => ({
        mode: usePersistedRef('sg-theme', 'system'),
        systemDark: false,
    }),
    getters: {
        resolved: (state) => (state.mode === 'system' ? (state.systemDark ? 'dark' : 'light') : state.mode),
    },
    actions: {
        init() {
            try {
                const mq = window.matchMedia('(prefers-color-scheme: dark)');
                this.systemDark = mq.matches;
                const onChange = (e) => { this.systemDark = e.matches; };
                if (typeof mq.addEventListener === 'function') {
                    mq.addEventListener('change', onChange);
                } else if (typeof mq.addListener === 'function') {
                    mq.addListener(onChange);
                }
            } catch (e) {
                this.systemDark = false;
            }
        },
        apply() {
            document.documentElement.classList.toggle('dark', this.resolved === 'dark');
        },
        cycle() {
            const next = { light: 'dark', dark: 'system', system: 'light' };
            this.mode = next[this.mode] ?? 'system';
        },
    },
});
```

Note: `state.mode` is assigned a `Ref` returned by `usePersistedRef` — Pinia's `state()` factory unwraps refs placed in the returned object automatically (documented Pinia behavior, same mechanism Setup Stores rely on), so `theme.mode` reads/writes as a plain string everywhere else in the app. The `apply()` action (DOM side effect) is wired to an effect watcher in `main.js`, Task 4 — not inside the store itself, keeping the store DOM-free except for this one unavoidable `document.documentElement` toggle, which mirrors the legacy `apply()` exactly.

Run: `cd frontend && npx vitest run src/stores/theme.spec.js`
Expected: `Tests 5 passed`.

- [x] **Step 7: Write failing tests for `stores/i18n.js`**

Create `frontend/src/stores/i18n.spec.js`:

```js
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useI18nStore } from './i18n.js';

beforeEach(() => {
    localStorage.clear();
    setActivePinia(createPinia());
});

describe('useI18nStore', () => {
    it('loads a locale, storing strings and updating <html lang>', async () => {
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ nav: { overview: 'Overview' } }),
        });
        const i18n = useI18nStore();
        await i18n.load('en');
        expect(i18n.locale).toBe('en');
        expect(i18n.t('nav.overview')).toBe('Overview');
        expect(document.documentElement.getAttribute('lang')).toBe('en');
    });

    it('persists the locale as a PLAIN STRING (not JSON-encoded) under sg-locale', async () => {
        global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({}) });
        const i18n = useI18nStore();
        await i18n.load('en');
        // Legacy stores.i18n.js writes `localStorage.setItem(STORAGE_KEY, locale)` —
        // a bare string, NOT `JSON.stringify(locale)`. Getting this wrong breaks every
        // user who already has "en" or "cs" (unquoted) saved from the Alpine build.
        expect(localStorage.getItem('sg-locale')).toBe('en');
    });

    it('rejects an unsupported locale without mutating state', async () => {
        global.fetch = vi.fn();
        const i18n = useI18nStore();
        await i18n.load('fr');
        expect(fetch).not.toHaveBeenCalled();
        expect(i18n.locale).toBe('en');
    });

    it('t() falls back to the dotted path itself when the key is missing', () => {
        const i18n = useI18nStore();
        expect(i18n.t('nonexistent.key')).toBe('nonexistent.key');
    });

    it('logs and leaves state unchanged when the fetch response is not ok', async () => {
        global.fetch = vi.fn().mockResolvedValue({ ok: false });
        const errSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
        const i18n = useI18nStore();
        await i18n.load('en');
        expect(i18n.locale).toBe('en'); // unchanged from the store's initial default
        errSpy.mockRestore();
    });
});
```

- [x] **Step 8: Run and confirm failure, then implement**

Run: `cd frontend && npx vitest run src/stores/i18n.spec.js` → fails (module missing).

Create `frontend/src/stores/i18n.js`:

```js
import { defineStore } from 'pinia';

const SUPPORTED = ['cs', 'en'];
const STORAGE_KEY = 'sg-locale';

function detectLocale() {
    const html = document.documentElement;
    const defaultLocale = html.dataset.defaultLocale || 'en';

    const urlLocale = new URLSearchParams(location.search).get('lang');
    if (urlLocale && SUPPORTED.includes(urlLocale)) {
        localStorage.setItem(STORAGE_KEY, urlLocale);
        return urlLocale;
    }

    const stored = localStorage.getItem(STORAGE_KEY);
    if (stored && SUPPORTED.includes(stored)) return stored;

    if (SUPPORTED.includes(defaultLocale)) return defaultLocale;

    const browser = (navigator.language || 'en').split('-')[0];
    if (SUPPORTED.includes(browser)) return browser;

    return 'en';
}

// Ported from frontend/stores/i18n.js. NOTE: `sg-locale` is a PLAIN STRING in
// localStorage (`localStorage.setItem(key, locale)`), unlike every other
// persisted key in this app which is JSON-encoded via @alpinejs/persist /
// usePersistedRef. Do not route this key through usePersistedRef — that
// would write `"en"` (quoted) instead of `en` and desync from any value a
// pre-rewrite session already stored.
export const useI18nStore = defineStore('i18n', {
    state: () => ({
        locale: 'en',
        strings: {},
    }),
    actions: {
        async init() {
            await this.load(detectLocale());
        },
        async load(locale) {
            if (!SUPPORTED.includes(locale)) return;
            const response = await fetch(`/styleguide/assets/locales/${locale}.json`, { cache: 'no-cache' });
            if (!response.ok) {
                console.error(`[styleguide] failed to load locale ${locale}`);
                return;
            }
            this.strings = await response.json();
            this.locale = locale;
            localStorage.setItem(STORAGE_KEY, locale);
            document.documentElement.setAttribute('lang', locale);
        },
        t(path) {
            return path.split('.').reduce((obj, key) => obj?.[key], this.strings) ?? path;
        },
    },
});
```

Run: `cd frontend && npx vitest run src/stores/i18n.spec.js`
Expected: `Tests 5 passed`.

- [x] **Step 9: Write failing tests for `stores/ui.js`**

Create `frontend/src/stores/ui.spec.js`:

```js
import { describe, it, expect, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useUiStore } from './ui.js';

beforeEach(() => {
    localStorage.clear();
    window.history.replaceState(null, '', '/styleguide/');
    setActivePinia(createPinia());
});

describe('useUiStore', () => {
    it('defaults previewWidth to 100% (Full)', () => {
        expect(useUiStore().previewWidth).toBe('100%');
    });

    it('setWidth sets width+height and clears previewRotated when height is null', () => {
        const ui = useUiStore();
        ui.previewRotated = true;
        ui.setWidth('375px');
        expect(ui.previewWidth).toBe('375px');
        expect(ui.previewHeight).toBeNull();
        expect(ui.previewRotated).toBe(false);
    });

    it('setWidth with an explicit height keeps previewRotated (preset application path)', () => {
        const ui = useUiStore();
        ui.setWidth('375px', 667);
        expect(ui.previewHeight).toBe(667);
    });

    it('toggleRotation is a no-op with no canonical height', () => {
        const ui = useUiStore();
        ui.setWidth('500px');
        ui.toggleRotation();
        expect(ui.previewRotated).toBe(false);
    });

    it('toggleRotation flips previewRotated when a canonical height exists', () => {
        const ui = useUiStore();
        ui.setWidth('375px', 667);
        ui.toggleRotation();
        expect(ui.previewRotated).toBe(true);
    });

    it('isPortrait reflects the effective (post-rotation) dimensions', () => {
        const ui = useUiStore();
        ui.setWidth('1280px', 800);
        expect(ui.isPortrait).toBe(false);
        ui.toggleRotation();
        expect(ui.isPortrait).toBe(true);
    });

    it('setPortrait(true) computes the correct rotated flag for a landscape-canonical preset', () => {
        const ui = useUiStore();
        ui.setWidth('1280px', 800);
        ui.setPortrait(true);
        expect(ui.previewRotated).toBe(true);
    });

    it('toggleSidebar flips sidebarOpen', () => {
        const ui = useUiStore();
        const before = ui.sidebarOpen;
        ui.toggleSidebar();
        expect(ui.sidebarOpen).toBe(!before);
    });

    it('setRoute flips isPreviewLoading synchronously for iframe-bearing route types only', () => {
        const ui = useUiStore();
        ui.setRoute('component', 'hero');
        expect(ui.isPreviewLoading).toBe(true);
        ui.isPreviewLoading = false;
        ui.setRoute('overview', null);
        expect(ui.isPreviewLoading).toBe(false);
    });

    it('initFromUrl applies a valid ?width= URL param exactly once at boot', () => {
        window.history.replaceState(null, '', '/styleguide/?width=768');
        const ui = useUiStore();
        ui.initFromUrl();
        expect(ui.previewWidth).toBe('768px');
        expect(ui.previewHeight).toBeNull();
    });

    it('persists previewWidth/Height/Rotated/sidebarOpen under their legacy keys as JSON', async () => {
        const ui = useUiStore();
        ui.setWidth('375px', 667);
        ui.toggleRotation();
        ui.toggleSidebar();
        await Promise.resolve();
        expect(localStorage.getItem('sg-preview-width')).toBe('"375px"');
        expect(localStorage.getItem('sg-preview-height')).toBe('667');
        expect(localStorage.getItem('sg-preview-rotated')).toBe('true');
        expect(JSON.parse(localStorage.getItem('sg-sidebar-open'))).toBe(false);
    });
});
```

- [x] **Step 10: Run and confirm failure, then implement**

Run: `cd frontend && npx vitest run src/stores/ui.spec.js` → fails (module missing).

Create `frontend/src/stores/ui.js`:

```js
import { defineStore } from 'pinia';
import { usePersistedRef } from '../lib/persistedRef.js';
import { parseWidthParam, effectiveDims, isPortraitOrientation, rotationForPortrait } from '../lib/viewportMath.js';

// Ported from frontend/stores/ui.js. `routeType`/`routeSlug` replace the
// legacy `route: {type, slug}` object with two flat fields (Pinia state
// diffing is simpler on primitives); `src/lib/routeInfo.js` + the
// vue-router `beforeEach` guard in Task 4 are what call `setRoute()` on
// every navigation, replacing the old `router.js` `apply()`/`popstate`
// listener.
export const useUiStore = defineStore('ui', {
    state: () => ({
        sidebarOpen: usePersistedRef('sg-sidebar-open', true),
        previewWidth: usePersistedRef('sg-preview-width', '100%'),
        previewHeight: usePersistedRef('sg-preview-height', null),
        previewRotated: usePersistedRef('sg-preview-rotated', false),
        isDragging: false,
        isPreviewLoading: false,
        searchQuery: '',
        routeType: 'landing',
        routeSlug: null,
    }),
    getters: {
        isPortrait: (state) => isPortraitOrientation({
            width: parseInt(state.previewWidth, 10),
            height: state.previewHeight,
            rotated: state.previewRotated,
        }),
        displayWidth: (state) => {
            if (state.previewRotated && state.previewHeight !== null) return `${state.previewHeight}px`;
            return state.previewWidth;
        },
        displayHeight: (state) => {
            if (state.previewRotated && state.previewHeight !== null) {
                const px = parseInt(state.previewWidth, 10);
                return Number.isInteger(px) ? px : state.previewHeight;
            }
            return state.previewHeight;
        },
        widthLabel: (state) => (state.previewWidth === '100%' ? 'Full' : state.previewWidth),
    },
    actions: {
        // Called once at app boot (main.js, Task 4). `?width=` overrides the
        // persisted value on first load only; user interaction after that
        // writes through usePersistedRef normally.
        initFromUrl() {
            if (window.matchMedia('(max-width: 1023px)').matches) {
                this.sidebarOpen = false;
            }
            const urlWidth = parseWidthParam(new URLSearchParams(location.search).get('width'));
            if (urlWidth) this.setWidth(urlWidth);
        },
        setWidth(w, h = null) {
            this.previewWidth = w;
            this.previewHeight = h;
            if (h === null) this.previewRotated = false;
        },
        toggleRotation() {
            if (this.previewHeight === null) return;
            this.previewRotated = !this.previewRotated;
        },
        setOrientation(rotated) {
            if (this.previewHeight === null) return;
            this.previewRotated = !!rotated;
        },
        setPortrait(portrait) {
            if (this.previewHeight === null) return;
            const wPx = parseInt(this.previewWidth, 10);
            if (!Number.isInteger(wPx)) return;
            this.previewRotated = rotationForPortrait({ width: wPx, height: this.previewHeight, portrait });
        },
        toggleSidebar() {
            this.sidebarOpen = !this.sidebarOpen;
        },
        setRoute(type, slug = null) {
            if (['component', 'page', 'doc', 'foundations'].includes(type)) {
                this.isPreviewLoading = true;
            }
            this.routeType = type;
            this.routeSlug = slug;
        },
    },
});
```

Note: `effectiveDims` is imported for parity with the composable Task 7 builds on top of this store; it is unused directly inside `ui.js` itself in this task (the store only needs `isPortraitOrientation`/`rotationForPortrait`/`parseWidthParam`) — remove the unused `effectiveDims` import if `phpstan`-equivalent JS linting (none configured yet) ever flags it; harmless either way since ESLint is not part of this repo's toolchain.

Run: `cd frontend && npx vitest run src/stores/ui.spec.js`
Expected: `Tests 11 passed`.

- [x] **Step 11: Write failing tests for `stores/catalog.js`**

Create `frontend/src/stores/catalog.spec.js`:

```js
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useCatalogStore } from './catalog.js';

function jsonResponse(body) {
    return Promise.resolve({ json: async () => body });
}

beforeEach(() => {
    setActivePinia(createPinia());
});

describe('useCatalogStore', () => {
    it('init() fetches components/pages/docs in parallel and flips loading off', async () => {
        global.fetch = vi.fn((url) => {
            if (url.endsWith('/api/components')) return jsonResponse([{ id: 'hero', name: 'Hero', category: 'Block' }]);
            if (url.endsWith('/api/pages')) return jsonResponse([{ id: 'homepage', name: 'Homepage', usage: 'hero' }]);
            if (url.endsWith('/api/docs')) return jsonResponse([]);
            throw new Error(`unexpected fetch ${url}`);
        });
        const catalog = useCatalogStore();
        expect(catalog.loading).toBe(true);
        await catalog.init();
        expect(catalog.loading).toBe(false);
        expect(catalog.items).toHaveLength(1);
        expect(catalog.pages).toHaveLength(1);
    });

    it('init() flips loading off even when a fetch rejects', async () => {
        global.fetch = vi.fn().mockRejectedValue(new Error('network down'));
        const errSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
        const catalog = useCatalogStore();
        await catalog.init();
        expect(catalog.loading).toBe(false);
        errSpy.mockRestore();
    });

    it('sectionOf buckets gutenberg/block/layout/unknown categories and pins pages', () => {
        const catalog = useCatalogStore();
        expect(catalog.sectionOf({ category: 'Gutenberg' }, 'component')).toBe('gutenberg');
        expect(catalog.sectionOf({ category: 'Block' }, 'component')).toBe('blocks');
        expect(catalog.sectionOf({ category: 'Layout' }, 'component')).toBe('blocks');
        expect(catalog.sectionOf({ category: 'Whatever' }, 'component')).toBe('basic');
        expect(catalog.sectionOf({ category: '' }, 'component')).toBe('basic');
        expect(catalog.sectionOf({}, 'page')).toBe('pages');
    });

    it('bySection excludes hasStyleguide:false skeleton templates', () => {
        const catalog = useCatalogStore();
        catalog.items = [
            { id: 'a', category: 'Block', hasStyleguide: true },
            { id: 'b', category: 'Block', hasStyleguide: false },
        ];
        expect(catalog.bySection('blocks').map((i) => i.id)).toEqual(['a']);
    });

    it('treeOf delegates to the prefix-tree lib for a section', () => {
        const catalog = useCatalogStore();
        catalog.items = [
            { id: 'widget-one', name: 'Widget - one', category: 'Block' },
            { id: 'widget-two', name: 'Widget - two', category: 'Block' },
            { id: 'widget-three', name: 'Widget - three', category: 'Block' },
        ];
        expect(catalog.treeOf('blocks')).toEqual([
            { type: 'group', label: 'Widget', sortKey: 'Widget', children: expect.any(Array) },
        ]);
    });

    it('find() looks up by id in the type-appropriate list', () => {
        const catalog = useCatalogStore();
        catalog.items = [{ id: 'hero', name: 'Hero' }];
        catalog.pages = [{ id: 'homepage', name: 'Homepage' }];
        catalog.docs = [{ id: 'sample-doc', name: 'Sample doc' }];
        expect(catalog.find('component', 'hero')?.name).toBe('Hero');
        expect(catalog.find('page', 'homepage')?.name).toBe('Homepage');
        expect(catalog.find('doc', 'sample-doc')?.name).toBe('Sample doc');
        expect(catalog.find('component', 'missing')).toBeNull();
    });

    it('reverseUsageFor inverts page.usage CSVs into a component -> [pages] map', () => {
        const catalog = useCatalogStore();
        catalog.pages = [{ id: 'homepage', name: 'Homepage', usage: 'hero, footer' }];
        catalog.items = [{ id: 'hero', name: 'Hero' }, { id: 'footer', name: 'Footer' }];
        expect(catalog.reverseUsageFor('hero')).toEqual([
            expect.objectContaining({ id: 'homepage', type: 'page', name: 'Homepage' }),
        ]);
        expect(catalog.reverseUsageFor('nonexistent')).toEqual([]);
    });

    it('forwardUsageFor resolves a page.usage CSV into named+typed chips, greying out unknown ids', () => {
        const catalog = useCatalogStore();
        catalog.pages = [{ id: 'homepage', name: 'Homepage', usage: 'hero,ghost-id' }];
        catalog.items = [{ id: 'hero', name: 'Hero' }];
        const chips = catalog.forwardUsageFor(catalog.pages[0]);
        expect(chips).toEqual([
            expect.objectContaining({ id: 'hero', type: 'component', name: 'Hero' }),
            expect.objectContaining({ id: 'ghost-id', type: null, name: 'ghost-id' }),
        ]);
    });
});
```

- [x] **Step 12: Run and confirm failure, then implement**

Run: `cd frontend && npx vitest run src/stores/catalog.spec.js` → fails (module missing).

Create `frontend/src/stores/catalog.js`:

```js
import { defineStore } from 'pinia';
import { buildTree } from '../lib/prefixTree.js';
import { externalLinksFor } from '../lib/externalLinks.js';

// Ported from frontend/stores/components.js, renamed `catalog` per the
// Phase 1 spec's target file layout. Adds reverseUsageFor/forwardUsageFor,
// which consolidate the reverse/forward usage-map builders that lived
// duplicated inside frontend/components/overview.js (`_buildReverseMap`/
// `_buildForwardMap`) — same algorithm, single home.
export const useCatalogStore = defineStore('catalog', {
    state: () => ({
        items: [],
        pages: [],
        docs: [],
        loading: true,
    }),
    getters: {
        docEntries: (state) => state.docs,
        pagesTree: (state) => buildTree(state.pages.filter((p) => p.hasStyleguide !== false)),
    },
    actions: {
        async init() {
            try {
                const [componentsRes, pagesRes, docsRes] = await Promise.all([
                    fetch('/styleguide/api/components'),
                    fetch('/styleguide/api/pages'),
                    fetch('/styleguide/api/docs'),
                ]);
                this.items = await componentsRes.json();
                this.pages = await pagesRes.json();
                this.docs = await docsRes.json();
            } catch (err) {
                console.error('[styleguide] failed to load catalogue', err);
            } finally {
                this.loading = false;
            }
        },

        sectionOf(item, type = 'component') {
            if (type === 'page') return 'pages';
            const cat = (item?.category ?? '').toLowerCase();
            if (cat === 'gutenberg') return 'gutenberg';
            if (['block', 'blocks', 'layout'].includes(cat)) return 'blocks';
            return 'basic';
        },

        bySection(section) {
            return this.items.filter((c) => this.sectionOf(c) === section && c.hasStyleguide !== false);
        },

        treeOf(section) {
            return buildTree(this.bySection(section));
        },

        find(type, slug) {
            const list = type === 'page' ? this.pages : type === 'doc' ? this.docs : this.items;
            return list.find((c) => c.id === slug) ?? null;
        },

        reverseUsageFor(id) {
            const map = new Map();
            for (const page of this.pages) {
                const ids = String(page.usage ?? '').split(',').map((s) => s.trim()).filter(Boolean);
                for (const usedId of ids) {
                    if (!map.has(usedId)) map.set(usedId, []);
                    map.get(usedId).push({ id: page.id, type: 'page', name: page.name ?? page.id, ...pickLinks(page) });
                }
            }
            return map.get(id) ?? [];
        },

        forwardUsageFor(itemOrId) {
            const id = typeof itemOrId === 'string' ? itemOrId : itemOrId?.id;
            const decorate = (item, type) => ({ id: item.id, type, name: item.name ?? item.id, ...pickLinks(item) });
            const source = [...this.pages, ...this.items].find((it) => it.id === id);
            if (!source) return [];
            const ids = String(source.usage ?? '').split(',').map((s) => s.trim()).filter(Boolean);
            return ids.map((usedId) => {
                const page = this.pages.find((p) => p.id === usedId);
                if (page) return decorate(page, 'page');
                const comp = this.items.find((c) => c.id === usedId);
                if (comp) return decorate(comp, 'component');
                return { id: usedId, type: null, name: usedId };
            });
        },
    },
});

function pickLinks(item) {
    const { asana, figma, drupal, web } = item;
    return { asana, figma, drupal, web };
}

// Re-exported so components that only need link resolution (LinkBar,
// OverviewView) don't need to import both catalog.js and externalLinks.js.
export { externalLinksFor };
```

Run: `cd frontend && npx vitest run src/stores/catalog.spec.js`
Expected: `Tests 7 passed`.

- [x] **Step 13: Full store suite + commit**

Run: `cd frontend && npm test`
Expected: all Task 2 + Task 3 spec files pass — `Test Files 11 passed`, `Tests 73 passed` (40 from Task 2 + 5 persistedRef + 5 routeInfo + 5 theme + 5 i18n + 11 ui + 7 catalog... = 40+5+5+5+5+11+7 = 78; exact count will settle once written — treat "all files green, zero failures" as the pass bar rather than an exact number, since minor edge-case tests may be added while implementing).

```bash
git add frontend/src/lib/persistedRef.js frontend/src/lib/persistedRef.spec.js \
        frontend/src/lib/routeInfo.js frontend/src/lib/routeInfo.spec.js \
        frontend/src/stores
git commit -m "feat(frontend): port Alpine stores (components/ui/i18n/theme) to Pinia

catalog.js replaces components.js; ui/i18n/theme keep their names. Every
existing localStorage key (sg-sections, sg-groups, sg-sidebar-open,
sg-preview-width/height/rotated, sg-theme, sg-overview-show-usage,
sg-locale) round-trips byte-for-byte via a new usePersistedRef composable
(sg-locale deliberately excluded — it stays plain-string, unlike the rest)."
```

---

### Task 4: App shell, `sg-config` injection point, and the PHP `dispatchSpa()` rewrite

Replaces the 7 silently-no-op regex substitutions in `Styleguide::dispatchSpa()` (6 `preg_replace` + 1 `preg_replace_callback`, targeting `<html lang>`, `#sg-favicon-tag`, `#sg-favicon`, `data-project-name`, `data-project-favicon`, `#sg-project-name`, `<title>`) with one substitution into a single `<script id="sg-config" type="application/json">{}</script>` element, per the spec. Also stands up the Vue app shell (`main.js`, `App.vue`), the `vue-router` instance, and keeps the FOUC-safe theme boot script working before Vue has even mounted.

**Files:**
- Modify: `frontend/index.html` (full rewrite of the `<head>`/`<body>` shell; keeps the inline FOUC script verbatim)
- Create: `frontend/src/main.js`
- Create: `frontend/src/App.vue`
- Create: `frontend/src/lib/config.js` + `frontend/src/lib/config.spec.js`
- Create: `frontend/src/router.js`
- Modify: `src/Styleguide.php` (`dispatchSpa()` method + constructor)
- Create: `tests/SpaConfigTest.php`

**Interfaces:**
- `lib/config.js` — `readSpaConfig(elementId?: string): {locale, projectName, favicon, title, baseUrl}` — throws if the element is missing or not valid JSON (fail loud, matching the PHP side's new throw-on-non-match behavior instead of the old "silently keep whatever was already in the static HTML" fallback).
- `src/router.js` — exports `router` (a configured `vue-router` instance) and installs a `router.beforeEach` guard that calls `useUiStore().setRoute(...)` via `routeInfo(to)` — the direct replacement for the legacy `router.js`'s `apply()` + `popstate` listener + `window.sgNavigate` global.
- `src/main.js` — boots Pinia + the router + mounts `App.vue` at `#app`; reads `readSpaConfig()` once and stamps `document.title`/favicon/project name reactively (replacing the `Alpine.effect` in the current `styleguide.js`).
- PHP `Styleguide::dispatchSpa()` — same signature (`private function dispatchSpa(array $route): void`), same call site, but now throws `\RuntimeException` when the `#sg-config` injection point is missing from `dist/index.html` instead of silently shipping a stale shell.

- [x] **Step 1: Write the failing PHP test first**

Create `tests/SpaConfigTest.php`:

```php
<?php

declare(strict_types=1);

namespace Parisek\Styleguide\Tests;

use Parisek\Styleguide\Styleguide;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SpaConfigTest extends TestCase
{
    private string $distRoot;

    protected function setUp(): void
    {
        $this->distRoot = sys_get_temp_dir() . '/sg-spa-config-test-' . uniqid();
        mkdir($this->distRoot);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->distRoot . '/*') ?: []);
        rmdir($this->distRoot);
    }

    private function writeIndexHtml(string $body): void
    {
        file_put_contents($this->distRoot . '/index.html', $body);
    }

    private function runStyleguide(): string
    {
        ob_start();
        (new Styleguide([
            'templates_path' => __DIR__ . '/fixtures/templates',
            'static_path' => __DIR__ . '/fixtures',
            'config_yaml' => __DIR__ . '/fixtures/styleguide.yaml',
            'default_locale' => 'cs',
            'dist_path' => $this->distRoot,
        ]))->run();
        return (string) ob_get_clean();
    }

    #[Test]
    public function injects_locale_favicon_project_name_and_title_into_sg_config(): void
    {
        $this->writeIndexHtml('<html><head><script id="sg-config" type="application/json">{}</script></head><body></body></html>');
        $_SERVER['REQUEST_URI'] = '/styleguide/';

        $html = $this->runStyleguide();

        self::assertMatchesRegularExpression(
            '/<script id="sg-config" type="application\/json">(\{.*?\})<\/script>/s',
            $html,
        );
        preg_match('/<script id="sg-config" type="application\/json">(\{.*?\})<\/script>/s', $html, $m);
        $config = json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('cs', $config['locale']);
        self::assertSame('Styleguide Fixture', $config['projectName']);
        self::assertArrayHasKey('favicon', $config);
        self::assertSame('Styleguide — Styleguide Fixture', $config['title']);
    }

    #[Test]
    public function throws_when_dist_index_html_is_missing_the_sg_config_injection_point(): void
    {
        $this->writeIndexHtml('<html><head><title>Styleguide</title></head><body></body></html>');
        $_SERVER['REQUEST_URI'] = '/styleguide/';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/sg-config/');

        $this->runStyleguide();
    }
}
```

Note: this test assumes `Styleguide`'s constructor accepts a `dist_path` override (it currently hardcodes `__DIR__ . '/../dist'` per the research, constructor line ~136). Add that override in Step 3 below; without it this test cannot point at a temp `dist/index.html` and would corrupt the real `dist/` fixture during the test run.

- [x] **Step 2: Run and confirm failure**

Run: `composer test -- --filter SpaConfigTest`
Expected: fails — either a constructor error (`dist_path` key not recognised) or (once that's stubbed) the old 7-regex `dispatchSpa()` doesn't touch `#sg-config` at all, so the first assertion's regex never matches.

- [x] **Step 3: Add the `dist_path` constructor override**

In `src/Styleguide.php`, find the constructor line that sets `$this->distRoot = __DIR__ . '/../dist';` and change it to honor an optional config key, keeping the existing default:

```php
$this->distRoot = (string) ($config['dist_path'] ?? (__DIR__ . '/../dist'));
```

- [x] **Step 4: Rewrite `dispatchSpa()`**

Replace the entire body of `private function dispatchSpa(array $route): void` in `src/Styleguide.php` with:

```php
    /**
     * @param array<string, mixed> $route
     */
    private function dispatchSpa(array $route): void
    {
        $indexPath = $this->distRoot . '/index.html';
        if (!is_file($indexPath)) {
            http_response_code(500);
            echo "Styleguide build missing — run 'npm run build' in vendor/parisek/styleguide/frontend/";
            return;
        }

        $html = (string) file_get_contents($indexPath);
        $locale = (string) $this->config['default_locale'];
        $project = (array) ($this->yamlConfig['project'] ?? []);
        $projectName = (string) ($project['name'] ?? 'Styleguide');
        $favicon = (string) ($project['favicon'] ?? '');
        $assetBase = (string) (($this->config['twig_context']['templateUrl'] ?? '') ?: '');
        if ($favicon !== '') {
            $favicon = Renderer::resolveAssetUrl($favicon, $assetBase);
        }

        $config = [
            'locale' => $locale,
            'projectName' => $projectName,
            'favicon' => $favicon,
            'title' => sprintf('Styleguide — %s', $projectName),
            'baseUrl' => '/styleguide',
        ];
        $configJson = json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $html = (string) preg_replace(
            '/<script id="sg-config" type="application\/json">.*?<\/script>/s',
            '<script id="sg-config" type="application/json">' . $configJson . '</script>',
            $html,
            1,
            $count,
        );
        if ($count !== 1) {
            throw new \RuntimeException(
                'dist/index.html is missing the #sg-config injection point — rebuild the frontend '
                . '(cd frontend && npm run build) or check dist/ for corruption.',
            );
        }

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        echo $html;
    }
```

This drops the `$esc`/`htmlspecialchars` helper and the `preg_replace_callback` entirely — `json_encode` already produces a safe embedding inside a `<script type="application/json">` element (no `</script>`-breakout risk for any of these string fields, and JSON's own escaping handles quotes/backslashes). `JSON_UNESCAPED_SLASHES` keeps favicon URLs readable (`/images/favicon.svg` instead of `\/images\/favicon.svg`) with no functional difference to the JS `JSON.parse()` on the other side.

- [x] **Step 5: Run and confirm the PHP test passes**

Run: `composer test -- --filter SpaConfigTest`
Expected: both tests pass. Then run the full suite: `composer test` exit 0 — no other test references the old regex targets (research confirmed zero existing coverage of `dispatchSpa`).

- [x] **Step 6: `composer phpstan`**

Run: `php -d memory_limit=512M vendor/bin/phpstan analyse`
Expected: 0 errors.

- [x] **Step 6b: Update Tailwind's `@source` directives for the new `src/` tree**

`frontend/styleguide.css` currently declares `@source "./index.html"; @source "./components/**/*.js"; @source "./stores/**/*.js";` (Tailwind v4's content-detection allowlist). Every Vue SFC from this task onward lives under `frontend/src/**/*.vue`, and utility classes inside a `.vue` file's `<template>` block are invisible to Tailwind unless a matching `@source` glob covers `.vue` files. Left unfixed, every class added by Tasks 4-11 would silently fail to generate — the single highest-risk regression in this whole migration, and one with no error message, just missing CSS. Fix it now, before the first Vue template with real utility classes lands (`App.vue`, Step 11 below):

```diff
 @import "tailwindcss";

 /* Tailwind v4 default content-detection skips files outside known patterns.
    Explicitly source the Vite entry + JS modules so utility classes get picked up. */
 @source "./index.html";
-@source "./components/**/*.js";
-@source "./stores/**/*.js";
+@source "./src/**/*.{js,vue}";
+/* Legacy Alpine sources stay scanned until Task 14 deletes them, so classes
+   used only by not-yet-ported markup keep generating during the migration. */
+@source "./components/**/*.js";
+@source "./stores/**/*.js";
```

Run: `cd frontend && npm run build`
Expected: exit 0 (this only re-confirms the glob syntax is valid; Step 14 below re-verifies with a class that exists ONLY inside a new `.vue` file, once `App.vue` exists to provide one).

- [x] **Step 7: Rewrite `frontend/index.html`**

Replace `<head>`'s favicon/title elements and `<body>`'s data attributes with the single config script; keep the FOUC-prevention inline script and the Tailwind/asset tags. New `<head>`:

```html
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Styleguide</title>
    <link rel="icon" id="sg-favicon-tag" href="">
    <script id="sg-config" type="application/json">{}</script>
    <!--
        Synchronous FOUC-prevention: apply `.dark` on <html> BEFORE the
        stylesheet loads so a dark-mode reload doesn't flash light. Reads
        the same localStorage key usePersistedRef writes for the theme
        store (bare `sg-theme` name, JSON-encoded). Falls back to
        `prefers-color-scheme` when nothing is persisted yet. Wrapped in
        try/catch because Safari private mode throws on localStorage access.
    -->
    <script>
        (function () {
            var mode = 'system';
            try {
                var stored = localStorage.getItem('sg-theme');
                if (stored) mode = JSON.parse(stored);
            } catch (e) {}
            var dark = mode === 'dark' || (mode === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
            if (dark) document.documentElement.classList.add('dark');
        })();
    </script>
    <link rel="stylesheet" href="./styleguide.css">
    <script type="module" src="./src/main.js"></script>
</head>
```

New `<body>` (mount point only — Task 5 onward builds the real DOM inside `App.vue`):

```html
<body class="bg-white text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100 font-sans antialiased">
    <div id="app"></div>
</body>
```

Note: `data-project-name`/`data-project-favicon` on `<body>` and `data-default-locale` on `<html>` are removed — they existed only so vanilla JS (favicon-fallback listener, document-title effect, `i18n.js`'s `detectLocale()`) could read server-injected values off the DOM before Vue/Pinia existed to hold that state reactively. `readSpaConfig()` (Step 8) is the new single source for the same three values; `detectLocale()` (ported unchanged into `stores/i18n.js`, Task 3) still reads `html.dataset.defaultLocale` as a fallback layer, so `main.js` (Step 10) stamps that attribute back onto `<html>` itself, in JS, right after reading the config.

- [x] **Step 8: Write the failing test for `lib/config.js`, then implement**

Create `frontend/src/lib/config.spec.js`:

```js
import { describe, it, expect, beforeEach } from 'vitest';
import { readSpaConfig } from './config.js';

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('readSpaConfig', () => {
    it('parses the JSON payload out of the #sg-config script element', () => {
        const el = document.createElement('script');
        el.id = 'sg-config';
        el.type = 'application/json';
        el.textContent = JSON.stringify({ locale: 'cs', projectName: 'Acme', favicon: '/f.svg', title: 'Styleguide — Acme', baseUrl: '/styleguide' });
        document.body.appendChild(el);

        expect(readSpaConfig()).toEqual({
            locale: 'cs', projectName: 'Acme', favicon: '/f.svg', title: 'Styleguide — Acme', baseUrl: '/styleguide',
        });
    });

    it('throws when the element is missing', () => {
        expect(() => readSpaConfig()).toThrow(/missing #sg-config/);
    });

    it('throws when the element contains invalid JSON', () => {
        const el = document.createElement('script');
        el.id = 'sg-config';
        el.textContent = '{not valid json';
        document.body.appendChild(el);
        expect(() => readSpaConfig()).toThrow();
    });

    it('accepts a custom element id', () => {
        const el = document.createElement('script');
        el.id = 'custom-config';
        el.textContent = '{"locale":"en"}';
        document.body.appendChild(el);
        expect(readSpaConfig('custom-config')).toEqual({ locale: 'en' });
    });
});
```

Run: `cd frontend && npx vitest run src/lib/config.spec.js` → fails (module missing).

Create `frontend/src/lib/config.js`:

```js
// Reads the server-injected config payload out of the single
// <script id="sg-config" type="application/json"> element that
// Styleguide::dispatchSpa() substitutes into dist/index.html. Throws
// (rather than falling back to a default) when the element is missing or
// unparsable — the PHP side made the matching decision (throw instead of
// silently no-op'ing 6+ separate regexes), so a build/deploy mismatch fails
// loudly on both ends instead of shipping a half-configured shell.
export function readSpaConfig(elementId = 'sg-config') {
    const el = document.getElementById(elementId);
    if (!el) throw new Error(`[styleguide] missing #${elementId} script element`);
    return JSON.parse(el.textContent || '{}');
}
```

Run: `cd frontend && npx vitest run src/lib/config.spec.js`
Expected: `Tests 4 passed`.

- [x] **Step 9: `src/router.js`**

Create `frontend/src/router.js`:

```js
import { createRouter, createWebHistory } from 'vue-router';
import { useUiStore } from './stores/ui.js';
import { routeInfo } from './lib/routeInfo.js';
import PreviewView from './views/PreviewView.vue';
import OverviewView from './views/OverviewView.vue';
import FoundationsView from './views/FoundationsView.vue';

// Route table mirrors frontend/router.js's regex exactly:
//   ^/styleguide(?:\/(component|page|doc|overview|foundations|fields)(?:\/(.+?))?\/?$
// Bare "/" (i.e. /styleguide or /styleguide/) renders FoundationsView
// directly — NOT a redirect — so the address bar stays at "/" exactly like
// the legacy router's "no pushState" landing behavior (a vue-router
// `redirect` would rewrite the visible URL to /foundations, an observable
// behavior change the parity constraint forbids).
// Route components deliberately take NO props for type/slug: the shared
// viewport chrome (toolbar/description/usage/links/fields) is owned by
// App.vue, one level above <RouterView/>, and derives type/slug from
// useRoute() itself (see Task 7 Step 9) — mirroring the legacy DOM, where
// the toolbar/description/usage/link/fields bars are siblings of the
// route-specific content inside the SAME <main x-data="preview"> scope, not
// nested inside it. PreviewView only ever renders <PreviewPane/>, which
// independently injects the same 'viewport' instance.
const routes = [
    { path: '/', name: 'landing', component: FoundationsView },
    { path: '/component/:slug', name: 'component', component: PreviewView },
    { path: '/page/:slug', name: 'page', component: PreviewView },
    { path: '/doc/:slug', name: 'doc', component: PreviewView },
    { path: '/overview', name: 'overview', component: OverviewView },
    { path: '/foundations', name: 'foundations', component: FoundationsView },
    // Dead-but-preserved: /fields used to be a top-level route; fields are
    // now an inline per-component drawer (see FieldsDrawer.vue, Task 9).
    // PreviewView renders PreviewPane's existing "no iframe src" empty
    // state for type 'fields' — identical to today's dead route.
    { path: '/fields', name: 'fields', component: PreviewView },
    // Any unmatched path falls back to the landing/foundations view, same
    // as the legacy parse()'s `if (!m) return { type: 'landing', slug: null }`.
    { path: '/:pathMatch(.*)*', name: 'not-found-fallback', component: FoundationsView },
];

export const router = createRouter({
    history: createWebHistory('/styleguide'),
    routes,
});

// Replaces the legacy router.js apply()/popstate wiring: flips
// ui.isPreviewLoading synchronously BEFORE the URL/DOM update, same
// ordering guarantee the legacy code calls out as load-bearing (avoids a
// race with cached iframe `load` events firing before isLoading flips true).
router.beforeEach((to) => {
    const ui = useUiStore();
    const { type, slug } = routeInfo(to);
    ui.setRoute(type, slug);
});
```

- [x] **Step 10: `src/main.js`**

Create `frontend/src/main.js`:

```js
import { createApp } from 'vue';
import { createPinia } from 'pinia';

import './styleguide.css';

import App from './App.vue';
import { router } from './router.js';
import { readSpaConfig } from './lib/config.js';
import { useI18nStore } from './stores/i18n.js';
import { useUiStore } from './stores/ui.js';
import { useThemeStore } from './stores/theme.js';
import { useCatalogStore } from './stores/catalog.js';

const config = readSpaConfig();

// detectLocale() in stores/i18n.js falls back to html.dataset.defaultLocale
// when no URL param / localStorage value picks a locale — index.html no
// longer stamps that attribute server-side (dispatchSpa() now only injects
// #sg-config), so we set it here, in JS, from the same config payload.
document.documentElement.dataset.defaultLocale = config.locale;

const app = createApp(App);
app.use(createPinia());
app.use(router);

const i18n = useI18nStore();
const ui = useUiStore();
const theme = useThemeStore();
const catalog = useCatalogStore();

theme.init();
i18n.init();
ui.initFromUrl();
catalog.init();

app.mount('#app');

// Favicon fallback — recover with a generic glyph if the configured favicon
// 404s, isn't a valid image, or no favicon is configured. Ported from
// styleguide.js; App.vue (Task 5) binds #sg-favicon's `src`/`alt`
// reactively to config.favicon — this only wires the `error` listener
// once at boot.
const GENERIC_FAVICON = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='3' width='18' height='18' rx='2'/%3E%3Cpath d='M3 9h18M9 21V9'/%3E%3C/svg%3E";
document.addEventListener('DOMContentLoaded', () => {
    const favEl = document.getElementById('sg-favicon');
    if (!favEl) return;
    const applyFallback = () => {
        if (favEl.src === GENERIC_FAVICON) return;
        favEl.src = GENERIC_FAVICON;
        favEl.classList.add('p-1');
    };
    favEl.addEventListener('error', applyFallback);
    if (!favEl.getAttribute('src') || (favEl.complete && favEl.naturalWidth === 0)) applyFallback();
});

// document.title sync — replaces the Alpine.effect in the legacy
// styleguide.js. Runs on every route/locale/catalog change via a Pinia
// subscription rather than Alpine's auto-tracking effect.
function syncTitle() {
    const route = router.currentRoute.value;
    let label;
    if (route.name === 'overview') {
        label = i18n.t('nav.overview');
    } else if (route.name === 'foundations' || route.name === 'landing') {
        label = i18n.t('nav.foundations');
    } else if (route.params.slug) {
        const item = catalog.find(route.name, route.params.slug);
        label = item?.name ?? route.params.slug;
    }
    document.title = label ? `${label} — ${config.projectName}` : `Styleguide — ${config.projectName}`;
}
router.afterEach(syncTitle);
i18n.$subscribe(syncTitle);
catalog.$subscribe(syncTitle);
syncTitle();

export { config };
```

- [x] **Step 11: `src/App.vue` — permanent outer layout, stub inner regions**

Create `frontend/src/App.vue` (the outer flex shell is final; Tasks 5-11 replace the two stub regions with real components):

```vue
<script setup>
import { useUiStore } from './stores/ui.js';

const ui = useUiStore();
</script>

<template>
    <div class="flex h-screen overflow-hidden">
        <div
            v-show="ui.sidebarOpen"
            class="fixed inset-0 z-40 bg-black/40 lg:hidden"
            @click="ui.toggleSidebar()"
        ></div>

        <!-- Task 5 replaces this with <Sidebar /> -->
        <aside class="w-72 bg-zinc-50 border-r border-zinc-200 dark:bg-zinc-900 dark:border-zinc-800 flex flex-col fixed inset-y-0 left-0 z-50 transition-transform duration-200 lg:static lg:z-auto lg:transition-none"
               :class="ui.sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:hidden'">
        </aside>

        <!-- Task 7-11 replace this with the toolbar/description/usage/links/fields chrome around <RouterView /> -->
        <main class="flex-1 flex flex-col min-w-0 bg-white dark:bg-zinc-950">
            <RouterView />
        </main>
    </div>
</template>
```

- [x] **Step 12: Stub the three view components so the router resolves**

Create minimal stubs — Tasks 7/8/11 fill these in for real:

`frontend/src/views/PreviewView.vue`:
```vue
<template>
    <div>Preview stub</div>
</template>
```

`frontend/src/views/OverviewView.vue`:
```vue
<template>
    <div>Overview stub</div>
</template>
```

`frontend/src/views/FoundationsView.vue`:
```vue
<template>
    <div>Foundations stub</div>
</template>
```

- [x] **Step 13: Leave the legacy Alpine sources in place for now**

`frontend/index.html` already stopped referencing `./styleguide.js` in Step 7 (it now loads `./src/main.js`). Leave `frontend/styleguide.js`, `frontend/router.js`, `frontend/components/`, `frontend/stores/` on disk untouched — Task 14 deletes them once every feature they contain has a proven Vue equivalent (Tasks 5-11). Deleting early would make mid-migration `git diff` review harder — no way to visually compare old vs new implementations side by side.

- [x] **Step 14: Verify the build — including that Vue templates actually feed Tailwind**

Run: `cd frontend && npm run build`
Expected: exit 0. Inspect `dist/index.html` — confirm it contains `<script id="sg-config" type="application/json">{}</script>` and `<div id="app">`.

Then confirm the Step 6b `@source` fix actually works end-to-end: `App.vue` (Step 11 above) uses `bg-black/40` on the mobile backdrop `<div>` — a class that appears NOWHERE in the legacy `index.html`/`components/`/`stores/` sources, only in the new `.vue` file. Run:

```bash
grep -o 'bg-black' dist/styleguide.*.css
```

Expected: at least one match. A miss here means the `@source "./src/**/*.{js,vue}"` glob (Step 6b) isn't actually being picked up — stop and fix it before continuing to Task 5, since every Vue template's utility classes would silently be missing from every build from here on.

- [x] **Step 15: Verify PHP injection end-to-end against the real fixture**

Run:
```bash
php -S 127.0.0.1:8421 -t tests/fixtures tests/fixtures/index.php &
sleep 1
curl -s http://127.0.0.1:8421/styleguide/ | grep -o '<script id="sg-config"[^<]*</script>'
kill %1
```
Expected: prints `<script id="sg-config" type="application/json">{"locale":"cs","projectName":"Styleguide Fixture",...}</script>` — confirms the PHP substitution fires against the real built `dist/index.html`, not just the synthetic string in `SpaConfigTest`.

- [x] **Step 16: Full regression + commit**

Run: `composer test && composer phpstan && cd frontend && npm test && npm run build`
Expected: all green.

```bash
git add tests/SpaConfigTest.php src/Styleguide.php frontend/index.html \
        frontend/src/main.js frontend/src/App.vue frontend/src/router.js \
        frontend/src/lib/config.js frontend/src/lib/config.spec.js \
        frontend/src/views
git commit -m "feat(spa): replace 6 silently-no-op regex patches with one #sg-config injection

Styleguide::dispatchSpa() now injects a single JSON payload (locale,
projectName, favicon, title, baseUrl) into dist/index.html and throws
when the injection point is missing, instead of silently shipping a
half-patched shell. Stands up the Vue 3 + Pinia + vue-router app shell
that Tasks 5-11 fill in."
```

---

### Task 5: `Sidebar.vue`

Ports `frontend/index.html` lines 55-293 (the `<aside>` block: header/logo/theme-toggle, search input markup, docs/basic/blocks/gutenberg section nav with prefix-tree rendering, Pages section, footer with Porta credit + language switcher) plus the logic in `frontend/components/sidebar.js` and `frontend/components/languageSwitcher.js`. Search keyboard shortcuts (⌘K/Esc) are deliberately deferred to Task 6 — this task wires the input to `ui.searchQuery` via plain `v-model` only.

**Files:**
- Create: `frontend/src/components/Sidebar.vue` + `frontend/src/components/Sidebar.spec.js`
- Modify: `frontend/src/App.vue` (swap the `<aside>` stub for `<Sidebar />`)

**Interfaces:**
- Props/emits: none (mounted once in `App.vue`, reads everything from stores/router).
- Consumes: `useCatalogStore()` (`docEntries`, `bySection(section)`, `treeOf(section)`, `pagesTree`), `useUiStore()` (`searchQuery`, `sidebarOpen`, `toggleSidebar()`), `useI18nStore()` (`t(path)`), `useThemeStore()` (`mode`, `cycle()`), `useRoute()`/`useRouter()` from vue-router, `filterItems` from `lib/searchMatch.js`, `usePersistedRef` from `lib/persistedRef.js`.
- Produces: navigates via `router.push()` (replacing the legacy global `window.sgNavigate`); no emits.
- Local persisted state (component-scoped, not store state — matches legacy exactly): `sections = usePersistedRef('sg-sections', { docs: true, basic: true, blocks: true, gutenberg: false, pages: false })`, `groups = usePersistedRef('sg-groups', {})`.

- [x] **Step 1: Write the failing test**

Create `frontend/src/components/Sidebar.spec.js`:

```js
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import Sidebar from './Sidebar.vue';
import { useCatalogStore } from '../stores/catalog.js';
import { useUiStore } from '../stores/ui.js';
import { useI18nStore } from '../stores/i18n.js';

function makeRouter() {
    return createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'landing', component: { template: '<div/>' } },
            { path: '/component/:slug', name: 'component', component: { template: '<div/>' } },
            { path: '/overview', name: 'overview', component: { template: '<div/>' } },
            { path: '/foundations', name: 'foundations', component: { template: '<div/>' } },
        ],
    });
}

async function mountSidebar() {
    setActivePinia(createPinia());
    const catalog = useCatalogStore();
    catalog.items = [
        { id: 'widget-one', name: 'Widget - one', category: 'Block', hasStyleguide: true },
        { id: 'widget-two', name: 'Widget - two', category: 'Block', hasStyleguide: true },
        { id: 'widget-three', name: 'Widget - three', category: 'Block', hasStyleguide: true },
        { id: 'gizmo', name: 'Gizmo', category: '', hasStyleguide: true },
    ];
    catalog.pages = [{ id: 'homepage', name: 'Homepage', hasStyleguide: true }];
    catalog.docs = [];
    catalog.loading = false;
    useI18nStore().strings = { nav: { docs: 'Docs', overview: 'Overview', foundations: 'Foundations', styleguide: 'Styleguide' }, sections: { basic: 'Basic', blocks: 'Blocks', gutenberg: 'Gutenberg', pages: 'Pages' }, search: { label: 'Search', placeholder: 'Search...' } };

    const router = makeRouter();
    await router.push('/foundations');
    const wrapper = mount(Sidebar, { global: { plugins: [router] } });
    await router.isReady();
    return { wrapper, router };
}

beforeEach(() => {
    vi.stubGlobal('matchMedia', vi.fn().mockReturnValue({ matches: false, addEventListener: vi.fn(), addListener: vi.fn() }));
    localStorage.clear();
});

describe('Sidebar', () => {
    it('renders a Widget group for a >=3 prefix cluster with suffix-only children', async () => {
        const { wrapper } = await mountSidebar();
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('Widget');
        expect(wrapper.text()).toContain('One');
        expect(wrapper.text()).not.toContain('Widget - one');
    });

    it('renders the Gizmo singleton flat with its full name', async () => {
        const { wrapper } = await mountSidebar();
        expect(wrapper.text()).toContain('Gizmo');
    });

    it('navigates via router.push when a component link is clicked, then closes the sidebar on mobile', async () => {
        vi.stubGlobal('matchMedia', vi.fn().mockReturnValue({ matches: true, addEventListener: vi.fn(), addListener: vi.fn() }));
        const { wrapper, router } = await mountSidebar();
        const ui = useUiStore();
        ui.sidebarOpen = true;
        const gizmoLink = wrapper.findAll('a').find((a) => a.text() === 'Gizmo');
        await gizmoLink.trigger('click');
        expect(router.currentRoute.value.fullPath).toBe('/component/gizmo');
        expect(ui.sidebarOpen).toBe(false);
    });

    it('flattens the Widget group to full names while a search query is active', async () => {
        const { wrapper } = await mountSidebar();
        const ui = useUiStore();
        ui.searchQuery = 'widget';
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('Widget - one');
    });

    it('toggleSection persists to sg-sections', async () => {
        const { wrapper } = await mountSidebar();
        const toggle = wrapper.findAll('button').find((b) => b.text() === 'Basic');
        await toggle.trigger('click');
        await wrapper.vm.$nextTick();
        expect(JSON.parse(localStorage.getItem('sg-sections')).basic).toBe(false);
    });
});
```

- [x] **Step 2: Run and confirm failure**

Run: `cd frontend && npx vitest run src/components/Sidebar.spec.js`
Expected: fails — `Sidebar.vue` does not exist.

- [x] **Step 3: Implement `Sidebar.vue`**

Create `frontend/src/components/Sidebar.vue`. Script block (full logic — port the template markup from `frontend/index.html:55-293` using the directive-translation table below):

```vue
<script setup>
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useCatalogStore } from '../stores/catalog.js';
import { useUiStore } from '../stores/ui.js';
import { useI18nStore } from '../stores/i18n.js';
import { useThemeStore } from '../stores/theme.js';
import { filterItems } from '../lib/searchMatch.js';
import { usePersistedRef } from '../lib/persistedRef.js';

const catalog = useCatalogStore();
const ui = useUiStore();
const i18n = useI18nStore();
const theme = useThemeStore();
const route = useRoute();
const router = useRouter();

const sections = usePersistedRef('sg-sections', {
    docs: true, basic: true, blocks: true, gutenberg: false, pages: false,
});
const groups = usePersistedRef('sg-groups', {});

function toggleSection(key) {
    sections.value[key] = !sections.value[key];
}

function groupKey(section, prefix) {
    return `${section}/${prefix}`;
}

function isGroupOpen(section, prefix, children) {
    if (children.some((c) => isActive('component', c.id) || isActive('page', c.id))) return true;
    return groups.value[groupKey(section, prefix)] ?? true;
}

function toggleGroup(section, prefix) {
    const key = groupKey(section, prefix);
    groups.value[key] = !(groups.value[key] ?? true);
}

function isActive(type, slug) {
    return route.name === type && route.params.slug === slug;
}

function isActiveMeta(type) {
    // 'foundations'/'landing' both count as the Foundations nav item being
    // active — mirrors the legacy isActive('foundations', null) check,
    // which only ever compared against ui.route.type (never distinguished
    // landing from foundations, since router.js already folds landing into
    // foundations before ui.route is set).
    if (type === 'foundations') return route.name === 'foundations' || route.name === 'landing' || route.name === 'not-found-fallback';
    return route.name === type;
}

function select(type, slug) {
    const path = slug ? `/${type}/${slug}` : `/${type}`;
    router.push(path);
    if (window.matchMedia('(max-width: 1023px)').matches) {
        ui.sidebarOpen = false;
    }
}

function items(section) {
    return filterItems(catalog.bySection(section), ui.searchQuery);
}

const docItems = computed(() => filterItems(catalog.docEntries, ui.searchQuery));
const pageItems = computed(() => filterItems(catalog.pages.filter((p) => p.hasStyleguide !== false), ui.searchQuery));

function supportedLocales() {
    return ['cs', 'en'];
}
</script>
```

- [x] **Step 4: Port the template**

Append the `<template>` block, translating `frontend/index.html:55-293` per this table (mechanical, 1:1):

| Alpine | Vue |
|---|---|
| `x-show="expr"` | `v-show="expr"` |
| `x-text="expr"` | `{{ expr }}` (inline text node) |
| `x-html="expr"` | `v-html="expr"` |
| `x-for="item in list" :key="k"` | `v-for="item in list" :key="k"` |
| `<template x-if="cond">...</template>` | `<template v-if="cond">...</template>` |
| `@click.prevent="select(...)"` | `@click.prevent="select(...)"` (unchanged — Vue has the same modifier) |
| `:class="expr"` | `:class="expr"` (unchanged) |
| `$store.i18n.t(x)` | `i18n.t(x)` |
| `$store.ui.X` | `ui.X` |
| `$store.components.X` | `catalog.X` |
| `$store.theme.X` | `theme.X` |
| `filterItems($store.components.bySection(s))` | `items(s)` (local helper above) |
| `filterItems($store.components.docEntries)` | `docItems` |
| `filterItems($store.components.pages)` | `pageItems` |
| `isActive('foundations', null)` | `isActiveMeta('foundations')` |
| `isActive('component'/'page', x)` | `isActive('component'/'page', x)` (unchanged signature) |
| `$store.i18n.t(\`sections.${section}\`)` | `i18n.t(\`sections.${section}\`)` |
| `x-data="{ open: false }"` (n/a here — none in the sidebar block) | — |

The Porta wordmark SVG, heart icon, and the two theme-mode SVG `<template x-if>` blocks copy verbatim (no directives to translate beyond the table). The language-switcher footer (`frontend/components/languageSwitcher.js` + its markup at `index.html:282-291`) inlines directly using `i18n.locale`/`i18n.load(loc)`/`supportedLocales()` in place of the old `languageSwitcher` Alpine component — it was a 3-getter wrapper with no independent state worth extracting into its own file.

- [x] **Step 5: Run and confirm the test passes**

Run: `cd frontend && npx vitest run src/components/Sidebar.spec.js`
Expected: `Tests 5 passed`.

- [x] **Step 6: Wire into `App.vue`**

Edit `frontend/src/App.vue` — replace the stub `<aside>...</aside>` block:

```diff
-        <!-- Task 5 replaces this with <Sidebar /> -->
-        <aside class="w-72 bg-zinc-50 border-r border-zinc-200 dark:bg-zinc-900 dark:border-zinc-800 flex flex-col fixed inset-y-0 left-0 z-50 transition-transform duration-200 lg:static lg:z-auto lg:transition-none"
-               :class="ui.sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:hidden'">
-        </aside>
+        <Sidebar />
```

Add the import: `import Sidebar from './components/Sidebar.vue';` at the top of the `<script setup>` block.

- [x] **Step 7: Full suite + build + commit**

Run: `cd frontend && npm test && npm run build`
Expected: all green, build succeeds.

```bash
git add frontend/src/components/Sidebar.vue frontend/src/components/Sidebar.spec.js frontend/src/App.vue
git commit -m "feat(spa): port Sidebar to Vue — sections, prefix-tree groups, active pill, mobile slide-over"
```

---

### Task 6: Search keyboard shortcuts (⌘K focus, Esc clear)

Ports `frontend/components/search.js` — a 15-line Alpine component whose only job is a `window` keydown listener (⌘K/Ctrl+K focuses the input, Esc clears the query and blurs). Implemented as a composable rather than a component: the legacy file has no template of its own (its `x-data="search"` scope wraps markup that already lives inside `Sidebar.vue`'s search block from Task 5), so a "SearchPalette.vue" wrapper would just be a pass-through with no independent responsibility.

**Files:**
- Create: `frontend/src/composables/useSearchShortcuts.js` + `frontend/src/composables/useSearchShortcuts.spec.js`
- Modify: `frontend/src/components/Sidebar.vue` (call the composable, bind the template ref)

**Interfaces:**
- `useSearchShortcuts(inputRef: Ref<HTMLInputElement|null>)` — registers a `window` `keydown` listener on `onMounted`, removes it on `onUnmounted`; on `Cmd/Ctrl+K` calls `inputRef.value?.focus()` (and `preventDefault()`); on `Escape` sets `useUiStore().searchQuery = ''` and calls `inputRef.value?.blur()`. No return value — side-effect-only composable, matching the legacy component's shape exactly.

- [x] **Step 1: Write the failing test**

Create `frontend/src/composables/useSearchShortcuts.spec.js`:

```js
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { defineComponent, h, ref } from 'vue';
import { mount } from '@vue/test-utils';
import { useSearchShortcuts } from './useSearchShortcuts.js';
import { useUiStore } from '../stores/ui.js';

const Host = defineComponent({
    setup() {
        const inputRef = ref(null);
        useSearchShortcuts(inputRef);
        return () => h('input', { ref: inputRef });
    },
});

let wrapper;

beforeEach(() => {
    setActivePinia(createPinia());
    wrapper = mount(Host, { attachTo: document.body });
});

afterEach(() => {
    wrapper.unmount();
});

describe('useSearchShortcuts', () => {
    it('focuses the input on Cmd+K', () => {
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true }));
        expect(document.activeElement).toBe(wrapper.find('input').element);
    });

    it('focuses the input on Ctrl+K', () => {
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', ctrlKey: true }));
        expect(document.activeElement).toBe(wrapper.find('input').element);
    });

    it('clears the search query and blurs on Escape', () => {
        const ui = useUiStore();
        ui.searchQuery = 'widget';
        wrapper.find('input').element.focus();
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(ui.searchQuery).toBe('');
        expect(document.activeElement).not.toBe(wrapper.find('input').element);
    });

    it('removes the window listener on unmount', () => {
        wrapper.unmount();
        // No assertion beyond "does not throw" — jsdom has no direct API to
        // introspect registered listener count; this guards against a
        // double-focus crash if a second Host mounts after this one unmounts.
        const w2 = mount(Host, { attachTo: document.body });
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true }));
        expect(document.activeElement).toBe(w2.find('input').element);
        w2.unmount();
    });
});
```

- [x] **Step 2: Run and confirm failure**

Run: `cd frontend && npx vitest run src/composables/useSearchShortcuts.spec.js`
Expected: fails — module missing.

- [x] **Step 3: Implement**

Create `frontend/src/composables/useSearchShortcuts.js`:

```js
import { onMounted, onUnmounted } from 'vue';
import { useUiStore } from '../stores/ui.js';

// Ported from frontend/components/search.js. Query state lives in
// useUiStore().searchQuery so any component can read it reactively — this
// composable only wires the two keyboard shortcuts to a given input ref.
export function useSearchShortcuts(inputRef) {
    const ui = useUiStore();

    function onKeydown(e) {
        if (e.key === 'k' && (e.metaKey || e.ctrlKey)) {
            e.preventDefault();
            inputRef.value?.focus();
        }
        if (e.key === 'Escape') {
            ui.searchQuery = '';
            inputRef.value?.blur();
        }
    }

    onMounted(() => window.addEventListener('keydown', onKeydown));
    onUnmounted(() => window.removeEventListener('keydown', onKeydown));
}
```

- [x] **Step 4: Run and confirm pass**

Run: `cd frontend && npx vitest run src/composables/useSearchShortcuts.spec.js`
Expected: `Tests 4 passed`.

- [x] **Step 5: Wire into `Sidebar.vue`**

Edit `frontend/src/components/Sidebar.vue` — add to the `<script setup>` block:

```js
import { ref } from 'vue';
import { useSearchShortcuts } from '../composables/useSearchShortcuts.js';

const searchInputRef = ref(null);
useSearchShortcuts(searchInputRef);
```

In the template, bind the search `<input>` (ported from `index.html:109-114`) with `ref="searchInputRef"` in place of the legacy `x-ref="input"`, and the `<kbd>` shortcut hint keeps its `{{ i18n.t('search.shortcut_hint') }}` text unchanged.

- [x] **Step 6: Regression test + commit**

Run: `cd frontend && npm test`
Expected: `Sidebar.spec.js` still passes (search input still renders and filters); `useSearchShortcuts.spec.js` passes.

```bash
git add frontend/src/composables/useSearchShortcuts.js frontend/src/composables/useSearchShortcuts.spec.js frontend/src/components/Sidebar.vue
git commit -m "feat(spa): port search keyboard shortcuts (Cmd+K focus, Escape clear) to a composable"
```

---

### Task 7: `useViewportPreset` composable + `ViewportToolbar.vue` + real `PreviewView.vue`

`frontend/components/preview.js` is one 715-line Alpine component backing the ENTIRE main pane in the legacy app — toolbar, description bar, usage panel, link bar, fields drawer, and the iframe itself all share its single `x-data="preview"` scope (see `index.html:305`, `<main x-data="preview" ...>` wraps all of it). The Vue rewrite decomposes that one scope into multiple `.vue` files that share one composable instance via `provide`/`inject`, so the reactive state stays unified exactly like the legacy single-scope design, while the template responsibilities split cleanly. This task creates the composable (the full logic surface) and the toolbar; Task 8 fills in the iframe pane itself.

**Files:**
- Modify: `frontend/vitest.config.js` (add a `setupFiles` entry — jsdom has no `ResizeObserver`)
- Create: `frontend/src/testSetup.js`
- Create: `frontend/src/composables/useViewportPreset.js` + `frontend/src/composables/useViewportPreset.spec.js`
- Create: `frontend/src/components/ViewportToolbar.vue` + `frontend/src/components/ViewportToolbar.spec.js`
- Modify: `frontend/src/views/PreviewView.vue` (replace the Task 4 stub with the real shell: provides the composable, renders `<ViewportToolbar/>`, description bar, and stub placeholders for `<UsagePanel/>`/`<LinkBar/>`/`<FieldsDrawer/>`/`<PreviewPane/>` that Tasks 8-10 fill in)

**Interfaces:**
- `useViewportPreset({ type: Ref<string>, slug: Ref<string|null> })` → returns a reactive bag: `currentItem`, `activePreset`, `activePresetCategory`, `isFullPreset`, `effective` (`{width,height}`), `zoom`, `dimensionsLabel`, `isPortrait`, `setPreset(key)`, `setPortrait(bool)`, `customWidthInput` (ref, v-model target), `applyCustomWidth()`, `reloadPreview()`, `iframeSrc`, `toolbarVisible`, `currentSectionKey`, `currentItemName`, `currentItemDescription`, `fieldsTree`, `fieldsCount`, `isDragging`, `startDrag(event)`, `observeWrapper(el)`, `observeContainer(el)`, `CUSTOM_WIDTH_MIN`, `CUSTOM_WIDTH_MAX`, `VIEWPORTS`.
- Provide/inject key: `'viewport'` — `App.vue` calls `provide('viewport', useViewportPreset({ type, slug }))` with `type`/`slug` derived from `useRoute()` (see Step 9 below — this lives in `App.vue`, one level above `<RouterView/>`, NOT in `PreviewView.vue`, so the toolbar/description/usage/links/fields chrome renders identically on every route including `/overview` and `/foundations`, matching the legacy single-scope `<main x-data="preview">`); `ViewportToolbar.vue` (this task) and `PreviewPane.vue`/`FieldsDrawer.vue`/`UsagePanel.vue`/`LinkBar.vue` (Tasks 8-10) call `inject('viewport')`.
- `ViewportToolbar.vue` — no props (reads everything via inject); no emits.

- [x] **Step 1: Add the jsdom `ResizeObserver` stub**

Create `frontend/src/testSetup.js`:

```js
// jsdom does not implement ResizeObserver. Every component that measures its
// own DOM box (the viewport composable's observeContainer/observeWrapper,
// PreviewPane's iframe-content auto-fit) needs a no-op stand-in so mounting
// them in a test doesn't throw `ResizeObserver is not defined`. Tests that
// need actual resize callbacks invoke the stored callback manually — see
// PreviewPane.spec.js (Task 8) for that pattern.
class ResizeObserverStub {
    constructor(callback) {
        this.callback = callback;
    }
    observe() {}
    unobserve() {}
    disconnect() {}
}

global.ResizeObserver = ResizeObserverStub;
```

Edit `frontend/vitest.config.js` to load it:

```js
import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'jsdom',
        include: ['src/**/*.spec.js'],
        setupFiles: ['./src/testSetup.js'],
        globals: false,
    },
});
```

- [x] **Step 2: Write the failing test for `useViewportPreset`**

Create `frontend/src/composables/useViewportPreset.spec.js`:

```js
import { describe, it, expect, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { ref, nextTick } from 'vue';
import { useViewportPreset } from './useViewportPreset.js';
import { useUiStore } from '../stores/ui.js';
import { useCatalogStore } from '../stores/catalog.js';

beforeEach(() => {
    setActivePinia(createPinia());
});

describe('useViewportPreset', () => {
    it('iframeSrc builds a render URL for a component route', () => {
        const type = ref('component');
        const slug = ref('hero');
        const vp = useViewportPreset({ type, slug });
        expect(vp.iframeSrc.value).toBe('/styleguide/render/component/hero');
    });

    it('iframeSrc is null for overview (no route slug, not foundations)', () => {
        const type = ref('overview');
        const slug = ref(null);
        const vp = useViewportPreset({ type, slug });
        expect(vp.iframeSrc.value).toBeNull();
    });

    it('iframeSrc uses the fixed foundations/index path regardless of slug', () => {
        const type = ref('foundations');
        const slug = ref(null);
        const vp = useViewportPreset({ type, slug });
        expect(vp.iframeSrc.value).toBe('/styleguide/render/foundations/index');
    });

    it('reloadPreview appends an incrementing _r nonce to iframeSrc', () => {
        const type = ref('component');
        const slug = ref('hero');
        const catalog = useCatalogStore();
        catalog.init = () => {};
        const vp = useViewportPreset({ type, slug });
        vp.reloadPreview();
        expect(vp.iframeSrc.value).toBe('/styleguide/render/component/hero?_r=1');
        vp.reloadPreview();
        expect(vp.iframeSrc.value).toBe('/styleguide/render/component/hero?_r=2');
    });

    it('setPreset applies both width and height from the VIEWPORTS table', () => {
        const type = ref('component');
        const slug = ref('hero');
        const ui = useUiStore();
        const vp = useViewportPreset({ type, slug });
        vp.setPreset('tablet');
        expect(ui.previewWidth).toBe('768px');
        expect(ui.previewHeight).toBe(1024);
        expect(vp.activePreset.value).toBe('tablet');
        expect(vp.activePresetCategory.value).toBe('tablet');
    });

    it('toolbarVisible is false when the current item is responsive:false', () => {
        const type = ref('doc');
        const slug = ref('sample-doc');
        const catalog = useCatalogStore();
        catalog.docs = [{ id: 'sample-doc', name: 'Sample doc', responsive: false }];
        const vp = useViewportPreset({ type, slug });
        expect(vp.toolbarVisible.value).toBe(false);
        expect(vp.effective.value).toEqual({ width: null, height: null });
    });

    it('dimensionsLabel reports the scaled percentage when zoom < 1', () => {
        const type = ref('component');
        const slug = ref('hero');
        const ui = useUiStore();
        const vp = useViewportPreset({ type, slug });
        vp.setPreset('desktop-2k');
        vp.observeContainer(null); // no container measured -> width/height stay 0
        // Simulate a measured 1280x800 container via the ResizeObserver callback path:
        vp.observeContainer({ clientWidth: 1328, clientHeight: 848, addEventListener: () => {} });
        expect(vp.zoom.value).toBeCloseTo(0.5, 2);
        expect(vp.dimensionsLabel.value).toBe('2560 × 1440 (50 %)');
    });

    it('applyCustomWidth rejects an out-of-range value and reverts the input', () => {
        const type = ref('component');
        const slug = ref('hero');
        const ui = useUiStore();
        ui.setWidth('375px');
        const vp = useViewportPreset({ type, slug });
        vp.customWidthInput.value = 5000;
        vp.applyCustomWidth();
        expect(ui.previewWidth).toBe('375px');
        expect(vp.customWidthInput.value).toBe(375);
    });

    it('currentSectionKey resolves via catalog.sectionOf for a component route', () => {
        const type = ref('component');
        const slug = ref('hero');
        const catalog = useCatalogStore();
        catalog.items = [{ id: 'hero', name: 'Hero', category: 'Block' }];
        const vp = useViewportPreset({ type, slug });
        expect(vp.currentSectionKey.value).toBe('blocks');
    });

    it('currentSectionKey is "pages" for a page route regardless of category', () => {
        const type = ref('page');
        const slug = ref('homepage');
        const catalog = useCatalogStore();
        catalog.pages = [{ id: 'homepage', name: 'Homepage' }];
        const vp = useViewportPreset({ type, slug });
        expect(vp.currentSectionKey.value).toBe('pages');
    });

    it('fieldsTree/fieldsCount reflect the current item\'s YAML fields map', () => {
        const type = ref('component');
        const slug = ref('hero');
        const catalog = useCatalogStore();
        catalog.items = [{ id: 'hero', name: 'Hero', fields: { title: { type: 'text' } } }];
        const vp = useViewportPreset({ type, slug });
        expect(vp.fieldsCount.value).toBe(1);
        expect(vp.fieldsTree.value[0].key).toBe('title');
    });
});
```

- [x] **Step 3: Run and confirm failure**

Run: `cd frontend && npx vitest run src/composables/useViewportPreset.spec.js`
Expected: fails — module missing.

- [x] **Step 4: Implement `useViewportPreset.js`**

Create `frontend/src/composables/useViewportPreset.js`:

```js
import { ref, computed, watch } from 'vue';
import { useUiStore } from '../stores/ui.js';
import { useCatalogStore } from '../stores/catalog.js';
import {
    VIEWPORTS, CUSTOM_WIDTH_MIN, CUSTOM_WIDTH_MAX,
    findPresetByWidth, effectiveDims, fitZoom, isPortraitOrientation,
} from '../lib/viewportMath.js';
import { flattenFieldsTree } from '../lib/fieldsTree.js';

// Ported from frontend/components/preview.js. One instance is provided by
// PreviewView.vue (Task 7 Step 8) and injected by ViewportToolbar.vue (this
// task) and PreviewPane.vue/FieldsDrawer.vue/UsagePanel.vue/LinkBar.vue
// (Tasks 8-10) — mirrors the legacy single `x-data="preview"` Alpine scope
// that all of that markup shared.
export function useViewportPreset({ type, slug }) {
    const ui = useUiStore();
    const catalog = useCatalogStore();

    const currentItem = computed(() => (slug.value ? catalog.find(type.value, slug.value) : null));

    const previewWidthPx = computed(() => {
        if (ui.previewWidth === '100%') return null;
        const px = parseInt(ui.previewWidth, 10);
        return Number.isInteger(px) ? px : null;
    });

    const activePreset = computed(() => {
        if (ui.previewWidth === '100%') return 'full';
        const match = findPresetByWidth(previewWidthPx.value);
        return match?.key ?? 'custom';
    });

    const activePresetCategory = computed(() => {
        if (activePreset.value === 'full') return 'full';
        if (activePreset.value === 'custom') return 'custom';
        return VIEWPORTS.find((v) => v.key === activePreset.value)?.category ?? 'desktop';
    });

    const isFullPreset = computed(() => ui.previewWidth === '100%');

    const effective = computed(() => {
        if (currentItem.value?.responsive === false) return { width: null, height: null };
        return effectiveDims({ width: previewWidthPx.value, height: ui.previewHeight, rotated: ui.previewRotated });
    });

    const containerAvailableWidth = ref(0);
    const containerAvailableHeight = ref(0);
    let containerRO = null;

    // Measures the chrome pane (the `.overflow-auto` container hosting the
    // iframe wrapper) so fit-to-bounds zoom tracks viewport resize. 48px =
    // 2x the p-6 padding on that container in both axes.
    function observeContainer(el) {
        if (containerRO) containerRO.disconnect();
        if (!el) return;
        containerRO = new ResizeObserver((entries) => {
            for (const entry of entries) {
                containerAvailableWidth.value = Math.max(0, entry.contentRect.width - 48);
                containerAvailableHeight.value = Math.max(0, entry.contentRect.height - 48);
            }
        });
        if (typeof el.addEventListener === 'function' || el instanceof Element) {
            containerRO.observe(el);
        }
        containerAvailableWidth.value = Math.max(0, (el.clientWidth ?? 0) - 48);
        containerAvailableHeight.value = Math.max(0, (el.clientHeight ?? 0) - 48);
    }

    const zoom = computed(() => fitZoom({
        width: effective.value.width,
        height: effective.value.height,
        availWidth: containerAvailableWidth.value,
        availHeight: containerAvailableHeight.value,
    }));

    const dimensionsLabel = computed(() => {
        const { width, height } = effective.value;
        if (!width || !height) return null;
        const dims = `${width} × ${height}`;
        return zoom.value < 1 ? `${dims} (${Math.round(zoom.value * 100)} %)` : dims;
    });

    const isPortrait = computed(() => isPortraitOrientation({
        width: previewWidthPx.value, height: ui.previewHeight, rotated: ui.previewRotated,
    }));

    function setPreset(key) {
        const preset = VIEWPORTS.find((v) => v.key === key);
        if (!preset) return;
        ui.setWidth(preset.width === null ? '100%' : `${preset.width}px`, preset.height);
    }

    function setPortrait(portrait) {
        ui.setPortrait(portrait);
    }

    const customWidthInput = ref('');
    function syncCustomFromStore() {
        if (ui.previewWidth === '100%') { customWidthInput.value = ''; return; }
        const px = parseInt(ui.previewWidth, 10);
        if (Number.isInteger(px)) customWidthInput.value = px;
    }
    watch(() => ui.previewWidth, syncCustomFromStore, { immediate: true });

    function applyCustomWidth() {
        const px = Number(customWidthInput.value);
        if (!Number.isInteger(px) || px < CUSTOM_WIDTH_MIN || px > CUSTOM_WIDTH_MAX) {
            syncCustomFromStore();
            return;
        }
        ui.setWidth(`${px}px`);
    }

    const reloadNonce = ref(0);
    function reloadPreview() {
        catalog.init();
        reloadNonce.value++;
    }

    const iframeSrc = computed(() => {
        let src;
        if (type.value === 'foundations') {
            src = '/styleguide/render/foundations/index';
        } else if (!slug.value || !['component', 'page', 'doc'].includes(type.value)) {
            return null;
        } else {
            src = `/styleguide/render/${type.value}/${slug.value}`;
        }
        if (reloadNonce.value) src += (src.includes('?') ? '&' : '?') + `_r=${reloadNonce.value}`;
        return src;
    });

    const toolbarVisible = computed(() => !!iframeSrc.value
        && type.value !== 'foundations'
        && type.value !== 'overview'
        && currentItem.value?.responsive !== false);

    const currentSectionKey = computed(() => {
        if (!slug.value) return null;
        if (type.value === 'page') return 'pages';
        if (type.value === 'doc') return null;
        if (!currentItem.value) return null;
        return catalog.sectionOf(currentItem.value, type.value);
    });

    const currentItemName = computed(() => currentItem.value?.name ?? slug.value);
    const currentItemDescription = computed(() => currentItem.value?.description ?? '');
    const fieldsTree = computed(() => flattenFieldsTree(currentItem.value?.fields));
    const fieldsCount = computed(() => fieldsTree.value.length);

    const isDragging = ref(false);
    let wrapperEl = null;
    function observeWrapper(el) {
        wrapperEl = el;
    }

    function startDrag(event) {
        event.preventDefault();
        const startX = event.clientX ?? event.touches?.[0]?.clientX;
        if (startX == null || !wrapperEl) return;
        const parentRect = wrapperEl.parentElement.getBoundingClientRect();
        const centerX = parentRect.left + parentRect.width / 2;
        const dragZoom = zoom.value || 1;
        isDragging.value = true;
        let raf = 0;
        let pendingWidth = null;
        const flush = () => {
            raf = 0;
            if (pendingWidth != null) {
                ui.setWidth(`${pendingWidth}px`);
                pendingWidth = null;
            }
        };
        const move = (e) => {
            const x = e.clientX ?? e.touches?.[0]?.clientX;
            if (x == null) return;
            const half = Math.max(160, (x - centerX) / dragZoom);
            pendingWidth = Math.round(half * 2);
            if (!raf) raf = requestAnimationFrame(flush);
        };
        const up = () => {
            if (raf) { cancelAnimationFrame(raf); flush(); }
            isDragging.value = false;
            document.removeEventListener('mousemove', move);
            document.removeEventListener('mouseup', up);
            document.removeEventListener('touchmove', move);
            document.removeEventListener('touchend', up);
        };
        document.addEventListener('mousemove', move);
        document.addEventListener('mouseup', up);
        document.addEventListener('touchmove', move, { passive: true });
        document.addEventListener('touchend', up);
    }

    return {
        currentItem, activePreset, activePresetCategory, isFullPreset, effective, zoom,
        dimensionsLabel, isPortrait, setPreset, setPortrait, customWidthInput, applyCustomWidth,
        reloadPreview, iframeSrc, toolbarVisible, currentSectionKey, currentItemName,
        currentItemDescription, fieldsTree, fieldsCount, isDragging, startDrag,
        observeWrapper, observeContainer, CUSTOM_WIDTH_MIN, CUSTOM_WIDTH_MAX, VIEWPORTS,
    };
}
```

- [x] **Step 5: Run and confirm pass**

Run: `cd frontend && npx vitest run src/composables/useViewportPreset.spec.js`
Expected: `Tests 11 passed`.

- [x] **Step 6: Write the failing test for `ViewportToolbar.vue`**

Create `frontend/src/components/ViewportToolbar.spec.js`:

```js
import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { ref, provide, defineComponent, h } from 'vue';
import ViewportToolbar from './ViewportToolbar.vue';
import { useViewportPreset } from '../composables/useViewportPreset.js';
import { useI18nStore } from '../stores/i18n.js';
import { useCatalogStore } from '../stores/catalog.js';

function mountWithViewport(type = 'component', slug = 'hero') {
    setActivePinia(createPinia());
    useI18nStore().strings = {
        toolbar: {
            viewport_preset: 'Viewport', custom_width_label: 'Custom', custom_width_placeholder: 'px',
            orientation_label: 'Orientation', type_component: 'Component', type_page: 'Page',
            canvas_mode_label: 'Canvas', open_in_new_tab: 'Open', reload: 'Reload', more_actions: 'More',
        },
        sections: { blocks: 'Blocks' },
    };
    useCatalogStore().items = [{ id: 'hero', name: 'Hero', category: 'Block' }];

    const Host = defineComponent({
        setup() {
            const typeRef = ref(type);
            const slugRef = ref(slug);
            provide('viewport', useViewportPreset({ type: typeRef, slug: slugRef }));
            return () => h(ViewportToolbar);
        },
    });
    return mount(Host);
}

describe('ViewportToolbar', () => {
    it('renders the active preset word label ("Full" by default)', () => {
        const wrapper = mountWithViewport();
        expect(wrapper.text()).toContain('Full');
    });

    it('clicking a preset row calls setPreset and updates the trigger label', async () => {
        const wrapper = mountWithViewport();
        await wrapper.find('[data-testid="viewport-trigger"]').trigger('click');
        const tabletRow = wrapper.findAll('[data-testid^="viewport-preset-"]').find((el) => el.attributes('data-testid') === 'viewport-preset-tablet');
        await tabletRow.trigger('click');
        expect(wrapper.text()).toContain('Tablet');
    });

    it('renders the breadcrumb section + item name for a component route', () => {
        const wrapper = mountWithViewport('component', 'hero');
        expect(wrapper.text()).toContain('Blocks');
        expect(wrapper.text()).toContain('Hero');
    });

    it('does not render the viewport dropdown for the foundations route', () => {
        const wrapper = mountWithViewport('foundations', null);
        expect(wrapper.find('[data-testid="viewport-trigger"]').exists()).toBe(false);
    });
});
```

- [x] **Step 7: Run and confirm failure, then implement**

Run: `cd frontend && npx vitest run src/components/ViewportToolbar.spec.js` → fails (module missing).

Create `frontend/src/components/ViewportToolbar.vue`. Script block:

```vue
<script setup>
import { inject, ref } from 'vue';
import { useI18nStore } from '../stores/i18n.js';
import { useUiStore } from '../stores/ui.js';

const i18n = useI18nStore();
const ui = useUiStore();
const viewport = inject('viewport');

const dropdownOpen = ref(false);
const overflowOpen = ref(false);

const CATEGORY_ICON_PATHS = {
    mobile: '<rect x="7" y="2" width="10" height="20" rx="2"/><line x1="11" y1="18" x2="13" y2="18"/>',
    tablet: '<rect x="4" y="3" width="16" height="18" rx="2"/><line x1="11" y1="18" x2="13" y2="18"/>',
    desktop: '<rect x="2" y="4" width="20" height="13" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>',
    full: '<polyline points="4 8 4 4 8 4"/><polyline points="20 8 20 4 16 4"/><polyline points="4 16 4 20 8 20"/><polyline points="20 16 20 20 16 20"/>',
};

function activeWordLabel() {
    const key = viewport.activePreset.value;
    if (key === 'full') return 'Full';
    if (key === 'custom') return i18n.t('toolbar.custom_width_label');
    return viewport.VIEWPORTS.find((v) => v.key === key)?.label ?? '?';
}

function triggerDims() {
    if (viewport.activePreset.value === 'full') return '100 %';
    if (viewport.activePreset.value === 'custom') {
        const z = viewport.zoom.value;
        const w = viewport.customWidthInput.value || 0;
        return z < 1 ? `${w} px · ${Math.round(z * 100)} %` : `${w} px`;
    }
    return viewport.dimensionsLabel.value;
}

function openInNewTabHref() {
    return viewport.iframeSrc.value;
}

function canvasHref() {
    const src = viewport.iframeSrc.value;
    return src ? src + (src.includes('?') ? '&' : '?') + 'canvas=1' : null;
}
</script>
```

Template — port `frontend/index.html:357-557` (the `toolbarVisible` block, the secondary-actions block, the ⋮ overflow block, and the foundations-only open-in-new-tab block), translating per the Task 5 directive table plus:

- `x-html="categoryIconPaths[...]"` → `v-html="CATEGORY_ICON_PATHS[...]"`.
- `x-data="{ open: false }"` (dropdown/overflow wrappers) → each becomes its own local `ref(false)` (`dropdownOpen`/`overflowOpen` above) instead of an Alpine-only inline scope.
- `@click.outside="open = false"` → Vue has no built-in outside-click directive; add a small local handler: `@click.self` on a full-screen invisible backdrop is overkill here since the legacy behavior is "click anywhere outside the popover" — implement with a `onMounted`/`onUnmounted` document-level `click` listener that closes the ref when `event.target` is outside the popover element (template ref `dropdownRef`/`overflowRef`, checked via `.contains()`), matching `@click.outside`'s actual semantics.
- `@keydown.escape="open = false"` → `@keydown.escape="dropdownOpen = false"` (native Vue event modifier, same as Alpine's).
- Add `data-testid="viewport-trigger"` to the unified switcher's trigger `<button>` and `data-testid="viewport-preset-<key>"` to each preset row `<button>` (new — the legacy DOM had no test hooks; these are additive attributes with zero visual/behavioral effect, needed because Vitest/Playwright can't reliably target the unlabelled buttons the legacy markup relied on visual position for).
- `reloadPreview` / `$store.ui.route.slug && (...)` canvas-mode click handler / `iframeSrc` references all resolve through `viewport.X` from the injected composable instead of `$store.ui`/local getters.

- [x] **Step 8: Run and confirm pass**

Run: `cd frontend && npx vitest run src/components/ViewportToolbar.spec.js`
Expected: `Tests 4 passed`.

- [x] **Step 9: Wire the composable + `ViewportToolbar` + description bar into `App.vue`**

This — not `PreviewView.vue` — is where the shared chrome belongs. In the legacy DOM, the toolbar (`index.html:311`, `<div class="flex justify-between items-center...">`) is an unconditional sibling inside the SAME `<main x-data="preview">` scope as the framed iframe / foundations iframe / overview grid — it renders identically whether the active route is a component, a page, `overview`, or `foundations` (only the viewport-dropdown sub-block within it, and the foundations-only mini-toolbar sub-block, are conditionally shown). `App.vue`'s `<main>` is the direct Vue equivalent of that scope; `<RouterView/>` is where the per-route body (framed preview / overview grid / foundations iframe) plugs in beneath it. Routing the composable through `PreviewView.vue` instead (a route-specific component that only matches component/page/doc/fields) would make the toolbar vanish on `/overview` and `/foundations` — a parity break. `PreviewView.vue` itself is unaffected by this step: it stays the Task 4 `<div>Preview stub</div>` placeholder until Task 8 swaps it for `<PreviewPane/>`, which independently injects the same `'viewport'` instance via Vue's ancestor-chain lookup (works across any number of intermediate components, including through `<RouterView/>`).

Edit `frontend/src/App.vue`:

```vue
<script setup>
import { computed, provide } from 'vue';
import { useRoute } from 'vue-router';
import { useUiStore } from './stores/ui.js';
import { routeInfo } from './lib/routeInfo.js';
import { useViewportPreset } from './composables/useViewportPreset.js';
import Sidebar from './components/Sidebar.vue';
import ViewportToolbar from './components/ViewportToolbar.vue';

const ui = useUiStore();
const route = useRoute();

const routeType = computed(() => routeInfo(route).type);
const routeSlug = computed(() => routeInfo(route).slug);
const viewport = useViewportPreset({ type: routeType, slug: routeSlug });
provide('viewport', viewport);
</script>

<template>
    <div class="flex h-screen overflow-hidden">
        <div
            v-show="ui.sidebarOpen"
            class="fixed inset-0 z-40 bg-black/40 lg:hidden"
            @click="ui.toggleSidebar()"
        ></div>

        <Sidebar />

        <main class="flex-1 flex flex-col min-w-0 bg-white dark:bg-zinc-950" :class="viewport.isDragging.value && 'cursor-ew-resize select-none'">
            <ViewportToolbar />
            <div v-if="viewport.currentItemDescription.value && routeSlug" class="sg-description-bar px-4 py-2 bg-zinc-100/60 border-b border-zinc-200 dark:bg-zinc-900/40 dark:border-zinc-800 text-xs text-zinc-600 dark:text-zinc-400 leading-relaxed">
                <span v-html="viewport.currentItemDescription.value"></span>
            </div>
            <!-- Task 10 replaces these two stubs with <UsagePanel /> and <LinkBar /> -->
            <div data-testid="usage-panel-stub"></div>
            <div data-testid="link-bar-stub"></div>
            <!-- Task 9 replaces this stub with <FieldsDrawer :fields="viewport.currentItem.value?.fields" v-if="viewport.fieldsCount.value > 0 && routeSlug" /> -->
            <div data-testid="fields-drawer-stub"></div>
            <RouterView />
        </main>
    </div>
</template>
```

Note: `routeSlug` (a `computed` ref) is used directly in the template's `v-if` expressions above — Vue's template compiler auto-unwraps top-level refs referenced in `<script setup>`, so `routeSlug` (not `routeSlug.value`) is correct inside the template, exactly like `ui.sidebarOpen` was already being used unwrapped-via-store in the Task 4 stub.

- [x] **Step 10: Full suite + build + commit**

Run: `cd frontend && npm test && npm run build`
Expected: all green.

```bash
git add frontend/vitest.config.js frontend/src/testSetup.js \
        frontend/src/composables/useViewportPreset.js frontend/src/composables/useViewportPreset.spec.js \
        frontend/src/components/ViewportToolbar.vue frontend/src/components/ViewportToolbar.spec.js \
        frontend/src/App.vue
git commit -m "feat(spa): port viewport preset/zoom/orientation logic + toolbar to Vue

Decomposes the legacy single-scope preview.js Alpine component into a
shared useViewportPreset composable (provided by PreviewView, injected by
ViewportToolbar here and PreviewPane/FieldsDrawer/UsagePanel/LinkBar in
Tasks 8-10) so the reactive state stays unified across the split templates."
```

---

### Task 8: `PreviewPane.vue` — the framed iframe, chassis, drag handles, auto-fit height

Ports the iframe-bearing half of `frontend/components/preview.js` plus `frontend/index.html:715-876` (the framed device-preview block — chassis decorations, drag handles, dimension badge, loading overlay). The full-bleed foundations iframe (`index.html:692-713`) is intentionally NOT part of this component — it moves to `FoundationsView.vue` in Task 11, since the legacy template already renders it via a separate `x-if` branch with different chrome (no frame, no drag handles, no auto-fit).

**Files:**
- Modify: `frontend/src/composables/useViewportPreset.js` (expose the `type`/`slug` refs so `PreviewPane.vue` can gate auto-fit-height by route type)
- Create: `frontend/src/components/PreviewPane.vue` + `frontend/src/components/PreviewPane.spec.js`
- Modify: `frontend/src/views/PreviewView.vue` (swap the `preview-pane-stub` div for `<PreviewPane />`)

**Interfaces:**
- `PreviewPane.vue` — no props (injects `'viewport'`); no emits.
- Consumes (via `inject('viewport')`): `type`, `slug` (new — see Step 1), `effective`, `zoom`, `isFullPreset`, `activePresetCategory`, `activePreset`, `dimensionsLabel`, `isPortrait`, `setPortrait`, `startDrag`, `isDragging`, `observeWrapper`, `iframeSrc`.
- Consumes directly: `useUiStore()` (`isPreviewLoading`, `previewHeight` for the rotate-button visibility gate), `useI18nStore()` (`t`).

- [x] **Step 1: Expose `type`/`slug` from the composable**

Edit `frontend/src/composables/useViewportPreset.js` — add `type, slug,` to the returned object (first two entries, before `currentItem`):

```diff
     return {
+        type, slug,
         currentItem, activePreset, activePresetCategory, isFullPreset, effective, zoom,
```

Run: `cd frontend && npx vitest run src/composables/useViewportPreset.spec.js`
Expected: still `Tests 11 passed` (purely additive — no existing assertion inspects the return shape's key set).

- [x] **Step 2: Write the failing test for `PreviewPane.vue`**

Create `frontend/src/components/PreviewPane.spec.js`:

```js
import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { ref, provide, defineComponent, h } from 'vue';
import PreviewPane from './PreviewPane.vue';
import { useViewportPreset } from '../composables/useViewportPreset.js';
import { useUiStore } from '../stores/ui.js';
import { useI18nStore } from '../stores/i18n.js';
import { useCatalogStore } from '../stores/catalog.js';

function mountPane(type = 'component', slug = 'hero') {
    setActivePinia(createPinia());
    useI18nStore().strings = { toolbar: { rotate: 'Rotate', orientation_portrait: 'Portrait', orientation_landscape: 'Landscape' }, empty_state: 'Select a component', loading: 'Loading...' };
    useCatalogStore().items = [{ id: 'hero', name: 'Hero' }];

    const Host = defineComponent({
        setup() {
            const typeRef = ref(type);
            const slugRef = ref(slug);
            provide('viewport', useViewportPreset({ type: typeRef, slug: slugRef }));
            return () => h(PreviewPane);
        },
    });
    return mount(Host, { attachTo: document.body });
}

describe('PreviewPane', () => {
    it('renders an iframe pointed at the render endpoint for the current route', () => {
        const wrapper = mountPane('component', 'hero');
        expect(wrapper.find('iframe').attributes('src')).toBe('/styleguide/render/component/hero');
    });

    it('uses width:100%;height:100% for the default Full preset', () => {
        const wrapper = mountPane('component', 'hero');
        const style = wrapper.find('[data-testid="iframe-wrapper"]').attributes('style');
        expect(style).toContain('width: 100%');
        expect(style).toContain('height: 100%');
    });

    it('shows drag handles only when the Custom preset is active', async () => {
        const wrapper = mountPane('component', 'hero');
        expect(wrapper.find('[data-testid="drag-handle-right"]').exists()).toBe(false);
        const ui = useUiStore();
        ui.setWidth('500px');
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="drag-handle-right"]').exists()).toBe(true);
    });

    it('shows mobile chassis decorations only for a mobile-category preset', async () => {
        const wrapper = mountPane('component', 'hero');
        const ui = useUiStore();
        ui.setWidth('375px', 667);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="chassis-mobile"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="chassis-tablet"]').exists()).toBe(false);
    });

    it('reflects ui.isPreviewLoading in the loading overlay visibility', async () => {
        const wrapper = mountPane('component', 'hero');
        const ui = useUiStore();
        ui.isPreviewLoading = true;
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="loading-overlay"]').isVisible()).toBe(true);
        ui.isPreviewLoading = false;
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="loading-overlay"]').isVisible()).toBe(false);
    });

    it('flips ui.isPreviewLoading to false when the iframe fires load', async () => {
        const wrapper = mountPane('component', 'hero');
        const ui = useUiStore();
        ui.isPreviewLoading = true;
        await wrapper.find('iframe').trigger('load');
        expect(ui.isPreviewLoading).toBe(false);
    });

    it('shows the empty-state message when there is no route slug and not loading', () => {
        const wrapper = mountPane('overview', null);
        expect(wrapper.text()).toContain('Select a component');
    });
});
```

- [x] **Step 3: Run and confirm failure**

Run: `cd frontend && npx vitest run src/components/PreviewPane.spec.js`
Expected: fails — module missing.

- [x] **Step 4: Implement `PreviewPane.vue`**

Script block:

```vue
<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch, inject } from 'vue';
import { useUiStore } from '../stores/ui.js';
import { useI18nStore } from '../stores/i18n.js';
import { useCatalogStore } from '../stores/catalog.js';

const ui = useUiStore();
const i18n = useI18nStore();
const catalog = useCatalogStore();
const viewport = inject('viewport');

const paneRef = ref(null);
const wrapperRef = ref(null);
const iframeContentHeight = ref(null);
let contentRO = null;

onMounted(() => {
    viewport.observeContainer(paneRef.value);
});
onBeforeUnmount(() => {
    if (contentRO) contentRO.disconnect();
});
watch(wrapperRef, (el) => viewport.observeWrapper(el));

const isLoading = computed(() => ui.isPreviewLoading);

function fitIframeToContent(iframe) {
    const doc = iframe?.contentDocument;
    if (!doc) return;
    const measure = () => {
        const h = Math.max(doc.documentElement?.scrollHeight ?? 0, doc.body?.scrollHeight ?? 0);
        if (h > 0) iframeContentHeight.value = h;
    };
    measure();
    if (contentRO) contentRO.disconnect();
    contentRO = new ResizeObserver(measure);
    if (doc.documentElement) contentRO.observe(doc.documentElement);
    if (doc.body) contentRO.observe(doc.body);
}

function onIframeLoad(event) {
    ui.isPreviewLoading = false;
    const type = viewport.type.value;
    if (type === 'component' || type === 'page') {
        fitIframeToContent(event.target);
    } else {
        iframeContentHeight.value = null;
        if (contentRO) contentRO.disconnect();
    }
}

const wrapperStyle = computed(() => {
    const { width: w, height: h } = viewport.effective.value;
    if (w === null) return 'width: 100%; height: 100%';
    const z = viewport.zoom.value;
    const sourceH = h ?? iframeContentHeight.value ?? 400;
    const scaledW = Math.max(1, Math.round(w * z));
    const scaledH = Math.max(1, Math.round(sourceH * z));
    return `width: ${scaledW}px; height: ${scaledH}px`;
});

const iframeStyle = computed(() => {
    const { width: w } = viewport.effective.value;
    if (w === null) return 'width: 100%; height: 100%';
    const h = viewport.effective.value.height ?? iframeContentHeight.value ?? 400;
    const z = viewport.zoom.value;
    return `width: ${w}px; height: ${h}px; transform: scale(${z}); transform-origin: 0 0`;
});
</script>
```

- [x] **Step 5: Port the template**

Port `frontend/index.html:715-876` into the `<template>` block using the Task 5 directive table, plus:

- `x-ref="iframeWrapper"` → `ref="wrapperRef"` (bound to the `wrapperStyle`/drag-handle-anchoring `<div>`), and `data-testid="iframe-wrapper"` (new test hook).
- `x-ref` on the outer pane (implicit — legacy queried `document.querySelector('[x-ref="iframeWrapper"]').closest('.overflow-auto')`) → `ref="paneRef"` on the `.flex-1.flex.justify-center.overflow-auto` div.
- `@mousedown="startDrag"` / `@touchstart="startDrag"` on the two drag-handle divs → unchanged (`viewport.startDrag`), add `data-testid="drag-handle-right"` / `data-testid="drag-handle-left"`.
- `activePresetCategory === 'mobile'` chassis block → add `data-testid="chassis-mobile"`; the tablet block → `data-testid="chassis-tablet"`.
- `x-show="isLoading"` overlay → `v-show="isLoading"`, add `data-testid="loading-overlay"`.
- `@load="onIframeLoad"` on the `<iframe>` → unchanged event name (native DOM `load` event, not an Alpine-specific binding).
- The rotate button (`@click="$store.ui.toggleRotation()"`) → `@click="ui.toggleRotation()"`; its `x-show` gate (`activePresetCategory === 'mobile' || 'tablet') && previewHeight !== null`) → same expression, sourced from `viewport.activePresetCategory.value` and `ui.previewHeight`.
- Empty-state / loading paragraphs (`x-if="!iframeSrc && !$store.components.loading"` / `x-if="$store.components.loading"`) → `v-if="!viewport.iframeSrc.value && !catalog.loading"` / `v-if="catalog.loading"`, text via `i18n.t('empty_state')` / `i18n.t('loading')`.

- [x] **Step 6: Run and confirm pass**

Run: `cd frontend && npx vitest run src/components/PreviewPane.spec.js`
Expected: `Tests 7 passed`.

- [x] **Step 7: Wire into `PreviewView.vue`**

Edit `frontend/src/views/PreviewView.vue`:

```diff
-    <!-- Task 8 replaces this stub with <PreviewPane /> -->
-    <div data-testid="preview-pane-stub" class="flex-1"></div>
+    <PreviewPane />
```

Add the import and register it alongside `ViewportToolbar` at the top of `<script setup>`.

- [x] **Step 8: Full suite + build + commit**

Run: `cd frontend && npm test && npm run build`
Expected: all green.

```bash
git add frontend/src/composables/useViewportPreset.js frontend/src/components/PreviewPane.vue \
        frontend/src/components/PreviewPane.spec.js frontend/src/views/PreviewView.vue
git commit -m "feat(spa): port the framed iframe pane — chassis, drag-resize, auto-fit height, rotation"
```

---

### Task 9: `FieldsDrawer.vue`

Ports `frontend/index.html:618-690` (the collapsible per-component fields table) — logic already lives in `lib/fieldsTree.js` (Task 2); this task is template + a small amount of local UI state (collapse toggle, type-pill color mapping).

**Files:**
- Create: `frontend/src/components/FieldsDrawer.vue` + `frontend/src/components/FieldsDrawer.spec.js`
- Modify: `frontend/src/views/PreviewView.vue` (swap the `fields-drawer-stub` div for `<FieldsDrawer>`, gated by `v-if`)

**Interfaces:**
- Props: `{ fields: { type: Object, default: null } }` — the raw YAML `fields:` map (`viewport.currentItem.value?.fields`), NOT the flattened tree — `FieldsDrawer.vue` calls `flattenFieldsTree` itself so it stays a standalone, independently testable component with a natural data shape as its contract.
- Emits: none. Local state: `open = ref(false)` (default collapsed, matching legacy `x-data="{ open: false }"`).

- [x] **Step 1: Write the failing test**

Create `frontend/src/components/FieldsDrawer.spec.js`:

```js
import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import FieldsDrawer from './FieldsDrawer.vue';
import { useI18nStore } from '../stores/i18n.js';

beforeEach(() => {
    setActivePinia(createPinia());
    useI18nStore().strings = { nav: { fields: 'Fields' }, fields: { required: 'Required field', requiredLegend: '= Required field' } };
});

const FIELDS = {
    title: { type: 'text', title: 'Title', required: true },
    items: { type: 'array', fields: { label: { type: 'text', title: 'Label' } } },
};

describe('FieldsDrawer', () => {
    it('renders the field count in the collapsed header', () => {
        const wrapper = mount(FieldsDrawer, { props: { fields: FIELDS } });
        expect(wrapper.text()).toContain('Fields');
        expect(wrapper.text()).toContain('3'); // title + items + items.label
    });

    it('hides the table body until toggled open', async () => {
        const wrapper = mount(FieldsDrawer, { props: { fields: FIELDS } });
        expect(wrapper.find('table').isVisible()).toBe(false);
        await wrapper.find('button').trigger('click');
        expect(wrapper.find('table').isVisible()).toBe(true);
    });

    it('indents a nested field row and shows the required-field dot on the required row', async () => {
        const wrapper = mount(FieldsDrawer, { props: { fields: FIELDS } });
        await wrapper.find('button').trigger('click');
        const rows = wrapper.findAll('tbody tr');
        expect(rows).toHaveLength(3);
        expect(rows[2].text()).toContain('label');
        expect(rows[0].find('[role="img"]').exists()).toBe(true); // title's required dot
        expect(rows[1].find('[role="img"]').exists()).toBe(false); // items has no required dot
    });

    it('renders an em-dash for a row with no declared type', async () => {
        const wrapper = mount(FieldsDrawer, { props: { fields: { untyped: { title: 'X' } } } });
        await wrapper.find('button').trigger('click');
        expect(wrapper.find('tbody tr').text()).toContain('—');
    });
});
```

- [x] **Step 2: Run and confirm failure**

Run: `cd frontend && npx vitest run src/components/FieldsDrawer.spec.js`
Expected: fails — module missing.

- [x] **Step 3: Implement**

Script block:

```vue
<script setup>
import { ref, computed } from 'vue';
import { useI18nStore } from '../stores/i18n.js';
import { flattenFieldsTree } from '../lib/fieldsTree.js';

const props = defineProps({
    fields: { type: Object, default: null },
});

const i18n = useI18nStore();
const open = ref(false);

const tree = computed(() => flattenFieldsTree(props.fields));

const TYPE_PILL_CLASSES = {
    array: 'bg-purple-500/20 text-purple-300',
    object: 'bg-pink-500/20 text-pink-300',
    text: 'bg-blue-500/20 text-blue-300',
    textarea: 'bg-red-500/20 text-red-300',
    image: 'bg-emerald-500/20 text-emerald-300',
    link: 'bg-orange-500/20 text-orange-300',
};
const TYPE_PILL_FALLBACK = 'bg-zinc-800 text-zinc-300';

function fieldsTypePill(type) {
    return TYPE_PILL_CLASSES[String(type ?? '').toLowerCase()] ?? TYPE_PILL_FALLBACK;
}
</script>
```

Template — port `frontend/index.html:626-689` verbatim (per the Task 5 directive table): the toggle `<button>` with the chevron SVG and `{{ tree.length }}` count; the `<table>` (`v-show="open"`, `x-collapse` dropped — Vue's `v-show` toggling `display` is sufficient, the smooth-height animation `@alpinejs/collapse` provided is a pure visual nicety not covered by the parity contract's behavioral guarantees) with `v-for="row in tree" :key="row.path"` rows, `:style="\`padding-left: ${row.depth * 1.5}rem\`"`, the required-dot `<span role="img">` gated by `v-if="row.required"`, the type pill gated by `v-if="row.type"` / em-dash fallback via `v-else`, and the description `v-html="row.description || '—'"`.

- [x] **Step 4: Run and confirm pass**

Run: `cd frontend && npx vitest run src/components/FieldsDrawer.spec.js`
Expected: `Tests 4 passed`.

- [x] **Step 5: Wire into `App.vue`**

`FieldsDrawer` is chrome shared across every route (hidden naturally whenever `viewport.fieldsCount` is 0, e.g. on `/overview`/`/foundations`), so it belongs beside `ViewportToolbar` in `App.vue`, not inside the per-route `PreviewView.vue` — see Task 7 Step 9's note on why the shared chrome lives at the `App.vue` level.

```diff
-            <!-- Task 9 replaces this stub with <FieldsDrawer :fields="viewport.currentItem.value?.fields" v-if="viewport.fieldsCount.value > 0 && routeSlug" /> -->
-            <div data-testid="fields-drawer-stub"></div>
+            <FieldsDrawer v-if="viewport.fieldsCount.value > 0 && routeSlug" :fields="viewport.currentItem.value?.fields" />
```

Add the import to `App.vue`'s `<script setup>`: `import FieldsDrawer from './components/FieldsDrawer.vue';`.

- [x] **Step 6: Full suite + build + commit**

Run: `cd frontend && npm test && npm run build`

```bash
git add frontend/src/components/FieldsDrawer.vue frontend/src/components/FieldsDrawer.spec.js frontend/src/App.vue
git commit -m "feat(spa): port the per-component Fields drawer"
```

---

### Task 10: `UsagePanel.vue` + `LinkBar.vue`

Ports `frontend/components/usage.js` + `frontend/components/linkBar.js` — the two thin cross-reference strips rendered between the toolbar/description-bar and the fields drawer (`frontend/index.html:574-616`). Kept as two small components (not merged) since they read different metadata (`usage:` CSV vs. the four link fields) and the legacy files are already this granular.

**Files:**
- Create: `frontend/src/components/UsagePanel.vue` + `frontend/src/components/UsagePanel.spec.js`
- Create: `frontend/src/components/LinkBar.vue` + `frontend/src/components/LinkBar.spec.js`
- Modify: `frontend/src/views/PreviewView.vue` (swap the two stub divs)

**Interfaces:**
- `UsagePanel.vue` — no props (injects `'viewport'` for `currentItem`/`type`); consumes `useCatalogStore()` (`pages`, `items`) and `useI18nStore()` (`t`); emits none; internal `select(item)` navigates via `useRouter().push()`.
- `LinkBar.vue` — no props (injects `'viewport'` for `currentItem`); consumes `externalLinksFor` from `lib/externalLinks.js`; emits none.

- [x] **Step 1: Write the failing test for `UsagePanel.vue`**

Create `frontend/src/components/UsagePanel.spec.js`:

```js
import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import { ref, provide, defineComponent, h } from 'vue';
import UsagePanel from './UsagePanel.vue';
import { useCatalogStore } from '../stores/catalog.js';
import { useI18nStore } from '../stores/i18n.js';

function mountPanel(type, slug) {
    setActivePinia(createPinia());
    useI18nStore().strings = { usage: { used_in: 'Used in', components_in_page: 'Components in page' } };
    const catalog = useCatalogStore();
    catalog.pages = [{ id: 'homepage', name: 'Homepage', usage: 'hero,ghost-id' }];
    catalog.items = [{ id: 'hero', name: 'Hero', usage: 'homepage' }];

    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: { template: '<div/>' } }] });

    const Host = defineComponent({
        setup() {
            provide('viewport', { currentItem: ref(catalog.find(type, slug)), type: ref(type) });
            return () => h(UsagePanel);
        },
    });
    return mount(Host, { global: { plugins: [router] } });
}

describe('UsagePanel', () => {
    it('shows "Used in" chips for a component route, resolving each usage id against pages/items', () => {
        const wrapper = mountPanel('component', 'hero');
        expect(wrapper.text()).toContain('Used in');
        expect(wrapper.text()).toContain('Homepage');
    });

    it('shows "Components in page" for a page route', () => {
        const wrapper = mountPanel('page', 'homepage');
        expect(wrapper.text()).toContain('Components in page');
        expect(wrapper.text()).toContain('Hero');
    });

    it('renders an unknown usage id as a disabled, greyed-out chip', () => {
        const wrapper = mountPanel('page', 'homepage');
        const ghost = wrapper.findAll('button').find((b) => b.text() === 'ghost-id');
        expect(ghost.attributes('disabled')).toBeDefined();
    });

    it('renders nothing for a route with no usage field', () => {
        const wrapper = mountPanel('component', 'nonexistent');
        expect(wrapper.text()).toBe('');
    });
});
```

- [x] **Step 2: Run and confirm failure, then implement**

Run: `cd frontend && npx vitest run src/components/UsagePanel.spec.js` → fails (module missing).

```vue
<script setup>
import { computed, inject } from 'vue';
import { useRouter } from 'vue-router';
import { useCatalogStore } from '../stores/catalog.js';
import { useI18nStore } from '../stores/i18n.js';

const catalog = useCatalogStore();
const i18n = useI18nStore();
const router = useRouter();
const viewport = inject('viewport');

const visible = computed(() => ['component', 'page'].includes(viewport.type.value) && !!viewport.currentItem.value);

const label = computed(() => (viewport.type.value === 'page' ? i18n.t('usage.components_in_page') : i18n.t('usage.used_in')));

const items = computed(() => {
    const cur = viewport.currentItem.value;
    if (!cur?.usage) return [];
    const ids = String(cur.usage).split(',').map((s) => s.trim()).filter(Boolean);
    return ids.map((id) => {
        const page = catalog.pages.find((p) => p.id === id);
        if (page) return { id, type: 'page', name: page.name ?? id };
        const comp = catalog.items.find((c) => c.id === id);
        if (comp) return { id, type: 'component', name: comp.name ?? id };
        return { id, type: null, name: id };
    });
});

function select(item) {
    if (!item.type) return;
    router.push(`/${item.type}/${item.id}`);
}
</script>
```

Template — port `frontend/index.html:574-590` (`v-show="visible && items.length"`, `v-for="item in items" :key="\`${item.type}:${item.id}\`"`, `:disabled="!item.type"`, the type-conditional pill classes, `@click="select(item)"`).

- [x] **Step 3: Run and confirm pass**

Run: `cd frontend && npx vitest run src/components/UsagePanel.spec.js`
Expected: `Tests 4 passed`.

- [x] **Step 4: Write the failing test for `LinkBar.vue`**

Create `frontend/src/components/LinkBar.spec.js`:

```js
import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { ref, provide, defineComponent, h } from 'vue';
import LinkBar from './LinkBar.vue';

function mountBar(item) {
    const Host = defineComponent({
        setup() {
            provide('viewport', { currentItem: ref(item) });
            return () => h(LinkBar);
        },
    });
    return mount(Host);
}

describe('LinkBar', () => {
    it('renders links in Asana -> Figma -> Drupal -> Web order', () => {
        const wrapper = mountBar({ asana: 'https://a', figma: 'https://f', drupal: '', web: 'https://w' });
        const hrefs = wrapper.findAll('a').map((a) => a.attributes('href'));
        expect(hrefs).toEqual(['https://a', 'https://f', 'https://w']);
    });

    it('renders nothing when the current item has no link fields', () => {
        const wrapper = mountBar({ id: 'x' });
        expect(wrapper.find('a').exists()).toBe(false);
    });

    it('renders nothing when there is no current item', () => {
        const wrapper = mountBar(null);
        expect(wrapper.find('a').exists()).toBe(false);
    });
});
```

- [x] **Step 5: Run and confirm failure, then implement**

Run: `cd frontend && npx vitest run src/components/LinkBar.spec.js` → fails (module missing).

```vue
<script setup>
import { computed, inject } from 'vue';
import { externalLinksFor } from '../lib/externalLinks.js';

const viewport = inject('viewport');
const links = computed(() => externalLinksFor(viewport.currentItem.value));
</script>
```

Template — port `frontend/index.html:596-616` (`v-show="visible && links.length"`, `v-for="link in links" :key="link.key"`, the four `v-if="link.key === '...'"` SVG blocks copied verbatim, `:href="link.url"`).

- [x] **Step 6: Run and confirm pass**

Run: `cd frontend && npx vitest run src/components/LinkBar.spec.js`
Expected: `Tests 3 passed`.

- [x] **Step 7: Wire both into `App.vue`**

Same reasoning as `FieldsDrawer` in Task 9 Step 5 — both panels are shared chrome (they naturally hide themselves via their own `visible`/`v-show` gates on every route where they don't apply), so they belong in `App.vue`, beside `ViewportToolbar`, not inside `PreviewView.vue`:

```diff
-            <div data-testid="usage-panel-stub"></div>
-            <div data-testid="link-bar-stub"></div>
+            <UsagePanel />
+            <LinkBar />
```

Add both imports to `App.vue`'s `<script setup>`.

- [x] **Step 8: Full suite + build + commit**

Run: `cd frontend && npm test && npm run build`

```bash
git add frontend/src/components/UsagePanel.vue frontend/src/components/UsagePanel.spec.js \
        frontend/src/components/LinkBar.vue frontend/src/components/LinkBar.spec.js \
        frontend/src/App.vue
git commit -m "feat(spa): port the usage cross-reference panel and external-link bar"
```

---

### Task 11: `OverviewView.vue`, `FoundationsView.vue`, and router deep-link coverage

Ports `frontend/components/overview.js` + `frontend/index.html:881-1070` (the components/pages master index grid) into `OverviewView.vue`, and the full-bleed foundations iframe block (`frontend/index.html:692-713`) into `FoundationsView.vue`. Also adds a router unit test covering all 7 route patterns (`component`, `page`, `doc`, `overview`, `foundations`, `fields`, landing/fallback) so the deep-link surface the spec calls out (Task outline item 10) has explicit coverage rather than relying only on manual/e2e verification.

**Files:**
- Create: `frontend/src/views/OverviewView.vue` + `frontend/src/views/OverviewView.spec.js`
- Create: `frontend/src/views/FoundationsView.vue` + `frontend/src/views/FoundationsView.spec.js`
- Create: `frontend/src/router.spec.js`

**Interfaces:**
- `OverviewView.vue` — no props; consumes `useCatalogStore()` (`items`, `pages`, `sectionOf`, `reverseUsageFor`, `forwardUsageFor`), `useI18nStore()`, `useRouter()`, `externalLinksFor` from `lib/externalLinks.js`, `usePersistedRef('sg-overview-show-usage', true)` for the local `showUsage` toggle (persisted key enumerated in Task 3's table).
- `FoundationsView.vue` — no props; injects `'viewport'` for `iframeSrc`; consumes `useUiStore()` for `isPreviewLoading`.

- [ ] **Step 1: Write the failing test for `OverviewView.vue`**

Create `frontend/src/views/OverviewView.spec.js`:

```js
import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import OverviewView from './OverviewView.vue';
import { useCatalogStore } from '../stores/catalog.js';
import { useI18nStore } from '../stores/i18n.js';

async function mountOverview() {
    setActivePinia(createPinia());
    localStorage.clear();
    const catalog = useCatalogStore();
    catalog.pages = [{ id: 'homepage', name: 'Homepage', usage: 'hero', hasStyleguide: true }];
    catalog.items = [
        { id: 'hero', name: 'Hero', category: 'Block', hasStyleguide: true, figma: 'https://figma/hero' },
        { id: 'gutenberg-block', name: 'GB block', category: 'Gutenberg', hasStyleguide: true },
    ];
    useI18nStore().strings = {
        overview: { title: 'Components and pages', subtitle: 'Sub', show_usage: 'Show usage', pages: 'Pages', components: 'Components', used_in: 'Used in' },
        sections: { blocks: 'Blocks', gutenberg: 'Gutenberg', basic: 'Basic' },
    };
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: { template: '<div/>' } }] });
    const wrapper = mount(OverviewView, { global: { plugins: [router] } });
    return { wrapper, router, catalog };
}

describe('OverviewView', () => {
    it('renders the Pages section and both component-category sections with counts', async () => {
        const { wrapper } = await mountOverview();
        expect(wrapper.text()).toContain('Homepage');
        expect(wrapper.text()).toContain('Hero');
        expect(wrapper.text()).toContain('GB block');
    });

    it('shows a Figma link icon for an item carrying a figma metadata field', async () => {
        const { wrapper } = await mountOverview();
        expect(wrapper.find('a[href="https://figma/hero"]').exists()).toBe(true);
    });

    it('shows forward usage chips under a page when showUsage is on', async () => {
        const { wrapper } = await mountOverview();
        expect(wrapper.text()).toContain('Used in');
        // "Hero" appears both as its own row title and as a forward-usage chip
        // under Homepage; assert the chip specifically renders (button, not link).
        const chipButtons = wrapper.findAll('button').filter((b) => b.text() === 'Hero');
        expect(chipButtons.length).toBeGreaterThan(0);
    });

    it('persists the showUsage toggle to sg-overview-show-usage', async () => {
        const { wrapper } = await mountOverview();
        const checkbox = wrapper.find('input[type="checkbox"]');
        await checkbox.setValue(false);
        await wrapper.vm.$nextTick();
        expect(localStorage.getItem('sg-overview-show-usage')).toBe('false');
    });

    it('navigates via router.push when a component row link is clicked', async () => {
        const { wrapper, router } = await mountOverview();
        const heroLink = wrapper.findAll('a').find((a) => a.text() === 'Hero');
        await heroLink.trigger('click');
        expect(router.currentRoute.value.fullPath).toBe('/component/hero');
    });
});
```

- [ ] **Step 2: Run and confirm failure, then implement**

Run: `cd frontend && npx vitest run src/views/OverviewView.spec.js` → fails (module missing).

Script block:

```vue
<script setup>
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { useCatalogStore } from '../stores/catalog.js';
import { useI18nStore } from '../stores/i18n.js';
import { usePersistedRef } from '../lib/persistedRef.js';
import { externalLinksFor } from '../lib/externalLinks.js';

const catalog = useCatalogStore();
const i18n = useI18nStore();
const router = useRouter();

const showUsage = usePersistedRef('sg-overview-show-usage', true);

const pages = computed(() => catalog.pages.filter((p) => p.hasStyleguide !== false));

const componentSections = computed(() => {
    const buckets = {};
    for (const item of catalog.items) {
        if (item.hasStyleguide === false) continue;
        const section = catalog.sectionOf(item, 'component');
        if (!buckets[section]) buckets[section] = [];
        buckets[section].push(item);
    }
    // Reading order: Pages (own section, rendered separately below) -> Blocks
    // -> Gutenberg -> Basic — composite groups before the atomic-element bucket.
    return ['blocks', 'gutenberg', 'basic']
        .filter((section) => buckets[section]?.length > 0)
        .map((section) => ({ section, items: buckets[section] }));
});

function select(item) {
    if (!item.type) return;
    router.push(`/${item.type}/${item.id}`);
}

function linksFor(item) {
    return externalLinksFor(item);
}

function forwardUsage(item) {
    return catalog.forwardUsageFor(item);
}

function reverseUsage(id) {
    return catalog.reverseUsageFor(id);
}
</script>
```

Template — port `frontend/index.html:881-1070` per the Task 5 directive table. Notable translations: `x-model="showUsage"` → `v-model="showUsage"` (the ref auto-unwraps in the template); `$store.i18n.t(...)` → `i18n.t(...)`; `@click.prevent="select(...)"` unchanged; the section icon `<template x-if="block.section === '...'">` blocks copy verbatim (pure SVG, no store reads); `reverseUsage(item.id)`/`forwardUsage(page)` calls unchanged (now resolving to the catalog store's real map-backed implementations from Task 3 instead of the legacy component's private `_buildReverseMap`/`_buildForwardMap`).

- [ ] **Step 3: Run and confirm pass**

Run: `cd frontend && npx vitest run src/views/OverviewView.spec.js`
Expected: `Tests 5 passed`.

- [ ] **Step 4: Write the failing test for `FoundationsView.vue`**

Create `frontend/src/views/FoundationsView.spec.js`:

```js
import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { setActivePinia, createPinia } from 'pinia';
import { ref, provide, defineComponent, h } from 'vue';
import FoundationsView from './FoundationsView.vue';
import { useUiStore } from '../stores/ui.js';

function mountView() {
    setActivePinia(createPinia());
    const Host = defineComponent({
        setup() {
            provide('viewport', { iframeSrc: ref('/styleguide/render/foundations/index') });
            return () => h(FoundationsView);
        },
    });
    return mount(Host);
}

describe('FoundationsView', () => {
    it('renders an iframe pointed at the foundations render endpoint', () => {
        const wrapper = mountView();
        expect(wrapper.find('iframe').attributes('src')).toBe('/styleguide/render/foundations/index');
    });

    it('shows the loading overlay while ui.isPreviewLoading is true, hides it on iframe load', async () => {
        const wrapper = mountView();
        const ui = useUiStore();
        ui.isPreviewLoading = true;
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="foundations-loading"]').isVisible()).toBe(true);
        await wrapper.find('iframe').trigger('load');
        expect(ui.isPreviewLoading).toBe(false);
    });
});
```

- [ ] **Step 5: Run and confirm failure, then implement**

Run: `cd frontend && npx vitest run src/views/FoundationsView.spec.js` → fails (module missing).

```vue
<script setup>
import { inject } from 'vue';
import { useUiStore } from '../stores/ui.js';

const ui = useUiStore();
const viewport = inject('viewport');

function onLoad() {
    ui.isPreviewLoading = false;
}
</script>

<template>
    <div class="flex-1 relative bg-white overflow-hidden">
        <iframe :src="viewport.iframeSrc.value" class="w-full h-full border-0" @load="onLoad"></iframe>
        <div v-show="ui.isPreviewLoading" data-testid="foundations-loading" class="absolute inset-0 flex items-center justify-center bg-white pointer-events-none">
            <div class="w-2 h-2 rounded-full bg-zinc-300 animate-pulse"></div>
        </div>
    </div>
</template>
```

This ports `frontend/index.html:692-713` — the full-bleed iframe used only by the `foundations` (and `landing`, which resolves to the same view per the router table) route. It is deliberately simpler than `PreviewPane.vue` (Task 8): no auto-fit height, no chassis, no drag handles, no zoom — matching the legacy block's own comment ("Foundations: full-bleed iframe, no frame / shadow / dark padding... theme-agnostic from our perspective").

- [ ] **Step 6: Run and confirm pass**

Run: `cd frontend && npx vitest run src/views/FoundationsView.spec.js`
Expected: `Tests 2 passed`.

- [ ] **Step 7: Router deep-link coverage**

Create `frontend/src/router.spec.js`:

```js
import { describe, it, expect, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { router } from './router.js';

beforeEach(() => {
    setActivePinia(createPinia());
});

describe('router deep links', () => {
    it.each([
        ['/', 'landing'],
        ['/component/hero', 'component'],
        ['/page/homepage', 'page'],
        ['/doc/sample-doc', 'doc'],
        ['/overview', 'overview'],
        ['/foundations', 'foundations'],
        ['/fields', 'fields'],
        ['/nonexistent/garbage/path', 'not-found-fallback'],
    ])('resolves %s to the %s route', async (path, expectedName) => {
        await router.push(path);
        expect(router.currentRoute.value.name).toBe(expectedName);
    });

    it('extracts the slug param for component/page/doc routes', async () => {
        await router.push('/component/hero');
        expect(router.currentRoute.value.params.slug).toBe('hero');
    });

    it('does not rewrite the address bar for the bare landing path (no redirect)', async () => {
        await router.push('/');
        expect(router.currentRoute.value.fullPath).toBe('/');
    });
});
```

Run: `cd frontend && npx vitest run src/router.spec.js`
Expected: `Tests 10 passed` (8 from `it.each` + 2 more).

- [ ] **Step 8: Full suite + build + commit**

Run: `cd frontend && npm test && npm run build`
Expected: all green.

Run the manual smoke from Task 4 Step 15 again against the built `dist/`, this time clicking through the sidebar to `/styleguide/overview` and `/styleguide/foundations` in a real browser (or `curl` each path and grep for `<div id="app">` to confirm the server-rendered shell is intact — full interactivity verification is Task 12's job) to build confidence before the Playwright suite formalizes it.

```bash
git add frontend/src/views/OverviewView.vue frontend/src/views/OverviewView.spec.js \
        frontend/src/views/FoundationsView.vue frontend/src/views/FoundationsView.spec.js \
        frontend/src/router.spec.js
git commit -m "feat(spa): port the Overview grid and Foundations iframe view; add router deep-link tests"
```

---

### Task 12: Playwright e2e — port the local-only Layer B suite into CI

`tests/e2e/smoke-browser.sh` (Layer B) is excluded from CI (`.github/workflows/tests.yml`'s `e2e` job runs `bash tests/e2e/run.sh --no-browser`, per the research — Layer B needs the `agent-browser` CLI + a real Chrome, local-only). It also reaches into `window.Alpine.store(...)` directly, which no longer exists post-rewrite. This task replaces it with a Playwright suite that asserts the same behavior through the rendered DOM only (no reach-through into Pinia internals — a stricter, more implementation-detail-proof test than the one it replaces) and wires it into CI for the first time.

**Files:**
- Create: `tests/fixtures/templates/component/with-fields/with-fields.twig` (new fixture — none of the existing fixtures declare a `fields:` YAML block)
- Modify: `frontend/package.json` (add `@playwright/test` devDependency + `test:e2e` script)
- Create: `frontend/playwright.config.js`
- Create: `tests/e2e/playwright/styleguide.spec.js`
- Modify: `.github/workflows/tests.yml` (new `e2e-playwright` job)

**Interfaces:** none (black-box browser test — no internal API surface).

- [ ] **Step 1: Add a `fields:`-bearing fixture**

Create `tests/fixtures/templates/component/with-fields/with-fields.twig`:

```twig
{#
name: "With fields"
category: "Block"
fields:
  title:
    type: text
    title: "Title"
    required: true
  items:
    type: array
    fields:
      label:
        type: text
        title: "Label"
#}
<div class="with-fields">{{ content.title }}</div>
```

New fixture, isolated from `sample` — avoids touching any existing `ComponentParser`/`Renderer` PHPUnit assertion that may already pin `sample`'s exact metadata shape.

- [ ] **Step 2: Add the Playwright dependency + config**

Edit `frontend/package.json` — add to `devDependencies`: `"@playwright/test": "^1.49.0"`, and to `scripts`: `"test:e2e": "playwright test"`.

Run: `cd frontend && npm install`

Create `frontend/playwright.config.js`:

```js
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: '../tests/e2e/playwright',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? 'github' : 'list',
    use: {
        baseURL: 'http://127.0.0.1:8421',
        trace: 'retain-on-failure',
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    ],
    // Reuses the exact boot command tests/e2e/run.sh already uses for Layer A/B
    // (php -S 127.0.0.1:8421 -t tests/fixtures tests/fixtures/index.php) so
    // there is exactly one fixture-server invocation pattern in the repo.
    webServer: {
        command: 'php -S 127.0.0.1:8421 -t ../tests/fixtures ../tests/fixtures/index.php',
        cwd: '.',
        url: 'http://127.0.0.1:8421/styleguide/',
        reuseExistingServer: !process.env.CI,
        timeout: 10_000,
    },
});
```

- [ ] **Step 3: Write the Playwright spec**

Create `tests/e2e/playwright/styleguide.spec.js` — every assertion below has a named legacy source (the `smoke-browser.sh` section it replaces) so a future reader can trace behavior back to its origin:

```js
import { test, expect } from '@playwright/test';

test.describe('Styleguide SPA', () => {
    test('landing hydrates on Foundations, cs locale, sidebar shows translated labels', async ({ page }) => {
        // Replaces smoke-browser.sh section 1 (landing hydration).
        await page.goto('/styleguide/');
        await expect(page.locator('iframe').first()).toHaveAttribute('src', '/styleguide/render/foundations/index');
        await expect(page.locator('html')).toHaveAttribute('lang', 'cs');
        await expect(page.getByText('Přehled')).toBeVisible();
    });

    test('sidebar navigation updates the URL and the iframe src', async ({ page }) => {
        // Replaces smoke-browser.sh section 2 (navigation).
        await page.goto('/styleguide/');
        await page.getByRole('link', { name: 'Sample', exact: true }).click();
        await expect(page).toHaveURL(/\/styleguide\/component\/sample$/);
        await expect(page.locator('iframe').first()).toHaveAttribute('src', '/styleguide/render/component/sample');
    });

    test('viewport toolbar renders for a responsive:true component', async ({ page }) => {
        // Replaces smoke-browser.sh section 3a (issue #36 regression guard).
        await page.goto('/styleguide/component/sample');
        await expect(page.getByTestId('viewport-trigger')).toBeVisible();
    });

    test('selecting the Tablet preset sets the trigger label to Tablet and 768 x 1024', async ({ page }) => {
        // Replaces smoke-browser.sh section 3 (width preset).
        await page.goto('/styleguide/component/sample');
        await page.getByTestId('viewport-trigger').click();
        await page.getByTestId('viewport-preset-tablet').click();
        await expect(page.getByTestId('viewport-trigger')).toContainText('Tablet');
        await expect(page.getByTestId('viewport-trigger')).toContainText('768');
    });

    test('a responsive:false doc hides the viewport dropdown and pins the preview to Full', async ({ page }) => {
        // Replaces smoke-browser.sh section 3b (issue #34 regression guard).
        // Deliberately visited AFTER the Tablet-preset test above in the SAME
        // spec file would race on shared persisted state across tests; each
        // Playwright test gets a fresh browser context by default, so
        // sg-preview-width from the previous test does not leak here.
        await page.goto('/styleguide/doc/sample-doc');
        await expect(page.getByTestId('viewport-trigger')).toHaveCount(0);
        const wrapper = page.getByTestId('iframe-wrapper');
        await expect(wrapper).toHaveCSS('width', /.+/); // sanity: element exists and is styled
        const style = await wrapper.getAttribute('style');
        expect(style).toContain('width: 100%');
    });

    test('a >=3 prefix cluster renders as a collapsible group with suffix-only children; a singleton stays flat', async ({ page }) => {
        // Replaces smoke-browser.sh section 3c (issue #38 regression guard).
        await page.goto('/styleguide/');
        await expect(page.getByRole('button', { name: /^Widget/ })).toBeVisible();
        await expect(page.getByRole('link', { name: 'One', exact: true })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Widget - one', exact: true })).toHaveCount(0);
        await expect(page.getByRole('link', { name: 'Gizmo', exact: true })).toBeVisible();
    });

    test('a search query flattens the Widget group to full names', async ({ page }) => {
        await page.goto('/styleguide/');
        await page.getByPlaceholder(/./).first().fill('widget');
        await expect(page.getByRole('link', { name: 'Widget - one', exact: true })).toBeVisible();
    });

    test('Cmd+K focuses the search input; Escape clears it', async ({ page }) => {
        // Replaces smoke-browser.sh section 4.
        await page.goto('/styleguide/');
        await page.keyboard.press('Meta+k');
        const input = page.locator('input[type="text"]').first();
        await expect(input).toBeFocused();
        await input.fill('widget');
        await page.keyboard.press('Escape');
        await expect(input).toHaveValue('');
    });

    test('switching locale to en updates strings and <html lang>', async ({ page }) => {
        // Replaces smoke-browser.sh section 5.
        await page.goto('/styleguide/');
        await page.getByRole('button', { name: 'en' }).click();
        await expect(page.locator('html')).toHaveAttribute('lang', 'en');
        await expect(page.getByText('Overview')).toBeVisible();
    });

    test('drag-resizing the Custom-preset handle changes the emulated width', async ({ page }) => {
        await page.goto('/styleguide/component/sample');
        await page.getByTestId('viewport-trigger').click();
        await page.getByLabel(/Custom width|Vlastní šířka/).fill('500');
        await page.keyboard.press('Enter');
        const handle = page.getByTestId('drag-handle-right');
        const box = await handle.boundingBox();
        await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
        await page.mouse.down();
        await page.mouse.move(box.x + 100, box.y + box.height / 2);
        await page.mouse.up();
        await expect(page.getByTestId('viewport-trigger')).not.toContainText('500 px');
    });

    test('rotating a Mobile preset swaps the effective width/height', async ({ page }) => {
        await page.goto('/styleguide/component/sample');
        await page.getByTestId('viewport-trigger').click();
        await page.getByTestId('viewport-preset-mobile').click();
        await expect(page.getByTestId('viewport-trigger')).toContainText('375');
        await page.getByTestId('rotate-button').click();
        await expect(page.getByTestId('viewport-trigger')).toContainText('667');
    });

    test('canvas mode navigates the top-level page to the render URL with canvas=1', async ({ page }) => {
        await page.goto('/styleguide/component/sample');
        await page.getByRole('button', { name: /Canvas/i }).click();
        await expect(page).toHaveURL(/canvas=1/);
    });

    test('the Fields drawer lists a component\'s declared fields once expanded', async ({ page }) => {
        await page.goto('/styleguide/component/with-fields');
        const drawerToggle = page.getByRole('button', { name: /Fields|Pole/ });
        await expect(drawerToggle).toContainText('3');
        await drawerToggle.click();
        await expect(page.getByText('title', { exact: true })).toBeVisible();
        await expect(page.getByText('label', { exact: true })).toBeVisible();
    });

    test('standalone render shows the back-bar; the same render inside the SPA iframe hides it', async ({ page }) => {
        // Replaces smoke-browser.sh section 6 — render-cell.twig's back-bar is
        // plain PHP/Twig + vanilla JS, entirely untouched by this rewrite;
        // ported here so the full parity checklist lives in one suite.
        await page.goto('/styleguide/render/component/sample');
        const bar = page.locator('#sg-standalone-bar');
        await expect(bar).toBeVisible();
        await expect(bar.locator('a')).toHaveAttribute('href', '/styleguide/component/sample');

        await page.goto('/styleguide/component/sample');
        const frame = page.frameLocator('iframe').first();
        await expect(frame.locator('#sg-standalone-bar')).toBeHidden();
    });
});
```

- [ ] **Step 4: Run locally**

Run: `cd frontend && npx playwright install --with-deps chromium && npm run test:e2e`
Expected: all specs pass against the `php -S` server Playwright's `webServer` config boots automatically. If the drag-resize or rotate tests are flaky locally (real mouse-move timing), retry once before treating as a real regression — these two are the only tests exercising raw pointer sequences rather than click/fill.

- [ ] **Step 5: Wire into CI**

Edit `.github/workflows/tests.yml` — add a new job after `e2e`:

```yaml
  e2e-playwright:
    runs-on: ubuntu-latest
    name: e2e (Layer C — Playwright)
    steps:
      - uses: actions/checkout@v5

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: intl, yaml
          coverage: none
          tools: composer:v2

      - name: Cache composer
        uses: actions/cache@v4
        with:
          path: vendor
          key: composer-${{ runner.os }}-php-8.3-${{ hashFiles('composer.lock') }}

      - run: composer install --no-interaction --prefer-dist

      - uses: actions/setup-node@v4
        with:
          node-version: 20
          cache: npm
          cache-dependency-path: frontend/package-lock.json

      - name: Install frontend dependencies
        run: cd frontend && npm ci

      - name: Build the SPA
        run: cd frontend && npm run build

      - name: Install Playwright browsers
        run: cd frontend && npx playwright install --with-deps chromium

      - name: Run Playwright suite
        run: cd frontend && npm run test:e2e
```

Note: this job needs `npm run build` first (unlike the Vitest job in Task 1, which never touches `dist/`) because Playwright drives the real PHP-served `dist/index.html`, not a jsdom-mounted component tree.

- [ ] **Step 6: Full regression + commit**

Run: `composer test && cd frontend && npm test && npm run build && npm run test:e2e`
Expected: everything green.

```bash
git add tests/fixtures/templates/component/with-fields frontend/package.json frontend/package-lock.json \
        frontend/playwright.config.js tests/e2e/playwright/styleguide.spec.js .github/workflows/tests.yml
git commit -m "test(e2e): port the local-only Alpine smoke suite to Playwright and run it in CI

Layer B (tests/e2e/smoke-browser.sh) reached into window.Alpine.store(...)
directly and required a locally-installed agent-browser CLI, so it never
ran in CI. The Playwright replacement asserts purely through the rendered
DOM (no store reach-through) and runs headless in GitHub Actions."
```

---

### Task 13: `dist/` reproducibility CI job

Adds the CI gate the spec calls for: `npm ci && npm run build && git diff --exit-code dist/` — committed `dist/` must always be exactly what `frontend/` source produces, catching the class of drift where someone hand-edits `dist/index.html` (the exact failure mode `SpaConfigTest`'s throw-on-non-match, Task 4, defends against at request time) or forgets to rebuild after a `frontend/` change.

**Files:**
- Modify: `.github/workflows/tests.yml` (new `dist-reproducible` job)

**Interfaces:** none (CI-only).

- [ ] **Step 1: Add the job**

Edit `.github/workflows/tests.yml`:

```yaml
  dist-reproducible:
    runs-on: ubuntu-latest
    name: dist/ is reproducible from frontend/
    steps:
      - uses: actions/checkout@v5

      - uses: actions/setup-node@v4
        with:
          node-version: 20
          cache: npm
          cache-dependency-path: frontend/package-lock.json

      - run: cd frontend && npm ci

      - run: cd frontend && npm run build

      - name: Fail if the rebuild differs from the committed dist/
        run: git diff --exit-code -- dist/
```

- [ ] **Step 2: Verify it currently passes**

Run: `cd frontend && npm run build && cd .. && git diff --exit-code -- dist/`
Expected: exit 0 with no diff — Task 1-12 already rebuilt `dist/` and committed it at the end of every task touching `frontend/`, so this should already be true going into this task. If it fails, `dist/` drifted at some point in Tasks 1-12 and was committed stale — run `cd frontend && npm run build` and amend that into a fixup commit before proceeding.

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/tests.yml
git commit -m "ci: fail the build when committed dist/ is not reproducible from frontend/ source"
```

---

### Task 14: Cleanup — delete the Alpine sources, drop the dependency, sync docs, CHANGELOG

Every legacy Alpine feature now has a proven Vue equivalent (Tasks 5-11 each shipped with passing tests before the corresponding legacy file was left in place per Task 4 Step 13's explicit deferral). This task removes the dead code, drops the now-unused dependency, and closes the AGENTS.md documentation gate — the rewrite touches consumer-visible behavior only in the sense that `dist/` changes wholesale (permitted, `@internal`), but the CHANGELOG entry and README/AGENTS.md sync are still required by the "docs land in the same PR" rule.

**Files:**
- Delete: `frontend/styleguide.js`, `frontend/router.js`, `frontend/components/` (7 files), `frontend/stores/` (4 files)
- Modify: `frontend/package.json` (drop `alpinejs`, `@alpinejs/collapse`, `@alpinejs/persist`)
- Modify: `frontend/styleguide.css` (drop the now-dead `@source` globs for the deleted dirs)
- Modify: `AGENTS.md` (repo layout tree, Development Commands, "SPA chrome change" workflow section)
- Modify: `CLAUDE.md` (Browser Verification section's directory reference)
- Modify: `README.md` (contributor section line ~506, and the two `frontend/stores/components.js` references at lines 238/393 → `frontend/src/stores/catalog.js`)
- Modify: `CHANGELOG.md` (`[Unreleased]` entry)

**Interfaces:** none (deletion + doc sync).

- [ ] **Step 1: Confirm nothing still imports the legacy files**

Run: `cd frontend && grep -rn "from '\\.\\./stores\\|from '\\.\\./components\\|from './stores\\|from './components\\|from './router\\.js'\\|from './styleguide\\.js'" src/ 2>/dev/null; grep -rn "styleguide\\.js\\|router\\.js" index.html`
Expected: no output at all — `index.html` was already switched to `./src/main.js` in Task 4 Step 7, and no `src/**` file imports the sibling legacy trees (every Task 5-11 component imports from `../stores/*.js`/`../lib/*.js` inside `src/`, never the top-level legacy `stores/`/`components/`).

- [ ] **Step 2: Delete the legacy sources**

```bash
git rm frontend/styleguide.js frontend/router.js
git rm -r frontend/components frontend/stores
```

- [ ] **Step 3: Drop the Alpine dependency**

Edit `frontend/package.json` — remove the entire `dependencies` block's three Alpine packages (leave `vue`/`pinia`/`vue-router` from Task 1, which live in `dependencies` too):

```diff
     "dependencies": {
-        "alpinejs": "^3.14.0",
-        "@alpinejs/collapse": "^3.14.0",
-        "@alpinejs/persist": "^3.14.0"
+        "vue": "^3.5.0",
+        "pinia": "^2.3.0",
+        "vue-router": "^4.5.0"
     }
```

(If Task 1 already placed `vue`/`pinia`/`vue-router` in `dependencies` rather than `devDependencies` — reasonable either way since they ship in the production bundle — this step just deletes the three Alpine lines and leaves the rest untouched.)

Run: `cd frontend && npm install`
Expected: `package-lock.json` updates, removing the Alpine packages and their transitive deps (`@vue/reactivity`-adjacent nothing changes — Alpine has no shared deps with the Vue stack).

- [ ] **Step 4: Drop the dead `@source` globs**

Edit `frontend/styleguide.css` — remove the two lines Step 6b (Task 4) had kept alive for the legacy trees:

```diff
 @source "./index.html";
 @source "./src/**/*.{js,vue}";
-/* Legacy Alpine sources stay scanned until Task 14 deletes them, so classes
-   used only by not-yet-ported markup keep generating during the migration. */
-@source "./components/**/*.js";
-@source "./stores/**/*.js";
```

- [ ] **Step 5: Rebuild and verify**

Run: `cd frontend && npm run build`
Expected: exit 0, smaller `dist/styleguide.[hash].js` than the last committed build (Alpine + the two plugins, ~25 KB min+gzip, are gone).

Run: `cd frontend && npm test && npx playwright install --with-deps chromium && npm run test:e2e`
Expected: all green — the full Vitest + Playwright suites still pass with zero Alpine code left in the tree.

- [ ] **Step 6: Update `AGENTS.md`**

In the `## Repo layout` code block, replace the `frontend/` subtree:

```diff
 ├── frontend/                  # SPA source (Vite + Alpine 3 + Tailwind v4)
 │   ├── index.html             # SPA shell — sidebar, toolbar, iframe preview
-│   ├── styleguide.js          # entrypoint (registers Alpine data + stores)
-│   ├── styleguide.css         # Tailwind v4 with @import / @source
-│   ├── components/            # Alpine components (preview, search, sidebar, usage, languageSwitcher)
-│   ├── stores/                # Alpine stores (ui, i18n, components)
+│   ├── styleguide.css         # Tailwind v4 with @import / @source
+│   ├── src/
+│   │   ├── main.js            # entrypoint — boots Pinia + vue-router + mounts App.vue
+│   │   ├── App.vue            # shell: sidebar, mobile backdrop, shared toolbar/description/usage/link/fields chrome
+│   │   ├── router.js          # vue-router instance + route table
+│   │   ├── views/             # OverviewView, FoundationsView, PreviewView (renders PreviewPane)
+│   │   ├── components/        # Sidebar, ViewportToolbar, PreviewPane, FieldsDrawer, UsagePanel, LinkBar
+│   │   ├── composables/       # useViewportPreset, useSearchShortcuts
+│   │   ├── stores/            # Pinia: catalog, ui, i18n, theme
+│   │   └── lib/                # framework-free: searchMatch, prefixTree, viewportMath, fieldsTree, externalLinks, persistedRef, routeInfo, config
 │   └── public/locales/        # cs.json, en.json
```

Update `## Development Commands`'s frontend code block to add the test script:

```diff
 # Frontend (SPA chrome — only when JS/CSS/HTML in frontend/ changes)
 cd frontend && npm install                   # first time / lock-file change
 cd frontend && npm run build                 # one-shot build → ../dist/
 cd frontend && npm run watch                 # rebuild on save (use during dev)
+cd frontend && npm test                      # Vitest — src/lib, src/stores, src/composables, src/components
+cd frontend && npm run test:e2e              # Playwright — full-browser parity checklist against tests/fixtures
```

Update the `### SPA chrome change (anything under \`frontend/\`)` workflow section's file references:

```diff
 1. Run `cd frontend && npm run watch` so `dist/` updates on save.
-2. Edit `frontend/index.html` (templates), `frontend/components/*.js` (Alpine components), `frontend/stores/*.js` (state), `frontend/public/locales/*.json` (i18n).
+2. Edit `frontend/src/components/*.vue` / `frontend/src/views/*.vue` (templates + logic), `frontend/src/stores/*.js` (Pinia state), `frontend/src/lib/*.js` (pure logic — write a Vitest spec first), `frontend/public/locales/*.json` (i18n).
 3. Reload the consumer's styleguide URL — Vite has already rebuilt `dist/`.
-4. Verify the touched feature plus one adjacent feature (smoke).
-5. Commit both source files AND the rebuilt `dist/` artifacts — consumers receive `dist/` verbatim.
+4. Run `cd frontend && npm test` — every store/lib/component change needs a passing spec before commit (see `docs/superpowers/plans/2026-07-04-phase-1-vue-rewrite.md` for the test-first pattern this codebase now follows).
+5. Verify the touched feature plus one adjacent feature (smoke) against the consumer via `composer styleguide:local`.
+6. Commit source files, specs, AND the rebuilt `dist/` artifacts — consumers receive `dist/` verbatim; CI's `dist-reproducible` job (Task 13) fails the build if you forget.
```

- [ ] **Step 7: Update `CLAUDE.md`**

The `## Preferred Tools` → `### Browser Verification` section currently reads "The package's only browser surface is the SPA in `dist/` plus the iframe render endpoint" — this sentence is still accurate (no change needed). No other `CLAUDE.md` section references the Alpine-specific directory names, so no further edit is required here — confirm with `grep -n "components/\|stores/\|Alpine" CLAUDE.md` returning nothing before treating this step as done.

- [ ] **Step 8: Update `README.md`**

Edit the "Local development (for package contributors)" section (~line 496-512):

```diff
 # PHP unit tests (Router, Renderer, ComponentParser, AssetServer)
 composer install
 vendor/bin/phpunit

-# SPA chrome (Vite + Tailwind v4 + Alpine)
+# SPA chrome (Vite + Vue 3 + Pinia + Tailwind v4)
 cd frontend
 npm install
 npm run watch          # rebuilds dist/ on every edit
+npm test               # Vitest unit suite (src/lib, src/stores, src/composables, src/components)
+npm run test:e2e       # Playwright, full-browser parity checklist
 ```

 Changes to PHP `src/` are picked up immediately (no build step). Changes to
 `frontend/*` require a Vite build — committed `dist/` artifacts are what
 consumers receive, so always commit the rebuilt bundle when the SPA changes.
```

Update the two `frontend/stores/components.js` references (the API-surface line ~238 about the SPA consuming all four endpoints, and the `category` schema line ~393) to `frontend/src/stores/catalog.js`.

- [ ] **Step 9: `CHANGELOG.md`**

`[Unreleased]` is currently empty (directly followed by `## [0.6.5] - 2026-06-22`). Add:

```markdown
## [Unreleased]

### Changed

- **SPA chrome rewritten from Alpine.js 3 to Vue 3 + Pinia + vue-router** (Phase 1 of the Styleguide 2.0 roadmap). 1:1 feature parity — no new user-facing behavior. The `dist/` bundle is `@internal` and not covered by SemVer, but for transparency: every viewport preset/zoom/rotation, the sidebar prefix-tree grouping, search, locale switching, theme cycling, the fields drawer, and the usage/link cross-reference panels now ship with unit tests (Vitest) and a headless-browser parity suite (Playwright, running in CI for the first time — the previous Alpine-era browser suite was local-only).
- `Styleguide::dispatchSpa()` now injects a single `<script id="sg-config" type="application/json">` payload into `dist/index.html` instead of 6 separate regex substitutions, and throws when that injection point is missing instead of silently shipping a half-patched shell.
- New CI job asserts committed `dist/` is byte-for-byte reproducible from `frontend/` source (`npm run build && git diff --exit-code dist/`).
```

- [ ] **Step 10: Full regression + commit**

Run: `composer test && composer phpstan && cd frontend && npm test && npm run build && npm run test:e2e`
Expected: everything green.

```bash
git add -A frontend AGENTS.md CLAUDE.md README.md CHANGELOG.md
git commit -m "chore(frontend): remove the legacy Alpine.js sources and dependency, sync docs

Every Alpine feature has a tested Vue 3 + Pinia equivalent as of Task 11;
this deletes frontend/styleguide.js, frontend/router.js,
frontend/components/, frontend/stores/, drops the three Alpine npm
packages, and syncs AGENTS.md/CLAUDE.md/README.md/CHANGELOG.md to the
new frontend/src/ layout per the AGENTS.md documentation gate."
```

- [ ] **Step 11: Verify against the `tailwind-base` symlink (manual)**

This step cannot be scripted from inside this repo alone — it requires the sibling `tailwind-base` checkout and is the final gate before this branch is considered mergeable:

```bash
cd ../tailwind-base
composer styleguide:local
```

Then in a browser, open `tailwind-base`'s styleguide URL and manually walk the Task 12 Playwright checklist once by hand (sidebar nav, search, ⌘K, locale switch, theme cycle, every viewport preset, drag-resize, rotation, canvas mode, fields drawer, usage/link panels, deep-linking directly to a few `/styleguide/component/<id>` URLs). This is the step that catches anything the fixture-only Playwright suite structurally can't — real consumer components (`tailwind-base` ships ~25 components + 10 pages per the Phase 1 design doc's fleet survey), the project's own Tailwind config interacting with `foundations.css`, and any last visual regression against the pre-rewrite Alpine chrome. Record the result in the PR description; do not merge on a red or skipped run of this step.

- [ ] **Step 12: Open the PR**

This branch (`feature/styleguide-2.0`) is now feature-complete for Phase 1. Do NOT push or open a PR during the autonomous overnight run — Phases 2-4 continue on this same branch; pushing and PR creation happen with the user in the morning. The actual `v0.7.0` version bump + git tag is a separate, later step per `AGENTS.md`'s `## Release workflow` section (`CHANGELOG.md`'s `[Unreleased]` heading only gets renamed to `## [0.7.0] - <date>` at that time, by whoever runs the release) — do not bump or tag from this branch.

---

## Post-plan self-review notes

- **Spec coverage.** All Phase 1 deliverables from the design doc's "Deliverables" section are covered: Vue 3 + Pinia + vue-router rewrite (Tasks 4-11), the `frontend/src/lib/` pure-function layer with unit tests (Task 2), the single `#sg-config` PHP↔SPA joint replacing the 6 regexes (Task 4), Vitest for `src/lib/` + stores (Tasks 2-3), Playwright in CI replacing the local-only agent-browser suite (Task 12) with the exact parity checklist the spec enumerates (viewport presets, drag-resize, rotation, prefix tree, search, locale, theme, deep links, canvas mode, fields drawer, standalone back-bar), the `dist/` reproducibility CI job (Task 13), and verification against `tailwind-base` via the path-repository workflow before release (Task 14 Step 11).
- **Deviations from the task outline, and why:**
  - Added two extra `lib/` modules beyond the outline's three (`fieldsTree.js`, `externalLinks.js`) — both are pure, previously zero-coverage, duplicated 2-3x in the legacy code, and fit the same test-first treatment (Task 2).
  - Added a `theme` Pinia store (Task 3) alongside the outline's named `catalog`/`ui`/`i18n` — the legacy app has 4 Alpine stores, not 3; omitting `theme` would drop the light/dark/system cycling feature, violating 1:1 parity.
  - `SearchPalette.vue` (outline item 5) became a composable (`useSearchShortcuts.js`, Task 6) instead of a component — the legacy `search.js` has no template of its own (its markup already lives inside the sidebar), so a wrapper component would have been a contentless pass-through.
  - Corrected a structural mistake found during self-review: the outline's implicit per-view ownership of viewport state (via a `PreviewView.vue`) would have hidden the toolbar/description/usage/link/fields chrome on `/overview` and `/foundations`, where the legacy single-scope Alpine component (`<main x-data="preview">`) renders all of it unconditionally. Tasks 7/9/10 instead provide the shared `useViewportPreset` composable from `App.vue`, one level above `<RouterView/>` — `PreviewView.vue` ends up rendering only `<PreviewPane/>`.
  - Split Task 11 (outline item 10, "OverviewView.vue + routing") to also land `FoundationsView.vue`, which the outline's prose mentions ("foundations + overview render views host iframes like today") but didn't list as its own file — needed as a real file since it's a distinct route target, not a state of `PreviewView`.
  - Playwright specs assert exclusively through the rendered DOM (no `window.Alpine.store(...)`-style reach-through, since Pinia state isn't attached to `window`) — stricter than the suite it replaces, and consistent with `docs/API.md`'s "internal CSS class/DOM structure is not contractual" stance.
- **Placeholder scan:** no `TBD`/`TODO`/"port appropriately" strings were used anywhere in this plan; every code step contains complete, runnable code, and every porting step names the exact legacy file + line range it derives from.
- **Name/signature consistency:** `useViewportPreset({ type, slug })`'s returned shape (`type`, `slug`, `currentItem`, `activePreset`, `activePresetCategory`, `isFullPreset`, `effective`, `zoom`, `dimensionsLabel`, `isPortrait`, `setPreset`, `setPortrait`, `customWidthInput`, `applyCustomWidth`, `reloadPreview`, `iframeSrc`, `toolbarVisible`, `currentSectionKey`, `currentItemName`, `currentItemDescription`, `fieldsTree`, `fieldsCount`, `isDragging`, `startDrag`, `observeWrapper`, `observeContainer`, `CUSTOM_WIDTH_MIN`, `CUSTOM_WIDTH_MAX`, `VIEWPORTS`) is introduced once in Task 7 and referenced by that exact name set in Tasks 8-11; the `'viewport'` provide/inject key is consistent everywhere; the 9-key localStorage enumeration in Task 3 is referenced (not re-derived) by Tasks 5 and 11 wherever a persisted key is touched.
