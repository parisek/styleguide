import { test, expect } from '@playwright/test';

// The overview grid (/styleguide/grid): every component and page as a live
// preview tile. The first block runs on the shared fixture; the landing test
// uses the presentation server (SG_PRESENTATION=1, `overview.default: grid`);
// the last block measures loading against a synthetic catalogue of 300
// entries, served through page.route() so no fixture file is needed.
const PRESENTATION = 'http://127.0.0.1:8424';

test.describe('overview grid', () => {
    test('shows every renderable entry as a live tile and opens one on click', async ({ page }) => {
        await page.goto('/styleguide/grid');
        const tiles = page.getByTestId('grid-tile');
        await expect(tiles.first()).toBeVisible();

        // The catalogue's own API decides what should be there.
        const [components, pages] = await Promise.all([
            page.request.get('/styleguide/api/components').then((r) => r.json()),
            page.request.get('/styleguide/api/pages').then((r) => r.json()),
        ]);
        const expected = components.filter((c) => c.has_styleguide !== false).length
            + pages.filter((p) => p.has_styleguide !== false).length;
        await expect(tiles).toHaveCount(expected);

        // A near-viewport tile really renders its entry.
        const multi = tiles.filter({ has: page.getByRole('link', { name: 'Multi', exact: true }) });
        await expect(multi.frameLocator('iframe').locator('.multi')).toContainText('Multi demo (default variant)');
        // Default + dark-bg + secondary.
        await expect(multi.getByTestId('grid-tile-variants')).toHaveText('3');
        await page.screenshot({ path: 'test-results/grid-overview.png' });

        await multi.getByRole('link', { name: 'Multi', exact: true }).click();
        await expect(page).toHaveURL(/\/styleguide\/component\/multi$/);
        await expect(page.getByTestId('variant-grid')).toBeVisible();
    });

    test('filters by section and by text, and the sidebar links to it', async ({ page }) => {
        await page.goto('/styleguide/foundations');
        await page.getByTestId('sidebar-grid-link').click();
        await expect(page).toHaveURL(/\/styleguide\/grid$/);

        await page.getByTestId('grid-filter-section').filter({ hasText: /Stránky|Pages/ }).click();
        await expect(page.getByTestId('grid-tile-link')).toHaveText(['Pricing', 'Contact']);

        await page.getByTestId('grid-filter-section').first().click();
        await page.getByTestId('grid-filter-query').fill('layout 238');
        await expect(page.getByTestId('grid-tile-link')).toHaveText(['Multi']);
    });

    test('lands on the grid with `overview.default: grid`, the address bar left at the mount', async ({ page }) => {
        await page.goto(`${PRESENTATION}/styleguide/`);
        await expect(page.getByTestId('grid-view')).toBeVisible();
        await expect(page).toHaveURL(`${PRESENTATION}/styleguide/`);
        await expect(page).toHaveTitle(/^(Náhledy|Previews) — /);
    });

    test('keeps Foundations as the landing without the key', async ({ page }) => {
        await page.goto('/styleguide/');
        await expect(page.getByTestId('grid-view')).toHaveCount(0);
        await expect(page.locator('iframe').first()).toHaveAttribute('src', '/styleguide/render/foundations/index');
    });
});

test.describe('overview grid with 300 entries', () => {
    const COUNT = 300;
    const RENDER_DELAY_MS = 150;

    async function syntheticCatalogue(page) {
        const stats = { inFlight: 0, maxInFlight: 0, started: 0 };
        await page.route('**/styleguide/api/components', (route) => route.fulfill({
            json: Array.from({ length: COUNT }, (_, i) => ({
                id: `synth-${i}`, name: `Synthetic ${String(i).padStart(3, '0')}`, category: i % 3 === 0 ? 'Block' : '',
                has_styleguide: true, has_default_variant: true, variants: [], aliases: [],
            })),
        }));
        await page.route('**/styleguide/api/pages', (route) => route.fulfill({ json: [] }));
        await page.route('**/styleguide/render/component/synth-*', async (route) => {
            stats.started += 1;
            stats.inFlight += 1;
            stats.maxInFlight = Math.max(stats.maxInFlight, stats.inFlight);
            await new Promise((resolve) => setTimeout(resolve, RENDER_DELAY_MS));
            stats.inFlight -= 1;
            await route.fulfill({ contentType: 'text/html', body: '<!doctype html><body style="margin:0;background:#eee">tile</body>' });
        });
        return stats;
    }

    test('loads only what is near the viewport, at most six at a time', async ({ page }, testInfo) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        const stats = await syntheticCatalogue(page);
        const t0 = Date.now();
        await page.goto('/styleguide/grid');
        await expect(page.getByTestId('grid-tile')).toHaveCount(COUNT);

        // Settle: every tile near the viewport has loaded, nothing is queued.
        await expect.poll(async () => page.locator('[data-testid="grid-tile"][data-state="loading"], [data-testid="grid-tile"][data-state="queued"]').count(), { timeout: 20_000 }).toBe(0);
        const settledMs = Date.now() - t0;
        const loadedAtRest = await page.locator('[data-testid="grid-tile"][data-state="loaded"]').count();
        const iframesAtRest = await page.locator('[data-testid="grid-tile"] iframe').count();

        expect(stats.maxInFlight).toBeLessThanOrEqual(6);
        expect(loadedAtRest).toBeGreaterThan(0);
        expect(loadedAtRest).toBeLessThan(COUNT / 3);
        expect(iframesAtRest).toBe(loadedAtRest);

        // Scroll to the end: the tiles there load, the cap still holds, and
        // the tiles scrolled past without loading never started.
        await page.getByTestId('grid-view').evaluate((el) => { el.scrollTop = el.scrollHeight; });
        await expect(page.locator('[data-testid="grid-tile"]').last()).toHaveAttribute('data-state', 'loaded', { timeout: 20_000 });
        const loadedAfterScroll = await page.locator('[data-testid="grid-tile"][data-state="loaded"]').count();
        expect(stats.maxInFlight).toBeLessThanOrEqual(6);
        expect(loadedAfterScroll).toBeLessThan(COUNT);

        const numbers = {
            entries: COUNT, renderDelayMs: RENDER_DELAY_MS, settledMs, loadedAtRest, loadedAfterScroll,
            maxInFlight: stats.maxInFlight, renderRequests: stats.started,
        };
        testInfo.annotations.push({ type: 'grid-numbers', description: JSON.stringify(numbers) });
        console.log('[grid-numbers]', JSON.stringify(numbers));
    });
});
