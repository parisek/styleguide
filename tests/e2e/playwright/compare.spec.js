import { test, expect } from '@playwright/test';

// tests/fixtures/styleguide.yaml sets `viewports.compare: [1440, 768, 320]`.
test.describe('compare mode (viewports.compare)', () => {
    test('a single preview shows every width side by side, at one scale, lazily', async ({ page }) => {
        await page.goto('/styleguide/component/gizmo');
        const toggle = page.getByTestId('compare-toggle');
        await expect(toggle).toHaveText('1440 · 768 · 320');
        await expect(toggle).toHaveAttribute('aria-pressed', 'false');

        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-pressed', 'true');
        await expect(page.getByTestId('viewport-trigger')).toHaveCount(0);
        await expect(page.getByTestId('iframe-wrapper')).toHaveCount(0);

        const strip = page.getByTestId('compare-strip');
        await expect(strip).toHaveCount(1);
        const captions = strip.getByTestId('compare-caption');
        await expect(captions).toHaveText([/^1440 px/, /^768 px/, /^320 px/]);
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
        expect(logical).toEqual([1440, 768, 320]);
        await page.screenshot({ path: 'test-results/compare-single.png' });

        await toggle.click();
        await expect(page.getByTestId('compare-strip')).toHaveCount(0);
        await expect(page.getByTestId('iframe-wrapper')).toHaveCount(1);
    });

    test('the variant grid gives every tile its own strip, one tile per row', async ({ page }) => {
        await page.goto('/styleguide/component/multi');
        await page.getByTestId('compare-toggle').click();

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

    test('compare mode carries across entries in one session', async ({ page }) => {
        await page.goto('/styleguide/component/gizmo');
        await page.getByTestId('compare-toggle').click();
        await page.getByRole('link', { name: 'Multi', exact: true }).click();
        await expect(page).toHaveURL(/\/component\/multi$/);
        await expect(page.getByTestId('variant-tile').first().getByTestId('compare-strip')).toHaveCount(1);
    });
});
