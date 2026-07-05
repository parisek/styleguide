<script setup>
import { useUiStore } from './stores/ui.js';

const ui = useUiStore();
</script>

<template>
    <div class="flex h-screen overflow-hidden">
        <div
            v-show="ui.sidebarOpen"
            class="fixed inset-0 z-40 bg-black/40 lg:hidden"
            @click="ui.toggleSidebar()"
        ></div>

        <!-- Task 5 replaces this with <Sidebar /> -->
        <aside class="w-72 bg-zinc-50 border-r border-zinc-200 dark:bg-zinc-900 dark:border-zinc-800 flex flex-col fixed inset-y-0 left-0 z-50 transition-transform duration-200 lg:static lg:z-auto lg:transition-none"
               :class="ui.sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:hidden'">
        </aside>

        <!-- Task 7-11 replace this with the toolbar/description/usage/links/fields chrome around <RouterView /> -->
        <main class="flex-1 flex flex-col min-w-0 bg-white dark:bg-zinc-950">
            <RouterView />
        </main>
    </div>
</template>
