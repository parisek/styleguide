import { test, expect } from '@playwright/test';

// Fixture data note: tests/fixtures/templates/page/landing has no
// styleguide.twig sibling, so it's a has_styleguide:false skeleton and the
// palette (like the sidebar) never surfaces it -- these specs stick to the
// component/doc groups, which the fixture set does cover.

test.describe('Command palette', () => {
    test('open, type, arrow, enter navigates', async ({ page }) => {
        await page.goto('/styleguide/');
        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeHidden();

        await page.keyboard.press('Meta+k');
        await expect(dialog).toBeVisible();

        await dialog.getByPlaceholder(/search|hledat/i).fill('multi');
        await expect(dialog.getByRole('option')).toHaveCount(1);
        await page.keyboard.press('ArrowDown');
        await page.keyboard.press('Enter');

        await expect(page).toHaveURL(/\/styleguide\/component\/multi$/);
        await expect(dialog).toBeHidden();
    });

    test('no-results state for a query that matches nothing', async ({ page }) => {
        await page.goto('/styleguide/');
        await page.keyboard.press('Meta+k');
        const dialog = page.getByRole('dialog');

        await dialog.getByPlaceholder(/search|hledat/i).fill('zzzzz-nope');
        await expect(page.getByText(/žádné výsledky|no results/i)).toBeVisible();
        await expect(dialog.getByRole('listbox')).toHaveCount(0);
    });

    test('is independent from the sidebar filter input', async ({ page }) => {
        await page.goto('/styleguide/');
        const dialog = page.getByRole('dialog');
        const sidebarInput = page.locator('aside input[type="text"]');

        // Typing in the sidebar's own filter never opens the palette.
        await sidebarInput.fill('widget');
        await expect(dialog).toBeHidden();

        // Opening the palette leaves the sidebar's query untouched.
        await page.keyboard.press('Meta+k');
        await expect(dialog).toBeVisible();
        await expect(sidebarInput).toHaveValue('widget');

        // Escape while the palette is open closes ONLY the palette --
        // review finding: the old global Escape-clears-the-filter behavior
        // (useSearchShortcuts.js, retired) would have wiped the sidebar
        // query here too; it's now scoped to the sidebar input itself.
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(sidebarInput).toHaveValue('widget');

        // Escape WHILE the sidebar filter input is actually focused still
        // clears it -- the narrowed, scoped behavior survives.
        await sidebarInput.focus();
        await page.keyboard.press('Escape');
        await expect(sidebarInput).toHaveValue('');
    });
});
