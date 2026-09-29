import { test, expect } from '@playwright/test';

// tests/fixtures/styleguide.yaml sets `viewports.compare: [1440, 768, 320]`:
// the width menu lists those widths first as the project's widths.
test.describe('compare mode (the width checklist)', () => {
    test('ticked widths show side by side, at one scale, lazily', async ({ page }) => {
        await page.goto('/styleguide/component/gizmo');
        await expect(page.getByTestId('compare-toggle')).toHaveCount(0);

        const trigger = page.getByTestId('viewport-trigger');
        await trigger.click();
        await page.getByTestId('compare-project-widths').click();
        await expect(page.getByTestId('viewport-trigger-dims')).toHaveText('320 · 768 · 1440');
        await expect(page.getByTestId('iframe-wrapper')).toHaveCount(0);

        const strip = page.getByTestId('compare-strip');
        await expect(strip).toHaveCount(1);
        const captions = strip.getByTestId('compare-caption');
        await expect(captions).toHaveText([/^320 px/, /^768 px/, /^1440 px/]);
        const frames = strip.locator('iframe');
        await expect(frames).toHaveCount(3);
        for (let i = 0; i < 3; i++) {
            await expect(frames.nth(i)).toHaveAttribute('loading', 'lazy');
            await expect(frames.nth(i)).toHaveAttribute('src', '/styleguide/render/component/gizmo');
        }

        // Columns are proportional to their widths, so every column shares
        // one zoom (±1 % for rounding).
        const zooms = (await captions.allTextContents()).map((t) => Number((t.match(/(\d+) %/) ?? [0, 100])[1]));
        expect(Math.max(...zooms) - Math.min(...zooms)).toBeLessThanOrEqual(1);

        // Each iframe renders at its logical width.
        const logical = await frames.evaluateAll((els) => els.map((el) => el.contentWindow?.innerWidth));
        expect(logical).toEqual([320, 768, 1440]);

        await trigger.click();
        await expect(page.getByTestId('viewport-preset-mobile-s')).toHaveAttribute('aria-checked', 'true');
        await page.screenshot({ path: 'test-results/compare-menu.png' });

        // A click on a row (not its tick) shows that width alone.
        await page.getByTestId('viewport-preset-tablet').click();
        await expect(page.getByTestId('compare-strip')).toHaveCount(0);
        await expect(page.getByTestId('iframe-wrapper')).toHaveCount(1);
        await expect(page.getByTestId('viewport-trigger-word')).toHaveText('Tablet');
    });

    test('a tick adds a width and keeps the menu open; unticking back to one ends the comparison', async ({ page }) => {
        await page.goto('/styleguide/component/gizmo');
        await page.getByTestId('viewport-trigger').click();
        await page.getByTestId('viewport-preset-tablet').click();

        await page.getByTestId('viewport-trigger').click();
        const menu = page.getByTestId('viewport-menu');
        await page.getByTestId('viewport-preset-mobile').locator('[data-width-check]').click();
        await expect(menu).toBeVisible();
        await expect(page.getByTestId('compare-caption')).toHaveText([/^375 px/, /^768 px/]);

        // Keyboard: Space ticks, as on any checkbox.
        await page.getByTestId('viewport-width-1440').focus();
        await page.keyboard.press('Space');
        await expect(page.getByTestId('compare-caption')).toHaveText([/^375 px/, /^768 px/, /^1440 px/]);
        await expect(menu).toBeVisible();

        await page.getByTestId('viewport-width-1440').locator('[data-width-check]').click();
        await page.getByTestId('viewport-preset-mobile').locator('[data-width-check]').click();
        await expect(page.getByTestId('compare-strip')).toHaveCount(0);
        await expect(page.getByTestId('viewport-trigger-word')).toHaveText('Tablet');
    });

    test('the variant grid gives every tile its own strip, one tile per row', async ({ page }) => {
        await page.goto('/styleguide/component/multi');
        await page.getByTestId('viewport-trigger').click();
        await page.getByTestId('compare-project-widths').click();

        const tiles = page.getByTestId('variant-tile');
        await expect(tiles).toHaveCount(3);
        await expect(page.getByTestId('variant-columns-trigger')).toHaveCount(0);
        for (let i = 0; i < 3; i++) {
            await expect(tiles.nth(i).getByTestId('compare-caption')).toHaveCount(3);
        }
        await expect(tiles.nth(2).locator('iframe').first()).toHaveAttribute('src', '/styleguide/render/component/multi?variant=secondary');
        await expect(tiles.nth(0).locator('iframe').first().contentFrame().locator('.multi')).toContainText('Multi demo (default variant)');

        // One tile per row: the tiles stack.
        const boxes = await tiles.evaluateAll((els) => els.map((el) => el.getBoundingClientRect().left));
        expect(new Set(boxes.map(Math.round)).size).toBe(1);
        await page.screenshot({ path: 'test-results/compare-grid.png', fullPage: true });
    });

    test('the comparison carries across entries and survives a reload', async ({ page }) => {
        await page.goto('/styleguide/component/gizmo');
        await page.getByTestId('viewport-trigger').click();
        await page.getByTestId('compare-project-widths').click();
        await page.getByRole('link', { name: 'Multi', exact: true }).click();
        await expect(page).toHaveURL(/\/component\/multi$/);
        await expect(page.getByTestId('variant-tile').first().getByTestId('compare-strip')).toHaveCount(1);

        await page.reload();
        await expect(page.getByTestId('variant-tile').first().getByTestId('compare-strip')).toHaveCount(1);
    });
});
