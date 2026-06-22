import Alpine from 'alpinejs';

// Viewport presets aligned with Tailwind v4 default breakpoints (md / lg / xl /
// 2xl = 768 / 1024 / 1280 / 1536) plus four device-mythic anchors (320 / 375 /
// 425 for mobiles, 1920 for Full HD). The first label group keeps the legacy
// "Mobile / Tablet / Desktop" semantics for tooltips, while the width number is
// the actual button text — Storybook-style segmented control with explicit
// pixel values.
//
// Height is informational (shown in the tooltip); the iframe itself stays as
// tall as the surrounding chrome allows, mirroring Chrome DevTools' Responsive
// mode. Custom widths go through `applyCustomWidth()` and don't live in this
// list — they're persisted as the raw px string via the ui store.
// `category` drives the leading icon glyph in the preset row (mobile / tablet /
// desktop / full). Categories cluster naturally by width: anything < 600 is a
// phone, 600–1199 is a tablet, ≥ 1200 is a desktop. The fourth category 'full'
// is the unconstrained 100%-wide preset — rendered with a different icon (a
// stretch / fit-screen glyph) to signal "no device, free width".
const VIEWPORTS = [
    { key: 'mobile-s',   label: 'Mobile S',   category: 'mobile',  width: 320,  height: 568  },
    { key: 'mobile',     label: 'Mobile',     category: 'mobile',  width: 375,  height: 667  },
    { key: 'mobile-l',   label: 'Mobile L',   category: 'mobile',  width: 425,  height: 812  },
    { key: 'tablet',     label: 'Tablet',     category: 'tablet',  width: 768,  height: 1024 },
    { key: 'tablet-l',   label: 'Tablet L',   category: 'tablet',  width: 1024, height: 1366 },
    { key: 'desktop',    label: 'Desktop',    category: 'desktop', width: 1280, height: 800  },
    { key: 'desktop-l',  label: 'Desktop L',  category: 'desktop', width: 1536, height: 960  },
    { key: 'desktop-xl', label: 'Desktop XL', category: 'desktop', width: 1920, height: 1080 },
    { key: 'desktop-2k', label: 'Desktop 2K', category: 'desktop', width: 2560, height: 1440 },
    { key: 'full',       label: 'Full',       category: 'full',    width: null, height: null }, // 100%
];

// Custom width sanity range — same bounds as the URL-param parser in ui.js,
// kept in sync so all three input paths (URL, drag, custom input) reject
// the same garbage.
const CUSTOM_MIN = 100;
const CUSTOM_MAX = 4000;

// Unknown types fall back to a neutral zinc pill so the drawer never
// breaks on a new type a project introduces — the YAML schema is open-
// ended and we don't want to gate rendering on a fixed vocabulary.
const TYPE_PILL_CLASSES = {
    array:    'bg-purple-500/20 text-purple-300',
    object:   'bg-pink-500/20 text-pink-300',
    text:     'bg-blue-500/20 text-blue-300',
    textarea: 'bg-indigo-500/20 text-indigo-300',
    image:    'bg-emerald-500/20 text-emerald-300',
    link:     'bg-orange-500/20 text-orange-300',
};
const TYPE_PILL_FALLBACK = 'bg-zinc-800 text-zinc-300';

// Depth-first walk over the YAML `fields:` map. Returns a flat list so
// the Alpine template can iterate linearly — Alpine 3 doesn't support
// self-referential templates inline, so the recursion lives here in JS.
// Each row's `depth` drives the template's indentation and `└` glyph.
function flattenFieldsTree(map, depth = 0, parentPath = '') {
    if (!map || typeof map !== 'object' || Array.isArray(map)) return [];
    const rows = [];
    for (const [key, field] of Object.entries(map)) {
        if (!field || typeof field !== 'object') continue;
        const path = parentPath ? `${parentPath}.${key}` : key;
        rows.push({
            // Full dotted path (e.g. `items.title`) — unique within a tree
            // even when leaf keys repeat across sibling branches, so it's
            // the stable :key in the Alpine x-for. Without it, Alpine
            // would reuse DOM nodes between siblings carrying the same
            // leaf key and briefly flash mismatched row state on
            // navigation between components.
            path,
            key,
            depth,
            type: typeof field.type === 'string' ? field.type : '',
            title: typeof field.title === 'string' ? field.title : '',
            description: typeof field.description === 'string' ? field.description : '',
            // YAML `required: 1` / `required: 0` parse to numbers; boolean
            // coercion keeps the template's `x-if` clean.
            required: !!field.required,
        });
        if (field.fields && typeof field.fields === 'object') {
            rows.push(...flattenFieldsTree(field.fields, depth + 1, path));
        }
    }
    return rows;
}

// Device-class icon SVG inner paths, keyed by VIEWPORTS[i].category. The
// outer <svg> shell lives in the template; this is just the inner content
// — Alpine's x-html injects the right group based on category. Inline
// rather than separate files because they're small, simple, and live next
// to the data they decorate.
const CATEGORY_ICON_PATHS = {
    mobile: '<rect x="7" y="2" width="10" height="20" rx="2"/><line x1="11" y1="18" x2="13" y2="18"/>',
    tablet: '<rect x="4" y="3" width="16" height="18" rx="2"/><line x1="11" y1="18" x2="13" y2="18"/>',
    desktop: '<rect x="2" y="4" width="20" height="13" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>',
    full: '<polyline points="4 8 4 4 8 4"/><polyline points="20 8 20 4 16 4"/><polyline points="4 16 4 20 8 20"/><polyline points="20 16 20 20 16 20"/>',
};

document.addEventListener('alpine:init', () => {
    Alpine.data('preview', () => ({
        viewports: VIEWPORTS,
        categoryIconPaths: CATEGORY_ICON_PATHS,
        // Live wrapper measurement — meaningful only in Full mode where the
        // iframe-bearing wrapper takes 100% of the chrome pane width with no
        // transform-scale, so its DOM box width equals the real CSS pixel
        // viewport inside. For preset/Custom modes the wrapper is `transform:
        // scale(z)` of a fixed-px box, so its DOM width is the *display* size,
        // not the emulated logical width — the `currentWidth` getter below
        // routes around this property in those modes.
        _measuredWrapperWidth: 0,
        // The Custom input always shows the current pixel value (or '' when
        // Full is active). Typing then Enter / blur writes through to the
        // store. The bidirectional sync is set up in init() below.
        customWidthInput: '',
        // Auto-fitted iframe height for component / page previews — null
        // before the iframe has loaded once; thereafter equals the inner
        // body's scrollHeight, kept up-to-date by a ResizeObserver attached
        // to the iframe's contentDocument so the iframe grows / shrinks
        // with its content. Short components don't force an internal scroll
        // bar; tall pages extend the outer preview area (which is
        // overflow-auto) into a natural document flow.
        iframeContentHeight: null,
        // Monotonically-increasing counter bumped by reloadPreview(). When
        // non-zero it is appended as `_r=<n>` to iframeSrc so the browser
        // treats the URL as a new document and reloads the iframe. Zero on
        // initial load so normal URLs stay clean (no spurious `?_r=0`).
        _reloadNonce: 0,
        // Cached output of flattenFieldsTree() for the current route. Filled
        // by an Alpine.effect in init() that re-runs only when route/components
        // change, so the DFS happens once per navigation rather than on every
        // template re-render that touches the tree or its count.
        _fieldsTree: [],

        init() {
            // Track iframe wrapper width reactively. `offsetWidth` isn't an
            // observable, so a computed getter reads stale values; a single
            // ResizeObserver on document.body picks up the wrapper whenever
            // x-if re-renders it (component navigation) without rebinding.
            // `x-ref` inside `<template x-if>` doesn't surface on the parent
            // x-data's $refs in Alpine 3, so we query the DOM directly.
            this._ro = new ResizeObserver((entries) => {
                for (const entry of entries) {
                    this._measuredWrapperWidth = Math.round(entry.contentRect.width);
                }
            });
            this.$watch('iframeSrc', () => queueMicrotask(() => this.observeWrapper()));
            queueMicrotask(() => this.observeWrapper());

            // Mirror store width → custom input. Runs on every width change
            // (preset click, drag, URL param boot, persisted load, custom
            // input itself) so the input field stays a faithful readout when
            // it's not the source of truth.
            this._syncCustomFromStore();
            Alpine.effect(() => this._syncCustomFromStore());

            // Recompute the flattened fields tree once per route change.
            // Reactive deps are the route + components store; the DFS now
            // runs at most once per visible component instead of on every
            // template access of currentItemFieldsTree / Count.
            Alpine.effect(() => {
                const route = Alpine.store('ui').route;
                const item = Alpine.store('components').find(route.type, route.slug);
                this._fieldsTree = flattenFieldsTree(item?.fields);
            });
        },

        _syncCustomFromStore() {
            const w = Alpine.store('ui').previewWidth;
            if (w === '100%') {
                this.customWidthInput = '';
                return;
            }
            const px = parseInt(w, 10);
            if (Number.isInteger(px)) this.customWidthInput = px;
        },

        // The loading flag itself is set synchronously by setRoute() in the
        // ui store (before the iframe src changes). Here we just clear it
        // when the new document finishes parsing. Iframes fire `load` for
        // every src change including the initial bind, so this reliably
        // matches a previous setRoute() → isPreviewLoading = true.
        onIframeLoad(event) {
            Alpine.store('ui').isPreviewLoading = false;
            const route = Alpine.store('ui').route;
            if (route.type === 'component' || route.type === 'page') {
                this._fitIframeToContent(event.target);
            } else {
                // Foundations / doc / other routes use a fixed h-full layout and
                // don't want auto-fit. Disconnect any previous observer and
                // null out the explicit height so the CSS class wins.
                this.iframeContentHeight = null;
                if (this._iframeContentRO) this._iframeContentRO.disconnect();
            }
        },

        // Same-origin iframes let the parent read contentDocument directly.
        // Measure scrollHeight (which accounts for everything below the fold)
        // and keep an observer so post-load DOM changes — image / font load,
        // collapse / accordion expansion, JS-driven content swaps — feed
        // back into the iframe element's explicit height. Sets a fresh
        // observer on every load so navigating between components doesn't
        // leak observers from the previous document.
        _fitIframeToContent(iframe) {
            const doc = iframe?.contentDocument;
            if (!doc) return;
            const measure = () => {
                const h = Math.max(
                    doc.documentElement?.scrollHeight ?? 0,
                    doc.body?.scrollHeight ?? 0,
                );
                if (h > 0) this.iframeContentHeight = h;
            };
            measure();
            if (this._iframeContentRO) this._iframeContentRO.disconnect();
            this._iframeContentRO = new ResizeObserver(measure);
            if (doc.documentElement) this._iframeContentRO.observe(doc.documentElement);
            if (doc.body) this._iframeContentRO.observe(doc.body);
        },

        get isLoading() {
            return Alpine.store('ui').isPreviewLoading;
        },

        // Available dimensions of the iframe's parent container — the .flex-1
        // box that hosts the wrapper. Drives auto-zoom: when the active preset's
        // width OR height exceeds the available room (e.g. Desktop 2K 2560×1440
        // active on a 1280×800 laptop), the iframe gets uniformly scaled down
        // so it fits in both axes without cutting off and without distorting
        // the device's aspect ratio. 0 means "not yet measured" — getters fall
        // back to no scaling. Padding (p-6 = 48px total per axis) is subtracted
        // to match the visual breathing room.
        containerAvailableWidth: 0,
        containerAvailableHeight: 0,

        observeWrapper() {
            this._ro.disconnect();
            const wrapper = document.querySelector('[x-ref="iframeWrapper"]');
            if (wrapper) this._ro.observe(wrapper);
            else this._measuredWrapperWidth = 0;

            // Observe the chrome preview pane (the `.flex-1 ... overflow-auto`
            // container) so containerAvailableWidth tracks viewport resize.
            // Separate observer from the wrapper one because the wrapper
            // itself is constrained by `max-width: 100%` — its measured
            // width can't tell us how much room there'd be without the
            // constraint.
            //
            // We `.closest('.overflow-auto')` instead of taking wrapper's
            // direct parentElement because the chassis-frame decoration layer
            // introduces an `inline-block` ancestor between the wrapper and
            // the chrome pane. Observing that inline-block would create a
            // feedback loop: it sizes to the wrapper, observer reads the
            // shrunk width, recomputes zoom smaller, wrapper shrinks again.
            if (this._containerRO) this._containerRO.disconnect();
            const parent = wrapper?.closest('.overflow-auto');
            if (parent) {
                this._containerRO = new ResizeObserver((entries) => {
                    for (const entry of entries) {
                        // 48px = 2× p-6 padding on the parent (both axes).
                        // Keeps the scaled iframe inside the visible chrome
                        // with the same visual breathing room horizontally
                        // and vertically.
                        this.containerAvailableWidth = Math.max(0, entry.contentRect.width - 48);
                        this.containerAvailableHeight = Math.max(0, entry.contentRect.height - 48);
                    }
                });
                this._containerRO.observe(parent);
                // Seed initial values synchronously so the first paint already
                // has the correct zoom; otherwise getter falls back to no
                // scaling for one frame.
                this.containerAvailableWidth = Math.max(0, parent.clientWidth - 48);
                this.containerAvailableHeight = Math.max(0, parent.clientHeight - 48);
            }
        },

        // Logical (un-rotated) device-emulation width in CSS pixels —
        // returns an integer for any fixed-px width (preset OR Custom),
        // null only when the store carries `'100%'` (Full mode). Custom
        // widths share the preset code path: a `'500px'` Custom width
        // produces the same iframe-style chain as a `375px` preset, just
        // without a `logicalPresetHeight` to pair with it (Custom is
        // width-only; iframe height comes from content). The iframe is
        // given these as its box-level width; auto-zoom then applies
        // transform: scale() on top so the visual fits the available space.
        get logicalPresetWidth() {
            const w = Alpine.store('ui').previewWidth;
            if (w === '100%') return null;
            const px = parseInt(w, 10);
            return Number.isInteger(px) ? px : null;
        },
        get logicalPresetHeight() {
            return Alpine.store('ui').previewHeight;
        },

        // Effective iframe dimensions after rotation. When previewRotated is
        // true, width and height swap so the iframe renders landscape (e.g.
        // Mobile 375×667 → 667×375). When no preset height is set (Full or
        // Custom), rotation is inert and only width is returned.
        get effectiveWidth() {
            const route = Alpine.store('ui').route;
            const item = Alpine.store('components').find(route.type, route.slug);
            if (item?.responsive === false) return null;

            if (Alpine.store('ui').previewRotated && this.logicalPresetHeight !== null) {
                return this.logicalPresetHeight;
            }
            return this.logicalPresetWidth;
        },
        get effectiveHeight() {
            if (Alpine.store('ui').previewRotated && this.logicalPresetHeight !== null && this.logicalPresetWidth !== null) {
                return this.logicalPresetWidth;
            }
            return this.logicalPresetHeight;
        },

        // Scale factor that makes the iframe fit the container's available
        // space. Two regimes, by whether the active mode has a *canonical
        // height*:
        //
        // 1. Device presets (Mobile / Tablet / Desktop … incl. rotated) —
        //    `effectiveHeight` is a fixed px value, so we fit-to-bounds:
        //    `min(availW/w, availH/h)`. This keeps the WHOLE emulated device
        //    visible at once — critical for tall / rotated presets (e.g. a
        //    rotated Desktop at 960×1536), which otherwise overflow the pane
        //    vertically and tuck their top edge under the toolbar where it
        //    can't be scrolled back into view.
        // 2. Full / Custom — no canonical height (content-driven), so height
        //    can't be fitted; stays width-only and the chrome pane's
        //    `overflow-auto` scrollbar handles vertical overflow. Full mode
        //    (effectiveWidth === null) returns 1 via the early `!w` guard.
        //
        // `Math.min(1, …)` caps at 1:1 — fitting only ever shrinks, never
        // upscales a small preset into a blurry enlargement.
        get zoom() {
            const w = this.effectiveWidth;
            if (!w) return 1;
            if (!this.containerAvailableWidth) return 1;
            let z = this.containerAvailableWidth / w;
            const h = this.effectiveHeight;
            if (h && this.containerAvailableHeight) {
                z = Math.min(z, this.containerAvailableHeight / h);
            }
            return Math.min(1, z);
        },

        // CSS for the iframe element. Logical preset dimensions in CSS px
        // (so viewport units inside resolve against the device's "true"
        // size) plus a uniform transform: scale() to fit visually. Wrapper
        // gets the *scaled* dimensions so layout flow uses the visible
        // size, not the logical one.
        get iframeStyle() {
            const w = this.effectiveWidth;
            const h = this.effectiveHeight ?? this.iframeContentHeight ?? 400;
            if (w === null) {
                // Full mode (previewWidth === '100%') — `effectiveWidth` is null
                // ONLY here, since every fixed-px width (preset or Custom)
                // parses to an integer in `logicalPresetWidth`. Iframe fills
                // the chrome's right pane vertically too so `h-svh` inside
                // resolves against the real chrome height instead of
                // collapsing to inner-document height (the issue #14 paradox).
                return 'width: 100%; height: 100%';
            }
            const z = this.zoom;
            // transform-origin: 0 0 + sized wrapper means scaled iframe
            // sits flush against the wrapper's top-left corner.
            return `width: ${w}px; height: ${h}px; transform: scale(${z}); transform-origin: 0 0`;
        },

        // Logical (CSS-pixel) width of the iframe the user has emulated. This
        // is what every consumer of "the current width" actually wants —
        // toolbar readout, dropdown trigger label, drag start anchor — none of
        // them care about the scaled DISPLAY size of the wrapper. The wrapper's
        // DOM width drifts from this value whenever `zoom < 1` (preset / Custom
        // wider than the chrome pane), so we route around the measurement and
        // read straight from the store, falling back to the measured DOM width
        // only when the store value is the unbounded `'100%'` (Full mode).
        get currentWidth() {
            const w = Alpine.store('ui').previewWidth;
            if (w === '100%') return this._measuredWrapperWidth;
            const px = parseInt(w, 10);
            return Number.isInteger(px) ? px : 0;
        },

        // Compact label for the responsive dropdown trigger that replaces
        // the segmented pill on narrow toolbars. Mirrors the in-pill text:
        // bare width for px-bearing presets ("1280"), "Full" for the
        // unconstrained preset, and the live pixel value for Custom
        // (so the dropdown reads back the dragged size without needing
        // to open the menu).
        get activeLabel() {
            const key = this.activePreset;
            if (key === 'full') return 'Full';
            if (key === 'custom') return `${this.currentWidth}`;
            const match = VIEWPORTS.find((v) => v.key === key);
            return match?.width ? String(match.width) : '?';
        },

        // Device WORD label for the unified switcher trigger (e.g. "Desktop",
        // "Mobile S", "Full", or the localised "Vlastní"/"Custom"). Distinct
        // from `activeLabel` above, which returns the bare width number — the
        // trigger pairs this word with `triggerDims` so the control always
        // reads in plain language at every viewport width.
        get activeWordLabel() {
            const key = this.activePreset;
            if (key === 'full') return 'Full';
            if (key === 'custom') return Alpine.store('i18n').t('toolbar.custom_width_label');
            const match = VIEWPORTS.find((v) => v.key === key);
            return match?.label ?? '?';
        },

        // Dimension summary for the switcher trigger: "1280 × 800" for presets
        // (with a ` · N %` suffix when scaled below 1:1), "100 %" for Full,
        // "980 px" for Custom. Folds the old standalone readout into the
        // trigger so the dimensions are always visible without a separate chip.
        get triggerDims() {
            if (this.activePreset === 'full') return '100 %';
            if (this.activePreset === 'custom') {
                const z = this.zoom;
                return z < 1 ? `${this.currentWidth} px · ${Math.round(z * 100)} %` : `${this.currentWidth} px`;
            }
            return this.dimensionsLabel;
        },

        // Human-readable "W × H" string for the active preset (after any
        // rotation), or null when the preset has no logical dimensions
        // (Full mode, or Custom widths whose height is content-driven).
        // When the iframe is scaled down (zoom < 1, fit-to-bounds), the
        // label also carries a ` (N %)` suffix so the user notices the
        // preview isn't 1:1 with the emulated device — easy to miss on a
        // wide monitor where a 2K preset visually fills the canvas but is
        // actually being scaled to ~85 %.
        get dimensionsLabel() {
            const w = this.effectiveWidth;
            const h = this.effectiveHeight;
            if (!w || !h) return null;
            const z = this.zoom;
            const dims = `${w} × ${h}`;
            return z < 1 ? `${dims} (${Math.round(z * 100)} %)` : dims;
        },

        // True when the Full preset is active (previewWidth === '100%'). Drives
        // chrome decoration: in Full mode we drop the .p-6 padding on the
        // outer container AND the wrapper's `rounded shadow-lg` device-frame
        // styling so the iframe truly fills the available chrome area edge
        // to edge — matches the user expectation that "Full" means full.
        // Fixed presets keep the breathing room + device-frame look so the
        // preview reads like a device on a desk.
        get isFullPreset() {
            return Alpine.store('ui').previewWidth === '100%';
        },

        // CSS for the wrapper element. Every fixed-px width (preset or
        // Custom) takes the *scaled* dimensions so the wrapper occupies the
        // visible amount of space in flow — drag handles + shadow + chassis
        // ring all anchor against the visible (scaled) box, not the logical
        // CSS-pixel size. Only Full mode (effectiveWidth null) bypasses the
        // scaling math.
        get wrapperStyle() {
            const w = this.effectiveWidth;
            const h = this.effectiveHeight;
            if (w === null) {
                // Full mode (previewWidth === '100%') — `effectiveWidth` is null
                // only here. Wrapper takes the full chrome-pane height so the
                // iframe's `height: 100%` resolves against a real container.
                return 'width: 100%; height: 100%';
            }
            const z = this.zoom;
            // Clamp the scaled dimensions to a minimum of 1px so a tiny
            // chrome pane (e.g. ResizeObserver firing during a transient
            // 0.x-wide layout) can never produce `width: 0px` — that would
            // make the preview wrapper visually disappear and the iframe
            // collapse to zero box even though the logical viewport
            // (effectiveWidth / effectiveHeight) is unchanged.
            //
            // For height we need a non-null source either way: preset modes
            // carry an explicit `effectiveHeight`; Custom widths fall back to
            // `iframeContentHeight` (the measured inner-document height).
            // Without that fallback the wrapper would default to `height: auto`
            // and follow the iframe's UNSCALED DOM size, while the iframe
            // itself is `transform: scale(z)`d down — leaving a blank gap below
            // the visible iframe equal to `unscaledH * (1 - z)`.
            const sourceH = h ?? this.iframeContentHeight ?? 400;
            const scaledW = Math.max(1, Math.round(w * z));
            const scaledH = Math.max(1, Math.round(sourceH * z));
            return `width: ${scaledW}px; height: ${scaledH}px`;
        },

        // Breadcrumb pieces for the toolbar. `currentSectionKey` returns null
        // while the components API hasn't loaded yet so the template hides the
        // section segment instead of flashing an untranslated label.
        get currentSectionKey() {
            const route = Alpine.store('ui').route;
            if (!route.slug) return null;
            const components = Alpine.store('components');
            if (route.type === 'page') return 'pages';
            if (route.type === 'doc') return null;
            const item = components.find(route.type, route.slug);
            if (!item) return null;
            return components.sectionOf(item, route.type);
        },

        get currentItemName() {
            const route = Alpine.store('ui').route;
            const item = Alpine.store('components').find(route.type, route.slug);
            return item?.name ?? route.slug;
        },

        get currentItemDescription() {
            const route = Alpine.store('ui').route;
            const item = Alpine.store('components').find(route.type, route.slug);
            return item?.description ?? '';
        },

        get iframeSrc() {
            const route = Alpine.store('ui').route;
            // Foundations renders inside the iframe (same project CSS + Twig
            // env as components / pages, just against the shared yaml context
            // instead of one specific component). Fields used to do the same
            // but is now a per-component SPA drawer — no top-level page.
            let src;
            if (route.type === 'foundations') {
                src = `/styleguide/render/${route.type}/index`;
            } else if (!route.slug || (route.type !== 'component' && route.type !== 'page' && route.type !== 'doc')) {
                return null;
            } else {
                src = `/styleguide/render/${route.type}/${route.slug}`;
            }
            // Append reload nonce when non-zero so bumping it forces the
            // browser to treat the URL as a new document. Zero on initial
            // load to keep clean URLs without a spurious `?_r=0` suffix.
            if (this._reloadNonce) {
                src += (src.includes('?') ? '&' : '?') + `_r=${this._reloadNonce}`;
            }
            return src;
        },

        // Whether the viewport-width toolbar should show for the current route.
        // Kept as a getter rather than inlined in the `x-if`: calling the
        // `$store.components.find(...)` store method WITH ARGUMENTS directly
        // inside an Alpine template expression silently breaks the x-if render
        // — the whole toolbar vanished for every responsive:true entry (#36),
        // with no console error and a logically-true gate. A bare identifier in
        // the template (`toolbarVisible`) evaluates trivially; the method call
        // lives here in plain JS instead.
        get toolbarVisible() {
            const route = Alpine.store('ui').route;
            return !!this.iframeSrc
                && route.type !== 'foundations'
                && route.type !== 'overview'
                && Alpine.store('components').find(route.type, route.slug)?.responsive !== false;
        },

        // Re-fetches the component/page catalogue from the API and forces the
        // preview iframe to reload by bumping the nonce in iframeSrc. Called
        // by the toolbar reload button.
        reloadPreview() {
            Alpine.store('components').init();
            this._reloadNonce++;
        },

        get currentItemFieldsTree() {
            return this._fieldsTree;
        },

        get currentItemFieldsCount() {
            return this._fieldsTree.length;
        },

        // Lower-cased lookup so YAML can spell `Array` or `TEXT` and still
        // hit the table; unknown types render neutral.
        fieldsTypePill(type) {
            const key = String(type ?? '').toLowerCase();
            return TYPE_PILL_CLASSES[key] ?? TYPE_PILL_FALLBACK;
        },

        get previewWidth() {
            return Alpine.store('ui').previewWidth;
        },

        get isDragging() {
            return Alpine.store('ui').isDragging;
        },

        // Which preset matches the current width? Returns the preset key for
        // an exact match, or 'custom' when the width is a pixel value that
        // doesn't line up with any preset (drag-resize, manual input,
        // URL-param override with a non-preset number).
        get activePreset() {
            const w = this.previewWidth;
            if (w === '100%') return 'full';
            const px = parseInt(w, 10);
            const match = VIEWPORTS.find((v) => v.width === px);
            return match?.key ?? 'custom';
        },

        // Resolved device category for the active preset — drives the
        // wrapper's device-frame look ("phone bezel" for mobile, slimmer
        // frame for tablet, monitor bevel for desktop). `full` has no
        // frame (edge-to-edge intent). `custom` gets its own minimal
        // `rounded shadow-lg` frame so the drag handles have a flat
        // edge to anchor against without intruding into a chassis ring.
        // Anything else (defensive fall-through for unrecognised keys)
        // resolves to desktop.
        get activePresetCategory() {
            const key = this.activePreset;
            if (key === 'full') return 'full';
            if (key === 'custom') return 'custom';
            const match = VIEWPORTS.find((v) => v.key === key);
            return match?.category ?? 'desktop';
        },

        setPreset(key) {
            const preset = VIEWPORTS.find((v) => v.key === key);
            if (!preset) return;
            // Apply both width and height so the iframe carries the preset's
            // aspect ratio. Height is what makes `h-svh` / `h-screen` inside
            // the iframe resolve against a meaningful device viewport (e.g.
            // Mobile 375 → h-svh = 667px, matching iPhone Safari).
            //
            // The "Full" preset is the exception: width = '100%', height = null,
            // which signals "fill the entire chrome pane". `iframeStyle` /
            // `wrapperStyle` route Full through a `height: 100%` branch
            // (NOT content-auto) — the iframe spans the chrome's full vertical
            // box so `h-svh` inside resolves against the real chrome height
            // instead of collapsing to inner-document height (the issue #14
            // paradox). Custom widths get `height: 400px` content-auto fallback
            // because the user explicitly opted into device emulation by typing
            // a width and there's no canonical height to use.
            const w = preset.width === null ? '100%' : `${preset.width}px`;
            Alpine.store('ui').setWidth(w, preset.height);
        },

        // Custom-input apply path. Triggered on Enter / blur from the number
        // input. Strict integer check (`Number(...)` + `Number.isInteger`)
        // rejects decimals like 375.5 outright — otherwise `parseInt` would
        // silently truncate to 375 and the user would see a different width
        // than they typed. Out-of-range or non-integer values revert to the
        // store's current width via _syncCustomFromStore so the input can't
        // be left in a desync state.
        applyCustomWidth() {
            const px = Number(this.customWidthInput);
            if (!Number.isInteger(px) || px < CUSTOM_MIN || px > CUSTOM_MAX) {
                this._syncCustomFromStore();
                return;
            }
            Alpine.store('ui').setWidth(`${px}px`);
        },

        startDrag(event) {
            event.preventDefault();
            const startX = event.clientX ?? event.touches?.[0]?.clientX;
            if (startX == null) return;

            const wrapper = document.querySelector('[x-ref="iframeWrapper"]');
            if (!wrapper) return;
            const parentRect = wrapper.parentElement.getBoundingClientRect();
            // Wrapper is centered in its parent, so dragging the right edge
            // by Δx grows the wrapper by 2·Δx (both sides grow symmetrically).
            // Anchor the calculation to the parent's center so the cursor
            // stays under the drag handle.
            const centerX = parentRect.left + parentRect.width / 2;
            // Snapshot the current zoom factor so the cursor-to-logical-width
            // conversion stays consistent for the whole drag, even if the
            // visible scale would otherwise recompute mid-drag (it shouldn't —
            // zoom depends on container width and effective width, both stable
            // during a drag — but snapshotting makes that guarantee explicit).
            const dragZoom = this.zoom || 1;

            Alpine.store('ui').isDragging = true;

            let raf = 0;
            let pendingWidth = null;
            const flush = () => {
                raf = 0;
                if (pendingWidth != null) {
                    Alpine.store('ui').setWidth(`${pendingWidth}px`);
                    pendingWidth = null;
                }
            };

            const move = (e) => {
                const x = e.clientX ?? e.touches?.[0]?.clientX;
                if (x == null) return;
                // Cursor's screen-pixel distance from the parent's center is
                // the wrapper's visible half-width under the cursor. The
                // iframe is emulating a `1 / dragZoom` larger logical viewport,
                // so a screen-px distance of d maps to a logical half-width
                // of d / dragZoom. Without the divide, dragging a 2K preset
                // (zoom ≈ 0.5) would only move logical width by half what
                // the user expected. Clamp to a sensible minimum so the
                // iframe doesn't collapse.
                const half = Math.max(160, (x - centerX) / dragZoom);
                // Snap to whole pixels to avoid sub-pixel flicker.
                pendingWidth = Math.round(half * 2);
                if (!raf) raf = requestAnimationFrame(flush);
            };

            const up = () => {
                if (raf) {
                    cancelAnimationFrame(raf);
                    flush();
                }
                Alpine.store('ui').isDragging = false;
                document.removeEventListener('mousemove', move);
                document.removeEventListener('mouseup', up);
                document.removeEventListener('touchmove', move);
                document.removeEventListener('touchend', up);
            };

            document.addEventListener('mousemove', move);
            document.addEventListener('mouseup', up);
            document.addEventListener('touchmove', move, { passive: true });
            document.addEventListener('touchend', up);
        },
    }));
});
