// Locale code -> human-readable language name, for the sidebar switcher.
//
// Names are ENDONYMS (each language written in itself: Čeština, Deutsch,
// 日本語), not translations into the chrome's UI language. Two reasons, both
// load-bearing:
//
//  1. The chrome ships UI strings for `cs` and `en` only (stores/i18n.js's
//     SUPPORTED), while the switcher offers every discovered `.mo`
//     catalogue. Exonyms would have to live in public/locales/*.json and
//     would therefore be missing for exactly the locales the switcher most
//     needs to label — a Polish visitor on English chrome would read
//     "Polish", the one word on screen that could have been in their own
//     language for free.
//  2. An endonym is the label a reader recognises without already reading
//     the current UI language, which is the whole job of a language picker.
//
// Deliberately NOT Intl.DisplayNames: that returns exonyms in the UI
// language, varies by browser/ICU build, and would make the switcher's
// labels untestable across environments.
const ENDONYMS = {
    ar: 'العربية',
    bg: 'Български',
    bs: 'Bosanski',
    ca: 'Català',
    cs: 'Čeština',
    da: 'Dansk',
    de: 'Deutsch',
    el: 'Ελληνικά',
    en: 'English',
    es: 'Español',
    et: 'Eesti',
    fa: 'فارسی',
    fi: 'Suomi',
    fr: 'Français',
    he: 'עברית',
    hi: 'हिन्दी',
    hr: 'Hrvatski',
    hu: 'Magyar',
    id: 'Bahasa Indonesia',
    is: 'Íslenska',
    it: 'Italiano',
    ja: '日本語',
    ko: '한국어',
    lt: 'Lietuvių',
    lv: 'Latviešu',
    mk: 'Македонски',
    nl: 'Nederlands',
    no: 'Norsk',
    pl: 'Polski',
    pt: 'Português',
    ro: 'Română',
    ru: 'Русский',
    sk: 'Slovenčina',
    sl: 'Slovenščina',
    sq: 'Shqip',
    sr: 'Српски',
    sv: 'Svenska',
    th: 'ไทย',
    tr: 'Türkçe',
    uk: 'Українська',
    vi: 'Tiếng Việt',
    zh: '中文',
};

// Region-specific overrides, for the cases where two catalogues share a
// language subtag but a reader would not accept one name for both. Kept
// small on purpose: only pairs that are conventionally published as
// separate localisations belong here, not every region a language has.
const REGIONAL = {
    pt_br: 'Português (Brasil)',
    zh_cn: '简体中文',
    zh_tw: '繁體中文',
    zh_hk: '繁體中文 (香港)',
};

// Splits any shape the switcher can receive -- 'cs', 'cs_CZ', 'pt-BR',
// 'CS_cz' -- into a lowercased language subtag and region. Locale codes are
// ASCII by spec, so a plain lowercase is safe here (no Turkish-i hazard).
function parts(code) {
    if (typeof code !== 'string' || code === '') return null;
    const [language, region] = code.toLowerCase().split(/[_-]/);
    if (!language) return null;
    return { language, region: region || '' };
}

/**
 * The language's own name for a locale code.
 *
 * Unknown codes come back VERBATIM rather than as a placeholder: a project
 * can ship any `.mo` filename it likes, and a raw `xx_YY` in the switcher
 * is still a usable, honest label — where "Unknown" would be neither.
 *
 * @param {string|null|undefined} code
 * @returns {string}
 */
export function languageName(code) {
    const p = parts(code);
    if (!p) return '';
    const regional = REGIONAL[`${p.language}_${p.region}`];
    if (regional) return regional;
    return ENDONYMS[p.language] ?? code;
}

/**
 * The short label for the closed switcher trigger: 'CS', 'EN'.
 *
 * `offered` is the full set the switcher currently lists. When two of them
 * share a language subtag the label would be ambiguous, so the region is
 * appended for those ('PT-PT' / 'PT-BR') and only for those — the common
 * case stays two characters wide, which is what keeps the trigger from
 * resizing the sidebar footer as the visitor switches.
 *
 * @param {string|null|undefined} code
 * @param {string[]} [offered]
 * @returns {string}
 */
export function shortLabel(code, offered = []) {
    const p = parts(code);
    if (!p) return '';
    const sharesLanguage = offered.filter((o) => parts(o)?.language === p.language).length > 1;
    if (sharesLanguage && p.region) {
        return `${p.language}-${p.region}`.toUpperCase();
    }
    return p.language.toUpperCase();
}
