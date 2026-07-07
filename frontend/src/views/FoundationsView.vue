<script setup>
import { inject } from 'vue';
import { useUiStore } from '../stores/ui.js';

// Ported from frontend/index.html:692-713 — the full-bleed iframe used only
// by the `foundations` (and `landing`, which resolves to this same view per
// the router table) route. Deliberately simpler than PreviewPane.vue (Task
// 8): no auto-fit height, no chassis, no drag handles, no zoom — the legacy
// block's own comment: "Foundations: full-bleed iframe, no frame / shadow /
// dark padding... theme-agnostic from our perspective."
const ui = useUiStore();
const viewport = inject('viewport');

function onLoad() {
    ui.isPreviewLoading = false;
}
</script>

<template>
    <!-- Wrapper stays bg-white in both themes — it sits BEHIND the iframe
         and shows through any transparent parts of the consumer's
         foundations content, which is theme-agnostic from our perspective.
         Painting it dark in dark mode would put the consumer's
         light-rendered text on a dark backdrop. -->
    <div class="flex-1 relative bg-white overflow-hidden">
        <iframe :src="viewport.iframeSrc.value" class="w-full h-full border-0" @load="onLoad"></iframe>
        <div v-show="ui.isPreviewLoading" data-testid="foundations-loading" class="absolute inset-0 flex items-center justify-center bg-white pointer-events-none">
            <div class="w-2 h-2 rounded-full bg-zinc-300 animate-pulse"></div>
        </div>
    </div>
</template>
