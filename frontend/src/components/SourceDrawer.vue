<script setup>
// The single preview's counterpart of the variant tile's "Code" toggle: a
// collapsed drawer under the toolbar, in the same shape as FieldsDrawer.
// App.vue mounts it only when the server allows the source and the preview
// renders a fixture file (a deep-linked tile, or an entry with a bare
// styleguide.twig).
import { ref, watch } from 'vue';
import { useI18nStore } from '../stores/i18n.js';
import SourcePanel from './SourcePanel.vue';

const props = defineProps({
    type: { type: String, required: true },
    slug: { type: String, required: true },
    variant: { type: String, default: null },
});

const i18n = useI18nStore();
// Closed on every new preview: the drawer is a lookup, not a mode.
const open = ref(false);
watch(() => [props.type, props.slug, props.variant], () => { open.value = false; });
</script>

<template>
    <div data-testid="source-drawer" class="border-b border-zinc-200 bg-zinc-100/60 dark:border-zinc-800 dark:bg-zinc-900/40">
        <button type="button"
                data-testid="source-drawer-toggle"
                @click="open = !open"
                :aria-expanded="open ? 'true' : 'false'"
                class="w-full flex items-center gap-2 px-4 py-2 text-xs text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100 transition-colors">
            <svg aria-hidden="true" focusable="false" class="w-3 h-3 transition-transform" :class="open && 'rotate-90'" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            <span class="uppercase tracking-wider font-semibold">{{ i18n.t('source.toggle') }}</span>
        </button>
        <SourcePanel v-if="open" class="max-h-80" :type="type" :slug="slug" :variant="variant" />
    </div>
</template>
