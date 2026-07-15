// Group a section's items into a prefix tree. A display name shaped
// "<Prefix> - <Suffix>" joins a bucket keyed by <Prefix>; a bucket with >=
// GROUP_MIN members becomes a collapsible `group` node (each child carries
// `leaf` = the suffix), otherwise its members spill back to flat `item` nodes
// carrying the full name. Names without " - " are always flat. Pure
// derivation from `name` — no metadata involved.
//
// SERVER ORDER IS PRESERVED — no client-side re-sort. The API lists are
// already sorted by front-comment `weight` with a cs-collation name
// tiebreak (ComponentParser::parseAll()), so re-sorting here alphabetically
// silently discarded every authored `weight:` (pages like "Hlavní strana"
// weight 1 rendered after "404"). Default-weight items keep their
// alphabetical order because the server tiebreak already produced it. A
// group node sits where its first member first appears; children keep the
// server order too (weighted variants stay in authored order). `sortKey`
// stays on the nodes for callers/tests that identify nodes by it.

export const GROUP_MIN = 3;

export function buildTree(list) {
    const buckets = new Map();
    for (const it of list) {
        const name = it.name ?? it.id;
        const sep = name.indexOf(' - ');
        const prefix = sep > 0 ? name.slice(0, sep) : null;
        const key = prefix ?? ` ${it.id}`;
        if (!buckets.has(key)) buckets.set(key, { prefix, items: [] });
        buckets.get(key).items.push(it);
    }
    const nodes = [];
    for (const b of buckets.values()) {
        if (b.prefix && b.items.length >= GROUP_MIN) {
            const children = b.items.map((it) => {
                const name = it.name ?? it.id;
                const suffix = name.slice(name.indexOf(' - ') + 3);
                return { ...it, leaf: suffix.charAt(0).toUpperCase() + suffix.slice(1) };
            });
            nodes.push({ type: 'group', label: b.prefix, sortKey: b.prefix, children });
        } else {
            for (const it of b.items) nodes.push({ type: 'item', item: it, sortKey: it.name ?? it.id });
        }
    }
    return nodes;
}
