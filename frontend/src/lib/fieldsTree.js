// Depth-first walk over the YAML `fields:` map. Returns a flat list so
// callers can render linearly without recursive templates. Each row's
// `depth` drives indentation. Ported verbatim from
// frontend/components/preview.js `flattenFieldsTree`.

export function flattenFieldsTree(map, depth = 0, parentPath = '') {
    if (!map || typeof map !== 'object' || Array.isArray(map)) return [];
    const rows = [];
    for (const [key, field] of Object.entries(map)) {
        if (!field || typeof field !== 'object') continue;
        const path = parentPath ? `${parentPath}.${key}` : key;
        rows.push({
            path,
            key,
            depth,
            type: typeof field.type === 'string' ? field.type : '',
            title: typeof field.title === 'string' ? field.title : '',
            description: typeof field.description === 'string' ? field.description : '',
            required: !!field.required,
        });
        if (field.fields && typeof field.fields === 'object') {
            rows.push(...flattenFieldsTree(field.fields, depth + 1, path));
        }
    }
    return rows;
}
