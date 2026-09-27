<script setup>
// The fixture source behind one preview: `styleguide.<variant>.twig`, or
// `styleguide.twig` without a variant, as the server returns it from
// /api/source (the leading metadata comment already removed). Mounted only
// when the #sg-config payload carries `showSource` -- see VariantGrid.vue's
// tile toggle and App.vue's drawer for the single preview.
import { ref, watch, onBeforeUnmount } from 'vue';
import { useI18nStore } from '../stores/i18n.js';
import { url } from '../lib/runtimeConfig.js';

const props = defineProps({
    type: { type: String, required: true },
    slug: { type: String, required: true },
    variant: { type: String, default: null },
});

const i18n = useI18nStore();

const state = ref('loading');
const file = ref('');
const source = ref('');
const copied = ref(false);
let copiedTimer = null;
// A later request wins: switching tiles quickly must not let a slow earlier
// answer overwrite the newer one.
let requestId = 0;

async function load() {
    const id = ++requestId;
    state.value = 'loading';
    copied.value = false;
    const query = props.variant ? `?variant=${encodeURIComponent(props.variant)}` : '';
    try {
        const response = await fetch(url(`api/source/${props.type}/${props.slug}`) + query);
        const body = response.ok ? await response.json() : null;
        if (id !== requestId) return;
        if (!body || typeof body.source !== 'string') {
            state.value = 'error';
            return;
        }
        file.value = typeof body.file === 'string' ? body.file : '';
        source.value = body.source;
        state.value = 'ready';
    } catch {
        if (id === requestId) state.value = 'error';
    }
}

watch(() => [props.type, props.slug, props.variant], load, { immediate: true });

async function copy() {
    try {
        await navigator.clipboard.writeText(source.value);
    } catch {
        // No clipboard (plain http, a denied permission): the code stays
        // selectable in the <pre>, so the button just does not confirm.
        return;
    }
    copied.value = true;
    clearTimeout(copiedTimer);
    copiedTimer = setTimeout(() => { copied.value = false; }, 1500);
}

onBeforeUnmount(() => clearTimeout(copiedTimer));
</script>

<template>
    <div data-testid="source-panel" class="flex flex-col min-h-0 bg-zinc-900 text-zinc-100 text-xs">
        <p v-if="state === 'loading'" class="px-3 py-2 text-zinc-400">{{ i18n.t('source.loading') }}</p>
        <p v-else-if="state === 'error'" class="px-3 py-2 text-zinc-400">{{ i18n.t('source.unavailable') }}</p>
        <template v-else>
            <div class="flex items-center gap-2 px-3 py-1.5 border-b border-zinc-700 shrink-0">
                <span data-testid="source-file" class="font-mono text-zinc-400 truncate min-w-0 flex-1">{{ file }}</span>
                <button type="button"
                        data-testid="source-copy"
                        @click.stop="copy()"
                        class="shrink-0 px-2 h-6 rounded bg-zinc-700 hover:bg-zinc-600 text-zinc-100 font-medium transition-colors">{{ copied ? i18n.t('source.copied') : i18n.t('source.copy') }}</button>
            </div>
            <!-- `{{ }}` only: the source is text to read and copy, never
                 markup to render. -->
            <pre class="flex-1 min-h-0 overflow-auto px-3 py-2 font-mono leading-relaxed whitespace-pre"><code data-testid="source-code">{{ source }}</code></pre>
        </template>
    </div>
</template>
