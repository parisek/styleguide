import { describe, it, expect, vi, afterEach } from 'vitest';
import {
    resolveContentHeight, entryScrolls, measureContentHeight, observeContentHeight, PROBE_HEIGHT_PX,
    CHROME_VIEWPORT_HEIGHT_PX, MAX_CONTENT_HEIGHT_PX,
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
