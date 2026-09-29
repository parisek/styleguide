// Must be imported before prismjs: Prism reads `manual` while it loads and
// would otherwise highlight every `language-*` element on DOMContentLoaded.
// The catalogue highlights one panel, on demand.
if (typeof window !== 'undefined') {
    window.Prism = window.Prism || {};
    window.Prism.manual = true;
}
