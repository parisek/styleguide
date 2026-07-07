<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue';
import { useRouter } from 'vue-router';
import { useCatalogStore } from '../stores/catalog.js';
import { useI18nStore } from '../stores/i18n.js';
import { scoreEntry, normalizeForSearch } from '../lib/searchMatch.js';

// Command palette (Task 5). Owns the global ⌘K/Ctrl+K shortcut that used to
// live in useSearchShortcuts.js (retired) and only focused the sidebar's
// inline filter input -- this component replaces that with a real ranked,
// grouped, keyboard-navigable overlay. The sidebar's own filter input keeps
// working independently (Sidebar.vue, Task 5 Step 4): this palette is a
// separate, additive entry point, not a replacement for it.
const catalog = useCatalogStore();
const i18n = useI18nStore();
const router = useRouter();

const isOpen = ref(false);
const query = ref('');
const activeIndex = ref(0);
const inputRef = ref(null);

// Field-level filtering mirrors Sidebar.vue's own rules exactly, so the
// palette never surfaces something the sidebar itself would hide: pages and
// components drop hasStyleguide:false skeleton templates (Sidebar's
// pageItems / catalog.bySection), docs stay unfiltered (Sidebar's docItems
// has never filtered them either).
function rank(type, list) {
    return list
        .map((entry) => ({ type, entry, score: scoreEntry(query.value, entry) }))
        .filter((row) => row.score > 0)
        .sort((a, b) => b.score - a.score);
}

// Empty query -> no groups at all (an unfiltered dump of every component/
// page/doc isn't a "palette", it's the sidebar) rather than falling back to
// some default listing.
const groups = computed(() => {
    if (query.value.trim() === '') return [];
    const componentRows = rank('component', catalog.items.filter((c) => c.hasStyleguide !== false));
    const pageRows = rank('page', catalog.pages.filter((p) => p.hasStyleguide !== false));
    const docRows = rank('doc', catalog.docEntries);
    return [
        { key: 'components', labelKey: 'search.group_components', rows: componentRows },
        { key: 'pages', labelKey: 'search.group_pages', rows: pageRows },
        { key: 'docs', labelKey: 'search.group_docs', rows: docRows },
    ].filter((g) => g.rows.length > 0);
});

// Flattened once so ArrowUp/ArrowDown can move a single linear cursor across
// group boundaries without the keyboard handler knowing about grouping.
const flatRows = computed(() => groups.value.flatMap((g) => g.rows));

function rowKey(row) {
    return `${row.type}:${row.entry.id}`;
}

function isActiveRow(row) {
    return flatRows.value.indexOf(row) === activeIndex.value;
}

function open() {
    isOpen.value = true;
    query.value = '';
    activeIndex.value = 0;
    nextTick(() => inputRef.value?.focus());
}

function close() {
    isOpen.value = false;
}

function move(delta) {
    const n = flatRows.value.length;
    if (n === 0) return;
    // +n before % wraps a negative delta (ArrowUp from index 0) back around
    // to the last row instead of going negative.
    activeIndex.value = (activeIndex.value + delta + n) % n;
}

function commit(row) {
    const target = row ?? flatRows.value[activeIndex.value];
    if (!target) return;
    router.push(`/${target.type}/${target.entry.id}`);
    close();
}

function onGlobalKeydown(e) {
    if (e.key === 'k' && (e.metaKey || e.ctrlKey)) {
        e.preventDefault();
        // Second press toggles closed rather than being a no-op -- matches
        // the "same shortcut opens and closes" pattern most command
        // palettes use (Slack, Linear, VS Code), so an accidental extra
        // press doesn't leave the user stuck wondering why nothing happened.
        if (isOpen.value) close();
        else open();
        return;
    }
    if (!isOpen.value) return;
    if (e.key === 'Escape') { close(); return; }
    if (e.key === 'ArrowDown') { e.preventDefault(); move(1); return; }
    if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); return; }
    if (e.key === 'Enter') { e.preventDefault(); commit(); }
}

// Every query edit invalidates whatever the cursor was pointing at (the
// result set itself changed shape), so snap back to the top match rather
// than risk it landing on an unrelated row or past the new, shorter list.
watch(query, () => { activeIndex.value = 0; });

onMounted(() => window.addEventListener('keydown', onGlobalKeydown));
onUnmounted(() => window.removeEventListener('keydown', onGlobalKeydown));

// Splits `text` into {value, matched} segments around the first case- and
// diacritic-insensitive occurrence of `query`, for the palette's
// highlighted-substring rendering. The template renders these with `{{ }}`
// text interpolation ONLY, never v-html -- there is no HTML-injection
// surface here regardless of where `text` originated, by construction.
function highlightSegments(text, q) {
    const raw = text ?? '';
    const foldedQuery = normalizeForSearch(q).trim();
    if (!foldedQuery) return [{ value: raw, matched: false }];
    // normalizeForSearch's NFKD-fold-then-strip-combining-marks preserves a
    // 1:1 index mapping against the original string for every character
    // this app's catalog data actually contains (Czech/English Latin
    // diacritics each collapse from one base+mark codepoint to one plain
    // codepoint) -- so slicing `raw` at indices found in the folded string
    // is safe.
    const idx = normalizeForSearch(raw).indexOf(foldedQuery);
    if (idx === -1) return [{ value: raw, matched: false }];
    const end = idx + foldedQuery.length;
    const segments = [];
    if (idx > 0) segments.push({ value: raw.slice(0, idx), matched: false });
    segments.push({ value: raw.slice(idx, end), matched: true });
    if (end < raw.length) segments.push({ value: raw.slice(end), matched: false });
    return segments;
}
</script>

<template>
    <div
        v-if="isOpen"
        role="dialog"
        aria-modal="true"
        :aria-label="i18n.t('search.label')"
        class="fixed inset-0 z-[60] flex items-start justify-center bg-black/40 pt-24 px-4"
        @click.self="close"
    >
        <!-- Open transition: scale(0.98)->1 + fade, ~120ms, via the
             .sg-palette-in keyframe (styleguide.css). A plain CSS animation
             rather than Vue's <Transition> -- the panel is freshly created
             by v-if="isOpen" on every open, so the keyframe just plays once
             on insertion with no JS-tracked enter/leave state needed (and
             no risk of a leave transition ever holding the dialog in the
             DOM after Escape/second Cmd+K, which callers -- and this
             component's own tests -- expect to remove it synchronously).
             Gated behind prefers-reduced-motion:no-preference in the CSS. -->
        <div class="sg-palette-in w-full max-w-lg rounded-xl shadow-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 overflow-hidden">
            <input
                ref="inputRef"
                v-model="query"
                type="text"
                :placeholder="i18n.t('search.placeholder')"
                class="w-full px-4 py-3 text-sm bg-transparent border-b border-zinc-200 dark:border-zinc-700 outline-none text-zinc-900 dark:text-zinc-100 placeholder-zinc-500"
            >
            <div v-if="query.trim() && flatRows.length === 0" class="px-4 py-6 text-center text-sm text-zinc-500">
                {{ i18n.t('search.no_results') }}
            </div>
            <ul v-else-if="flatRows.length > 0" role="listbox" :aria-label="i18n.t('search.label')" class="max-h-96 overflow-y-auto py-1.5">
                <template v-for="group in groups" :key="group.key">
                    <li role="presentation" class="px-4 pt-2 pb-1 text-[10px] uppercase tracking-wider font-bold text-zinc-500">{{ i18n.t(group.labelKey) }}</li>
                    <li
                        v-for="row in group.rows"
                        :key="rowKey(row)"
                        role="option"
                        :aria-selected="isActiveRow(row)"
                        :data-active="isActiveRow(row)"
                        class="mx-1.5 cursor-pointer rounded-lg px-3 py-2 text-sm transition-colors"
                        :class="isActiveRow(row) ? 'bg-red-600/10 text-red-700 dark:bg-red-400/15 dark:text-red-400' : 'text-zinc-700 dark:text-zinc-200 hover:bg-zinc-100 dark:hover:bg-zinc-700'"
                        @mouseenter="activeIndex = flatRows.indexOf(row)"
                        @click="commit(row)"
                    >
                        <span
                            v-for="(segment, idx) in highlightSegments(row.entry.name ?? row.entry.id, query)"
                            :key="idx"
                            :data-matched="segment.matched"
                            :class="segment.matched && 'font-semibold text-red-600 dark:text-red-400'"
                        >{{ segment.value }}</span>
                    </li>
                </template>
            </ul>
            <div class="flex justify-end gap-3 border-t border-zinc-200 dark:border-zinc-700 px-4 py-2 text-[11px] text-zinc-500 dark:text-zinc-400">
                <span>{{ i18n.t('search.hint_navigate') }}</span>
                <span>{{ i18n.t('search.hint_close') }}</span>
            </div>
        </div>
    </div>
</template>
