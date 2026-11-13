import { describe, it, expect, vi, afterEach } from 'vitest';
import {
    resolveContentHeight, entryScrolls, measureContentHeight, observeContentHeight, PROBE_HEIGHT_PX,
    CHROME_VIEWPORT_HEIGHT_PX, MAX_CONTENT_HEIGHT_PX, followsViewport, viewportGrowth,
    VIEWPORT_PROBE_LOW_PX, VIEWPORT_PROBE_HIGH_PX, VIEWPORT_TOLERANCE_PX, isComponentRender,
    pageGrowth, designedViewportHeight,
} from './previewHeight.js';

// The measurement a runaway chrome surface actually reported before the fix
// (2^25, the browser's element-height clamp).
const RUNAWAY = 33554400;

describe('resolveContentHeight', () => {
    it('ignores the measurement entirely for a chrome surface', () => {
        expect(resolveContentHeight({ rawContentHeight: RUNAWAY, scrolls: true }))
            .toBe(CHROME_VIEWPORT_HEIGHT_PX);
    });

    it('pins a chrome surface even before any measurement exists', () => {
        expect(resolveContentHeight({ rawContentHeight: null, minHeight: 96, scrolls: true }))
            .toBe(CHROME_VIEWPORT_HEIGHT_PX);
    });

    it('measures normally for a non-chrome surface', () => {
        expect(resolveContentHeight({ rawContentHeight: 1234, minHeight: 96 })).toBe(1234);
    });

    it('falls back to minHeight before the first measurement', () => {
        expect(resolveContentHeight({ rawContentHeight: null, minHeight: 96 })).toBe(96);
    });

    it('caps an absurd measurement without truncating a long-but-real fixture', () => {
        expect(resolveContentHeight({ rawContentHeight: RUNAWAY })).toBe(MAX_CONTENT_HEIGHT_PX);
        expect(resolveContentHeight({ rawContentHeight: 8000 })).toBe(8000);
    });
});

// One predicate for both surfaces, so the grid and the single preview cannot
// disagree about what counts as chrome — the drift that made the first fix for
// #116 incomplete.
describe('entryScrolls', () => {
    it('is true only for the chrome render mode', () => {
        expect(entryScrolls({ render: 'chrome' })).toBe(true);
        for (const render of ['inset', 'bleed', 'overlay']) {
            expect(entryScrolls({ render })).toBe(false);
        }
    });

    it('treats an absent, unknown or nullish entry as non-chrome', () => {
        expect(entryScrolls({})).toBe(false);
        expect(entryScrolls({ render: 'some-future-mode' })).toBe(false);
        expect(entryScrolls(null)).toBe(false);
        expect(entryScrolls(undefined)).toBe(false);
    });
});

// jsdom does no layout, so a shelled page is a double that follows the rules
// that matter: the viewport is the iframe element's inline height, a `100dvh`
// floor (on the shell or body) resolves to it, the injected floor rule takes
// `surround` off it, and the document is never shorter than its viewport.
function shelledPage({ content, surround = 0, frameHeight = 900, floor = true }) {
    const frame = document.createElement('iframe');
    frame.style.height = `${frameHeight}px`;
    const viewport = () => parseFloat(frame.style.height) || 0;
    const injected = { el: null };
    const head = { appendChild: (el) => { injected.el = el; } };
    const fixed = () => {
        const m = injected.el?.textContent?.match(/100dvh - (\d+)px/);
        return m ? Number(m[1]) : 0;
    };
    const shellHeight = () => (floor ? Math.max(content, viewport() - fixed()) : content);
    const shell = {
        get offsetHeight() { return shellHeight(); },
    };
    const doc = {
        head,
        defaultView: {
            frameElement: frame,
            get innerHeight() { return viewport(); },
            getComputedStyle: () => ({ minHeight: floor ? `${viewport()}px` : '0px' }),
        },
        documentElement: { get scrollHeight() { return Math.max(viewport(), surround + shellHeight()); } },
        body: { get scrollHeight() { return surround + shellHeight(); } },
        querySelector: (sel) => (sel === '[data-sg-page-shell]' ? shell : null),
        getElementById: (id) => (injected.el?.id === id ? injected.el : null),
        createElement: (tag) => document.createElement(tag),
    };
    return { doc, frame, injected };
}

describe('measureContentHeight', () => {
    it('reads the document when there is no page shell', () => {
        const doc = { documentElement: { scrollHeight: 900 }, body: { scrollHeight: 700 }, querySelector: () => null };
        expect(measureContentHeight(doc)).toBe(900);
    });

    it('tolerates a document double without querySelector', () => {
        expect(measureContentHeight({ documentElement: { scrollHeight: 640 }, body: { scrollHeight: 10 } })).toBe(640);
    });

    // Content 5540 px in an iframe still 6691 px tall from an earlier, taller
    // paint. The document says 6691; the content is 5540.
    it('measures a shelled page at the probe height, not at the iframe height', () => {
        const { doc } = shelledPage({ content: 5540, frameHeight: 6691 });
        expect(doc.documentElement.scrollHeight).toBe(6691);
        expect(measureContentHeight(doc)).toBe(5540);
    });

    it('counts what the document keeps around the shell', () => {
        const { doc } = shelledPage({ content: 500, surround: 64 });
        expect(measureContentHeight(doc)).toBe(564);
    });

    it('puts the iframe element back exactly as it was', () => {
        const { doc, frame } = shelledPage({ content: 500 });
        frame.style.setProperty('min-height', '400px', 'important');
        measureContentHeight(doc);
        expect(frame.style.getPropertyValue('height')).toBe('900px');
        expect(frame.style.getPropertyPriority('height')).toBe('');
        expect(frame.style.getPropertyValue('min-height')).toBe('400px');
        expect(frame.style.getPropertyPriority('min-height')).toBe('important');
    });

    it('probes at zero, so no floor in the page can add height', () => {
        expect(PROBE_HEIGHT_PX).toBe(0);
    });
});

describe('observeContentHeight', () => {
    afterEach(() => vi.unstubAllGlobals());

    function recordingObserver() {
        const instances = [];
        class Recording {
            constructor(cb) { this.cb = cb; this.targets = []; this.disconnected = false; instances.push(this); }
            observe(t) { this.targets.push(t); }
            disconnect() { this.disconnected = true; }
        }
        vi.stubGlobal('ResizeObserver', Recording);
        return instances;
    }

    it('reports the first measure synchronously and every later one', () => {
        const instances = recordingObserver();
        const doc = { documentElement: { scrollHeight: 300 }, body: { scrollHeight: 300 } };
        const seen = [];
        observeContentHeight(doc, (h) => seen.push(h));
        doc.documentElement.scrollHeight = 450;
        instances[0].cb();
        expect(seen).toEqual([300, 450]);
    });

    // With the iframe at the measured height, a 100dvh floor would hold the
    // shell `surround` px taller than its content: a band above the footer.
    it('takes the surround off a viewport floor so the shell stays flush', () => {
        recordingObserver();
        const { doc, frame, injected } = shelledPage({ content: 1000, surround: 64 });
        observeContentHeight(doc, (h) => { frame.style.height = `${h}px`; });
        expect(frame.style.height).toBe('1064px');
        expect(injected.el.textContent).toBe('[data-sg-page-shell]{min-height:calc(100dvh - 64px) !important}');
        expect(doc.body.scrollHeight).toBe(1064);
    });

    it('leaves a shell without a viewport floor alone', () => {
        recordingObserver();
        const { doc, injected } = shelledPage({ content: 1000, surround: 64, floor: false });
        observeContentHeight(doc, () => {});
        expect(injected.el).toBeNull();
    });

    // Inside a shelled page `main` absorbs a shrink by growing, so no box above
    // it changes size; a DOM change is what announces it.
    it('measures again after a DOM change in a shelled page, once per frame', () => {
        recordingObserver();
        const { doc } = shelledPage({ content: 1500 });
        const observers = [];
        const frames = [];
        doc.defaultView.MutationObserver = class {
            constructor(cb) { this.cb = cb; observers.push(this); }
            observe() {}
            disconnect() {}
        };
        doc.defaultView.requestAnimationFrame = (cb) => frames.push(cb);
        const seen = [];
        observeContentHeight(doc, (h) => seen.push(h));
        expect(observers).toHaveLength(1);
        observers[0].cb();
        observers[0].cb();
        expect(frames).toHaveLength(1);
        frames[0]();
        expect(seen).toEqual([1500, 1500]);
    });

    it('stops reporting once disconnected', () => {
        const instances = recordingObserver();
        const doc = { documentElement: { scrollHeight: 300 }, body: { scrollHeight: 300 } };
        const seen = [];
        const handle = observeContentHeight(doc, (h) => seen.push(h));
        handle.disconnect();
        instances[0].cb();
        expect(seen).toEqual([300]);
        expect(instances[0].disconnected).toBe(true);
    });

    // `fonts.ready` is replaced each time a new batch starts loading; the event
    // fires for every batch.
    it('measures again after every web-font batch', () => {
        recordingObserver();
        const listeners = {};
        const doc = {
            documentElement: { scrollHeight: 300 }, body: { scrollHeight: 300 },
            fonts: {
                addEventListener: (type, cb) => { listeners[type] = cb; },
                removeEventListener: (type) => { delete listeners[type]; },
            },
        };
        const seen = [];
        const handle = observeContentHeight(doc, (h) => seen.push(h));
        doc.documentElement.scrollHeight = 320;
        listeners.loadingdone();
        doc.documentElement.scrollHeight = 340;
        listeners.loadingdone();
        expect(seen).toEqual([300, 320, 340]);
        handle.disconnect();
        expect(listeners.loadingdone).toBeUndefined();
    });
});

// jsdom does no layout, so a component render is a double that follows the
// rules that matter: the viewport is the iframe element's inline height, a
// child with a viewport floor is max(content, viewport), the document is never
// shorter than its viewport, and `surround` is what the document holds around
// the children (the inset padding).
function componentRender({ children, surround = 0, frameHeight = 400 }) {
    const frame = document.createElement('iframe');
    frame.style.height = `${frameHeight}px`;
    const viewport = () => parseFloat(frame.style.height) || 0;
    const childHeight = (c) => (c.floor ? Math.max(c.content, viewport()) : c.content);
    const elements = children.map((c) => ({
        spec: c,
        getBoundingClientRect: () => ({ height: childHeight(c) }),
    }));
    const contentHeight = () => surround + elements.reduce((sum, el) => sum + (el.spec.fixed ? 0 : childHeight(el.spec)), 0);
    const doc = {
        defaultView: {
            frameElement: frame,
            getComputedStyle: (el) => ({
                position: el.spec?.fixed ? 'fixed' : 'static',
                display: el.contents ? 'contents' : 'block',
            }),
        },
        documentElement: { get scrollHeight() { return Math.max(viewport(), contentHeight()); } },
        body: {
            children: elements,
            get scrollHeight() { return contentHeight(); },
        },
        querySelector: () => null,
    };
    return { doc, frame };
}

describe('viewportGrowth', () => {
    it('names how much the iframe and the content grew between the probes', () => {
        const { doc } = componentRender({ children: [{ content: 300, floor: true }], surround: 48 });
        expect(viewportGrowth(doc)).toEqual({
            follows: true,
            viewportDeltaPx: VIEWPORT_PROBE_HIGH_PX - VIEWPORT_PROBE_LOW_PX,
            contentDeltaPx: VIEWPORT_PROBE_HIGH_PX - VIEWPORT_PROBE_LOW_PX,
        });
    });

    // An 880 px root meets its floor only above 880, so it grows by 320.
    it('reports the growth of the root that grew most', () => {
        const { doc } = componentRender({ children: [{ content: 120 }, { content: 880, floor: true }] });
        expect(viewportGrowth(doc)).toMatchObject({ follows: true, contentDeltaPx: VIEWPORT_PROBE_HIGH_PX - 880 });
    });

    it('reports a short component as not following, with no growth', () => {
        const { doc } = componentRender({ children: [{ content: 120 }], surround: 48 });
        expect(viewportGrowth(doc)).toMatchObject({ follows: false, contentDeltaPx: 0 });
    });

    it('has no answer where followsViewport has none', () => {
        expect(viewportGrowth(componentRender({ children: [{ content: 0 }] }).doc)).toBeNull();
    });
});

describe('pageGrowth', () => {
    it('reports how much a page grows when shown at its own height', () => {
        const { doc } = componentRender({ children: [{ content: 500, floor: true }, { content: 1000 }], frameHeight: 0 });
        expect(pageGrowth(doc, 1500)).toEqual({ follows: true, viewportDeltaPx: 1500, contentDeltaPx: 1000 });
    });

    // Any growth beyond the tolerance counts: a frame floor with something
    // around it would cut that much off the bottom of the page.
    it('counts a frame floor with a surround', () => {
        const { doc } = componentRender({ children: [{ content: 1600, floor: true }], surround: 112, frameHeight: 0 });
        expect(pageGrowth(doc, 1712)).toEqual({ follows: true, viewportDeltaPx: 1712, contentDeltaPx: 112 });
        const short = componentRender({ children: [{ content: 80 }, { content: 300, floor: true }, { content: 200 }], frameHeight: 0 });
        expect(pageGrowth(short.doc, 580).follows).toBe(true);
    });

    it('does not count a frame floor the content fills', () => {
        const { doc } = componentRender({ children: [{ content: 1600, floor: true }], frameHeight: 0 });
        expect(pageGrowth(doc, 1600)).toEqual({ follows: false, viewportDeltaPx: 1600, contentDeltaPx: 0 });
    });

    it('needs more than the tolerance', () => {
        const { doc } = componentRender({ children: [{ content: 40, floor: true }], surround: 60, frameHeight: 0 });
        expect(pageGrowth(doc, 100)).toEqual({ follows: false, viewportDeltaPx: 100, contentDeltaPx: 60 });
    });

    it('allows a few pixels of reflow', () => {
        const { doc } = componentRender({ children: [{ content: 1500 }], frameHeight: 0 });
        expect(pageGrowth(doc, 1500).follows).toBe(false);
    });

    it('has no answer without a frame or a height', () => {
        expect(pageGrowth({ defaultView: {} }, 900)).toBeNull();
        const { doc } = componentRender({ children: [{ content: 100 }] });
        expect(pageGrowth(doc, 0)).toBeNull();
    });
});

describe('designedViewportHeight', () => {
    it('takes the preset height first', () => {
        expect(designedViewportHeight({ width: 1440, height: 1024 })).toBe(1024);
    });

    it('takes the catalogue frame for a desktop width without a height', () => {
        expect(designedViewportHeight({ width: 1440 })).toBe(900);
        expect(designedViewportHeight({ width: 1920 })).toBe(1200);
    });

    // At 1024 px and below a pinned preview is a device screen, not a square.
    it('takes the device screen for a phone or tablet width', () => {
        expect(designedViewportHeight({ width: 320 })).toBe(568);
        expect(designedViewportHeight({ width: 375 })).toBe(667);
        expect(designedViewportHeight({ width: 768 })).toBe(1024);
        expect(designedViewportHeight({ width: 900 })).toBe(1201);
        expect(designedViewportHeight({ width: 360 })).toBe(640);
        // 1024 px is a tablet, as in the catalogue grid; above it the 16:10 frame.
        expect(designedViewportHeight({ width: 1024 })).toBe(1366);
        expect(designedViewportHeight({ width: 1025 })).toBe(641);
    });

    it('falls back to the chrome height without a width', () => {
        expect(designedViewportHeight()).toBe(CHROME_VIEWPORT_HEIGHT_PX);
        expect(designedViewportHeight({ width: null, height: null })).toBe(CHROME_VIEWPORT_HEIGHT_PX);
    });
});

describe('resolveContentHeight with a pinned height', () => {
    it('pins a chrome surface at the given height', () => {
        expect(resolveContentHeight({ rawContentHeight: 5000, scrolls: true, pinnedHeight: 900 })).toBe(900);
    });
});

describe('followsViewport', () => {
    it('is true for a root with a viewport floor', () => {
        const { doc } = componentRender({ children: [{ content: 300, floor: true }], surround: 48 });
        expect(followsViewport(doc)).toBe(true);
    });

    // The document of a short component is as tall as its viewport at either
    // probe: asking the document flagged 64 of 98 real components in #133, 62 of
    // them wrongly.
    it('is false for a short component, whose document still fills the viewport', () => {
        const { doc } = componentRender({ children: [{ content: 120 }], surround: 48 });
        expect(doc.documentElement.scrollHeight).toBe(400);
        expect(followsViewport(doc)).toBe(false);
    });

    it('is false for a floor the content outgrows at both probes', () => {
        const { doc } = componentRender({ children: [{ content: 1959, floor: true }] });
        expect(followsViewport(doc)).toBe(false);
    });

    it('is true when content meets its floor between the probes', () => {
        const { doc } = componentRender({ children: [{ content: 880, floor: true }] });
        expect(followsViewport(doc)).toBe(true);
    });

    // A body that is a flex row or a grid with a viewport min-height stretches
    // the inset wrapper with the viewport; the component inside keeps 120 px.
    it('asks the component inside the inset wrapper, not the stretched wrapper', () => {
        const { doc, frame } = componentRender({ children: [{ content: 168, floor: true }] });
        const root = { getBoundingClientRect: () => ({ height: 120 }) };
        const wrapper = { children: [root] };
        doc.querySelector = (sel) => (sel === '[data-sg-inset]' ? wrapper : null);
        expect(parseFloat(frame.style.height)).toBe(400);
        expect(followsViewport(doc)).toBe(false);
    });

    // A `display: contents` root has no box and measures 0; its children are
    // the roots. Read as empty, it fed the plain document measure back into the
    // frame and the preview grew on every pass.
    it('asks the children of a display: contents root', () => {
        const { doc, frame } = componentRender({ children: [{ content: 120, floor: true }] });
        const inner = doc.body.children[0];
        const contents = { contents: true, children: [inner], getBoundingClientRect: () => ({ height: 0 }) };
        const wrapper = { children: [contents] };
        doc.querySelector = (sel) => (sel === '[data-sg-inset]' ? wrapper : null);
        expect(parseFloat(frame.style.height)).toBe(400);
        expect(followsViewport(doc)).toBe(true);
    });

    it('ignores a fixed overlay that fills the viewport', () => {
        const { doc } = componentRender({ children: [{ content: 0, floor: true, fixed: true }, { content: 200 }] });
        expect(followsViewport(doc)).toBe(false);
    });

    it('has no answer for content that measures zero, or without a frame', () => {
        expect(followsViewport(componentRender({ children: [{ content: 0 }] }).doc)).toBeNull();
        expect(followsViewport(componentRender({ children: [] }).doc)).toBeNull();
        expect(followsViewport({ documentElement: { scrollHeight: 300 }, body: { scrollHeight: 300 } })).toBeNull();
    });

    it('allows a reflow of a line or two between the probes', () => {
        const { doc, frame } = componentRender({ children: [{ content: 600 }] });
        const el = doc.body.children[0];
        el.getBoundingClientRect = () => ({ height: parseFloat(frame.style.height) < 800 ? 640 : 600 });
        expect(followsViewport(doc)).toBe(false);
        expect(VIEWPORT_TOLERANCE_PX).toBeLessThan(VIEWPORT_PROBE_HIGH_PX - VIEWPORT_PROBE_LOW_PX);
    });

    it('puts the iframe element back exactly as it was', () => {
        const { doc, frame } = componentRender({ children: [{ content: 300, floor: true }], frameHeight: 787 });
        frame.style.setProperty('max-height', '900px', 'important');
        followsViewport(doc);
        expect(frame.style.getPropertyValue('height')).toBe('787px');
        expect(frame.style.getPropertyValue('min-height')).toBe('');
        expect(frame.style.getPropertyValue('max-height')).toBe('900px');
        expect(frame.style.getPropertyPriority('max-height')).toBe('important');
    });
});

describe('observeContentHeight with a viewport-unit component', () => {
    afterEach(() => vi.unstubAllGlobals());

    function stubResizeObserver() {
        const instances = [];
        vi.stubGlobal('ResizeObserver', class {
            constructor(cb) { this.cb = cb; instances.push(this); }
            observe() {}
            disconnect() {}
        });
        return instances;
    }

    // The repro: the inner document stayed 48 px taller than the iframe it was
    // measured from, and each measure added the same 48 px.
    it('keeps one height however often it measures', () => {
        const instances = stubResizeObserver();
        const { doc, frame } = componentRender({ children: [{ content: 300, floor: true }], surround: 48, frameHeight: 400 });
        const seen = [];
        observeContentHeight(doc, (h, info) => {
            seen.push([h, info.followsViewport]);
            frame.style.height = `${h}px`;
        });
        for (let i = 0; i < 5; i++) instances[0].cb();
        expect(seen).toHaveLength(6);
        expect(new Set(seen.map(([h]) => h))).toEqual(new Set([CHROME_VIEWPORT_HEIGHT_PX]));
        expect(seen.every(([, follows]) => follows === true)).toBe(true);
    });

    it('passes the measured deltas with a pinned height', () => {
        stubResizeObserver();
        const { doc, frame } = componentRender({ children: [{ content: 880, floor: true }], frameHeight: 400 });
        const seen = [];
        observeContentHeight(doc, (h, info) => { seen.push([h, info]); frame.style.height = `${h}px`; });
        expect(seen).toEqual([[CHROME_VIEWPORT_HEIGHT_PX, {
            followsViewport: true,
            kind: 'component',
            pinnedPx: CHROME_VIEWPORT_HEIGHT_PX,
            viewportDeltaPx: VIEWPORT_PROBE_HIGH_PX - VIEWPORT_PROBE_LOW_PX,
            contentDeltaPx: VIEWPORT_PROBE_HIGH_PX - 880,
        }]]);
    });

    // A page render keeps its content height, so a wrapper with a viewport min-height is
    // measured at zero height instead of pinned.
    it('does not pin a page render', () => {
        stubResizeObserver();
        const { doc, frame } = componentRender({ children: [{ content: 700, floor: true }], frameHeight: 400 });
        doc.defaultView.location = { pathname: '/styleguide/render/page/landing' };
        const seen = [];
        observeContentHeight(doc, (h, info) => { seen.push([h, info.followsViewport]); frame.style.height = `${h}px`; });
        expect(seen).toEqual([[700, false]]);
    });

    // The downstream case: a hero with a `100svh` floor in the middle of a page.
    // Shown at its content height, the hero would fill the whole iframe and
    // push the rest of the page below it, so the page is pinned at the
    // surface's designed viewport instead.
    it('pins a page with a viewport-floor section at the designed height', () => {
        const instances = stubResizeObserver();
        const { doc, frame } = componentRender({
            children: [{ content: 80 }, { content: 500, floor: true }, { content: 2400 }],
            frameHeight: 400,
        });
        doc.defaultView.location = { pathname: '/styleguide/render/page/home' };
        const seen = [];
        observeContentHeight(doc, (h, info) => { seen.push([h, info]); frame.style.height = `${h}px`; }, { pinnedHeight: 900 });
        for (let i = 0; i < 3; i++) instances[0].cb();
        expect(new Set(seen.map(([h]) => h))).toEqual(new Set([900]));
        expect(seen[0][1]).toEqual({ followsViewport: true, kind: 'page', pinnedPx: 900, viewportDeltaPx: 2980, contentDeltaPx: 2480 });
    });

    // The width menu changes the frame without a new load: the next measure
    // takes the frame of that moment.
    it('reads a pinned height given as a function on every measure', () => {
        const instances = stubResizeObserver();
        const { doc, frame } = componentRender({ children: [{ content: 300, floor: true }], frameHeight: 400 });
        let designed = 900;
        const seen = [];
        observeContentHeight(doc, (h, info) => { seen.push([h, info.pinnedPx]); frame.style.height = `${h}px`; }, { pinnedHeight: () => designed });
        designed = 768;
        instances[0].cb();
        expect(seen).toEqual([[900, 900], [768, 768]]);
    });

    // An app shell: the one root is `height: 100vh`, so it measures zero at
    // the zero-height probe. It is asked as a component and pinned.
    it('pins a page whose only root has a viewport height', () => {
        const instances = stubResizeObserver();
        const { doc, frame } = componentRender({ children: [{ content: 0, floor: true }], surround: 8, frameHeight: 400 });
        doc.defaultView.location = { pathname: '/styleguide/render/page/app' };
        const seen = [];
        observeContentHeight(doc, (h, info) => { seen.push([h, info.kind]); frame.style.height = `${h}px`; }, { pinnedHeight: 900 });
        for (let i = 0; i < 3; i++) instances[0].cb();
        expect(new Set(seen.map(([h]) => h))).toEqual(new Set([900]));
        expect(seen[0][1]).toBe('viewport');
    });

    // A skip link or a cookie banner next to the shell leaves it a shell.
    it('pins an app shell next to another root', () => {
        stubResizeObserver();
        const { doc, frame } = componentRender({ children: [{ content: 1 }, { content: 0, floor: true }], surround: 8, frameHeight: 400 });
        doc.defaultView.location = { pathname: '/styleguide/render/page/app' };
        const seen = [];
        observeContentHeight(doc, (h, info) => { seen.push([h, info.kind]); frame.style.height = `${h}px`; }, { pinnedHeight: 900 });
        expect(seen).toEqual([[900, 'viewport']]);
    });

    // A page frame with a viewport floor (a wrapper, a body grid) fills the
    // viewport by design: at its content height it stays there.
    it('keeps a page whose only viewport floor is its own frame', () => {
        stubResizeObserver();
        const { doc, frame } = componentRender({ children: [{ content: 2400, floor: true }], frameHeight: 400 });
        doc.defaultView.location = { pathname: '/styleguide/render/page/about' };
        const seen = [];
        observeContentHeight(doc, (h, info) => { seen.push([h, info.followsViewport]); frame.style.height = `${h}px`; }, { pinnedHeight: 900 });
        expect(seen).toEqual([[2400, false]]);
    });

    it('tells a page render from a component render by its path', () => {
        const at = (pathname) => ({ defaultView: { location: { pathname } } });
        expect(isComponentRender(at('/styleguide/render/component/card'))).toBe(true);
        expect(isComponentRender(at('/tools/ui/render/page/about'))).toBe(false);
        expect(isComponentRender({})).toBe(true);
    });

    it('still reports the content height of a short component', () => {
        stubResizeObserver();
        const { doc, frame } = componentRender({ children: [{ content: 120 }], surround: 48, frameHeight: 96 });
        const seen = [];
        observeContentHeight(doc, (h, info) => {
            seen.push([h, info.followsViewport]);
            frame.style.height = `${h}px`;
        });
        expect(seen).toEqual([[168, false]]);
    });

    // The single preview starts at a 400 px floor: the document reads 400, the
    // content is 168.
    it('measures a short component below the height its iframe has now', () => {
        stubResizeObserver();
        const { doc } = componentRender({ children: [{ content: 120 }], surround: 48, frameHeight: 400 });
        expect(doc.documentElement.scrollHeight).toBe(400);
        expect(measureContentHeight(doc)).toBe(168);
    });

    it('keeps the plain document measure for content that is all out of flow', () => {
        const { doc } = componentRender({ children: [{ content: 0, floor: true, fixed: true }], frameHeight: 500 });
        expect(measureContentHeight(doc)).toBe(500);
    });

    // The inset padding keeps the document at 48 px at the probe; the roots
    // inside the wrapper are what is empty.
    it('keeps the plain document measure for out-of-flow content inside the inset wrapper', () => {
        const { doc } = componentRender({ children: [{ content: 0, floor: true, fixed: true }], surround: 48, frameHeight: 400 });
        expect(measureContentHeight(doc)).toBe(400);
    });
});
