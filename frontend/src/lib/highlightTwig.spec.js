import { describe, it, expect } from 'vitest';
import { highlightTwig, TOKEN_CLASSES } from './highlightTwig.js';

const source = "{# ukázka #}\n<div class=\"x\">\n{{ component_button({\n    title: 'Koupit plavbu',\n}) }}\n{% if a %}ok{% endif %}\n</div>";

describe('highlightTwig', () => {
    it('keeps the source intact: the segments join back to it', () => {
        expect(highlightTwig(source).map((s) => s.text).join('')).toBe(source);
    });

    it('marks Twig delimiters, keywords, strings and comments', () => {
        const segments = highlightTwig(source);
        const classOf = (text) => segments.find((s) => s.text === text)?.class;
        expect(classOf('{{')).toBe(TOKEN_CLASSES.delimiter);
        expect(classOf('{%')).toBe(TOKEN_CLASSES.delimiter);
        expect(classOf('if')).toBe(TOKEN_CLASSES.keyword);
        expect(segments.some((s) => s.text.includes('Koupit plavbu') && s.class === TOKEN_CLASSES.string)).toBe(true);
        expect(classOf('{# ukázka #}')).toBe(TOKEN_CLASSES.comment);
    });

    it('marks the HTML around the Twig', () => {
        const segments = highlightTwig(source);
        expect(segments.some((s) => s.text === 'class' && s.class === TOKEN_CLASSES['attr-name'])).toBe(true);
    });

    it('returns text, never markup: HTML in the source stays text', () => {
        const segments = highlightTwig('<script>alert(1)</script>');
        expect(segments.map((s) => s.text).join('')).toBe('<script>alert(1)</script>');
    });

    it('returns nothing for an empty or missing source', () => {
        expect(highlightTwig('')).toEqual([]);
        expect(highlightTwig(undefined)).toEqual([]);
    });

    it('does not turn on Prism auto-highlighting', () => {
        expect(window.Prism.manual).toBe(true);
    });
});
