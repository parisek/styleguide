<script setup>
import { computed, ref } from 'vue';
import { useCatalogStore } from '../stores/catalog.js';
import { useI18nStore } from '../stores/i18n.js';

// Unobtrusive operator signal for GET /styleguide/api/health: a template
// that throws while parsing no longer 500s the whole catalogue (see
// ComponentParser::parseAll()) — it's skipped and recorded instead. Most
// projects never populate `catalog.warnings`, so this renders nothing by
// default; when it does have entries, a small badge appears near the
// sidebar header rather than a whole new UI surface — this is diagnostics
// for whoever maintains the templates, not end-user chrome.
//
// Clicking opens a native <dialog> listing the warnings (#89) — the
// original console.warn-only behavior read as a dead button (a real owner
// clicked it repeatedly and filed it as a bug). The native element gives
// Esc handling, focus containment and a ::backdrop for free, consistent
// with the consumer-side native-first floating-UI doctrine. console.warn
// stays as a debugging side channel.
const catalog = useCatalogStore();
const i18n = useI18nStore();

const count = computed(() => catalog.warnings.length);
const visible = computed(() => count.value > 0);

const dialogEl = ref(null);

// Keeps the <dialog> mounted while it's showing even if the warnings list
// refreshes to empty underneath it (catalog.init() re-runs on the toolbar
// reload button) — a v-if tied to the live count alone would unmount an
// OPEN modal, skipping close() and stranding ::backdrop/focus state.
const isOpen = ref(false);

const title = computed(() => i18n.t('health.warnings_title'));

function open() {
    console.warn('[styleguide] parser warnings', catalog.warnings);
    isOpen.value = true;
    dialogEl.value?.showModal();
}

function close() {
    dialogEl.value?.close();
}

// Single source of truth for "no longer showing": fires for Esc, the close
// button and backdrop clicks alike (all funnel through dialog.close()).
function onDialogClose() {
    isOpen.value = false;
}

// Native <dialog> closes on Esc by itself; backdrop clicks need this one
// handler — a click whose target is the <dialog> element itself landed on
// the ::backdrop area (all real content is inside the inner wrappers).
function onDialogClick(e) {
    if (e.target === dialogEl.value) close();
}
</script>

<template>
    <button
        v-if="visible"
        type="button"
        @click="open"
        :title="title"
        :aria-label="title"
        class="shrink-0 inline-flex items-center gap-1 h-9 px-2 rounded-full border border-amber-300 bg-amber-50 text-amber-700 hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-400 dark:hover:bg-amber-900/50 transition-colors"
    >
        <svg aria-hidden="true" focusable="false" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 2 22 20H2z"/>
            <path d="M12 9v5M12 17.5v.01"/>
        </svg>
        <span class="text-xs font-semibold">{{ count }}</span>
    </button>

    <dialog
        v-if="visible || isOpen"
        ref="dialogEl"
        @click="onDialogClick"
        @close="onDialogClose"
        aria-labelledby="health-warning-dialog-title"
        class="m-auto w-full max-w-xl rounded-xl border border-amber-300 bg-white p-0 text-zinc-900 shadow-2xl backdrop:bg-black/40 dark:border-amber-700 dark:bg-zinc-900 dark:text-zinc-100"
    >
        <div class="flex items-center gap-2 border-b border-zinc-100 px-5 py-4 dark:border-zinc-800">
            <svg aria-hidden="true" focusable="false" class="size-5 shrink-0 text-amber-600 dark:text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 2 22 20H2z"/>
                <path d="M12 9v5M12 17.5v.01"/>
            </svg>
            <h2 id="health-warning-dialog-title" class="flex-1 text-sm font-semibold">{{ i18n.t('health.dialog_title') }}</h2>
            <button
                type="button"
                @click="close"
                :aria-label="i18n.t('health.dialog_close')"
                class="shrink-0 rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white transition-colors"
            >
                <svg aria-hidden="true" focusable="false" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <path d="M6 6l12 12M18 6 6 18"/>
                </svg>
            </button>
        </div>
        <ul class="max-h-96 overflow-y-auto divide-y divide-zinc-100 dark:divide-zinc-800">
            <li v-for="(warning, i) in catalog.warnings" :key="i" class="px-5 py-3">
                <p class="font-mono text-xs font-semibold text-amber-700 dark:text-amber-400 break-all">{{ warning.file }}</p>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ warning.error }}</p>
            </li>
        </ul>
    </dialog>
</template>
