import { test, expect } from '@playwright/test';

// The interface must not show while its strings and the catalogue are on
// the way: a loader covers it, then it goes.
test.describe('boot loader', () => {
    test('covers the interface until the strings are in, then shows it without raw keys', async ({ page }) => {
        await page.route('**/locales/*.json', async (route) => {
            await new Promise((resolve) => setTimeout(resolve, 1200));
            await route.continue();
        });
        await page.goto('/styleguide/');

        const splash = page.getByTestId('boot-splash');
        await expect(splash).toBeVisible();
        await expect(splash).toHaveCSS('background-color', /rgb\(\d+, \d+, \d+\)$/);

        await expect(splash).toHaveCount(0);
        await expect(page.getByRole('link', { name: 'Gizmo', exact: true })).toBeVisible();
        await expect(page.locator('body')).not.toContainText(/\bnav\.[a-z_]+/);
    });

    test('a shortcut pressed during the boot leaves the palette focused once it ends', async ({ page }) => {
        await page.route('**/locales/*.json', async (route) => {
            await new Promise((resolve) => setTimeout(resolve, 800));
            await route.continue();
        });
        await page.goto('/styleguide/');
        await page.keyboard.press('Meta+k');

        await expect(page.getByTestId('boot-splash')).toHaveCount(0);
        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();
        await expect(dialog.getByPlaceholder(/search|hledat/i)).toBeFocused();
    });
});
