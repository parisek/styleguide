# Fields drawer — design

**Status:** Approved (brainstorm complete)
**Date:** 2026-05-20
**Owner:** Pari

## Problem

The per-component Fields drawer added in `dda0c8d` is effectively dead code for real consumers. `frontend/components/preview.js:178` exposes fields via:

```js
get currentItemFields() {
    const route = Alpine.store('ui').route;
    const item = Alpine.store('components').find(route.type, route.slug);
    return Array.isArray(item?.fields) ? item.fields : [];
}
```

But `ComponentParser` passes the YAML `fields:` block straight through, and that block is a **map keyed by field name**, not a list. Reference shape (`tailwind-base` `accordion`):

```yaml
fields:
    heading:
        title: Heading
        type: array
        fields:
            title:
                title: Title
                type: text
                required: 1
            subtitle:
                title: Subtitle
                type: text
    items:
        title: Items
        type: array
        required: 1
        fields:
            title:
                title: Title
                type: text
                required: 1
            perex:
                title: Perex
                type: textarea
```

`Array.isArray(...)` is `false` for objects, so the drawer's `x-if` gate `currentItemFields.length > 0` always evaluates to `0` and the drawer never appears. Consumers using nested field schemas therefore see no metadata at all.

A previous incarnation of the styleguide (pre-refactor) rendered this schema as a nested tree with a dedicated "Fields schema" panel — Field / Type / Title / Description columns, indented children, color-coded type pills, red-dot required indicator. The new SPA needs the same capability, redrawn for its dark chrome.

## Goals

1. Render the full `fields:` map, including arbitrarily nested children (`type: array|object` with their own `fields:` map).
2. Match the visual treatment of the SPA chrome (dark, monospace keys, color-accented type pills).
3. Preserve the existing pattern: inline collapsible drawer below the iframe, no new layers, no new routes.
4. Keep it discoverable but unobtrusive — default collapsed, with a count badge on the trigger.

## Non-goals

- **Dark/light mode toggle.** Future feature, tracked separately. The drawer ships dark-only in this iteration (`localStorage`-persisted theme switch will style this drawer along with the rest of the SPA when it lands).
- **Editing / playground for field values.** Read-only documentation surface only.
- **Custom per-type renderers.** Every field renders into the same 4-column row regardless of `type`. Color pills convey the type category, but cell content is uniform.
- **Live preview deep-linking** (e.g. clicking a field to scroll the iframe to a rendered element). Out of scope.

## Design

### Layout — inline drawer (kept)

The drawer stays exactly where it is today: a collapsible region directly below the iframe preview, above any other component metadata. The container, toggle button, and `x-collapse` animation in `frontend/index.html:280-323` stay; only the body changes.

### Visual treatment — variant C (dark tree)

The mockup at `/tmp/styleguide-fields-mockups.html` § C is the binding visual reference. Key properties:

- **Container:** `bg-zinc-900`, `border border-zinc-700`, no card shadow (sits flush inside SPA chrome).
- **Trigger:** unchanged — chevron, `Fields schema` uppercase tracker, count badge.
- **Table head:** four columns — `FIELD | TYPE | TITLE | DESCRIPTION` — uppercase, `text-zinc-500`, separator `border-zinc-800`.
- **Field cell:** monospace key in a `bg-zinc-800` pill; child rows indented `pl-6` per depth level with a `└` glyph in `text-zinc-600` immediately preceding the key pill.
- **Type cell:** uppercase monospace pill, color-coded by family:
    - `array`, `object` → purple/pink family (`bg-purple-500/18 text-purple-300`)
    - `text` → blue (`bg-blue-500/18 text-blue-300`)
    - `textarea` → indigo (`bg-indigo-500/18 text-indigo-300`)
    - `image` → emerald (`bg-emerald-500/18 text-emerald-300`)
    - `link` → orange (`bg-orange-500/18 text-orange-300`)
    - anything else → neutral (`bg-zinc-800 text-zinc-300`) — fallback so the drawer never breaks on a new type.
- **Title cell:** plain text in `text-zinc-300` (the human-readable label from YAML `title:`).
- **Description cell:** `text-zinc-500`, may carry HTML (already `x-html`-rendered today, preserved); em-dash placeholder when empty.
- **Required marker:** 8 px red dot (`bg-red-500`, `rounded-full`) immediately to the right of the field key pill. No asterisk. Title attribute `Required` for hover.
- **Footer legend:** thin `border-zinc-800` separator, then `● = Required field` in `text-zinc-500`.

### Default state — collapsed

Drawer ships collapsed by default. The trigger button is the discoverability path; the count badge (`currentItemFieldsCount`) shows there's content worth expanding. No `localStorage` persistence in this iteration — keep parity with today's behavior, revisit only if friction shows up.

### Data model — recursive walk

`ComponentParser::normaliseMetadata` already passes `fields` through as the parsed YAML tree. We don't touch PHP at all. Frontend rendering becomes a recursive walk; the simplest shape:

1. Replace `currentItemFields` (currently filters to arrays) with a getter that returns the raw `fields` object/map (or `null` when absent).
2. Add `currentItemFieldsCount` — recursive count of all nodes (root + descendants) for the trigger badge.
3. Render the table with a recursive Alpine template (`<template x-for>` over `Object.entries(node)`), each row calling itself for `field.fields` at `depth + 1`. The depth value drives left-padding (`pl-6 * depth`) and the leading `└` glyph (shown when `depth > 0`).

Alpine 3 doesn't support self-referential templates inline, so the recursion is unrolled into a helper function on the component (returns a flat list of `{ key, depth, type, title, description, required }` rows produced by a depth-first traversal). The template then iterates that flat list, with `depth` driving indentation and glyph visibility.

### Type pill mapping — single source of truth

A small map (`TYPE_PILL_CLASSES`) lives in `preview.js` next to the existing `VIEWPORTS` constant. Unknown types fall back to neutral zinc so the drawer never breaks when a project introduces a new field type.

### Edge cases

- **No `fields:` block** → drawer hidden entirely (existing `x-if` guard against empty input).
- **Empty `fields:` map (declared but no keys)** → drawer hidden (count is 0).
- **Field missing `type:`** → no pill rendered; the Type cell shows `—` in `text-zinc-600`. Never crash on an absent type string.
- **Field missing `title:`** → title cell shows `—` in `text-zinc-600`.
- **`type: array` or `type: object` without nested `fields:`** → no children rendered, no expansion glyph; the row stands alone.
- **Description containing HTML** → rendered via `x-html` as today (already a deliberate choice for richer YAML descriptions).
- **Deeply nested (>3 levels)** → indentation continues; no max-depth cap. The drawer's `max-h-80 overflow-y-auto` already scrolls when content is tall.

## Out of scope, tracked for later

- **SPA-wide dark/light theme toggle persisted to `localStorage`.** Will need to be designed for the whole chrome, not just the drawer. When that lands, this drawer adopts the system automatically via the established theming primitives.
- **Drawer-open state persistence** to `localStorage`. Revisit if users habitually re-open it on every navigation.
- **Inline anchor links** from a sidebar TOC to specific fields. Premature.

## Testing

- The PHP layer is untouched; existing `RendererTest` / `ComponentParserTest` continue to pass without changes.
- No new automated tests for the SPA in this iteration — Alpine components in this project don't have a unit-test harness today, and the rendering logic is straightforward enough that a manual smoke check on `tailwind-base`'s `accordion` (nested fields, required markers, multiple types) is the verification gate.
- Verification protocol:
    1. `cd frontend && npm run build`.
    2. Load `https://tailwind-base.ddev.site/styleguide/component/accordion`.
    3. Confirm: drawer collapsed by default with count `6`; expand; rows match the mockup variant C structure (heading → title*, subtitle; items* → title*, perex); required dots on the four expected rows; type pills colored.
    4. Spot-check a flat-fields component (`alert`) — no glyph, no indentation, correct count.
    5. Spot-check a component without `fields:` — drawer absent.

## Files touched

- `frontend/index.html` — replace the drawer body (lines ~280-323) with the new table markup that consumes the depth-walked row list.
- `frontend/components/preview.js` — add `currentItemFieldsTree`, `currentItemFieldsCount`, `TYPE_PILL_CLASSES`; update or remove the existing `currentItemFields` getter.
- `dist/` — committed after rebuild.
- `CHANGELOG.md` — entry under `[Unreleased]`.
