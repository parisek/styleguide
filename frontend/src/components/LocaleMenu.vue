<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useI18nStore } from '../stores/i18n.js';
import { useContentLocale } from '../composables/useContentLocale.js';
import { readDiscoveredLocales } from '../lib/contentLocale.js';
import { languageName, shortLabel } from '../lib/localeNames.js';

const i18n = useI18nStore();
// One pick drives both: i18n.load() (chrome UI strings — falls back to
// English outside the chrome's own SUPPORTED set) and setContentLocale()
// (which catalogue the iframe renders). See lib/contentLocale.js's doc
// comment for why the two share a single storage key.
const { setContentLocale } = useContentLocale();

// Every DISCOVERED `.mo` catalogue, not the chrome's closed cs/en set — so
// Slovak/Polish/Italian content stays reachable even where the chrome has
// no strings of its own. Read once at setup: the server stamps this onto
// <html data-locales> with the initial HTML and never revises it in-session.
const locales = readDiscoveredLocales();

// A one-option picker cannot pick anything, and a zero-option one has
// nothing to show. Both collapse to "render no switcher" rather than to a
// disabled control, which would only take up room in an already tight footer.
const hasChoice = computed(() => locales.length > 1);

const open = ref(false);
const triggerRef = ref(null);
const listboxRef = ref(null);
// Which option the keyboard is on. Distinct from the SELECTED locale: an
// arrow key moves this without switching anything, so a keyboard user can
// walk the list without firing a fetch per step.
const activeIndex = ref(0);

const label = computed(() => shortLabel(i18n.locale, locales));

function optionId(locale) {
    return `sg-locale-opt-${locale}`;
}

function isSelected(locale) {
    return i18n.locale === locale;
}

function openMenu(index) {
    const selected = locales.indexOf(i18n.locale);
    activeIndex.value = index ?? (selected === -1 ? 0 : selected);
    open.value = true;
    // The listbox owns the keyboard while it is open (aria-activedescendant
    // pattern: focus stays on the container, the "cursor" is an attribute),
    // so it has to exist in the DOM before it can take focus.
    nextTick(() => listboxRef.value?.focus());
}

function closeMenu({ restoreFocus = true } = {}) {
    if (!open.value) return;
    open.value = false;
    // Dropping focus on the floor would send a keyboard user back to the top
    // of the document — return it to the control they opened.
    if (restoreFocus) nextTick(() => triggerRef.value?.focus());
}

function pick(locale) {
    i18n.load(locale);
    setContentLocale(locale);
    closeMenu();
}

function move(delta) {
    const count = locales.length;
    // Wraps at both ends: the list is short and circular movement saves a
    // 15-key walk back to the top.
    activeIndex.value = (activeIndex.value + delta + count) % count;
}

function onListboxKeydown(event) {
    switch (event.key) {
        case 'ArrowDown': move(1); break;
        case 'ArrowUp': move(-1); break;
        case 'Home': activeIndex.value = 0; break;
        case 'End': activeIndex.value = locales.length - 1; break;
        case 'Enter':
        case ' ': pick(locales[activeIndex.value]); break;
        case 'Escape':
        case 'Tab': closeMenu(); break;
        default: return;
    }
    // Only reached for keys handled above, so Tab still leaves the menu (it
    // closes first) and unhandled keys keep their native behaviour.
    if (event.key !== 'Tab') event.preventDefault();
}

function onTriggerKeydown(event) {
    if (event.key === 'ArrowUp') {
        // The panel opens UPWARD, so "up" should land on the entry nearest
        // the trigger — which is the visually lowest, i.e. the last one.
        openMenu(locales.length - 1);
        event.preventDefault();
    } else if (event.key === 'ArrowDown') {
        openMenu(0);
        event.preventDefault();
    }
}

// Capture phase: a pointerdown inside the preview <iframe>'s own chrome, or
// on a control that stops propagation, would otherwise never bubble here and
// the menu would stay stuck open behind the user's next click.
function onDocumentPointerDown(event) {
    if (triggerRef.value?.contains(event.target)) return;
    if (listboxRef.value?.contains(event.target)) return;
    // No focus restore: the pointer has already moved the user's attention
    // elsewhere, and yanking focus back to the trigger would fight the click
    // that is landing right now.
    closeMenu({ restoreFocus: false });
}

// Bound only while open, so a closed switcher costs the document no
// listener at all — this component is mounted for the whole session.
watch(open, (isOpen) => {
    if (isOpen) {
        document.addEventListener('pointerdown', onDocumentPointerDown, true);
    } else {
        document.removeEventListener('pointerdown', onDocumentPointerDown, true);
    }
});

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onDocumentPointerDown, true);
});
</script>

<template>
    <!-- `relative` is the positioning context the panel anchors to; without
         it the panel would escape to the nearest positioned ancestor and
         lose its tie to the trigger. -->
    <div v-if="hasChoice" class="relative">
        <button
            ref="triggerRef"
            data-testid="locale-trigger"
            type="button"
            role="combobox"
            aria-haspopup="listbox"
            :aria-expanded="open ? 'true' : 'false'"
            :aria-label="i18n.t('locale.switch')"
            :title="i18n.t('locale.switch')"
            class="flex items-center gap-1 font-mono text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100 transition-colors rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-500"
            @click="open ? closeMenu() : openMenu()"
            @keydown="onTriggerKeydown"
        >
            <span>{{ label }}</span>
            <!-- Chevron points up while closed (that is where the panel will
                 appear) and flips down while open (where it will go). -->
            <svg
                aria-hidden="true"
                class="h-3 w-3 transition-transform"
                :class="open ? 'rotate-180' : ''"
                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                stroke-linecap="round" stroke-linejoin="round"
            >
                <path d="M18 15l-6-6-6 6" />
            </svg>
        </button>

        <!-- Drops UP: the switcher is pinned to the bottom edge of the
             sidebar, so there is never room below it. `bottom-full` puts the
             panel's bottom on the trigger's top; `right-0` keeps a long
             language name from spilling out of the 288px sidebar. The height
             cap plus overflow-y-auto is what lets 15+ languages fit without
             the panel running off the top of the viewport. -->
        <ul
            v-if="open"
            ref="listboxRef"
            role="listbox"
            tabindex="0"
            :aria-label="i18n.t('locale.switch')"
            :aria-activedescendant="optionId(locales[activeIndex])"
            class="absolute bottom-full right-0 mb-2 z-50 min-w-[10rem] max-h-[min(60vh,20rem)] overflow-y-auto overscroll-contain rounded-lg border border-zinc-200 bg-white py-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-800 focus:outline-none"
            @keydown="onListboxKeydown"
        >
            <li
                v-for="(loc, idx) in locales"
                :id="optionId(loc)"
                :key="loc"
                role="option"
                :aria-selected="isSelected(loc) ? 'true' : 'false'"
                class="flex items-center justify-between gap-3 px-3 py-1.5 cursor-pointer text-zinc-700 dark:text-zinc-200"
                :class="[
                    idx === activeIndex ? 'bg-zinc-100 dark:bg-zinc-700' : '',
                    isSelected(loc) ? 'font-semibold text-zinc-900 dark:text-zinc-50' : '',
                ]"
                @click="pick(loc)"
                @mousemove="activeIndex = idx"
            >
                <span data-testid="locale-name">{{ languageName(loc) }}</span>
                <!-- The raw catalogue code stays visible: it is what the
                     project's `.mo` filename and any `?locale=` deep link
                     actually use, so a developer can still map a row to a file. -->
                <span class="font-mono text-[10px] text-zinc-400 dark:text-zinc-500">{{ loc }}</span>
            </li>
        </ul>
    </div>
</template>
