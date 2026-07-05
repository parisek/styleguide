<script setup>
import { computed, provide } from 'vue';
import { useRoute } from 'vue-router';
import { useUiStore } from './stores/ui.js';
import { routeInfo } from './lib/routeInfo.js';
import { useViewportPreset } from './composables/useViewportPreset.js';
import Sidebar from './components/Sidebar.vue';
import ViewportToolbar from './components/ViewportToolbar.vue';

const ui = useUiStore();
const route = useRoute();

const routeType = computed(() => routeInfo(route).type);
const routeSlug = computed(() => routeInfo(route).slug);
// Provided one level above <RouterView/>, not inside PreviewView.vue — the
// legacy DOM's toolbar/description/usage/link/fields chrome are siblings of
// the route-specific body inside the SAME `x-data="preview"` scope, not
// nested inside it, so they render identically on every route (including
// /overview and /foundations). See Task 7 brief Step 9 for the full
// rationale. PreviewPane.vue/FieldsDrawer.vue/UsagePanel.vue/LinkBar.vue
// (Tasks 8-10) inject this same instance through <RouterView/>.
const viewport = useViewportPreset({ type: routeType, slug: routeSlug });
provide('viewport', viewport);
</script>

<template>
    <div class="flex h-screen overflow-hidden">
        <div
            v-show="ui.sidebarOpen"
            class="fixed inset-0 z-40 bg-black/40 lg:hidden"
            @click="ui.toggleSidebar()"
        ></div>

        <Sidebar />

        <main class="flex-1 flex flex-col min-w-0 bg-white dark:bg-zinc-950" :class="viewport.isDragging.value && 'cursor-ew-resize select-none'">
            <ViewportToolbar />
            <!-- Description bar — surfaces the component / page `description`
                 from its YAML metadata between the toolbar and the usage
                 panel. Renders via v-html so authors can embed real anchor
                 tags — content originates in dev-authored .twig YAML
                 headers, not user input, matching the rest of the
                 styleguide chrome's trust model. -->
            <div v-if="viewport.currentItemDescription.value && routeSlug" class="sg-description-bar px-4 py-2 bg-zinc-100/60 border-b border-zinc-200 dark:bg-zinc-900/40 dark:border-zinc-800 text-xs text-zinc-600 dark:text-zinc-400 leading-relaxed">
                <span v-html="viewport.currentItemDescription.value"></span>
            </div>
            <!-- Task 10 replaces these two stubs with <UsagePanel /> and <LinkBar /> -->
            <div data-testid="usage-panel-stub"></div>
            <div data-testid="link-bar-stub"></div>
            <!-- Task 9 replaces this stub with <FieldsDrawer :fields="viewport.currentItem.value?.fields" v-if="viewport.fieldsCount.value > 0 && routeSlug" /> -->
            <div data-testid="fields-drawer-stub"></div>
            <RouterView />
        </main>
    </div>
</template>
