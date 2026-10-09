import { describe, it, expect } from 'vitest';
import { pillState, PILL_BUTTON } from './pillClasses.js';

// Semantic hover tokens switch their values with the shell theme.
const hasDarkHoverTwin = (classes) => !classes.includes('hover:text-') || classes.includes('hover:text-ui-text') || classes.includes('dark:hover:text-');

describe('pillClasses', () => {
    it('gives every light hover colour a dark twin', () => {
        expect(hasDarkHoverTwin(pillState(false))).toBe(true);
        expect(hasDarkHoverTwin(pillState(true))).toBe(true);
        expect(hasDarkHoverTwin(PILL_BUTTON)).toBe(true);
    });

    it('differs between pressed and idle', () => {
        expect(pillState(true)).not.toBe(pillState(false));
    });

    it('builds the never-pressed button from the idle colours', () => {
        expect(PILL_BUTTON).toContain(pillState(false));
    });
});
