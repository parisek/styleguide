<script setup>
import { ref } from 'vue';
import { useI18nStore } from '../stores/i18n.js';
import { fieldsTypePill } from '../lib/fieldsTree.js';

// Shared with FieldsDrawer.vue (per-component collapsible drawer) and
// FieldsView.vue (cross-component /fields overview, #95) — extracted after
// the two call sites started out as a deliberate ~20-line template
// duplication (Task 5 brief) and a reviewer asked for the extraction once
// the duplication actually landed twice. `keyPrefix` disambiguates the
// `expanded` map's keys when multiple FieldsTable instances render on the
// same page (FieldsView renders one per component, all sharing a
// FieldsDrawer-derived `row.path`, which would otherwise collide across
// components with same-shaped fields).
const props = defineProps({
    rows: { type: Array, required: true },
    keyPrefix: { type: String, default: '' },
});

const i18n = useI18nStore();
const expanded = ref({}); // `${keyPrefix}${row.path}` → boolean (detail visibility)
</script>

<template>
    <table class="w-full text-xs">
        <thead class="sticky top-0 bg-zinc-100 dark:bg-zinc-900 text-left text-[10px] uppercase tracking-wider text-zinc-500">
            <tr class="border-b border-zinc-200 dark:border-zinc-800">
                <th class="px-4 py-2 font-medium w-56">Field</th>
                <th class="px-4 py-2 font-medium w-28">Type</th>
                <th class="px-4 py-2 font-medium w-40">Label</th>
                <th class="px-4 py-2 font-medium">Description</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800/60">
            <template v-for="row in props.rows" :key="`${keyPrefix}${row.path}`">
                <tr class="hover:bg-zinc-100 dark:hover:bg-zinc-800/40 transition-colors">
                    <td class="px-4 py-2 align-middle">
                        <!-- Tailwind utility classes can't take a runtime depth value;
                             inline padding-left is the cleanest path. -->
                        <span class="inline-flex items-center gap-2" :style="`padding-left: ${row.depth * 1.5}rem`">
                            <span v-if="row.depth > 0" class="text-zinc-400 dark:text-zinc-600 font-mono text-base leading-none -mt-1" aria-hidden="true">&#9492;</span>
                            <span class="font-mono text-zinc-800 bg-zinc-200 dark:text-zinc-100 dark:bg-zinc-800 px-1.5 py-0.5 rounded text-xs">{{ row.key }}</span>
                            <span v-if="row.required" class="w-2 h-2 rounded-full bg-red-500 shrink-0" role="img" :title="i18n.t('fields.required')" :aria-label="i18n.t('fields.required')"></span>
                            <button v-if="row.hasExtras" @click="expanded[`${keyPrefix}${row.path}`] = !expanded[`${keyPrefix}${row.path}`]"
                                    class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-zinc-200 text-zinc-600 hover:text-zinc-900 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-100 transition-colors"
                                    :aria-expanded="!!expanded[`${keyPrefix}${row.path}`]" :aria-label="i18n.t('fields.detail')">
                                {{ expanded[`${keyPrefix}${row.path}`] ? '−' : '+' }}
                            </button>
                        </span>
                    </td>
                    <td class="px-4 py-2 align-middle">
                        <span v-if="row.type" class="inline-flex items-center px-2 py-0.5 rounded font-mono text-[10px] tracking-wider uppercase font-semibold" :class="fieldsTypePill(row.type)">{{ row.type }}</span>
                        <span v-else class="text-zinc-400 dark:text-zinc-600">&mdash;</span>
                    </td>
                    <td class="px-4 py-2 align-middle text-zinc-700 dark:text-zinc-300">{{ row.label || '—' }}</td>
                    <!-- v-html is safe here for the same reason as the description bar in
                         App.vue: field descriptions are dev-authored YAML from the project's
                         own Twig templates (already executed as Twig by this request), never
                         end-user input. -->
                    <td class="px-4 py-2 align-middle text-zinc-500 leading-relaxed" v-html="row.description || '—'"></td>
                </tr>
                <tr v-if="row.hasExtras && expanded[`${keyPrefix}${row.path}`]" class="bg-zinc-50 dark:bg-zinc-900/60">
                    <td colspan="4" class="px-4 py-2">
                        <pre class="text-[11px] font-mono whitespace-pre-wrap text-zinc-600 dark:text-zinc-400" :style="`margin-left: ${row.depth * 1.5}rem`">{{ JSON.stringify(row.extras, null, 2) }}</pre>
                    </td>
                </tr>
            </template>
        </tbody>
    </table>
</template>
