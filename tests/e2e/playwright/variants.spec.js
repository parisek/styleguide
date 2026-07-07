import { test, expect } from '@playwright/test';

// Fixture: tests/fixtures/templates/component/multi ships two file-convention
// siblings. `styleguide.secondary.twig` carries its OWN {# title: description: #}
// front-comment annotation (the primary authoring convention) --
// `styleguide.dark-bg.twig` deliberately carries none, exercising the
// id-fallback path. `multi.twig`'s `variants:` map keeps only a `ghost`
// entry with no matching file (must never surface) -- the legacy map
// fallback for secondary is gone now that its metadata lives in the sibling
// file. `sample` has no styleguide.twig at all (falls back to its own
// component template), so it doubles as the "no variants discovered"
// fixture for the no-grid case below. The fixture server's default locale
// is `cs`, so the grid's synthetic "Default" tile label renders as its
// Czech string ("Výchozí") -- English literal fallbacks are asserted via
// regex alternation throughout.
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

        // The single-preview machinery (responsive width dropdown) is back
        // -- a grid-mode-only restriction.
        await expect(page.getByTestId('viewport-trigger')).toBeVisible();

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

// styleguide-2.0 rework: device presets now apply to every grid tile
// (scaled down per tile to fit its own cell — never upscaled), a rows/grid
// layout toggle controls how tiles flow, and a tile's header is itself a
// click/keyboard affordance that isolates it to the classic single preview
// (with a small "back to all" control to return).
test.describe('variant grid v2 — device presets, layout toggle, click-to-isolate', () => {
    // styleguide 2.0 UX fix: every tile shares the same preset and (uniform
    // cell widths) the same zoom, so a per-tile "375 × 667 · NN %" readout
    // in each tile header was pure repeated noise. The shared scale now
    // shows ONCE in the toolbar's viewport trigger label instead, in the
    // same "(NN %)" convention the classic single preview's dimensions
    // readout already uses.
    //
    // Manual "4" columns on the same 1680px canvas the density tests below
    // use is deliberate: "Auto" density's own minmax() basis at Mobile's
    // 375px preset width already fits fewer, WIDER-than-375px columns on
    // this canvas (see "Auto packs multiple Mobile-preset tiles per row"
    // below, which nets a zoom of exactly 1) -- forcing exactly 4 equal
    // columns instead gives each tile a ~330px cell, genuinely narrower
    // than the 375px preset, so the shared zoom actually drops below 100%.
    test('grid mode shows the shared scale ONCE in the toolbar trigger label, not per tile', async ({ page }) => {
        await page.setViewportSize({ width: 1680, height: 900 });
        await page.goto('/styleguide/component/multi');
        await expect(page.getByTestId('variant-grid')).toBeVisible();

        // Grid mode no longer hides the responsive-width dropdown.
        await expect(page.getByTestId('viewport-trigger')).toBeVisible();
        await page.getByTestId('viewport-trigger').click();
        await page.getByTestId('viewport-preset-mobile').click();
        await page.getByTestId('variant-columns-trigger').click();
        await page.getByTestId('variant-columns-4').click();

        const tiles = page.getByTestId('variant-tile');
        await expect(tiles).toHaveCount(3);
        // No per-tile scale readout anywhere in the grid -- removed as
        // redundant noise (every tile shares the identical preset + zoom).
        await expect(page.getByTestId('variant-tile-scale')).toHaveCount(0);

        for (let i = 0; i < 3; i++) {
            const iframe = tiles.nth(i).locator('iframe');
            // Logical preset size (Mobile: 375×667) — the actual VISIBLE
            // size is this scaled down (never up) to fit the tile's own
            // cell via `transform: scale(...)`.
            await expect(iframe).toHaveAttribute('style', /width: 375px/);
            await expect(iframe).toHaveAttribute('style', /height: 667px/);
        }

        // The toolbar's single trigger label carries the shared scale
        // instead -- and the forced 4-column cell here is narrower than
        // 375px, so it must actually read below 100%.
        const trigger = page.getByTestId('viewport-trigger');
        await expect(trigger).toContainText('375 × 667');
        const triggerText = await trigger.innerText();
        const match = triggerText.match(/\((\d+) %\)/);
        expect(match).not.toBeNull();
        expect(Number(match[1])).toBeLessThan(100);
    });

    test('the Full preset (default) renders fluid tiles with no scale readout, and no percentage in the trigger', async ({ page }) => {
        await page.goto('/styleguide/component/multi');
        await expect(page.getByTestId('variant-tile-scale')).toHaveCount(0);
        const firstIframe = page.getByTestId('variant-tile').nth(0).locator('iframe');
        await expect(firstIframe).not.toHaveAttribute('style', /transform/);
        // Full's trigger reads a fixed "100 %" (ViewportToolbar.vue's own
        // 'full' branch, independent of the grid's shared-zoom reporting).
        await expect(page.getByTestId('viewport-trigger')).toContainText('100 %');
    });

    // Replaces the pre-2.0 "rows" layout entirely (styleguide-2.0 density
    // control: Auto | 1 | 2 | 3 | 4) -- a single-column grid gives every tile
    // its own row of subgrid header/canvas tracks (VariantGrid.vue), which
    // is visually identical to the old dedicated flex-col "rows" branch.
    test('density control: "1" stacks every tile in a single column; "Auto" is the default', async ({ page }) => {
        await page.goto('/styleguide/component/multi');
        const tiles = page.getByTestId('variant-tile');
        await expect(tiles).toHaveCount(3);

        // Default density is "Auto" — the dropdown trigger exists and
        // reads "Auto"; opening it highlights the Auto row.
        const columnsTrigger = page.getByTestId('variant-columns-trigger');
        await expect(columnsTrigger).toBeVisible();
        await expect(columnsTrigger).toContainText('Auto');
        await columnsTrigger.click();
        await expect(page.getByTestId('variant-columns-auto')).toHaveClass(/text-red-700/);

        await page.getByTestId('variant-columns-1').click();
        await expect(columnsTrigger).toContainText(/1 (column|sloupec)/);

        const boxes = await tiles.evaluateAll((els) => els.map((el) => {
            const r = el.getBoundingClientRect();
            return { left: r.left, top: r.top, bottom: r.bottom };
        }));
        expect(boxes).toHaveLength(3);
        // Single column: every tile starts at the same left edge...
        const lefts = new Set(boxes.map((b) => Math.round(b.left)));
        expect(lefts.size).toBe(1);
        // ...and stacks top to bottom, one full row per tile.
        expect(boxes[1].top).toBeGreaterThanOrEqual(boxes[0].bottom - 1);
        expect(boxes[2].top).toBeGreaterThanOrEqual(boxes[1].bottom - 1);
    });

    // "Auto" derives the auto-fit minmax() basis from the ACTIVE preset
    // (lib/tileGeometry.js's autoGridColumnBasis()) instead of one fixed
    // constant for every preset -- a Mobile preset's small effective width
    // fits multiple tiles per row on a wide canvas, unlike a Desktop preset
    // on the same canvas (see the next test).
    test('density control: "Auto" packs multiple Mobile-preset tiles per row on a wide canvas', async ({ page }) => {
        await page.setViewportSize({ width: 1680, height: 900 });
        await page.goto('/styleguide/component/multi');
        const tiles = page.getByTestId('variant-tile');
        await expect(tiles).toHaveCount(3);

        await expect(page.getByTestId('variant-columns-trigger')).toContainText('Auto');
        await page.getByTestId('viewport-trigger').click();
        await page.getByTestId('viewport-preset-mobile').click();

        const tops = await tiles.evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().top)));
        // At least two of the three tiles share a row (same top) -- i.e.
        // strictly more than one tile per row, which a fixed 420px-only
        // basis would already satisfy, but a preset-BLIND implementation
        // (always the Full-preset basis) would too; the real assertion this
        // guards is the density DIFFERENCE from Desktop in the next test.
        const rowCount = new Set(tops).size;
        expect(rowCount).toBeLessThan(3);

        // Auto's own minmax() basis here already gives each tile a cell at
        // least as wide as Mobile's 375px preset, so the shared zoom is 1
        // -- the trigger shows dimensions alone, no "(NN %)" suffix (at
        // zoom 1 the dims already say everything there is to say).
        await expect(page.getByTestId('viewport-trigger')).not.toContainText('%');
    });

    test('density control: "Auto" packs fewer Desktop-preset tiles per row than Mobile on the same wide canvas', async ({ page }) => {
        await page.setViewportSize({ width: 1680, height: 900 });
        await page.goto('/styleguide/component/multi');
        const tiles = page.getByTestId('variant-tile');
        await expect(tiles).toHaveCount(3);

        await page.getByTestId('viewport-trigger').click();
        await page.getByTestId('viewport-preset-desktop').click();

        const tops = await tiles.evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().top)));
        // Desktop's preset width (1280px) plus tile chrome padding already
        // exceeds most of a 1680px canvas, so every tile lands in its own
        // row -- unlike Mobile's basis in the test above.
        const rowCount = new Set(tops).size;
        expect(rowCount).toBe(3);
    });

    test('density control: "2" always packs exactly two tiles per row, regardless of the active preset', async ({ page }) => {
        await page.setViewportSize({ width: 1680, height: 900 });
        await page.goto('/styleguide/component/multi');
        const tiles = page.getByTestId('variant-tile');
        await expect(tiles).toHaveCount(3);

        // Desktop would otherwise force one tile per row under Auto (see
        // above) -- an explicit "2" overrides that regardless of preset.
        await page.getByTestId('viewport-trigger').click();
        await page.getByTestId('viewport-preset-desktop').click();
        const columnsTrigger = page.getByTestId('variant-columns-trigger');
        await columnsTrigger.click();
        await page.getByTestId('variant-columns-2').click();
        await expect(columnsTrigger).toContainText(/2 (columns|sloupce)/);

        const boxes = await tiles.evaluateAll((els) => els.map((el) => {
            const r = el.getBoundingClientRect();
            return { left: Math.round(r.left), top: Math.round(r.top) };
        }));
        // Tiles 0 and 1 share the first row (same top, different left);
        // tile 2 starts a new row.
        expect(boxes[0].top).toBe(boxes[1].top);
        expect(boxes[0].left).not.toBe(boxes[1].left);
        expect(boxes[2].top).toBeGreaterThan(boxes[0].top);
    });

    // Regression test for a tile-centering bug: computeTileGeometry()'s zoom
    // used to be fit against the content-area wrapper's PADDING-BOX width
    // (`el.clientWidth`, which includes the wrapper's own `p-3`), overstating
    // the space actually available to the scaled screen by the padding
    // amount -- the scaled iframe then rendered past the wrapper's true
    // content box and got cropped by its `overflow: hidden`, clipping the
    // right edge of every fixed-width-preset tile's rendered screen
    // (VariantGrid.vue's registerCell() now reads the wrapper's content-box
    // width -- clientWidth minus its own padding -- so both the initial
    // synchronous measurement and every ResizeObserver tick agree). Real
    // layout only, hence Playwright rather than tileGeometry.spec.js: the
    // bug lived in what gets measured, not in the (already-correct) math
    // that consumes the measurement.
    test('a fixed-width preset scales each tile centered in its cell -- no left/right gap asymmetry, no clipped screen edge', async ({ page }) => {
        await page.setViewportSize({ width: 1680, height: 900 });
        await page.goto('/styleguide/component/multi');
        const tiles = page.getByTestId('variant-tile');
        await expect(tiles).toHaveCount(3);

        await page.getByTestId('viewport-trigger').click();
        await page.getByTestId('viewport-preset-desktop').click();
        await page.getByTestId('variant-columns-trigger').click();
        await page.getByTestId('variant-columns-4').click();

        const measurements = await tiles.evaluateAll((els) => els.map((tile) => {
            const cell = tile.children[1]; // content-area wrapper (registerCell's ref target)
            const scaledWrapper = cell.querySelector(':scope > div'); // the width/height-styled scaling box
            const iframe = scaledWrapper.querySelector('iframe');
            const cellRect = cell.getBoundingClientRect();
            const wrapperRect = scaledWrapper.getBoundingClientRect();
            const iframeRect = iframe.getBoundingClientRect();
            return {
                leftGap: wrapperRect.left - cellRect.left,
                rightGap: cellRect.right - wrapperRect.right,
                // A positive value here means the scaled screen's own right
                // edge extends past the wrapper that clips it -- i.e. it is
                // being cropped off, not just visually mis-centered.
                iframeOverflowRight: iframeRect.right - wrapperRect.right,
            };
        }));

        for (const { leftGap, rightGap, iframeOverflowRight } of measurements) {
            expect(Math.abs(leftGap - rightGap)).toBeLessThanOrEqual(2);
            expect(iframeOverflowRight).toBeLessThanOrEqual(0.5);
        }
    });

    // styleguide 2.0 UX redesign: the earlier "← All" toolbar back control is
    // gone -- the toolbar breadcrumb's component-name crumb becomes the
    // "back to the grid" affordance once a variant is isolated, and a
    // trailing Variant segment appears alongside it.
    test('the Default tile header is not clickable; every other tile header isolates it to the classic single preview, with the breadcrumb crumb returning to the grid', async ({ page }) => {
        await page.goto('/styleguide/component/multi');
        const tiles = page.getByTestId('variant-tile');

        // Default tile (index 0): no role="button", not focusable.
        const defaultHeader = tiles.nth(0).getByTestId('variant-tile-header');
        await expect(defaultHeader).not.toHaveAttribute('role', 'button');
        await expect(defaultHeader).not.toHaveAttribute('tabindex', '0');

        // dark-bg tile (index 1): clicking its header isolates it.
        const crumb = page.getByTestId('breadcrumb-item-name');
        await expect(crumb).not.toHaveJSProperty('tagName', 'BUTTON');
        await expect(page.getByTestId('breadcrumb-variant')).toHaveCount(0);
        await tiles.nth(1).getByTestId('variant-tile-header').click();
        await expect(page).toHaveURL(/variant=dark-bg/);
        await expect(page.getByTestId('variant-grid')).toHaveCount(0);
        const iframe = page.frameLocator('iframe');
        await expect(iframe.locator('.multi')).toContainText('Multi demo (dark-bg variant, no YAML label)');

        // The breadcrumb crumb becomes a button and a Variant segment
        // appears once isolated; clicking the crumb returns to the grid.
        await expect(crumb).toHaveJSProperty('tagName', 'BUTTON');
        await expect(page.getByTestId('breadcrumb-variant')).toHaveText('dark-bg');
        await crumb.click();
        await expect(page).not.toHaveURL(/variant=/);
        await expect(page.getByTestId('variant-grid')).toBeVisible();
        await expect(page.getByTestId('variant-tile')).toHaveCount(3);
        await expect(page.getByTestId('breadcrumb-variant')).toHaveCount(0);
    });
});
