import { describe, it, expect } from 'vitest';
import { toLines, fileUrl, repoHostLabel } from './codeLines.js';

describe('toLines', () => {
    it('cuts segments at newlines and keeps their class', () => {
        const lines = toLines([{ text: 'a\nb', class: 'x' }, { text: 'c\n', class: '' }]);
        expect(lines).toEqual([[{ text: 'a', class: 'x' }], [{ text: 'b', class: 'x' }, { text: 'c', class: '' }]]);
    });

    it('keeps blank lines inside the source', () => {
        expect(toLines([{ text: 'a\n\nb', class: '' }])).toHaveLength(3);
    });

    it('returns one empty line for no source', () => {
        expect(toLines([])).toEqual([[]]);
    });
});

describe('fileUrl', () => {
    it('puts the path in the template', () => {
        expect(fileUrl('https://github.com/acme/site/blob/main/templates/{path}', 'component/hero/styleguide.twig'))
            .toBe('https://github.com/acme/site/blob/main/templates/component/hero/styleguide.twig');
    });

    it('encodes each segment but keeps the slashes', () => {
        expect(fileUrl('https://x.test/{path}', 'component/a b/c#.twig')).toBe('https://x.test/component/a%20b/c%23.twig');
    });

    it('returns null without a usable template or path', () => {
        expect(fileUrl(null, 'a')).toBeNull();
        expect(fileUrl('https://x.test/', 'a')).toBeNull();
        expect(fileUrl('https://x.test/{path}', '')).toBeNull();
    });
});

describe('repoHostLabel', () => {
    it('names GitHub and GitLab, and says Git otherwise', () => {
        expect(repoHostLabel('https://github.com/a/b/blob/main/{path}')).toBe('GitHub');
        expect(repoHostLabel('https://gitlab.example.cz/a/b/-/blob/main/{path}')).toBe('GitLab');
        expect(repoHostLabel('https://git.example.cz/{path}')).toBe('Git');
        expect(repoHostLabel(null)).toBe('Git');
    });
});
