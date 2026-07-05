import { readSpaConfig } from './config.js';

/**
 * Inject axe-core into a same-origin iframe's document (if not already
 * present) and run it. Same-origin because the render endpoint
 * (/styleguide/render/...) is served from the same origin as the SPA shell —
 * contentWindow/contentDocument access works without a postMessage bridge.
 * Runs ONLY on explicit call — no automatic/background scanning.
 *
 * The script src is built from the server-injected `baseUrl` config field
 * (`Styleguide::dispatchSpa()`'s `#sg-config` payload), not a hardcoded
 * `/styleguide/` prefix — mirrors the config-read helper the SPA already
 * uses for its own asset base (see main.js's readSpaConfig() call at boot).
 * readSpaConfig() re-reads the same #sg-config element main.js already
 * parsed once; cheap (a small already-in-DOM script tag) and keeps this
 * module free of any dependency on app boot order.
 */
export function runAxeCheck(iframeEl) {
    const win = iframeEl?.contentWindow;
    const doc = iframeEl?.contentDocument;
    if (!win || !doc) return Promise.reject(new Error('iframe not accessible'));

    if (win.axe) {
        return win.axe.run();
    }

    return new Promise((resolve, reject) => {
        const script = doc.createElement('script');
        script.src = `${readSpaConfig().baseUrl}/assets/axe.min.js`;
        script.onload = () => win.axe.run().then(resolve, reject);
        script.onerror = () => reject(new Error('failed to load axe-core into the iframe'));
        doc.head.appendChild(script);
    });
}
