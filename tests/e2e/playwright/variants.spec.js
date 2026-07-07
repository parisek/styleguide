import { test, expect } from '@playwright/test';

// Fixture: tests/fixtures/templates/component/multi ships two file-convention
// siblings (styleguide.dark-bg.twig, styleguide.secondary.twig) plus a
// YAML-only `ghost` entry with no matching file (must never surface) --
// exercises the full variant-discovery/render/grid chain end to end.
// `sample` has no styleguide.twig at all (falls back to its own component
// template), so it doubles as the "no variants discovered" fixture for the
// no-grid case below. The fixture server's default locale is `cs`, so the
// grid's synthetic "Default" tile label renders as its Czech string
// ("Výchozí") -- English literal fallbacks are asserted via regex
// alternation throughout.
//
// styleguide-2.0 redesign: the toolbar pill switcher (commit dc4715a) and
// the server-side stacked view (commit 901e1b8) are both GONE. An entry
// with discovered variants and no `?variant=` now renders a responsive GRID
// of independent preview tiles -- one per variant, default fixture first --
// instead of either a switcher+single-preview or a server-stacked document.
// A deep-linked `?variant=<id>` still isolates to the classic single
// preview; an unknown/removed id falls back to the grid (not a 404, not a
// stale "selected" pill -- there is no pill any more).
test.describe('file-convention variants', () => {
    test('no grid, no toolbar pills, single preview for a component with no discovered variants', async ({ page }) => {
        await page.goto('/styleguide/component/sample');
        await expect(page.getByTestId('variant-switcher')).toHaveCount(0);
        await expect(page.getByTestId('variant-grid')).toHaveCount(0);
        await expect(page.getByTestId('variant-tile')).toHaveCount(0);
        // Classic single iframe still renders.
        await expect(page.locator('iframe').first()).toHaveAttribute('src', '/styleguide/render/component/sample');
    });

    test('default view (no ?variant=) renders a grid of 3 tiles -- Default, dark-bg, secondary -- each with its own iframe content', async ({ page }) => {
        await page.goto('/styleguide/component/multi');

        // No toolbar pill switcher anywhere in this redesign.
        await expect(page.getByTestId('variant-switcher')).toHaveCount(0);

        const grid = page.getByTestId('variant-grid');
        await expect(grid).toBeVisible();
        const tiles = page.getByTestId('variant-tile');
        await expect(tiles).toHaveCount(3);

        // Tile order: default fixture first, then discovered variants in id
        // order (dark-bg < secondary, same as ComponentParser::discoverVariants()'s
        // strcmp sort). `ghost` (YAML-only, no backing file) must never surface.
        const labels = page.getByTestId('variant-tile-label');
        await expect(labels).toHaveText([/Default|Výchozí/, 'dark-bg', 'Secondary style']);

        // Each tile owns an independent <iframe> rendering only its own
        // variant's body -- not a server-side stack inside one document.
        const defaultTile = tiles.nth(0).frameLocator('iframe');
        await expect(defaultTile.locator('.multi')).toContainText('Multi demo (default variant)');

        const darkBgTile = tiles.nth(1).frameLocator('iframe');
        await expect(darkBgTile.locator('.multi')).toContainText('Multi demo (dark-bg variant, no YAML label)');

        const secondaryTile = tiles.nth(2).frameLocator('iframe');
        await expect(secondaryTile.locator('.multi')).toContainText('Multi demo (secondary variant)');

        // secondary's YAML `description` renders under its tile header;
        // dark-bg and the default tile have none.
        await expect(tiles.nth(0).getByTestId('variant-tile-description')).toHaveCount(0);
        await expect(tiles.nth(1).getByTestId('variant-tile-description')).toHaveCount(0);
        await expect(tiles.nth(2).getByTestId('variant-tile-description')).toHaveText('Tuned for a secondary-toned surface.');

        // The a11y check button needs a single iframe, so it's disabled (not
        // hidden) in grid mode -- see ViewportToolbar.vue.
        await expect(page.getByTestId('a11y-check-button')).toBeDisabled();
    });

    test('?variant=<id> deep link shows the classic single preview, not the grid', async ({ page }) => {
        await page.goto('/styleguide/component/multi?variant=secondary');

        await expect(page.getByTestId('variant-grid')).toHaveCount(0);
        await expect(page.getByTestId('variant-tile')).toHaveCount(0);
        await expect(page.getByTestId('variant-switcher')).toHaveCount(0);

        await expect(page.locator('iframe').first()).toHaveAttribute('src', '/styleguide/render/component/multi?variant=secondary');
        const iframe = page.frameLocator('iframe');
        await expect(iframe.locator('.multi')).toContainText('Multi demo (secondary variant)');
        await expect(iframe.locator('body')).not.toContainText('Multi demo (default variant)');

        // The single-preview machinery (responsive width dropdown) is back,
        // and the a11y check button is enabled again -- both are grid-mode-only
        // restrictions.
        await expect(page.getByTestId('viewport-trigger')).toBeVisible();
        await expect(page.getByTestId('a11y-check-button')).toBeEnabled();

        // A different variant id isolates to its own body, still no grid.
        await page.goto('/styleguide/component/multi?variant=dark-bg');
        await expect(page.getByTestId('variant-grid')).toHaveCount(0);
        await expect(page.locator('iframe').first()).toHaveAttribute('src', '/styleguide/render/component/multi?variant=dark-bg');
        await expect(iframe.locator('.multi')).toContainText('Multi demo (dark-bg variant, no YAML label)');
    });

    test('an unknown/removed variant id falls back to the grid, not a single preview or a 404', async ({ page }) => {
        // useVariant()'s computed whitelists an id against the entry's
        // discovered variants and nulls it out when it doesn't match --
        // the render request that reaches the server therefore carries NO
        // `?variant=` at all, indistinguishable from a bare no-variant load,
        // so it lands on the grid exactly like `/styleguide/component/multi`.
        await page.goto('/styleguide/component/multi?variant=retired');

        await expect(page.getByTestId('variant-grid')).toBeVisible();
        await expect(page.getByTestId('variant-tile')).toHaveCount(3);
        await expect(page.locator('iframe').first()).toHaveAttribute('src', '/styleguide/render/component/multi');
    });

    test('router-push navigation to a different entry resets the variant and swaps grid for single preview appropriately', async ({ page }) => {
        await page.goto('/styleguide/component/multi?variant=secondary');
        await expect(page.getByTestId('variant-grid')).toHaveCount(0);

        // "Gizmo" rather than "sample": `sample` has no dedicated
        // styleguide.twig (ComponentParser), so it never appears as a
        // sidebar link at all. Gizmo has no variants -> single preview,
        // no grid, matching a plain no-variants entry.
        await page.getByRole('link', { name: 'Gizmo', exact: true }).click();
        await expect(page).toHaveURL(/\/styleguide\/component\/gizmo$/);
        await expect(page).not.toHaveURL(/variant=/);
        await expect(page.locator('iframe').first()).toHaveAttribute('src', '/styleguide/render/component/gizmo');
        await expect(page.getByTestId('variant-grid')).toHaveCount(0);

        // Navigating to the multi-variant entry (no ?variant= carried over)
        // lands on the grid.
        await page.goto('/styleguide/component/multi');
        await expect(page.getByTestId('variant-grid')).toBeVisible();
        await expect(page).not.toHaveURL(/variant=/);
    });
});
