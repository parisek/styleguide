import { url } from './runtimeConfig.js';

// The render-endpoint URL of one preview iframe. Shared by the single
// preview and the variant grid (useViewportPreset.js buildIframeSrc()) and
// by the overview grid (GridView.vue), so every iframe composes the same
// query in the same order and a grid tile shows what the preview would.
//
// Each query key is appended only when it differs from the default, so the
// historical bare URL (`render/component/card`) is unchanged for a visitor
// who never touches a toggle:
// - `_r`: the reload nonce, a cache-buster for the Reload button;
// - `theme=dark`: the iframe content theme (never the SPA chrome's theme);
// - `variant`: a `styleguide.<variant>.twig` sibling;
// - `locale`: the content locale, only when it differs from the server's
//   `default_locale` (a project without translations never sees it).
//
// Returns null for a route that renders no iframe.
export function buildRenderSrc({
    type, slug, variant = null, theme = 'light', contentLocale = '', defaultLocale = '', reloadNonce = 0,
}) {
    let src;
    if (type === 'foundations' || type === 'icons') {
        src = url(`render/${type}/index`);
    } else if (!slug || !['component', 'page', 'doc'].includes(type)) {
        return null;
    } else {
        src = url(`render/${type}/${slug}`);
    }
    const append = (pair) => { src += (src.includes('?') ? '&' : '?') + pair; };
    if (reloadNonce) append(`_r=${reloadNonce}`);
    if (theme === 'dark') append('theme=dark');
    if (variant) append(`variant=${encodeURIComponent(variant)}`);
    if (defaultLocale && contentLocale && contentLocale !== defaultLocale) {
        append(`locale=${encodeURIComponent(contentLocale)}`);
    }
    return src;
}
