import { defineStore } from 'pinia';
import { markRaw } from 'vue';

// JavaScript errors reported by the previews (dist/render-relay.js, loaded
// first in templates/render-cell.twig, posts them). Each entry belongs to one <iframe>
// element, and no entry outlives its element: an iframe leaves the DOM on
// every change that matters -- another entry, a theme or locale switch, a
// reload, an unticked compare width, an isolated variant -- and a
// MutationObserver prunes on that. A link clicked inside a preview keeps the
// element but ends the document: the relay's "start" from the next document
// clears the element's entries, and when the link leads to a page without
// the relay (a project page, another site, an error page) the frame's
// `load` does, since no "start" will come.
//
// The checks on a message (same origin, a known iframe, a known shape) keep
// out other windows and accidental collisions. They are not a security
// boundary: a preview's own scripts can post the same messages, and they
// already have full access to this same-origin page anyway.
//
// Where an error happened is read from stamps on the iframe element
// (data-sg-tile, data-sg-label, data-sg-width), put there by PreviewPane,
// VariantGrid, CompareStrip and GridTile. Entries hold the element itself,
// never its Window: a Window in reactive state would be proxied, and an
// element answers `isConnected` without touching the frame.

// What the relay may send. Anything else is ignored, so a page that posts
// its own messages to the parent cannot fill the list.
const KINDS = ['error', 'rejection', 'console', 'resource'];

function frameOf(source) {
    if (!source) return null;
    for (const frame of document.querySelectorAll('iframe')) {
        if (frame.contentWindow === source) return frame;
    }
    return null;
}

function placeOf(frame) {
    const width = parseInt(frame.dataset.sgWidth ?? '', 10);
    return {
        tile: frame.dataset.sgTile ?? '',
        label: frame.dataset.sgLabel ?? '',
        width: Number.isInteger(width) ? width : null,
    };
}

let nextId = 1;

export const useRenderErrorsStore = defineStore('renderErrors', {
    state: () => ({
        entries: [],
        // Frames that hit the relay's cap: their list is not complete.
        truncated: [],
        // Bumped to ask the warning dialog to open (a tile's mark does).
        openRequest: 0,
    }),
    getters: {
        count: (state) => state.entries.length,
        // One mark per tile (and per compare column): how many errors
        // happened there.
        countFor: (state) => (tile, width = null) => state.entries
            .filter((e) => e.tile === tile && (width === null || e.width === width)).length,
        // Identical errors from many tiles or widths read as one row with
        // the list of places, not as 18 rows.
        groups: (state) => {
            const byKey = new Map();
            for (const e of state.entries) {
                const key = [e.kind, e.message, e.source, e.line].join('\u0000');
                if (!byKey.has(key)) {
                    byKey.set(key, { key, kind: e.kind, message: e.message, source: e.source, line: e.line, count: 0, places: [] });
                }
                const group = byKey.get(key);
                group.count++;
                const place = [e.label, e.width ? `${e.width} px` : ''].filter(Boolean).join(' · ');
                if (place && !group.places.includes(place)) group.places.push(place);
            }
            return [...byKey.values()];
        },
        hasTruncated: (state) => state.truncated.some((frame) => frame.isConnected),
    },
    actions: {
        // Called once from main.js, never on import: a listener registered
        // at import time would leak across every spec that imports a store.
        // Returns the teardown.
        listen(target = window) {
            const onMessage = (event) => this.receive(event);
            target.addEventListener('message', onMessage);
            // `load` does not bubble, but a capturing listener on the
            // document sees every iframe's.
            const onLoad = (event) => {
                if (event.target?.nodeName === 'IFRAME') this.frameLoaded(event.target);
            };
            document.addEventListener('load', onLoad, true);
            // A grid re-render removes many nodes at once; one prune per
            // frame is enough, and the check per node stays cheap.
            let scheduled = false;
            const schedule = () => {
                if (scheduled) return;
                scheduled = true;
                const run = () => { scheduled = false; this.prune(); };
                if (typeof requestAnimationFrame === 'function') requestAnimationFrame(run);
                else setTimeout(run, 0);
            };
            const holdsFrame = (node) => node.nodeType === 1
                && (node.nodeName === 'IFRAME' || node.getElementsByTagName('iframe').length > 0);
            const observer = new MutationObserver((records) => {
                if (!this.entries.length && !this.truncated.length) return;
                if (records.some((r) => [...r.removedNodes].some(holdsFrame))) schedule();
            });
            observer.observe(document.body, { childList: true, subtree: true });
            return () => {
                target.removeEventListener('message', onMessage);
                document.removeEventListener('load', onLoad, true);
                observer.disconnect();
            };
        },
        receive(event) {
            if (event.origin !== window.location.origin) return;
            const payload = event.data?.sgRender;
            if (!payload || typeof payload !== 'object') return;
            const frame = frameOf(event.source);
            if (!frame) return;
            if (payload.type === 'start') {
                this.entries = this.entries.filter((e) => e.frame !== frame);
                this.truncated = this.truncated.filter((f) => f !== frame);
                return;
            }
            if (payload.type === 'more') {
                if (!this.truncated.includes(frame)) this.truncated.push(markRaw(frame));
                return;
            }
            if (payload.type !== 'error' || !KINDS.includes(payload.kind)) return;
            this.entries.push({
                id: nextId++,
                frame: markRaw(frame),
                ...placeOf(frame),
                kind: payload.kind,
                message: String(payload.message ?? '').slice(0, 500),
                source: String(payload.source ?? '').slice(0, 500),
                line: Number.isInteger(payload.line) ? payload.line : 0,
            });
        },
        // A frame finished loading a document. With the relay in it, "start"
        // has already cleared the old errors; without it (or across
        // origins, where the document cannot be read) nothing will, so
        // clear them here.
        frameLoaded(frame) {
            let relayed = false;
            try {
                relayed = !!frame.contentDocument?.querySelector('script[src*="/render-relay.js"]');
            } catch {
                relayed = false;
            }
            if (relayed) return;
            if (this.entries.some((e) => e.frame === frame)) this.entries = this.entries.filter((e) => e.frame !== frame);
            this.truncated = this.truncated.filter((f) => f !== frame);
        },
        prune() {
            if (this.entries.some((e) => !e.frame.isConnected)) {
                this.entries = this.entries.filter((e) => e.frame.isConnected);
            }
            this.truncated = this.truncated.filter((f) => f.isConnected);
        },
        requestOpen() {
            this.openRequest++;
        },
    },
});
