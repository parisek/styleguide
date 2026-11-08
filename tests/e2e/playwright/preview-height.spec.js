import { test, expect } from '@playwright/test';

// Milestone 1.38, "reliable previews" (#133). Fixtures:
// tests/fixtures/templates/component/viewport-inset (render: inset) and
// viewport-bleed (render: bleed). Each has a short default fixture and a `full`
// variant whose root has a viewport floor (`min-height: 100dvh`, `100svh`).
// Sized from its own document, such a variant grew by its surround on every
// measure until the 20,000 px ceiling. The fix pins it at the demo viewport
// height (640, lib/previewHeight.js) and leaves every other preview at its
// content height, per document, so each grid tile gets its own answer.
const PINNED = 640;

// Height of the iframe element, as the SPA set it, in CSS px of the iframe's
// own layout (the logical height, before any scale transform).
async function frameHeight(locator) {
    return locator.evaluate((el) => parseFloat(getComputedStyle(el).height));
}

// The height at load, and again after the page had time for several more
// measures (ResizeObserver, fonts, the next frames).
async function heightAtLoadAndSettled(page, locator) {
    await expect.poll(() => frameHeight(locator)).toBeGreaterThan(0);
    await page.waitForTimeout(300);
    const atLoad = await frameHeight(locator);
    await page.waitForTimeout(1200);
    const settled = await frameHeight(locator);
    return { atLoad, settled };
}

test.describe('preview height of viewport-unit components', () => {
    for (const slug of ['viewport-inset', 'viewport-bleed']) {
        test(`${slug}: the full-height variant keeps one height in the single preview`, async ({ page }) => {
            // A custom width has no canonical preset height, so the iframe is
            // sized from what it measures: the path that looped.
            await page.goto(`/styleguide/component/${slug}?variant=full&width=900`);
            const iframe = page.locator(`iframe[src*="/styleguide/render/component/${slug}"]`).first();
            const { atLoad, settled } = await heightAtLoadAndSettled(page, iframe);
            expect(Math.abs(settled - atLoad)).toBeLessThanOrEqual(1);
            expect(settled).toBe(PINNED);
        });

        test(`${slug}: each grid tile gets its own height`, async ({ page }) => {
            await page.setViewportSize({ width: 1680, height: 900 });
            await page.goto(`/styleguide/component/${slug}`);
            await expect(page.getByTestId('variant-tile')).toHaveCount(2);
            const short = page.locator(`iframe[data-sg-tile="${slug}:__default__"]`);
            const full = page.locator(`iframe[data-sg-tile="${slug}:full"]`);

            const fullHeights = await heightAtLoadAndSettled(page, full);
            expect(Math.abs(fullHeights.settled - fullHeights.atLoad)).toBeLessThanOrEqual(1);
            expect(fullHeights.settled).toBe(PINNED);

            // The short default fixture is as tall as its content (a 120 px
            // section, plus the inset padding in inset mode), not pinned.
            const shortHeight = await frameHeight(short);
            expect(shortHeight).toBeGreaterThanOrEqual(120);
            expect(shortHeight).toBeLessThan(260);
        });
    }

    test('a component without viewport units keeps its content height', async ({ page }) => {
        await page.goto('/styleguide/component/gizmo?width=900');
        const iframe = page.locator('iframe[src*="/styleguide/render/component/gizmo"]').first();
        const { atLoad, settled } = await heightAtLoadAndSettled(page, iframe);
        expect(Math.abs(settled - atLoad)).toBeLessThanOrEqual(1);
        const content = await iframe.evaluate((el) => {
            const doc = el.contentDocument;
            return Math.ceil(doc.body.getBoundingClientRect().height);
        });
        expect(settled).toBeLessThan(PINNED);
        expect(Math.abs(settled - content)).toBeLessThanOrEqual(24);
    });
});
