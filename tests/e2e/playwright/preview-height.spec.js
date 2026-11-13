import { test, expect } from '@playwright/test';

// Milestone 1.38, "reliable previews" (#133). Fixtures:
// tests/fixtures/templates/component/viewport-inset (render: inset) and
// viewport-bleed (render: bleed). Each has a short default fixture and a `full`
// variant whose root has a viewport floor (`min-height: 100dvh`, `100svh`).
// Sized from its own document, such a variant grew by its surround on every
// measure until the 20,000 px ceiling. The fix pins it and leaves every other
// preview at its content height, per document, so each grid tile gets its own
// answer.
//
// #277: one rule for every surface and kind. A pinned preview takes the
// designed frame of its surface (lib/previewHeight.js designedViewportHeight()):
// the preset height, else the catalogue frame of its width (16:10 on a desktop
// width, square below it), else 640 px in Full mode. A page is pinned too when
// shown at its own height it would grow (a full-screen hero inside it).
const PINNED = 640;
// Above 1024 px the catalogue frame (16:10); at 1024 px and below the screen of
// the nearest phone or tablet preset.
const DEVICES = [[320, 568], [375, 667], [425, 812], [768, 1024], [1024, 1366]];
const designed = (width) => {
    if (width > 1024) return Math.round(width * 0.625);
    const [w, h] = DEVICES.reduce((best, d) => (Math.abs(d[0] - width) < Math.abs(best[0] - width) ? d : best));
    return Math.round(width * (h / w));
};

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
            expect(settled).toBe(designed(900));
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
        await expect(captions).toHaveText([/^320 × 568\b/, /^768 × 1024\b/, /^1440 × 900\b/]);
        await page.waitForTimeout(1200);
        await expect(captions).toHaveText([/^320 × 568\b/, /^768 × 1024\b/, /^1440 × 900\b/]);
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

    // A page without a viewport floor of its own is measured at zero height
    // like a component and keeps its content height.
    test('a page without a shell keeps one content height', async ({ page }) => {
        await page.goto('/styleguide/page/contact?width=900');
        const iframe = page.locator('iframe[src*="/styleguide/render/page/contact"]').first();
        const { atLoad, settled } = await heightAtLoadAndSettled(page, iframe);
        expect(Math.abs(settled - atLoad)).toBeLessThanOrEqual(1);
        const content = await iframe.evaluate((el) => Math.ceil(el.contentDocument.body.getBoundingClientRect().height));
        expect(settled).not.toBe(PINNED);
        expect(Math.abs(settled - content)).toBeLessThanOrEqual(24);
    });

    // The downstream case (#277): a hero with a `100svh` floor inside a page.
    // At its content height the hero would fill the whole preview, so the page
    // is pinned at the designed frame and scrolls inside.
    test('a page with a full-screen hero is pinned at the designed frame in the single preview', async ({ page }) => {
        await page.goto('/styleguide/page/screen-hero?width=1440');
        const iframe = page.locator('iframe[src*="/styleguide/render/page/screen-hero"]').first();
        const { atLoad, settled } = await heightAtLoadAndSettled(page, iframe);
        expect(Math.abs(settled - atLoad)).toBeLessThanOrEqual(1);
        expect(settled).toBe(designed(1440));
        const scrolls = await iframe.evaluate((el) => el.contentDocument.documentElement.scrollHeight > el.clientHeight);
        expect(scrolls).toBe(true);
    });

    test('a page with a full-screen hero is pinned in every compare column', async ({ page }) => {
        await page.setViewportSize({ width: 1680, height: 900 });
        await page.goto('/styleguide/page/screen-hero');
        await page.getByTestId('viewport-trigger').click();
        await page.getByTestId('compare-project-widths').click();
        const captions = page.getByTestId('compare-strip').getByTestId('compare-caption');
        await expect(captions).toHaveText([/^320 × 568\b/, /^768 × 1024\b/, /^1440 × 900\b/]);
        await page.waitForTimeout(1200);
        await expect(captions).toHaveText([/^320 × 568\b/, /^768 × 1024\b/, /^1440 × 900\b/]);
    });

    test('on the board a page with a full-screen hero is pinned and a plain page is not', async ({ page }) => {
        await page.setViewportSize({ width: 1680, height: 900 });
        await page.goto('/styleguide/grid?view=board');
        const hero = page.locator('[data-testid="board-frame"] iframe[src*="/render/page/screen-hero"]');
        const contact = page.locator('[data-testid="board-frame"] iframe[src*="/render/page/contact"]');
        await hero.scrollIntoViewIfNeeded();
        const width = await hero.evaluate((el) => parseFloat(getComputedStyle(el).width));
        const { atLoad, settled } = await heightAtLoadAndSettled(page, hero);
        expect(Math.abs(settled - atLoad)).toBeLessThanOrEqual(1);
        expect(settled).toBe(designed(width));
        await contact.scrollIntoViewIfNeeded();
        await expect.poll(() => frameHeight(contact)).toBeGreaterThan(0);
        expect(await frameHeight(contact)).toBeLessThan(designed(width));
    });

    // A frame floor with something around it (96 px of padding, a header and a
    // footer around a `main` with a viewport floor) would cut the bottom of the
    // page off by that much: its height depends on the viewport too, and it is
    // pinned. A frame floor its content fills keeps the content height.
    for (const slug of ['frame-padded', 'frame-main']) {
        test(`${slug}: a page whose frame floor has a surround is pinned`, async ({ page }) => {
            await page.goto(`/styleguide/page/${slug}?width=1440`);
            const iframe = page.locator(`iframe[src*="/styleguide/render/page/${slug}"]`).first();
            const { atLoad, settled } = await heightAtLoadAndSettled(page, iframe);
            expect(Math.abs(settled - atLoad)).toBeLessThanOrEqual(1);
            expect(settled).toBe(designed(1440));
            await expect(page.getByTestId('viewport-height-label')).toHaveCount(1);
        });
    }

    test('frame-flush: a page whose frame floor its content fills keeps its content height', async ({ page }) => {
        await page.goto('/styleguide/page/frame-flush?width=1440');
        const iframe = page.locator('iframe[src*="/styleguide/render/page/frame-flush"]').first();
        const { atLoad, settled } = await heightAtLoadAndSettled(page, iframe);
        expect(Math.abs(settled - atLoad)).toBeLessThanOrEqual(1);
        expect(settled).toBeGreaterThan(1500);
        await expect(page.getByTestId('viewport-height-label')).toHaveCount(0);
    });

    // In Full mode the iframe fills the pane: nothing is pinned, nothing named.
    test('a page with a full-screen hero carries no mark in Full mode', async ({ page }) => {
        await page.goto('/styleguide/page/screen-hero');
        await expect(page.locator('iframe[src*="/styleguide/render/page/screen-hero"]').first()).toBeVisible();
        await page.waitForTimeout(800);
        await expect(page.getByTestId('viewport-height-label')).toHaveCount(0);
    });

    // The width menu changes the frame without loading the preview again: the
    // pinned height follows the frame of the moment.
    test('a pinned page follows a width switch in the menu', async ({ page }) => {
        await page.setViewportSize({ width: 1680, height: 900 });
        await page.goto('/styleguide/page/screen-hero?width=768');
        const iframe = page.locator('iframe[src*="/styleguide/render/page/screen-hero"]').first();
        await expect.poll(() => frameHeight(iframe)).toBe(designed(768));
        await page.getByTestId('viewport-trigger').click();
        await page.getByTestId('viewport-width-1440').click();
        await expect.poll(() => frameHeight(iframe)).toBe(designed(1440));
        await expect(page.getByTestId('viewport-height-label')).toHaveAttribute('title', new RegExp(`${designed(1440)} px\\.$`));
        // A preset brings its own height, and the label names it.
        await page.getByTestId('viewport-trigger').click();
        await page.getByTestId('viewport-preset-mobile').click();
        await expect.poll(() => frameHeight(iframe)).toBe(667);
        await expect(page.getByTestId('viewport-height-label')).toHaveAttribute('title', /667 px\.$/);
    });

    test('a pinned grid tile follows a width switch in the menu', async ({ page }) => {
        await page.setViewportSize({ width: 1680, height: 900 });
        await page.goto('/styleguide/component/viewport-inset');
        const full = page.locator('iframe[data-sg-tile="viewport-inset:full"]');
        await expect.poll(() => frameHeight(full)).toBe(PINNED);
        await page.getByTestId('viewport-trigger').click();
        await page.getByTestId('viewport-width-1440').click();
        await expect.poll(() => frameHeight(full)).toBe(designed(1440));
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
        await expect(label).toHaveAttribute('title', new RegExp(`\\+800 px .*\\+\\d+ px.*${designed(900)} px`));
    });

    test('below md the toolbar keeps the mark as a chip', async ({ page }) => {
        await page.setViewportSize({ width: 600, height: 900 });
        await page.goto('/styleguide/component/viewport-inset?variant=full&width=900');
        await expect(page.getByTestId('viewport-height-label')).toBeHidden();
        const chip = page.getByTestId('viewport-height-chip');
        await expect(chip).toBeVisible();
        await expect(chip).toHaveAttribute('title', /^(Výška podle viewportu|Viewport height)\. .*\+800 px/);
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
        // The chip shows only an icon, so its tooltip names the mark too.
        await expect(chip).toHaveAttribute('title', /^(Výška podle viewportu|Viewport height)\. /);
        // The header around it is a button, whose own name carries the pin.
        await expect(page.locator('[data-testid="variant-tile-header"][role="button"]'))
            .toHaveAttribute('aria-label', /\((Výška podle viewportu|Viewport height)\)$/);
    });
});
