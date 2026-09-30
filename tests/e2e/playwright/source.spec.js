import { test, expect } from '@playwright/test';

// tests/fixtures/styleguide.yaml sets `show_source: true`, so the fixture
// catalogue shows the "Code" toggle. The PHPUnit suite
// (tests/Api/SourceEndpointTest.php) covers the default-off rule.
test.describe('fixture source ("Code")', () => {
    test('a tile toggle shows the source of that tile without its annotation, and its HTML', async ({ page }) => {
        await page.goto('/styleguide/component/multi');
        const tile = page.getByTestId('variant-tile').nth(2); // secondary
        await tile.getByTestId('variant-tile-code-toggle').click();

        // Still the grid: the toggle does not isolate the tile.
        await expect(page).not.toHaveURL(/variant=/);
        const code = tile.getByTestId('source-code');
        await expect(code).toHaveText('<div class="multi multi--secondary">Multi demo (secondary variant)</div>');
        await expect(code).not.toContainText('title:');

        // The HTML view shows what the fixture renders, as text.
        await tile.getByTestId('source-tab-html').click();
        await expect(code).toHaveText('<div class="multi multi--secondary">Multi demo (secondary variant)</div>');
        await expect(tile.getByTestId('source-tab-html')).toHaveAttribute('aria-pressed', 'true');

        // Only that tile opened.
        await expect(page.getByTestId('source-panel')).toHaveCount(1);
        await page.screenshot({ path: 'test-results/source-tile.png' });
    });

    test('an isolated tile shows its source in a drawer', async ({ page }) => {
        await page.goto('/styleguide/component/multi?variant=dark-bg');
        const toggle = page.getByTestId('source-drawer-toggle');
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await toggle.click();
        await expect(page.getByTestId('source-code')).toHaveText('<div class="multi multi--dark-bg">Multi demo (dark-bg variant, no YAML label)</div>');
    });

    // gizmo/ ships only gizmo.twig: it renders its own template, which is
    // not a fixture, so there is no source to show.
    test('an entry without a fixture file has no drawer', async ({ page }) => {
        await page.goto('/styleguide/component/gizmo');
        await expect(page.locator('iframe').first()).toHaveAttribute('src', '/styleguide/render/component/gizmo');
        await expect(page.getByTestId('source-drawer')).toHaveCount(0);
        const status = await page.evaluate(async () => (await fetch('/styleguide/api/source/component/gizmo')).status);
        expect(status).toBe(404);
    });
});
