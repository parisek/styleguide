// Substring match against name (locale-tuned label) AND id (raw slug) so users
// can find a component by either spelling. Diacritics-insensitive via
// NFKD-normalise so "drobeckova" matches "Drobečková navigace". Ported
// verbatim from frontend/components/sidebar.js `matchSearch`/`filterItems`.

export function normalizeForSearch(value) {
    return (value ?? '')
        .toString()
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
}

function matchesNameOrId(item, q) {
    return normalizeForSearch(item?.name).includes(q) || normalizeForSearch(item?.id).includes(q);
}

// `aliases` (ComponentParser::normaliseAliases()): a list of
// `{name, variant}`. Read defensively: a server older than the key sends
// none, and the search must behave exactly as before.
function aliasesOf(item) {
    return Array.isArray(item?.aliases) ? item.aliases.filter((a) => typeof a?.name === 'string') : [];
}

export function matchesQuery(item, query) {
    const q = normalizeForSearch(query).trim();
    if (!q) return true;
    return matchesNameOrId(item, q)
        || aliasesOf(item).some((a) => normalizeForSearch(a.name).includes(q));
}

// The alias a sidebar hit shows as its second line: the first matching
// alias, but only when the name and id did not match on their own. A hit
// by name needs no explanation.
export function matchedAlias(item, query) {
    const q = normalizeForSearch(query).trim();
    if (!q || matchesNameOrId(item, q)) return null;
    return aliasesOf(item).find((a) => normalizeForSearch(a.name).includes(q)) ?? null;
}

export function filterItems(items, query) {
    return items.filter((item) => matchesQuery(item, query));
}

// --- Command-palette scoring (Task 5) -------------------------------------
// ADDITIVE: normalizeForSearch/matchesQuery/filterItems above are untouched
// and keep serving the sidebar's plain substring filter input — a different
// UX (inline filter, no ranking) with its own tests depending on today's
// exact behaviour. scoreEntry() is a new, separate export the command
// palette uses for ranked, multi-field results.

// name is the strongest signal (what a human types to find "the button
// component"), id a close second (developers search by slug too), category
// groups loosely, and description is the weakest (long-form text with the
// most incidental hits).
const SCORE_FIELD_WEIGHTS = { name: 10, id: 6, category: 3, description: 1 };

// Tags themselves (e.g. `<strong>`) must never contribute matchable text —
// only the content between them should be searchable — so tags are replaced
// with a space rather than deleted outright (keeps adjacent words from
// merging into one token).
function stripHtml(html) {
    return (html ?? '').toString().replace(/<[^>]*>/g, ' ');
}

/**
 * Score a catalog entry against a search query across name/id/category/
 * description. 0 = no match on any field (caller drops it from results when
 * query is non-empty). Higher is better; exact > prefix > substring per
 * field, and the field's weight multiplies each tier so e.g. a substring hit
 * on `name` (weight 10) can still outrank a prefix hit on `description`
 * (weight 1) — matching the intuition that names matter most regardless of
 * match quality. Diacritic folding and lowercasing both reuse
 * normalizeForSearch so the palette and the sidebar filter never disagree on
 * what counts as "the same letter".
 */
export function scoreEntry(query, entry) {
    const q = normalizeForSearch(query).trim();
    if (q === '') return 0;

    let score = 0;
    for (const [field, weight] of Object.entries(SCORE_FIELD_WEIGHTS)) {
        const raw = field === 'description' ? stripHtml(entry?.description) : entry?.[field];
        score = Math.max(score, scoreText(q, raw, weight));
    }
    return score;
}

// Exact > prefix > substring, times the field weight. `q` is already folded.
function scoreText(q, raw, weight) {
    const folded = normalizeForSearch(raw);
    if (folded === '') return 0;
    if (folded === q) return weight * 3;
    if (folded.startsWith(q)) return weight * 2;
    if (folded.includes(q)) return weight;
    return 0;
}

// Between name (10) and id (6): an alias is a name a human types, but a
// second one.
const ALIAS_WEIGHT = 8;

/**
 * The palette rows one entry contributes: `{score, alias}` each.
 *
 * - `alias: null` is the entry itself, ranked by scoreEntry().
 * - Every alias with a `variant` that matches is its own row: it opens a
 *   different tile, so "Layout 238" and "Layout 239" of one family must not
 *   collapse into one hit.
 * - An alias without a variant opens the entry, so it adds a row only when
 *   nothing else of the entry matched, and only once.
 */
export function paletteHits(query, entry) {
    const q = normalizeForSearch(query).trim();
    if (q === '') return [];

    const rows = [];
    const base = scoreEntry(query, entry);
    if (base > 0) rows.push({ score: base, alias: null });

    let plainAliasShown = base > 0;
    for (const alias of aliasesOf(entry)) {
        const score = scoreText(q, alias.name, ALIAS_WEIGHT);
        if (score === 0) continue;
        if (alias.variant) {
            rows.push({ score, alias: { name: alias.name, variant: alias.variant } });
        } else if (!plainAliasShown) {
            plainAliasShown = true;
            rows.push({ score, alias: { name: alias.name, variant: null } });
        }
    }
    return rows;
}
