<script setup>
import { ref, computed } from 'vue';
import { useRoute } from 'vue-router';
import { useI18nStore } from '../stores/i18n.js';
import { flattenFieldsTree } from '../lib/fieldsTree.js';
import FieldsTable from './FieldsTable.vue';

const props = defineProps({
    fields: { type: Array, default: null },
});

const i18n = useI18nStore();
const route = useRoute();

// ?fields=1 deep link (FieldsView click-through) opens the drawer on load;
// otherwise default collapsed, matching legacy `x-data="{ open: false }"`.
const open = ref('fields' in route.query);

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
                <FieldsTable :rows="tree" />
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
