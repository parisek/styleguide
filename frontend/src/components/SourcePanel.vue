<script setup>
// The fixture source behind one preview: `styleguide.<variant>.twig`, or
// `styleguide.twig` without a variant, as the server returns it from
// /api/source (the leading metadata comment already removed), and on its
// second tab the HTML that fixture renders (/api/markup). Mounted only
// when the #sg-config payload carries `showSource` -- see VariantGrid.vue's
// tile toggle and App.vue's drawer for the single preview.
import { computed, ref, shallowRef, watch } from 'vue';
import { useI18nStore } from '../stores/i18n.js';
import { url, highlightSource, sourceUrl, sourceViews } from '../lib/runtimeConfig.js';
import { toLines, fileUrl, repoHostLabel } from '../lib/codeLines.js';

const props = defineProps({
    type: { type: String, required: true },
    slug: { type: String, required: true },
    variant: { type: String, default: null },
    // Horizontal padding of the header row, so its controls line up with
    // whatever opened the panel: the tile's Code button, or the drawer's
    // own label.
    gutter: { type: String, default: 'pl-3 pr-1.5' },
});

const i18n = useI18nStore();

// The views of one tile, in the panel's order: 'data' (the fixture: the
// call with sample data), 'twig' (the entry's own template), 'html' (what
// the fixture renders), 'css' / 'js' (the entry's own stylesheets and
// scripts). `source_views` decides which exist; a file view without files
// is not offered either.
const enabled = sourceViews();
const has = (view) => enabled.includes(view);
// The first listed view opens; while its files are still on the way, the
// first view that exists stands in.
const chosen = ref(enabled[0]);
const state = ref(has('data') ? 'loading' : 'ready');
const file = ref('');
const source = ref('');
const markupState = ref('idle');
const markup = ref('');
const files = ref([]);

// The highlighter is its own chunk: the browser loads it with the first
// Code panel, and never when `highlight_source: false`. Until it arrives,
// and whenever it cannot, the code shows as plain text.
const highlighter = shallowRef(null);
if (highlightSource()) {
    import('../lib/highlightTwig.js')
        .then((module) => { highlighter.value = module; })
        .catch(() => {});
}

const templatePath = computed(() => `${props.type}/${props.slug}/${props.slug}.twig`);
const twigFiles = computed(() => files.value.filter((f) => f.language === 'twig'));
const cssFiles = computed(() => files.value.filter((f) => f.language === 'css'));
const jsFiles = computed(() => files.value.filter((f) => f.language === 'js'));
const views = computed(() => [
    ...(has('data') ? [{ id: 'data', label: 'Data' }] : []),
    ...(has('twig') && twigFiles.value.length ? [{ id: 'twig', label: 'Twig' }] : []),
    ...(has('html') ? [{ id: 'html', label: 'HTML' }] : []),
    ...(has('css') && cssFiles.value.length ? [{ id: 'css', label: 'CSS' }] : []),
    ...(has('js') && jsFiles.value.length ? [{ id: 'js', label: 'JS' }] : []),
]);
// The view on screen: the one chosen while it exists, else the first one.
const tab = computed(() => (views.value.some((v) => v.id === chosen.value) ? chosen.value : views.value[0]?.id ?? null));

function highlight(text, language) {
    if (!highlighter.value) return [{ text, class: '' }];
    return highlighter.value.highlightCode(text, language);
}

// What the body shows: one block for Data, Twig and HTML, one block per
// file for CSS and JS. A block of a file carries its path and link.
const blocks = computed(() => {
    if (tab.value === 'twig') {
        return twigFiles.value.map((f) => ({ key: f.path, path: null, link: null, lines: toLines(highlight(f.source, 'twig')) }));
    }
    if (tab.value === 'css' || tab.value === 'js') {
        return (tab.value === 'css' ? cssFiles.value : jsFiles.value).map((f) => ({
            key: f.path,
            path: f.path,
            link: fileUrl(sourceUrl(), f.path),
            lines: toLines(highlight(f.source, f.language === 'css' ? 'css' : 'javascript')),
        }));
    }
    if (tab.value === 'html') {
        return [{ key: 'html', path: null, link: null, lines: toLines(highlight(markup.value, 'markup')) }];
    }
    if (tab.value === 'data') {
        return [{ key: 'data', path: null, link: null, lines: toLines(highlight(source.value, 'twig')) }];
    }
    return [];
});
const bodyState = computed(() => (tab.value === 'html' ? markupState.value : 'ready'));

// The header's link into the repository (`source_url`), to the file behind
// the view: the fixture for Data, the entry's own template for Twig and
// HTML. The CSS and JS views link each file in its own heading instead.
const repoPath = computed(() => {
    if (tab.value === 'html' || tab.value === 'twig') return templatePath.value;
    if (tab.value === 'data') return file.value;
    return null;
});
const repoLink = computed(() => (repoPath.value ? fileUrl(sourceUrl(), repoPath.value) : null));
const repoLabel = computed(() => repoHostLabel(sourceUrl()));
// A later request wins: switching tiles quickly must not let a slow earlier
// answer overwrite the newer one.
let requestId = 0;

function query() {
    return props.variant ? `?variant=${encodeURIComponent(props.variant)}` : '';
}

async function load() {
    const id = ++requestId;
    markupState.value = 'idle';
    markup.value = '';
    files.value = [];
    if (['twig', 'css', 'js'].some(has)) loadFiles(id);
    if (tab.value === 'html') loadMarkup();
    if (!has('data')) return;
    state.value = 'loading';
    try {
        const response = await fetch(url(`api/source/${props.type}/${props.slug}`) + query());
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

// The entry's own files decide whether their views exist at all, so they
// come with the panel. A failure only hides those views.
async function loadFiles(id) {
    try {
        const response = await fetch(url(`api/files/${props.type}/${props.slug}`));
        const body = response.ok ? await response.json() : null;
        if (id !== requestId || !body || !Array.isArray(body.files)) return;
        files.value = body.files.filter((f) => f && typeof f.path === 'string' && typeof f.source === 'string');
    } catch {
        // No views to add.
    }
}

// The markup is a render, so it is fetched only when its tab is opened.
async function loadMarkup() {
    if (markupState.value !== 'idle') return;
    const id = requestId;
    markupState.value = 'loading';
    try {
        const response = await fetch(url(`api/markup/${props.type}/${props.slug}`) + query());
        const body = response.ok ? await response.json() : null;
        if (id !== requestId) return;
        if (!body || typeof body.html !== 'string') {
            markupState.value = 'error';
            return;
        }
        markup.value = body.html;
        markupState.value = 'ready';
    } catch {
        if (id === requestId) markupState.value = 'error';
    }
}

function selectTab(name) {
    chosen.value = name;
}

// The markup is fetched when its view comes on screen, whether chosen or
// standing in.
watch(tab, (view) => { if (view === 'html') loadMarkup(); });

watch(() => [props.type, props.slug, props.variant], load, { immediate: true });

</script>

<template>
    <div data-testid="source-panel" class="flex flex-col min-h-0 bg-zinc-900 text-zinc-100 text-xs">
        <p v-if="state === 'loading'" class="px-3 py-2 text-zinc-400">{{ i18n.t('source.loading') }}</p>
        <p v-else-if="state === 'error'" class="px-3 py-2 text-zinc-400">{{ i18n.t('source.unavailable') }}</p>
        <template v-else>
            <div class="flex items-center justify-between gap-3 py-1.5 border-b border-zinc-700 shrink-0" :class="gutter">
                <!-- Views of one tile: toggle buttons rather than an ARIA tab
                     set, since each shows the same kind of content in the
                     same place. -->
                <div class="flex shrink-0 rounded bg-zinc-800 p-0.5" role="group" :aria-label="i18n.t('source.view')">
                    <button v-for="view in views" :key="view.id" type="button"
                            :data-testid="`source-tab-${view.id}`" :aria-pressed="tab === view.id ? 'true' : 'false'"
                            @click.stop="selectTab(view.id)"
                            class="px-2 h-5 rounded font-medium transition-colors"
                            :class="tab === view.id ? 'bg-zinc-600 text-zinc-50' : 'text-zinc-400 hover:text-zinc-100'">{{ view.label }}</button>
                </div>
                <!-- Sized like the tile's Code button above it (h-6, px-2),
                     so the two line up on the right. -->
                <a v-if="repoLink" data-testid="source-repo-link" :href="repoLink" target="_blank" rel="noopener"
                   :title="repoPath"
                   class="inline-flex items-center gap-1 shrink-0 px-2 h-6 rounded font-medium text-zinc-300 hover:text-zinc-50 hover:bg-zinc-800 transition-colors" @click.stop>
                    {{ repoLabel }}
                    <svg aria-hidden="true" focusable="false" class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7M8 7h9v9"/></svg>
                    <span class="sr-only">({{ i18n.t('source.opens_new_tab') }}: {{ repoPath }})</span>
                </a>
            </div>
            <p v-if="bodyState === 'loading'" class="px-3 py-2 text-zinc-400">{{ i18n.t('source.loading') }}</p>
            <p v-else-if="bodyState === 'error'" class="px-3 py-2 text-zinc-400">{{ i18n.t('source.no_markup') }}</p>
            <!-- `{{ }}` only: the code is text to read and copy, never
                 markup to render. Highlighting splits it into text spans,
                 one row per line; the line numbers are CSS counters, so a
                 selection copies the code without them. Long lines wrap
                 under their own number. No v-html. -->
            <div v-else data-testid="source-code" class="flex-1 min-h-0 overflow-auto">
                <section v-for="block in blocks" :key="block.key" class="border-b border-zinc-800 last:border-b-0">
                    <p v-if="block.path" data-testid="source-file-heading" class="pt-2 font-mono text-zinc-500" :class="gutter">
                        <a v-if="block.link" :href="block.link" target="_blank" rel="noopener" class="hover:text-zinc-100 underline decoration-zinc-700 underline-offset-2" @click.stop>{{ block.path }}<span class="sr-only"> ({{ i18n.t('source.opens_new_tab') }})</span></a>
                        <template v-else>{{ block.path }}</template>
                    </p>
                    <pre class="sg-code py-2 pr-3 font-mono leading-relaxed whitespace-pre-wrap break-words"><code><span v-for="(line, lineIndex) in block.lines" :key="lineIndex" class="sg-code-line"><span v-for="(segment, index) in line" :key="index" :class="segment.class || undefined">{{ segment.text }}</span>{{ lineIndex < block.lines.length - 1 ? '\n' : '' }}</span></code></pre>
                </section>
            </div>
        </template>
    </div>
</template>
