import { test, expect } from '@playwright/test';
import { PILL_BASE, PILL_BUTTON, pillState } from '../../../frontend/src/lib/pillClasses.js';

for (const width of [390, 860, 1100, 1440]) {
    test(`semantic UI values at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });
        await page.goto('/styleguide/');
        const href = await page.locator('link[rel="stylesheet"]').first().getAttribute('href');
        const css = new URL(href, page.url()).href;
        await page.setContent(`<html><head><link rel="stylesheet" href="${css}"></head><body><button id="idle" class="${PILL_BUTTON}">Idle</button><button id="active" class="${PILL_BASE} ${pillState(true)}">Active</button><div id="toolbar" class="min-h-ui-toolbar bg-ui-toolbar border-b border-ui-border">Toolbar</div><div id="preview" class="bg-ui-preview ring-1 ring-ui-border rounded-ui-panel">Preview</div></body></html>`);
        for (const dark of [false, true]) {
            await page.evaluate(value => document.documentElement.classList.toggle('dark', value), dark);
            const palette = async (selector, property, color) => {
                await expect.poll(() => page.locator(selector).evaluate((element, options) => {
                    const reference = document.createElement('span');
                    reference.style[options.property] = `var(--color-${options.color})`;
                    element.append(reference);
                    const values = [getComputedStyle(element)[options.property], getComputedStyle(reference)[options.property]];
                    reference.remove(); return values[0] === values[1];
                }, { property, color })).toBe(true);
            };
            await page.mouse.move(width - 1, 899);
            await palette('#idle', 'backgroundColor', dark ? 'zinc-900' : 'white');
            await palette('#idle', 'color', dark ? 'zinc-300' : 'zinc-600');
            await palette('#active', 'backgroundColor', dark ? 'zinc-100' : 'zinc-900');
            await palette('#active', 'color', dark ? 'zinc-900' : 'white');
            await palette('#toolbar', 'backgroundColor', dark ? 'zinc-900' : 'zinc-50');
            await palette('#preview', 'backgroundColor', 'white');
            await page.locator('#idle').hover();
            await expect.poll(() => page.locator('#idle').evaluate(element => { const ref = document.createElement('span'); ref.style.color = 'var(--ui-text)'; element.append(ref); const same = getComputedStyle(element).color === getComputedStyle(ref).color; ref.remove(); return same; })).toBe(true);
            await page.mouse.move(width - 1, 899);
            await page.locator('#idle').focus();
            await page.keyboard.press('ArrowRight');
            await expect(page.locator('#idle')).toBeFocused();
            await palette('#idle', 'outlineColor', 'red-600');
            expect(await page.locator('#idle').evaluate(element => getComputedStyle(element).outlineWidth)).toBe('2px');
            expect(await page.locator('#idle').evaluate(element => element.getBoundingClientRect().height)).toBe(32);
            expect(await page.locator('#toolbar').evaluate(element => element.getBoundingClientRect().height)).toBe(57);
            expect(await page.locator('#preview').evaluate(element => getComputedStyle(element).borderRadius)).toBe('4px');
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
            await page.locator('#idle').evaluate(element => element.blur());
        }
    });
}
