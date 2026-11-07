import { describe, it, expect, vi, afterEach } from 'vitest';
import {
    resolveContentHeight, entryScrolls, measureContentHeight, observeContentHeight,
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

// jsdom does no layout, so the page shell is a double whose height follows the
// one rule that matters: with its `100dvh` floor in place it is never shorter
// than the iframe viewport.
function shellDocument({ content, viewport, top = 0, children = [] }) {
    const style = document.createElement('div').style;
    const floored = () => !style.getPropertyValue('min-height');
    const height = () => (floored() ? Math.max(content, viewport) : content);
    const shell = {
        style,
        children,
        get offsetHeight() { return height(); },
        get scrollHeight() { return height(); },
        getBoundingClientRect: () => ({ top }),
    };
    const documentElement = {
        get scrollHeight() { return Math.max(viewport, top + height()); },
        getBoundingClientRect: () => ({ top: 0 }),
    };
    return {
        shell,
        doc: {
            documentElement,
            body: { get scrollHeight() { return height(); } },
            querySelector: (sel) => (sel === '[data-sg-page-shell]' ? shell : null),
        },
    };
}

describe('measureContentHeight', () => {
    it('reads the document when there is no page shell', () => {
        const doc = { documentElement: { scrollHeight: 900 }, body: { scrollHeight: 700 }, querySelector: () => null };
        expect(measureContentHeight(doc)).toBe(900);
    });

    it('tolerates a document double without querySelector', () => {
        expect(measureContentHeight({ documentElement: { scrollHeight: 640 }, body: { scrollHeight: 10 } })).toBe(640);
    });

    // #255: content 5540 px in an iframe still 6691 px tall from an earlier,
    // taller paint. The document says 6691; the content is 5540.
    it('measures the content, not the 100dvh floor, inside a page shell', () => {
        const { doc } = shellDocument({ content: 5540, viewport: 6691 });
        expect(doc.documentElement.scrollHeight).toBe(6691);
        expect(measureContentHeight(doc)).toBe(5540);
    });

    it('counts what sits above the shell', () => {
        const { doc } = shellDocument({ content: 500, viewport: 900, top: 24 });
        expect(measureContentHeight(doc)).toBe(524);
    });

    it('restores the shell\'s own inline styles after measuring', () => {
        const { doc, shell } = shellDocument({ content: 500, viewport: 900 });
        shell.style.setProperty('height', '50%');
        measureContentHeight(doc);
        expect(shell.style.getPropertyValue('min-height')).toBe('');
        expect(shell.style.getPropertyValue('height')).toBe('50%');
        expect(shell.style.getPropertyPriority('height')).toBe('');
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

    // Inside the shell, `main` absorbs a shrink by growing, so html, body and
    // the shell keep their size; only the sections inside change.
    it('observes the shell\'s children and grandchildren', () => {
        const instances = recordingObserver();
        const section = {};
        const main = { children: [section] };
        const header = { children: [] };
        const { doc, shell } = shellDocument({ content: 500, viewport: 900, children: [header, main] });
        observeContentHeight(doc, () => {});
        expect(instances[0].targets).toEqual(expect.arrayContaining([shell, header, main, section]));
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

    it('measures again once web fonts are ready', async () => {
        recordingObserver();
        let ready;
        const doc = {
            documentElement: { scrollHeight: 300 }, body: { scrollHeight: 300 },
            fonts: { ready: new Promise((r) => { ready = r; }) },
        };
        const seen = [];
        observeContentHeight(doc, (h) => seen.push(h));
        doc.documentElement.scrollHeight = 320;
        ready();
        await doc.fonts.ready;
        expect(seen).toEqual([300, 320]);
    });
});
