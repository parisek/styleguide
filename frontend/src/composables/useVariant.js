import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';

// Wraps the `?variant=` query param through vue-router (Phase 1 Task 4's
// router.js) instead of a hand-rolled route object -- reads via
// useRoute().query.variant, writes via router.replace({ query }). `entry` is
// a ref/computed exposing the current catalogue item (or null); its
// `.variants` array (Task 1: ComponentParser.discoverVariants(), passed
// through /api/components|pages|docs) is the whitelist an incoming
// `?variant=` id must match. An unknown/removed variant (deleted sibling
// file, stale link, hand-edited URL) resolves to null -- the implicit
// default -- instead of surfacing as "selected", mirroring
// Router::whitelistVariant()'s silent server-side fallback.
//
// "Reset on navigation to a different entry, unless the incoming URL itself
// carries the param" falls out of vue-router's own semantics for free:
// Sidebar.vue's select() navigates via `router.push('/type/slug')` with a
// bare path, which never forwards the previous route's query, so the next
// render's route.query.variant is simply absent. A pasted deep link
// (`?variant=` already on the URL when the SPA boots, or a browser
// back/forward navigation) is the only way a target entry's route carries
// the param, and the whitelist check below still silently drops it if that
// entry doesn't actually have a matching variant.
export function useVariant(entry) {
    const route = useRoute();
    const router = useRouter();

    const variant = computed(() => {
        const raw = route.query.variant;
        if (typeof raw !== 'string' || raw === '') return null;
        const variants = entry.value?.variants ?? [];
        return variants.some((v) => v.id === raw) ? raw : null;
    });

    function setVariant(id) {
        const query = { ...route.query };
        if (id) {
            query.variant = id;
        } else {
            delete query.variant;
        }
        // Returns the navigation promise so callers that need to await the
        // resulting route/query update (e.g. tests) can do so; ViewportToolbar.vue's
        // @click handler ignores it, same as every other router.push() call site
        // in this codebase (Sidebar.vue's select(), UsagePanel.vue).
        return router.replace({ query });
    }

    return { variant, setVariant };
}
