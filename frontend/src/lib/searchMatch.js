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

export function matchesQuery(item, query) {
    const q = normalizeForSearch(query).trim();
    if (!q) return true;
    return normalizeForSearch(item?.name).includes(q) || normalizeForSearch(item?.id).includes(q);
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
        const folded = normalizeForSearch(raw);
        if (folded === '') continue;
        if (folded === q) score = Math.max(score, weight * 3);
        else if (folded.startsWith(q)) score = Math.max(score, weight * 2);
        else if (folded.includes(q)) score = Math.max(score, weight);
    }
    return score;
}
