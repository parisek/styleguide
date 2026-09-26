<script setup>
// The bare mount (`/styleguide/`). Foundations, as always, unless
// styleguide.yaml sets `overview.default: grid`, which lands on the
// overview grid. Rendered in place, not redirected, so the address bar
// stays at the mount (see router.js).
import { landing } from '../lib/runtimeConfig.js';
import GridView from './GridView.vue';
import FoundationsView from './FoundationsView.vue';

const showGrid = landing() === 'grid';
</script>

<template>
    <GridView v-if="showGrid" />
    <FoundationsView v-else />
</template>
