<script setup>
import { useUiStore } from '../stores/ui.js';
import { useI18nStore } from '../stores/i18n.js';

// Fixed order regardless of which groups actually have entries -- matches
// a11yFormat.js's own IMPACT_ORDER, so the panel and the pure formatter
// never drift out of sync on "what does critical→minor mean here".
const IMPACTS = ['critical', 'serious', 'moderate', 'minor'];

const ui = useUiStore();
const i18n = useI18nStore();

// axe-core's violation.nodes[].target is a CSS-selector-fragment array (one
// entry per shadow-DOM/frame hop; a single-document violation is just
// [selector]). Joined for display only -- this never re-queries the DOM, so
// a plain string join is enough context for the author to spot the
// offending element by eye.
function targetsFor(violation) {
    return violation.nodes.map((n) => n.target.join(' ')).join(', ');
}
</script>

<template>
    <!-- Mounted by App.vue only while ui.a11yResults || ui.a11yRunning is
         truthy (App.vue's own v-if) -- this component itself has no gating
         logic, mirroring FieldsDrawer/UsagePanel's caller-gated pattern.
         Every axe-provided string below (v.help, node targets) is rendered
         via {{ }} text interpolation only -- never v-html. Unlike the
         component-description bar and FieldsDrawer's field descriptions
         (dev-authored YAML, trusted), axe's `help`/`description` strings
         ultimately describe content that can originate from a *consumer's*
         Twig template (e.g. an authored `alt` attribute value echoed back
         into a violation message) -- untrusted by this package's own trust
         model, so escaping the normal Vue way is a deliberate choice, not
         an oversight. -->
    <div class="border-t border-zinc-200 bg-zinc-50 p-4 text-sm max-h-64 overflow-y-auto dark:border-zinc-800 dark:bg-zinc-900/40" data-testid="a11y-panel">
        <h3 class="mb-2 font-semibold text-zinc-900 dark:text-zinc-100">{{ i18n.t('a11y.panel_title') }}</h3>
        <p v-if="ui.a11yRunning" class="text-zinc-500 dark:text-zinc-400">{{ i18n.t('a11y.running') }}</p>
        <p v-else-if="ui.a11yResults && ui.a11yResults.total === 0" class="text-zinc-500 dark:text-zinc-400">{{ i18n.t('a11y.no_violations') }}</p>
        <template v-else-if="ui.a11yResults">
            <div v-for="impact in IMPACTS" :key="impact">
                <template v-if="ui.a11yResults.byImpact[impact].length > 0">
                    <h4 :data-testid="`a11y-impact-${impact}`" class="mt-3 text-xs font-semibold uppercase text-zinc-500 dark:text-zinc-400">
                        {{ i18n.t(`a11y.impact_${impact}`) }} ({{ ui.a11yResults.byImpact[impact].length }})
                    </h4>
                    <ul class="space-y-1">
                        <li v-for="v in ui.a11yResults.byImpact[impact]" :key="v.id" class="text-zinc-700 dark:text-zinc-300">
                            <strong>{{ v.help }}</strong>
                            <code class="ml-1 text-xs text-zinc-500 dark:text-zinc-400">{{ targetsFor(v) }}</code>
                        </li>
                    </ul>
                </template>
            </div>
        </template>
    </div>
</template>
