<script setup>
import { ref, computed } from 'vue';
import { useRoute } from 'vue-router';
import { useI18nStore } from '../stores/i18n.js';
import { flattenFieldsTree, fieldsTypePill } from '../lib/fieldsTree.js';

const props = defineProps({
    fields: { type: Array, default: null },
});

const i18n = useI18nStore();
const route = useRoute();

// ?fields=1 deep link (FieldsView click-through) opens the drawer on load;
// otherwise default collapsed, matching legacy `x-data="{ open: false }"`.
const open = ref('fields' in route.query);
const expanded = ref({}); // row.path → boolean (detail visibility)

const tree = computed(() => flattenFieldsTree(props.fields));
</script>

<template>
    <!-- Collapsible per-component fields table. Shows only when the current
         route's component / page actually declares a `fields:` block in its
         YAML metadata (gated by the caller via v-if on fieldsCount). Default
         collapsed unless the route deep-links with ?fields=1; tree depth
         comes from flattenFieldsTree() so the template stays linear. -->
    <div class="border-b border-zinc-200 bg-zinc-100/60 dark:border-zinc-800 dark:bg-zinc-900/40">
        <button @click="open = !open"
                class="w-full flex items-center gap-2 px-4 py-2 text-xs text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100 transition-colors">
            <svg aria-hidden="true" focusable="false" class="w-3 h-3 transition-transform" :class="open && 'rotate-90'" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            <span class="uppercase tracking-wider font-semibold">{{ i18n.t('nav.fields') }}</span>
            <span class="font-mono text-zinc-500">{{ tree.length }}</span>
        </button>
        <div v-show="open" class="bg-white/60 dark:bg-zinc-950/40">
            <div class="max-h-80 overflow-y-auto">
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
                        <template v-for="row in tree" :key="row.path">
                            <tr class="hover:bg-zinc-100 dark:hover:bg-zinc-800/40 transition-colors">
                                <td class="px-4 py-2 align-middle">
                                    <!-- Tailwind utility classes can't take a runtime depth value;
                                         inline padding-left is the cleanest path. -->
                                    <span class="inline-flex items-center gap-2" :style="`padding-left: ${row.depth * 1.5}rem`">
                                        <span v-if="row.depth > 0" class="text-zinc-400 dark:text-zinc-600 font-mono text-base leading-none -mt-1" aria-hidden="true">&#9492;</span>
                                        <span class="font-mono text-zinc-800 bg-zinc-200 dark:text-zinc-100 dark:bg-zinc-800 px-1.5 py-0.5 rounded text-xs">{{ row.key }}</span>
                                        <span v-if="row.required" class="w-2 h-2 rounded-full bg-red-500 shrink-0" role="img" :title="i18n.t('fields.required')" :aria-label="i18n.t('fields.required')"></span>
                                        <button v-if="row.hasExtras" @click="expanded[row.path] = !expanded[row.path]"
                                                class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-zinc-200 text-zinc-600 hover:text-zinc-900 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-100 transition-colors"
                                                :aria-expanded="!!expanded[row.path]" :aria-label="i18n.t('fields.detail')">
                                            {{ expanded[row.path] ? '−' : '+' }}
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
                            <tr v-if="row.hasExtras && expanded[row.path]" class="bg-zinc-50 dark:bg-zinc-900/60">
                                <td colspan="4" class="px-4 py-2">
                                    <pre class="text-[11px] font-mono whitespace-pre-wrap text-zinc-600 dark:text-zinc-400" :style="`margin-left: ${row.depth * 1.5}rem`">{{ JSON.stringify(row.extras, null, 2) }}</pre>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <div class="px-4 py-2 border-t border-zinc-200 bg-zinc-100/80 dark:border-zinc-800 dark:bg-zinc-900/60 text-[10px] text-zinc-500 flex items-center gap-2">
                    <!-- Decorative: the localised legend text right next
                         to it already conveys meaning to screen readers. -->
                    <span class="w-2 h-2 rounded-full bg-red-500 inline-block" aria-hidden="true"></span>
                    <span>{{ i18n.t('fields.requiredLegend') }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
