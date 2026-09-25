import { test, expect } from '@playwright/test';

// The catalogue at a configured mount (bootstrap.base_url = /tools/ui),
// served by the second webServer in frontend/playwright.config.js. Every URL
// the SPA builds has to follow the mount: a path still hardcoded to
// /styleguide shows up here as a blank view, a 404 or a wrong iframe src.
const ORIGIN = 'http://127.0.0.1:8423';
const MOUNT = '/tools/ui';

test.describe('catalogue at a configured mount', () => {
    test('the landing hydrates, with the catalogue and the chrome strings loaded', async ({ page }) => {
        const failed = [];
        page.on('response', (r) => { if (r.status() >= 400) failed.push(`${r.status()} ${r.url()}`); });

        await page.goto(`${ORIGIN}${MOUNT}/`);
        await expect(page.locator('iframe').first()).toHaveAttribute('src', `${MOUNT}/render/foundations/index`);
        // Translated chrome proves the locale JSON loaded from <mount>/assets.
        await expect(page.getByText('Přehled')).toBeVisible();
        // The catalogue proves the API loaded from <mount>/api.
        await expect(page.getByRole('link', { name: 'Sample Doc', exact: true })).toBeVisible();
        expect(failed).toEqual([]);
    });

    test('the bare mount, without a trailing slash, loads its assets', async ({ page }) => {
        await page.goto(`${ORIGIN}${MOUNT}`);
        await expect(page.getByText('Přehled')).toBeVisible();
    });

    test('navigation stays under the mount, and a deep link survives a reload', async ({ page }) => {
        await page.goto(`${ORIGIN}${MOUNT}/`);
        await page.getByRole('link', { name: 'Sample Doc', exact: true }).click();
        await expect(page).toHaveURL(`${ORIGIN}${MOUNT}/doc/sample-doc`);
        await expect(page.locator('iframe').first()).toHaveAttribute('src', `${MOUNT}/render/doc/sample-doc`);

        await page.reload();
        await expect(page.locator('iframe').first()).toHaveAttribute('src', `${MOUNT}/render/doc/sample-doc`);
    });

    test('the iframe renders the component under the mount', async ({ page }) => {
        await page.goto(`${ORIGIN}${MOUNT}/component/sample`);
        const frame = page.frameLocator('iframe').first();
        await expect(frame.locator('body')).not.toBeEmpty();
        await expect(frame.locator('#sg-standalone-bar a').first()).toHaveAttribute('href', `${MOUNT}/component/sample`);
    });

    test('the iframe theme cookie is scoped to the mount', async ({ page, context }) => {
        // The cookie is the server's fallback for in-iframe navigations. A
        // path outside the mount means the browser never sends it back.
        await page.goto(`${ORIGIN}${MOUNT}/component/sample`);
        await page.getByTestId('iframe-theme-toggle').click();
        await expect(page.locator('iframe').first()).toHaveAttribute('src', /\?theme=dark$/);

        const cookie = (await context.cookies()).find((c) => c.name === 'sg-iframe-theme');
        expect(cookie?.path).toBe(MOUNT);
    });

    test('the default mount is not the catalogue', async ({ request }) => {
        // The fixture redirects anything that is not the catalogue to it.
        const response = await request.get(`${ORIGIN}/styleguide/api/components`, { maxRedirects: 0 });
        expect(response.status()).toBe(302);
        expect(response.headers()['location']).toBe(`${MOUNT}/`);
    });
});
