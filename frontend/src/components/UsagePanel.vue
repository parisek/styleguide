<script setup>
import { computed, inject } from 'vue';
import { useRouter } from 'vue-router';
import { useCatalogStore } from '../stores/catalog.js';
import { useI18nStore } from '../stores/i18n.js';

// Ported from frontend/components/usage.js. Reads the current route's
// `usage` CSV (`page-header-image,gallery-slider,...`) and resolves each
// token against the pages/items stores so the panel can render real names +
// clickable navigation chips.
//
// Semantic difference by route kind:
//   - on a component view → `usage` lists OTHER components/pages that USE this one
//   - on a page view      → `usage` lists components THE PAGE USES
//
// The token resolver tries pages first, then components, mirroring the
// legacy `processUsage()` helper — pages and components share an id
// namespace so an unambiguous lookup is fine.
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
        // Unknown reference — still show as a chip but greyed out + non-clickable.
        return { id, type: null, name: id };
    });
});

function select(item) {
    if (!item.type) return;
    router.push(`/${item.type}/${item.id}`);
}
</script>

<template>
    <!-- Usage cross-reference panel — what this component is used in, or
         which components a page uses. Gated with v-if rather than the
         legacy x-show so the empty state renders zero DOM nodes (matches
         the "renders nothing" test); the end visual state (fully hidden)
         is identical either way. -->
    <div v-if="visible && items.length" class="px-4 py-2 bg-zinc-100/80 border-b border-zinc-200 dark:bg-zinc-900/60 dark:border-zinc-800 flex items-center gap-2 flex-wrap text-xs">
        <span class="uppercase tracking-wider font-semibold text-zinc-500 shrink-0">{{ label }}</span>
        <button v-for="item in items" :key="`${item.type}:${item.id}`"
                @click="select(item)"
                :disabled="!item.type"
                :title="item.type ? `${item.type}/${item.id}` : item.id"
                class="px-2 py-0.5 rounded-md font-mono transition-colors"
                :class="item.type
                    ? (item.type === 'page'
                        ? 'bg-red-600/10 text-red-700 hover:bg-red-600/20 dark:bg-red-400/15 dark:text-red-400 dark:hover:bg-red-400/25'
                        : 'bg-zinc-200 text-zinc-700 hover:bg-zinc-300 hover:text-zinc-900 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700 dark:hover:text-white')
                    : 'bg-zinc-200/60 text-zinc-400 dark:bg-zinc-800/40 dark:text-zinc-600 cursor-not-allowed line-through'">
            {{ item.name }}
        </button>
    </div>
</template>
