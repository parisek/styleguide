<script setup>
import { computed, ref } from 'vue';
import { useCatalogStore } from '../stores/catalog.js';
import { useI18nStore } from '../stores/i18n.js';
import { flattenFieldsTree, fieldsTypePill } from '../lib/fieldsTree.js';
import { normalizeForSearch } from '../lib/searchMatch.js';

const catalog = useCatalogStore();
const i18n = useI18nStore();
const query = ref('');
const expanded = ref({}); // `${componentId}:${row.path}` → boolean

const groups = computed(() => {
    const q = normalizeForSearch(query.value);
    return catalog.items
        .filter((c) => Array.isArray(c.fields) && c.fields.length > 0 && c.has_styleguide !== false)
        .map((c) => {
            const rows = flattenFieldsTree(c.fields);
            if (!q) return { id: c.id, name: c.name, rows };
            if (normalizeForSearch(c.name).includes(q) || normalizeForSearch(c.id).includes(q)) {
                return { id: c.id, name: c.name, rows };
            }
            return {
                id: c.id,
                name: c.name,
                rows: rows.filter((r) => [r.key, r.label, r.type].some((v) => normalizeForSearch(v).includes(q))),
            };
        })
        .filter((g) => g.rows.length > 0);
});
</script>

<template>
    <div class="h-full overflow-y-auto">
        <div class="max-w-5xl mx-auto px-6 py-8">
            <h1 class="text-lg font-bold text-zinc-900 dark:text-zinc-50">{{ i18n.t('fields.overviewTitle') }}</h1>
            <input v-model="query" type="search" :placeholder="i18n.t('fields.filterPlaceholder')"
                   class="mt-4 w-full max-w-md px-3.5 py-2 text-sm rounded-lg border border-zinc-300 bg-white text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                   data-testid="fields-filter">
            <p v-if="groups.length === 0" class="mt-8 text-sm text-zinc-500">{{ i18n.t('fields.empty') }}</p>
            <section v-for="g in groups" :key="g.id" class="mt-8">
                <router-link :to="{ name: 'component', params: { slug: g.id }, query: { fields: '1' } }"
                             class="text-sm font-bold text-zinc-900 hover:text-red-700 dark:text-zinc-50 dark:hover:text-red-400 transition-colors">
                    {{ g.name }}
                </router-link>
                <table class="mt-2 w-full text-xs">
                    <thead class="text-left text-[10px] uppercase tracking-wider text-zinc-500">
                        <tr class="border-b border-zinc-200 dark:border-zinc-800">
                            <th class="px-4 py-2 font-medium w-56">Field</th>
                            <th class="px-4 py-2 font-medium w-28">Type</th>
                            <th class="px-4 py-2 font-medium w-40">Label</th>
                            <th class="px-4 py-2 font-medium">Description</th>
                        </tr>
                    </thead>
                    <!-- Copied from FieldsDrawer.vue's <tbody> (same column set, same
                         expandable-extras row), keyed by `${g.id}:${row.path}` here
                         since this view flattens multiple components on one page and
                         FieldsDrawer's bare `row.path` key would collide across them.
                         Deliberate ~20-line template duplication instead of an early
                         extraction — see Task 5 brief; extract FieldsTable.vue if a
                         reviewer pushes back. -->
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800/60">
                        <template v-for="row in g.rows" :key="`${g.id}:${row.path}`">
                            <tr class="hover:bg-zinc-100 dark:hover:bg-zinc-800/40 transition-colors">
                                <td class="px-4 py-2 align-middle">
                                    <span class="inline-flex items-center gap-2" :style="`padding-left: ${row.depth * 1.5}rem`">
                                        <span v-if="row.depth > 0" class="text-zinc-400 dark:text-zinc-600 font-mono text-base leading-none -mt-1" aria-hidden="true">&#9492;</span>
                                        <span class="font-mono text-zinc-800 bg-zinc-200 dark:text-zinc-100 dark:bg-zinc-800 px-1.5 py-0.5 rounded text-xs">{{ row.key }}</span>
                                        <span v-if="row.required" class="w-2 h-2 rounded-full bg-red-500 shrink-0" role="img" :title="i18n.t('fields.required')" :aria-label="i18n.t('fields.required')"></span>
                                        <button v-if="row.hasExtras" @click="expanded[`${g.id}:${row.path}`] = !expanded[`${g.id}:${row.path}`]"
                                                class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-zinc-200 text-zinc-600 hover:text-zinc-900 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-100 transition-colors"
                                                :aria-expanded="!!expanded[`${g.id}:${row.path}`]" :aria-label="i18n.t('fields.detail')">
                                            {{ expanded[`${g.id}:${row.path}`] ? '−' : '+' }}
                                        </button>
                                    </span>
                                </td>
                                <td class="px-4 py-2 align-middle">
                                    <span v-if="row.type" class="inline-flex items-center px-2 py-0.5 rounded font-mono text-[10px] tracking-wider uppercase font-semibold" :class="fieldsTypePill(row.type)">{{ row.type }}</span>
                                    <span v-else class="text-zinc-400 dark:text-zinc-600">&mdash;</span>
                                </td>
                                <td class="px-4 py-2 align-middle text-zinc-700 dark:text-zinc-300">{{ row.label || '—' }}</td>
                                <!-- v-html is safe here for the same reason as FieldsDrawer's
                                     identical column: field descriptions are dev-authored YAML
                                     from the project's own Twig templates, never end-user input. -->
                                <td class="px-4 py-2 align-middle text-zinc-500 leading-relaxed" v-html="row.description || '—'"></td>
                            </tr>
                            <tr v-if="row.hasExtras && expanded[`${g.id}:${row.path}`]" class="bg-zinc-50 dark:bg-zinc-900/60">
                                <td colspan="4" class="px-4 py-2">
                                    <pre class="text-[11px] font-mono whitespace-pre-wrap text-zinc-600 dark:text-zinc-400" :style="`margin-left: ${row.depth * 1.5}rem`">{{ JSON.stringify(row.extras, null, 2) }}</pre>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </section>
        </div>
    </div>
</template>
