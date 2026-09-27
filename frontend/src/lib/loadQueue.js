// Caps how many preview iframes load at once (the overview grid, GridView).
// A render boots the project's CSS and JS; hundreds of them at once would
// starve the page and the PHP server. A tile asks for a slot when it comes
// near the viewport, starts loading when it gets one, and gives the slot back
// when its iframe fires `load` or `error`, when a safety timeout passes, or
// when it leaves the DOM.
//
// Slots go out first come, first served. A tile that scrolls away before its
// turn cancels its request, so a fast scroll never queues the whole catalogue.
export const DEFAULT_LOAD_LIMIT = 6;

export function createLoadQueue(limit = DEFAULT_LOAD_LIMIT) {
    const active = new Set();
    const pending = [];

    function pump() {
        while (active.size < limit && pending.length > 0) {
            const { key, start } = pending.shift();
            active.add(key);
            start();
        }
    }

    return {
        // Idempotent: a key that is already queued or loading is ignored.
        request(key, start) {
            if (active.has(key) || pending.some((p) => p.key === key)) return;
            pending.push({ key, start });
            pump();
        },
        // Frees the key's slot, or drops it from the queue if it had none.
        release(key) {
            const index = pending.findIndex((p) => p.key === key);
            if (index !== -1) pending.splice(index, 1);
            if (active.delete(key)) pump();
        },
        isActive(key) {
            return active.has(key);
        },
        get activeCount() {
            return active.size;
        },
        get pendingCount() {
            return pending.length;
        },
    };
}
