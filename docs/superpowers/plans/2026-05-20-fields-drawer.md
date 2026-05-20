# Fields Drawer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore working nested-fields rendering in the per-component drawer (variant C — dark tree, default collapsed, 4 columns, color-coded type pills, red-dot required indicator) per `docs/superpowers/specs/2026-05-20-fields-drawer-design.md`.

**Architecture:** Frontend-only. `ComponentParser` already passes the YAML `fields:` map through unchanged. The SPA flattens that nested object into a depth-tagged list (DFS in JS, since Alpine 3 templates can't self-recurse), and the drawer template iterates that flat list — depth drives indentation and the `└` glyph. Type pills come from a single static map with a neutral fallback.

**Tech Stack:** Alpine.js 3, Tailwind v4 (utility classes), Vite (bundled into `dist/`).

---

## File Structure

- `frontend/components/preview.js` — add `TYPE_PILL_CLASSES` constant, `flattenFieldsTree(map, depth)` helper, replace the existing flat-array `currentItemFields` getter with `currentItemFieldsTree` (flat depth-tagged list) + `currentItemFieldsCount` (length of that list).
- `frontend/index.html` — replace the drawer body markup (currently lines ~280-323) with the new four-column tree table that consumes `currentItemFieldsTree`.
- `dist/` — committed verbatim after `npm run build`.
- `CHANGELOG.md` — `[Unreleased]` entry under `### Fixed` / `### Changed`.

The whole change is contained within those four files. No PHP, no template, no test fixtures.

---

### Task 1: Data layer — flatten + type pill map in preview.js

**Files:**
- Modify: `frontend/components/preview.js`

The current `currentItemFields` getter uses `Array.isArray()` against `item.fields`, but real YAML metadata is an object (map). Replace with a DFS that produces a flat list of rows.

- [ ] **Step 1: Add `TYPE_PILL_CLASSES` top-level constant**

Insert directly above the `document.addEventListener('alpine:init', ...)` line (after the `CUSTOM_MAX` constant at the top of the file).

```javascript
// Type → Tailwind classes for the colored Type pill in the fields drawer.
// Lower-case keys; the lookup in fieldsTypePill() normalises the incoming
// value. Unknown types fall back to a neutral zinc pill so the drawer
// never breaks on a new type a project introduces — the YAML schema is
// open-ended and we don't want to gate rendering on type vocabulary.
const TYPE_PILL_CLASSES = {
    array:    'bg-purple-500/20 text-purple-300',
    object:   'bg-pink-500/20 text-pink-300',
    text:     'bg-blue-500/20 text-blue-300',
    textarea: 'bg-indigo-500/20 text-indigo-300',
    image:    'bg-emerald-500/20 text-emerald-300',
    link:     'bg-orange-500/20 text-orange-300',
};
const TYPE_PILL_FALLBACK = 'bg-zinc-800 text-zinc-300';
```

- [ ] **Step 2: Add `flattenFieldsTree` helper above the same `alpine:init` line**

Place directly under `TYPE_PILL_FALLBACK`.

```javascript
// Depth-first walk over the YAML `fields:` map (object keyed by field
// name). Produces a flat list of rows so the Alpine template can iterate
// linearly — Alpine 3 doesn't support self-referential templates inline.
// Each row carries its `depth` (0 for root, 1+ for children) so the
// template can apply `pl-6 * depth` padding and render the `└` glyph
// only when depth > 0.
//
// Nested children live under `field.fields` (`type: array` / `object`
// shape). Missing or non-object children mean the row is a leaf — no
// recursion, no glyph for its (non-existent) descendants.
function flattenFieldsTree(map, depth = 0) {
    if (!map || typeof map !== 'object' || Array.isArray(map)) return [];
    const rows = [];
    for (const [key, field] of Object.entries(map)) {
        if (!field || typeof field !== 'object') continue;
        rows.push({
            key,
            depth,
            type: typeof field.type === 'string' ? field.type : '',
            title: typeof field.title === 'string' ? field.title : '',
            description: typeof field.description === 'string' ? field.description : '',
            // YAML `required: 1` parses to `1` (truthy), `required: 0` to `0`
            // (falsy). Coerce to boolean so the template's `x-if` is clean.
            required: !!field.required,
        });
        if (field.fields && typeof field.fields === 'object') {
            rows.push(...flattenFieldsTree(field.fields, depth + 1));
        }
    }
    return rows;
}
```

- [ ] **Step 3: Replace the existing `currentItemFields` getter**

Locate the existing block in `frontend/components/preview.js`:

```javascript
        get currentItemFields() {
            const route = Alpine.store('ui').route;
            const item = Alpine.store('components').find(route.type, route.slug);
            return Array.isArray(item?.fields) ? item.fields : [];
        },
```

Replace it with:

```javascript
        get currentItemFieldsTree() {
            const route = Alpine.store('ui').route;
            const item = Alpine.store('components').find(route.type, route.slug);
            return flattenFieldsTree(item?.fields);
        },

        get currentItemFieldsCount() {
            return this.currentItemFieldsTree.length;
        },

        // Tailwind classes for a single field's Type pill. Lower-cased
        // lookup so projects can spell `Array` or `TEXT` in YAML and still
        // hit the map. Unknown types render neutral so the drawer stays
        // schema-agnostic.
        fieldsTypePill(type) {
            const key = String(type ?? '').toLowerCase();
            return TYPE_PILL_CLASSES[key] ?? TYPE_PILL_FALLBACK;
        },
```

- [ ] **Step 4: Commit**

```bash
git add frontend/components/preview.js
git commit -m "feat(fields-drawer): flatten nested fields map into depth-tagged rows

Adds flattenFieldsTree (DFS over the YAML fields: map) plus a static
type→pill class table with a neutral fallback. Replaces the
Array.isArray-gated currentItemFields getter — that gate evaluated false
for every real consumer because the YAML produces an object, not a
list, so the drawer never rendered."
```

---

### Task 2: HTML drawer markup in index.html

**Files:**
- Modify: `frontend/index.html` (current drawer block around lines 275-323)

The new table has four columns (Field / Type / Title / Description), depth-based indentation, color-coded type pills via `fieldsTypePill()`, red-dot required indicator, and a footer legend. Default-collapsed via `x-data="{ open: false }"` — unchanged from today.

- [ ] **Step 1: Replace the drawer block**

Find this block (the current `x-if="currentItemFields.length > 0 ..."` template) in `frontend/index.html`:

```html
            <!-- Per-component Fields drawer — collapsible, SPA-rendered (NOT
                 in the iframe) so the metadata table looks identical across
                 every consuming project regardless of their own CSS. Shows
                 only when the current route's component / page actually
                 declares a `fields:` block in its YAML metadata. -->
            <template x-if="currentItemFields.length > 0 && $store.ui.route.slug">
                <div x-data="{ open: false }" class="border-b border-zinc-800 bg-zinc-900/40">
                    <button @click="open = !open"
                            class="w-full flex items-center gap-2 px-4 py-2 text-xs text-zinc-400 hover:text-zinc-100 transition-colors">
                        <svg aria-hidden="true" focusable="false" class="w-3 h-3 transition-transform" :class="open && 'rotate-90'" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        <span class="uppercase tracking-wider font-semibold" x-text="$store.i18n.t('nav.fields')"></span>
                        <span class="font-mono text-zinc-500" x-text="currentItemFields.length"></span>
                    </button>
                    <div x-show="open" x-collapse class="bg-zinc-950/40">
                        <div class="max-h-80 overflow-y-auto">
                            <table class="w-full text-xs">
                                <thead class="sticky top-0 bg-zinc-900 text-left text-[10px] uppercase tracking-wider text-zinc-500">
                                    <tr class="border-b border-zinc-800">
                                        <th class="px-4 py-2 font-medium w-40">Name</th>
                                        <th class="px-4 py-2 font-medium w-28">Type</th>
                                        <th class="px-4 py-2 font-medium">Description</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-800/60">
                                    <template x-for="(field, idx) in currentItemFields" :key="idx">
                                        <tr class="hover:bg-zinc-800/40 transition-colors">
                                            <td class="px-4 py-2 align-top font-mono text-zinc-100">
                                                <span x-text="field.name ?? field.key ?? '—'"></span>
                                                <template x-if="field.required">
                                                    <span class="ml-1 text-rose-400" title="Required">*</span>
                                                </template>
                                            </td>
                                            <td class="px-4 py-2 align-top">
                                                <template x-if="field.type">
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-zinc-800 font-mono text-zinc-300" x-text="field.type"></span>
                                                </template>
                                                <template x-if="!field.type">
                                                    <span class="text-zinc-600">—</span>
                                                </template>
                                            </td>
                                            <td class="px-4 py-2 align-top text-zinc-400 leading-relaxed" x-html="field.description ?? ''"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </template>
```

Replace the entire block (everything between the `<!-- Per-component Fields drawer ... -->` comment and its closing `</template>`) with:

```html
            <!-- Per-component Fields drawer — collapsible, SPA-rendered (NOT
                 in the iframe) so the metadata table looks identical across
                 every consuming project regardless of their own CSS. Shows
                 only when the current route's component / page actually
                 declares a `fields:` block in its YAML metadata. Default
                 collapsed; tree depth comes from flattenFieldsTree() so
                 the template stays linear. -->
            <template x-if="currentItemFieldsCount > 0 && $store.ui.route.slug">
                <div x-data="{ open: false }" class="border-b border-zinc-800 bg-zinc-900/40">
                    <button @click="open = !open"
                            class="w-full flex items-center gap-2 px-4 py-2 text-xs text-zinc-400 hover:text-zinc-100 transition-colors">
                        <svg aria-hidden="true" focusable="false" class="w-3 h-3 transition-transform" :class="open && 'rotate-90'" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        <span class="uppercase tracking-wider font-semibold" x-text="$store.i18n.t('nav.fields')"></span>
                        <span class="font-mono text-zinc-500" x-text="currentItemFieldsCount"></span>
                    </button>
                    <div x-show="open" x-collapse class="bg-zinc-950/40">
                        <div class="max-h-80 overflow-y-auto">
                            <table class="w-full text-xs">
                                <thead class="sticky top-0 bg-zinc-900 text-left text-[10px] uppercase tracking-wider text-zinc-500">
                                    <tr class="border-b border-zinc-800">
                                        <th class="px-4 py-2 font-medium w-56">Field</th>
                                        <th class="px-4 py-2 font-medium w-28">Type</th>
                                        <th class="px-4 py-2 font-medium w-40">Title</th>
                                        <th class="px-4 py-2 font-medium">Description</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-800/60">
                                    <template x-for="(row, idx) in currentItemFieldsTree" :key="idx">
                                        <tr class="hover:bg-zinc-800/40 transition-colors">
                                            <td class="px-4 py-2 align-middle">
                                                <!-- Indentation comes from a left-padding style multiplied
                                                     by depth; Tailwind utility classes can't take a runtime
                                                     value, so a small inline style is the cleanest path. -->
                                                <span class="inline-flex items-center gap-2"
                                                      :style="`padding-left: ${row.depth * 1.5}rem`">
                                                    <template x-if="row.depth > 0">
                                                        <span class="text-zinc-600 font-mono text-base leading-none -mt-1">└</span>
                                                    </template>
                                                    <span class="font-mono text-zinc-100 bg-zinc-800 px-1.5 py-0.5 rounded text-xs"
                                                          x-text="row.key"></span>
                                                    <template x-if="row.required">
                                                        <span class="w-2 h-2 rounded-full bg-red-500 shrink-0" title="Required"></span>
                                                    </template>
                                                </span>
                                            </td>
                                            <td class="px-4 py-2 align-middle">
                                                <template x-if="row.type">
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded font-mono text-[10px] tracking-wider uppercase font-semibold"
                                                          :class="fieldsTypePill(row.type)"
                                                          x-text="row.type"></span>
                                                </template>
                                                <template x-if="!row.type">
                                                    <span class="text-zinc-600">—</span>
                                                </template>
                                            </td>
                                            <td class="px-4 py-2 align-middle text-zinc-300" x-text="row.title || '—'"></td>
                                            <td class="px-4 py-2 align-middle text-zinc-500 leading-relaxed" x-html="row.description || '—'"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                            <div class="px-4 py-2 border-t border-zinc-800 bg-zinc-900/60 text-[10px] text-zinc-500 flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-red-500 inline-block"></span>
                                <span x-text="$store.i18n.t('fields.requiredLegend')"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
```

- [ ] **Step 2: Add the legend i18n key**

The `$store.i18n.t('fields.requiredLegend')` lookup needs entries in both locale files.

Edit `frontend/public/locales/en.json` — find the `fields` object (or `nav.fields`) and add a `fields` block if missing, then a `requiredLegend` key. Likely shape (read the file first to confirm current structure):

```json
"fields": {
    "requiredLegend": "= Required field"
}
```

Same for `frontend/public/locales/cs.json`:

```json
"fields": {
    "requiredLegend": "= Povinné pole"
}
```

If a `fields` block already exists in either file, add only `requiredLegend` under it.

- [ ] **Step 3: Commit**

```bash
git add frontend/index.html frontend/public/locales/cs.json frontend/public/locales/en.json
git commit -m "feat(fields-drawer): four-column tree markup with required-dot legend

Replaces the previous flat Name/Type/Description table with a depth-
indented Field/Type/Title/Description tree that consumes the flattened
list from currentItemFieldsTree. Required indicator is a red dot; type
pills are color-coded via fieldsTypePill() with a neutral fallback;
footer legend localised in cs + en."
```

---

### Task 3: Rebuild dist + manual verification

**Files:**
- Build artifacts: `dist/` (committed)

The package ships `dist/` to consumers verbatim — no consumer-side npm step. Verification is manual against the local `tailwind-base` symlink target.

- [ ] **Step 1: Build the SPA**

```bash
cd frontend && npm run build
```

Expected: build completes, exit 0, files under `dist/` updated (`dist/assets/index-*.js`, `dist/index.html`).

- [ ] **Step 2: Verify on tailwind-base**

Open `https://tailwind-base.ddev.site/styleguide/component/accordion` in the browser.

Expected:
- Below the iframe preview a collapsible row labelled `Fields schema` (or the localised equivalent — `nav.fields`) shows count `6`.
- Default state: collapsed.
- Expand → table with four columns: Field / Type / Title / Description.
- Rows in order: `heading` (ARRAY), then indented `title` (TEXT, red dot) and `subtitle` (TEXT), then `items` (ARRAY, red dot), then indented `title` (TEXT, red dot) and `perex` (TEXTAREA).
- Indented rows display the `└` glyph.
- Required rows carry a red dot to the right of the key pill.
- Type pills are color-coded (ARRAY purple, TEXT blue, TEXTAREA indigo).
- Footer legend: red dot + "= Required field" / "= Povinné pole".

- [ ] **Step 3: Smoke check — flat-fields component**

Open `https://tailwind-base.ddev.site/styleguide/component/alert` (or another component with a non-nested `fields:` block).

Expected:
- Drawer present with the correct count.
- No `└` glyphs (depth is 0 everywhere).
- All other visuals match.

- [ ] **Step 4: Smoke check — no-fields component**

Open any component that doesn't declare `fields:` at all in its YAML (or open the foundations route).

Expected:
- Drawer absent (the `x-if="currentItemFieldsCount > 0"` gate suppresses it).

- [ ] **Step 5: Commit the rebuilt dist**

```bash
git add dist/
git commit -m "build(fields-drawer): rebuild dist/ with the new tree drawer"
```

---

### Task 4: CHANGELOG + push + open PR

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Read the current `[Unreleased]` section structure**

Open `CHANGELOG.md` and locate `## [Unreleased]`. Note which subsections (`### Added` / `### Changed` / `### Fixed`) already exist. The drawer change is primarily a bug-fix (the previous drawer was dead code for real consumers) with a visual redesign on top, so it belongs under `### Fixed` (primary) with a `### Changed` sentence about the visual upgrade.

- [ ] **Step 2: Add the changelog entry**

Insert under the appropriate subsection(s). Concrete text:

Under `### Fixed`:

```markdown
- Per-component **Fields drawer** now actually renders for real-world
  components. The previous incarnation gated on `Array.isArray(fields)`
  but `ComponentParser` passes the YAML `fields:` map straight through
  as a PHP associative array (JSON object on the wire), so the drawer
  silently hid itself on every component with field metadata. The
  drawer now walks the nested map via DFS and renders a flat,
  depth-tagged list — arbitrary nesting depth supported.
```

Under `### Changed`:

```markdown
- The Fields drawer is redrawn as a four-column tree
  (Field / Type / Title / Description) with depth-indented child rows
  (`└` glyph at depth > 0), a colour-coded Type pill per field-type
  family (`array`/`object` purple-pink, `text` blue, `textarea` indigo,
  `image` emerald, `link` orange, anything else neutral zinc), and a
  red-dot Required indicator with a localised footer legend. Default
  state stays collapsed; the trigger badge shows the recursive node
  count.
```

- [ ] **Step 3: Commit the changelog**

```bash
git add CHANGELOG.md
git commit -m "docs(changelog): describe the fields drawer fix + redesign"
```

- [ ] **Step 4: Push the branch**

```bash
git push -u origin feat/fields-drawer
```

- [ ] **Step 5: Open the pull request**

```bash
gh pr create --title "feat(spa): per-component fields drawer — nested tree, dark chrome" --body "$(cat <<'EOF'
## Why

`Styleguide`'s SPA drawer added in `dda0c8d` shipped with a critical bug for real consumers: `frontend/components/preview.js` gated the drawer on `Array.isArray(item?.fields)`, but the YAML `fields:` block is parsed into an associative map (object on the wire), not a list. The gate always evaluated false, so the drawer never appeared for any component or page that actually declared field metadata. Consumers that *do* use nested schemas (e.g. tailwind-base's \`accordion\` with two \`array\` parents wrapping their own \`fields:\` maps) saw no schema information at all.

A pre-refactor incarnation of the styleguide rendered the same schema as a nested tree with Field / Type / Title / Description columns, colour-coded type pills, and a red-dot required indicator. This PR brings that capability back, redrawn for the new SPA's dark chrome.

## What changed

\`frontend/components/preview.js\`
- Adds \`flattenFieldsTree(map, depth)\` — DFS over the nested object, produces a flat list of \`{ key, depth, type, title, description, required }\` rows. Alpine 3 templates can't self-recurse, so the flattening happens once in JS and the markup iterates linearly.
- Adds \`TYPE_PILL_CLASSES\` map + \`fieldsTypePill(type)\` helper — lower-cased lookup with a neutral fallback so the drawer doesn't break when a project introduces a new field type.
- Replaces \`currentItemFields\` (the buggy Array.isArray getter) with \`currentItemFieldsTree\` + \`currentItemFieldsCount\`.

\`frontend/index.html\`
- Four-column tree table. Depth-based indentation via inline \`style=\"padding-left: …\"\`, \`└\` glyph at depth > 0, colour-coded Type pill, red-dot Required indicator, footer legend with \`fields.requiredLegend\` i18n key.
- Default collapsed; trigger badge shows recursive count.

\`frontend/public/locales/{cs,en}.json\`
- New \`fields.requiredLegend\` key.

\`dist/\`
- Rebuilt.

\`CHANGELOG.md\`
- Entries under \`Fixed\` (silent-hide bug) and \`Changed\` (redesign).

## Spec

\`docs/superpowers/specs/2026-05-20-fields-drawer-design.md\` — design rationale, mockup variants, and the variant-C decision (dark theme, default collapsed) live there.

## Test plan

- [x] \`composer test\` (unchanged — no PHP touched).
- [ ] Load \`/styleguide/component/accordion\` on tailwind-base — drawer renders count 6, four columns, two nesting levels, red dots on the four required rows, colour-coded pills.
- [ ] Load a flat-fields component (\`alert\` or similar) — drawer renders without \`└\` glyphs.
- [ ] Load a no-fields component — drawer absent.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-Review

**Spec coverage:**

| Spec requirement | Task |
|---|---|
| Render full nested `fields:` map | Task 1 step 2 (`flattenFieldsTree`) + Task 2 step 1 (template) |
| Variant C visual — dark, 4 columns, `└` glyph, color pills, red dot | Task 2 step 1 |
| Default collapsed | Task 2 step 1 (`x-data="{ open: false }"`, unchanged) |
| `TYPE_PILL_CLASSES` with neutral fallback | Task 1 step 1 |
| No PHP changes | Confirmed — no PHP tasks |
| No new automated tests; manual smoke | Task 3 steps 2-4 |
| Edge: empty `fields:` map | Task 2 step 1 (`currentItemFieldsCount > 0` gate) |
| Edge: missing `type:` | Task 2 step 1 (`x-if="!row.type"` branch shows `—`) |
| Edge: missing `title:` | Task 2 step 1 (`x-text="row.title || '—'"`) |
| Edge: array/object without nested `fields:` | Task 1 step 2 (no recursion when `field.fields` absent) |
| Edge: description with HTML | Task 2 step 1 (`x-html="row.description || '—'"`) |
| Edge: deeply nested | Task 2 step 1 (`padding-left: ${row.depth * 1.5}rem` scales) |
| CHANGELOG entry | Task 4 step 2 |

**Placeholder scan:** No TBDs, no "implement later", every step carries the literal code or command to run.

**Type consistency:** `currentItemFieldsTree` / `currentItemFieldsCount` / `flattenFieldsTree` / `fieldsTypePill` referenced consistently across Tasks 1 and 2.

**Footnote:** The `x-html` rendering of descriptions is intentional and preserved from the prior implementation — YAML descriptions can carry rich HTML. If a consumer ever pipes user input into descriptions this would be an XSS surface, but YAML metadata is authored by the component author themselves, so the trust boundary is the same as authoring a Twig template.
