import { onMounted, onUnmounted } from 'vue';
import { useUiStore } from '../stores/ui.js';

// Ported from frontend/components/search.js. Query state lives in
// useUiStore().searchQuery so any component can read it reactively — this
// composable only wires the two keyboard shortcuts to a given input ref.
export function useSearchShortcuts(inputRef) {
    const ui = useUiStore();

    function onKeydown(e) {
        if (e.key === 'k' && (e.metaKey || e.ctrlKey)) {
            e.preventDefault();
            inputRef.value?.focus();
        }
        if (e.key === 'Escape') {
            ui.searchQuery = '';
            inputRef.value?.blur();
        }
    }

    onMounted(() => window.addEventListener('keydown', onKeydown));
    onUnmounted(() => window.removeEventListener('keydown', onKeydown));
}
