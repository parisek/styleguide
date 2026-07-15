import { createRouter, createWebHistory } from 'vue-router';
import { useUiStore } from './stores/ui.js';
import { routeInfo } from './lib/routeInfo.js';
import PreviewView from './views/PreviewView.vue';
import OverviewView from './views/OverviewView.vue';
import FoundationsView from './views/FoundationsView.vue';

// Route table mirrors frontend/router.js's regex exactly:
//   ^/styleguide(?:\/(component|page|doc|overview|foundations|fields)(?:\/(.+?))?\/?$
// Bare "/" (i.e. /styleguide or /styleguide/) renders FoundationsView
// directly — NOT a redirect — so the address bar stays at "/" exactly like
// the legacy router's "no pushState" landing behavior (a vue-router
// `redirect` would rewrite the visible URL to /foundations, an observable
// behavior change the parity constraint forbids).
// Route components deliberately take NO props for type/slug: the shared
// viewport chrome (toolbar/description/usage/links/fields) is owned by
// App.vue, one level above <RouterView/>, and derives type/slug from
// useRoute() itself (see Task 7 Step 9) — mirroring the legacy DOM, where
// the toolbar/description/usage/link/fields bars are siblings of the
// route-specific content inside the SAME <main x-data="preview"> scope, not
// nested inside it. PreviewView only ever renders <PreviewPane/>, which
// independently injects the same 'viewport' instance.
const routes = [
    { path: '/', name: 'landing', component: FoundationsView },
    { path: '/component/:slug', name: 'component', component: PreviewView },
    { path: '/page/:slug', name: 'page', component: PreviewView },
    { path: '/doc/:slug', name: 'doc', component: PreviewView },
    { path: '/overview', name: 'overview', component: OverviewView },
    { path: '/foundations', name: 'foundations', component: FoundationsView },
    // Standalone icon catalog (#87) — same full-bleed iframe view as
    // foundations; the iframe src derives from the route type in
    // useViewportPreset's buildIframeSrc().
    { path: '/icons', name: 'icons', component: FoundationsView },
    // Dead-but-preserved: /fields used to be a top-level route; fields are
    // now an inline per-component drawer (see FieldsDrawer.vue, Task 9).
    // PreviewView renders PreviewPane's existing "no iframe src" empty
    // state for type 'fields' — identical to today's dead route.
    { path: '/fields', name: 'fields', component: PreviewView },
    // Any unmatched path falls back to the landing/foundations view, same
    // as the legacy parse()'s `if (!m) return { type: 'landing', slug: null }`.
    { path: '/:pathMatch(.*)*', name: 'not-found-fallback', component: FoundationsView },
];

export const router = createRouter({
    history: createWebHistory('/styleguide'),
    routes,
});

// Replaces the legacy router.js apply()/popstate wiring: flips
// ui.isPreviewLoading synchronously BEFORE the URL/DOM update, same
// ordering guarantee the legacy code calls out as load-bearing (avoids a
// race with cached iframe `load` events firing before isLoading flips true).
router.beforeEach((to) => {
    const ui = useUiStore();
    const { type, slug } = routeInfo(to);
    ui.setRoute(type, slug);
});
