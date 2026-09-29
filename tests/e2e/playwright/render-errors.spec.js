import { test, expect } from '@playwright/test';

// The previews' JavaScript errors reach the warning badge and the tile.
// The fixture components are clean on purpose, so each test breaks a render
// on the wire: the real render-cell.twig document, with a script that throws
// and a missing image appended. The relay script at the top of <head> is
// the one under test.
async function breakRenders(page, pattern) {
    await page.route(pattern, async (route) => {
        const response = await route.fetch();
        const body = (await response.text()).replace(
            '</body>',
            '<script>throw new Error("fixture boom")</script><img src="/fixture-missing.png" alt=""></body>',
        );
        await route.fulfill({ response, body });
    });
}

test.describe('JavaScript errors from the previews', () => {
    // The fixture project points at a favicon and a script it does not ship.
    // The relay reports those too (it is right to), so serve them here and
    // keep each count down to what a test breaks on purpose.
    test.beforeEach(async ({ page }) => {
        await page.route('**/images/favicon.svg', (route) => route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg"/>' }));
        await page.route('**/dist/js/script.js', (route) => route.fulfill({ contentType: 'text/javascript', body: '' }));
    });

    test('a single preview: the badge counts them and the dialog lists them', async ({ page }) => {
        await breakRenders(page, '**/styleguide/render/component/gizmo*');
        await page.goto('/styleguide/component/gizmo');

        const badge = page.getByRole('button', { name: /problémy|problems/i });
        await expect(badge).toHaveText('2');
        await badge.click();
        const rows = page.getByTestId('health-js-row');
        await expect(rows).toHaveCount(2);
        await expect(rows.nth(0)).toContainText('fixture boom');
        await expect(rows.nth(1)).toContainText('/fixture-missing.png');
        await page.keyboard.press('Escape');

        // Another entry: the old iframe leaves the page and its errors go.
        await page.getByRole('link', { name: 'Multi', exact: true }).click();
        await expect(page).toHaveURL(/\/component\/multi$/);
        await expect(badge).toHaveCount(0);
    });

    test('the variant grid marks the tile, a compare column marks its width, and unticking clears it', async ({ page }) => {
        await breakRenders(page, '**/styleguide/render/component/multi?variant=secondary*');
        await page.goto('/styleguide/component/multi');
        const tiles = page.getByTestId('variant-tile');
        await expect(tiles.nth(2).getByTestId('error-mark')).toHaveText('2');
        await expect(tiles.nth(0).getByTestId('error-mark')).toHaveCount(0);

        // The mark opens the same dialog, and does not isolate the variant.
        await tiles.nth(2).getByTestId('error-mark').click();
        await expect(page.getByTestId('health-js-row').first()).toContainText('fixture boom');
        await expect(page).toHaveURL(/\/component\/multi$/);
        await page.keyboard.press('Escape');

        await page.getByTestId('viewport-trigger').click();
        await page.getByTestId('compare-project-widths').click();
        const strip = tiles.nth(2).getByTestId('compare-strip');
        await expect(strip.getByTestId('error-mark')).toHaveCount(3);
        await expect(tiles.nth(2).getByTestId('error-mark').first()).toHaveText('6');

        await page.getByTestId('viewport-trigger').click();
        await page.getByTestId('viewport-check-1440').click();
        await expect(strip.getByTestId('error-mark')).toHaveCount(2);
        await page.screenshot({ path: 'test-results/render-errors-grid.png' });
    });

    test('a render opened on its own posts nothing and still works', async ({ page }) => {
        const response = await page.goto('/styleguide/render/component/gizmo');
        expect(response?.status()).toBe(200);
        await expect(page.locator('.gizmo')).toHaveText('Gizmo');
    });
});
