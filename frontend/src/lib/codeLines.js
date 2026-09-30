// Splits highlighted segments (`{ text, class }`, see highlightTwig.js) into
// lines, so the Code panel can number them and wrap each one on its own. A
// segment that spans a newline is cut in two, each half keeping its class.
// The trailing newline of a file does not open an empty last line.
export function toLines(segments) {
    const lines = [[]];
    for (const segment of segments ?? []) {
        const parts = segment.text.split('\n');
        parts.forEach((part, index) => {
            if (index > 0) lines.push([]);
            if (part !== '') lines[lines.length - 1].push({ text: part, class: segment.class });
        });
    }
    if (lines.length > 1 && lines[lines.length - 1].length === 0) lines.pop();
    return lines;
}

// A repository address for one template file: `template` is `source_url`
// from styleguide.yaml with `{path}` in it, `path` is relative to the
// templates directory ("component/hero/styleguide.twig"). Each path segment
// is encoded; the slashes stay.
export function fileUrl(template, path) {
    if (typeof template !== 'string' || !template.includes('{path}') || !path) return null;
    return template.replace('{path}', String(path).split('/').map(encodeURIComponent).join('/'));
}

// The link text for `source_url`: the host's name where it is one people
// know, "Git" otherwise.
export function repoHostLabel(template) {
    let host = '';
    try {
        host = new URL(String(template).replace('{path}', '')).hostname;
    } catch {
        return 'Git';
    }
    if (host === 'github.com') return 'GitHub';
    if (host.includes('gitlab')) return 'GitLab';
    return 'Git';
}
