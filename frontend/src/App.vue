<script setup>
import { useUiStore } from './stores/ui.js';
import Sidebar from './components/Sidebar.vue';

const ui = useUiStore();
</script>

<template>
    <div class="flex h-screen overflow-hidden">
        <div
            v-show="ui.sidebarOpen"
            class="fixed inset-0 z-40 bg-black/40 lg:hidden"
            @click="ui.toggleSidebar()"
        ></div>

        <Sidebar />

        <!-- Task 7-11 replace this with the toolbar/description/usage/links/fields chrome around <RouterView /> -->
        <main class="flex-1 flex flex-col min-w-0 bg-white dark:bg-zinc-950">
            <RouterView />
        </main>
    </div>
</template>
