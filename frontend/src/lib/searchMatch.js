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
