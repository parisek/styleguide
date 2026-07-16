// Depth-first walk over the canonical fields LIST from /api/components /
// /api/fields (docs/API.md § Fields; ADR-0002). Core keys become row
// properties; every other authored key (open contract) is collected into
// `extras` so the UI can render the verbatim detail without knowing the
// vocabulary.
const CORE_KEYS = new Set(['key', 'label', 'type', 'description', 'required', 'children']);

export function flattenFieldsTree(list, depth = 0, parentPath = '') {
    if (!Array.isArray(list)) return [];
    const rows = [];
    for (const field of list) {
        if (!field || typeof field !== 'object' || typeof field.key !== 'string') continue;
        const path = parentPath ? `${parentPath}.${field.key}` : field.key;
        const extras = {};
        for (const [k, v] of Object.entries(field)) {
            if (!CORE_KEYS.has(k)) extras[k] = v;
        }
        rows.push({
            path,
            key: field.key,
            depth,
            type: typeof field.type === 'string' ? field.type : '',
            label: typeof field.label === 'string' ? field.label : '',
            description: typeof field.description === 'string' ? field.description : '',
            required: !!field.required,
            extras,
            hasExtras: Object.keys(extras).length > 0,
        });
        if (Array.isArray(field.children)) {
            rows.push(...flattenFieldsTree(field.children, depth + 1, path));
        }
    }
    return rows;
}

// Moved from FieldsDrawer.vue so FieldsView shares one vocabulary. Unknown
// types fall back to a neutral pill — the contract is open-ended by design.
const TYPE_PILL_CLASSES = {
    // legacy doctrine
    array: 'bg-purple-500/20 text-purple-300',
    object: 'bg-pink-500/20 text-pink-300',
    textarea: 'bg-red-500/20 text-red-300',
    image: 'bg-emerald-500/20 text-emerald-300',
    // shared / definition-kit abstract types
    text: 'bg-blue-500/20 text-blue-300',
    richtext: 'bg-sky-500/20 text-sky-300',
    number: 'bg-cyan-500/20 text-cyan-300',
    boolean: 'bg-teal-500/20 text-teal-300',
    select: 'bg-amber-500/20 text-amber-300',
    date: 'bg-lime-500/20 text-lime-300',
    media: 'bg-emerald-500/20 text-emerald-300',
    link: 'bg-orange-500/20 text-orange-300',
    reference: 'bg-fuchsia-500/20 text-fuchsia-300',
    group: 'bg-pink-500/20 text-pink-300',
    repeater: 'bg-purple-500/20 text-purple-300',
};
const TYPE_PILL_FALLBACK = 'bg-zinc-800 text-zinc-300';

export function fieldsTypePill(type) {
    return TYPE_PILL_CLASSES[String(type ?? '').toLowerCase()] ?? TYPE_PILL_FALLBACK;
}
