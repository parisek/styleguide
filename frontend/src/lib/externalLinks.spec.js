import { describe, it, expect } from 'vitest';
import { externalLinksFor } from './externalLinks.js';

describe('externalLinksFor', () => {
    it('returns links in Asana -> Figma -> Drupal -> Web order, filtering empty ones', () => {
        const item = { asana: 'https://asana/x', figma: '', drupal: 'https://drupal/y', web: 'https://web/z' };
        expect(externalLinksFor(item)).toEqual([
            { key: 'asana', url: 'https://asana/x', label: 'Asana' },
            { key: 'drupal', url: 'https://drupal/y', label: 'Drupal' },
            { key: 'web', url: 'https://web/z', label: 'Web' },
        ]);
    });

    it('returns an empty array when no link fields are set', () => {
        expect(externalLinksFor({ id: 'x' })).toEqual([]);
    });

    it('returns an empty array for null/undefined input', () => {
        expect(externalLinksFor(null)).toEqual([]);
        expect(externalLinksFor(undefined)).toEqual([]);
    });
});
