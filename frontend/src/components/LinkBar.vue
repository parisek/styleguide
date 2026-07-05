<script setup>
import { computed, inject } from 'vue';
import { externalLinksFor } from '../lib/externalLinks.js';

// Ported from frontend/components/linkBar.js. Reads the current component /
// page's external-link fields (`asana`, `figma`, `drupal`, `web`) via the
// shared externalLinksFor() resolver (Task 2) and renders them as a list of
// badges. The SVG path data is owned by the template so markup stays
// inspectable — mirrors the legacy badge row: same four targets, same SVG
// sources (Asana official, Figma official, Drupal teardrop, generic link
// glyph for "web").
const viewport = inject('viewport');
const links = computed(() => externalLinksFor(viewport.currentItem.value));
</script>

<template>
    <!-- External-link bar — Asana / Figma / Drupal / Web icons fed from the
         current component's parsed metadata (`asana:`, `figma:`, `drupal:`,
         `web:` keys in the .twig YAML comment). Hidden when no links are
         declared. -->
    <div v-show="links.length" class="px-4 py-2 bg-zinc-100/60 border-b border-zinc-200 dark:bg-zinc-900/40 dark:border-zinc-800 flex items-center gap-2 flex-wrap text-xs">
        <a v-for="link in links" :key="link.key"
           :href="link.url" target="_blank" rel="noopener"
           :title="`${link.label} — ${link.url}`"
           class="inline-flex items-center gap-1.5 px-2 py-1 rounded bg-zinc-200/80 text-zinc-700 hover:bg-zinc-300 hover:text-zinc-900 dark:bg-zinc-800/60 dark:text-zinc-300 dark:hover:bg-zinc-700 dark:hover:text-white transition-colors">
            <svg v-if="link.key === 'asana'" aria-hidden="true" focusable="false" class="w-3.5 h-3.5 text-rose-500 dark:text-rose-400" viewBox="0 0 24 24" fill="currentColor"><path d="M18.78 12.653c-2.478 0-4.487 2.009-4.487 4.487 0 2.478 2.009 4.487 4.487 4.487 2.478 0 4.487-2.009 4.487-4.487 0-2.478-2.009-4.487-4.487-4.487zm-13.56 0c-2.478 0-4.487 2.009-4.487 4.487 0 2.478 2.009 4.487 4.487 4.487 2.478 0 4.487-2.009 4.487-4.487 0-2.478-2.009-4.487-4.487-4.487zm11.267-5.14c0 2.478-2.009 4.487-4.487 4.487-2.478 0-4.487-2.009-4.487-4.487 0-2.478 2.009-4.487 4.487-4.487 2.478 0 4.487 2.009 4.487 4.487"/></svg>
            <svg v-if="link.key === 'figma'" aria-hidden="true" focusable="false" class="w-3.5 h-3.5" viewBox="0 0 15 22" fill="none"><path d="M7.5 11a3.75 3.75 0 1 1 7.5 0 3.75 3.75 0 0 1-7.5 0z" fill="#1ABCFE"/><path d="M0 18.25a3.75 3.75 0 0 1 3.75-3.75H7.5V22a3.75 3.75 0 1 1-7.5 0v-3.75z" fill="#0ACF83"/><path d="M7.5 0v7.5h3.75a3.75 3.75 0 1 0 0-7.5H7.5z" fill="#FF7262"/><path d="M0 3.75A3.75 3.75 0 0 0 3.75 7.5H7.5V0H3.75A3.75 3.75 0 0 0 0 3.75z" fill="#F24E1E"/><path d="M0 11a3.75 3.75 0 0 0 3.75 3.75H7.5V7.5H3.75A3.75 3.75 0 0 0 0 11z" fill="#A259FF"/></svg>
            <svg v-if="link.key === 'drupal'" aria-hidden="true" focusable="false" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="#0678be"><path d="M12 0C10.736 3.256 8.928 4.976 6.624 7.28 3.456 10.448 2 13.12 2 16.064 2 20.448 6.464 24 12 24s10-3.552 10-7.936c0-2.944-1.456-5.616-4.624-8.784C15.072 4.976 13.264 3.256 12 0z"/></svg>
            <svg v-if="link.key === 'web'" aria-hidden="true" focusable="false" class="w-3.5 h-3.5 text-zinc-500 dark:text-zinc-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
            <span>{{ link.label }}</span>
        </a>
    </div>
</template>
