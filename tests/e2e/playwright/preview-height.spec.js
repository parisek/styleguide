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

    // Each compare column is its own document, measured on its own.
    test('every compare column of the full-height variant keeps one height', async ({ page }) => {
        await page.setViewportSize({ width: 1680, height: 900 });
        await page.goto('/styleguide/component/viewport-inset?variant=full');
        await page.getByTestId('viewport-trigger').click();
        await page.getByTestId('compare-project-widths').click();
        const captions = page.getByTestId('compare-strip').getByTestId('compare-caption');
        await expect(captions).toHaveText([/^320 × 640\b/, /^768 × 640\b/, /^1440 × 640\b/]);
        await page.waitForTimeout(1200);
        await expect(captions).toHaveText([/^320 × 640\b/, /^768 × 640\b/, /^1440 × 640\b/]);
    });

    // The project's body is a grid with a viewport min-height, so the inset
    // wrapper stretches with the iframe. The component inside does not, and is
    // not pinned.
    test('a short component in a stretching body keeps its content height', async ({ page }) => {
        await page.goto('/styleguide/component/viewport-stretch?width=900');
        const iframe = page.locator('iframe[src*="/styleguide/render/component/viewport-stretch"]').first();
        const { atLoad, settled } = await heightAtLoadAndSettled(page, iframe);
        expect(Math.abs(settled - atLoad)).toBeLessThanOrEqual(1);
        expect(settled).toBeLessThan(260);
    });

    // A page render is never pinned; without a page shell it is measured at
    // zero height like a component, so it keeps its content height.
    test('a page without a shell keeps one content height', async ({ page }) => {
        await page.goto('/styleguide/page/contact?width=900');
        const iframe = page.locator('iframe[src*="/styleguide/render/page/contact"]').first();
        const { atLoad, settled } = await heightAtLoadAndSettled(page, iframe);
        expect(Math.abs(settled - atLoad)).toBeLessThanOrEqual(1);
        const content = await iframe.evaluate((el) => Math.ceil(el.contentDocument.body.getBoundingClientRect().height));
        expect(settled).not.toBe(PINNED);
        expect(Math.abs(settled - content)).toBeLessThanOrEqual(24);
    });

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

// #263: a pinned preview says so. The toolbar carries a label with the measured
// deltas on the single preview; on a variant grid each pinned tile carries a
// chip and the short one carries none.
test.describe('viewport height mark', () => {
    test('the toolbar labels a pinned single preview and names the deltas', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto('/styleguide/component/viewport-inset?variant=full&width=900');
        const label = page.getByTestId('viewport-height-label');
        await expect(label).toBeVisible();
        await expect(label).toHaveAttribute('title', /\+800 px .*\+\d+ px.*640 px/);
    });

    test('the toolbar shows no label for a preview at its content height', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto('/styleguide/component/viewport-stretch?width=900');
        await expect(page.locator('iframe[src*="/styleguide/render/component/viewport-stretch"]').first()).toBeVisible();
        await page.waitForTimeout(800);
        await expect(page.getByTestId('viewport-height-label')).toHaveCount(0);
    });

    test('only the pinned grid tile carries the chip', async ({ page }) => {
        await page.setViewportSize({ width: 1680, height: 900 });
        await page.goto('/styleguide/component/viewport-bleed');
        const tiles = page.getByTestId('variant-tile');
        await expect(tiles).toHaveCount(2);
        await expect(page.getByTestId('viewport-height-chip')).toHaveCount(1);
        await expect(page.getByTestId('viewport-height-label')).toHaveCount(0);
        const chip = page.getByTestId('viewport-height-chip');
        await expect(chip).toHaveAttribute('aria-label', /\+800 px/);
    });
});
