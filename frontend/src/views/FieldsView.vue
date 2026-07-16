<script setup>
import { computed, ref } from 'vue';
import { useCatalogStore } from '../stores/catalog.js';
import { useI18nStore } from '../stores/i18n.js';
import { flattenFieldsTree } from '../lib/fieldsTree.js';
import { normalizeForSearch } from '../lib/searchMatch.js';
import FieldsTable from '../components/FieldsTable.vue';

const catalog = useCatalogStore();
const i18n = useI18nStore();
const query = ref('');

const groups = computed(() => {
    const q = normalizeForSearch(query.value);
    return catalog.items
        .filter((c) => Array.isArray(c.fields) && c.fields.length > 0 && c.has_styleguide !== false)
        .map((c) => {
            const rows = flattenFieldsTree(c.fields);
            if (!q) return { id: c.id, name: c.name, rows };
            if (normalizeForSearch(c.name).includes(q) || normalizeForSearch(c.id).includes(q)) {
                return { id: c.id, name: c.name, rows };
            }
            return {
                id: c.id,
                name: c.name,
                rows: rows.filter((r) => [r.key, r.label, r.type].some((v) => normalizeForSearch(v).includes(q))),
            };
        })
        .filter((g) => g.rows.length > 0);
});
</script>

<template>
    <div class="h-full overflow-y-auto">
        <div class="max-w-5xl mx-auto px-6 py-8">
            <h1 class="text-lg font-bold text-zinc-900 dark:text-zinc-50">{{ i18n.t('fields.overviewTitle') }}</h1>
            <input v-model="query" type="search" :placeholder="i18n.t('fields.filterPlaceholder')"
                   class="mt-4 w-full max-w-md px-3.5 py-2 text-sm rounded-lg border border-zinc-300 bg-white text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                   data-testid="fields-filter">
            <p v-if="groups.length === 0" class="mt-8 text-sm text-zinc-500">{{ i18n.t('fields.empty') }}</p>
            <section v-for="g in groups" :key="g.id" class="mt-8">
                <router-link :to="{ name: 'component', params: { slug: g.id }, query: { fields: '1' } }"
                             class="text-sm font-bold text-zinc-900 hover:text-red-700 dark:text-zinc-50 dark:hover:text-red-400 transition-colors">
                    {{ g.name }}
                </router-link>
                <div class="mt-2">
                    <FieldsTable :rows="g.rows" :key-prefix="g.id + ':'" />
                </div>
            </section>
        </div>
    </div>
</template>
