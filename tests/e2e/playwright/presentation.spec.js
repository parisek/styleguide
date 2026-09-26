import { test, expect } from '@playwright/test';

// The fixture with the opt-in presentation keys switched on, served by the
// third webServer in frontend/playwright.config.js (SG_PRESENTATION=1, read
// by tests/fixtures/index.php): `pages.group_by: category`.
const ORIGIN = 'http://127.0.0.1:8424';

test.describe('pages grouped by category', () => {
    test('the Pages section lists one collapsible group per category, uncategorised last', async ({ page }) => {
        await page.goto(`${ORIGIN}/styleguide/foundations`);
        const sidebar = page.locator('aside');
        await sidebar.getByRole('button', { name: /^(Stránky|Pages)/ }).click();

        const groups = page.getByTestId('sidebar-page-group');
        await expect(groups).toHaveCount(2);
        await expect(groups.nth(0).getByRole('button')).toContainText('Marketing');
        await expect(groups.nth(1).getByRole('button')).toContainText(/Ostatní|Other/);

        await groups.nth(1).getByRole('link', { name: 'Contact' }).click();
        await expect(page).toHaveURL(`${ORIGIN}/styleguide/page/contact`);

        const toggle = groups.nth(0).getByRole('button');
        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    });

    test('without the key the Pages section stays a flat list', async ({ page }) => {
        await page.goto('/styleguide/foundations');
        await page.locator('aside').getByRole('button', { name: /^(Stránky|Pages)/ }).click();
        await expect(page.getByTestId('sidebar-page-group')).toHaveCount(0);
        await expect(page.locator('aside').getByRole('link', { name: 'Contact' })).toBeVisible();
    });
});
