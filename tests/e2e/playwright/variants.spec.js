import { test, expect } from '@playwright/test';

// Fixture: tests/fixtures/templates/component/multi ships two file-convention
// siblings (styleguide.dark-bg.twig, styleguide.secondary.twig) plus a
// YAML-only `ghost` entry with no matching file (must never surface) --
// exercises the full variant-discovery/render/switcher chain end to end.
// `sample` has no styleguide.twig at all (falls back to its own component
// template), so it doubles as the "no variants discovered" fixture for the
// hidden-switcher / no-stack cases below. The fixture server's default
// locale is `cs`, so the stacked view's synthetic "Default" heading and the
// switcher's leading pill render as their Czech strings ("Výchozí" /
// "Vše" respectively) -- English literal fallbacks are asserted via regex
// alternation throughout.
test.describe('file-convention variants', () => {
    test('switcher is hidden for a single-variant component (no siblings)', async ({ page }) => {
        await page.goto('/styleguide/component/sample');
        await expect(page.getByTestId('variant-switcher')).toHaveCount(0);
        // No discovered variants -> single default block, never the stacked
        // markup (BC: byte-identical to pre-stacking output).
        const iframe = page.frameLocator('iframe');
        await expect(iframe.locator('.sg-variant-heading')).toHaveCount(0);
    });

    test('switcher is visible for a multi-variant component, in id order, with no ghost entry', async ({ page }) => {
        // Order: the leading pill (synthetic, always first, now labelled
        // "Vše"/"All" -- the default view shows every variant, not just the
        // implicit default) then the two discovered siblings sorted by id
        // (ComponentParser::discoverVariants() sorts by strcmp) --
        // "dark-bg" < "secondary". `ghost` has no backing file, so it must
        // never appear here even though it's declared in the YAML
        // `variants:` map.
        await page.goto('/styleguide/component/multi');
        const switcher = page.getByTestId('variant-switcher');
        await expect(switcher).toBeVisible();
        await expect(switcher.getByRole('button')).toHaveText([/All|Vše/, 'dark-bg', 'Secondary style']);
    });

    test('default view stacks every block with headings and the description; clicking a variant still isolates it', async ({ page }) => {
        await page.goto('/styleguide/component/multi');
        const iframe = page.frameLocator('iframe');
        const switcher = page.getByTestId('variant-switcher');

        // Stacked default view: default body first, then both variants in
        // discovery order, each under its own heading. Default body comes
        // from styleguide.twig (Renderer::renderInner()'s no-variant
        // candidate) -- not multi.twig, which is never reached while a
        // styleguide.twig sibling exists.
        await expect(iframe.locator('.sg-variant-heading')).toHaveText([/Default|Výchozí/, 'dark-bg', 'Secondary style']);
        await expect(iframe.locator('body')).toContainText('Multi demo (default variant)');
        await expect(iframe.locator('body')).toContainText('Multi demo (dark-bg variant, no YAML label)');
        await expect(iframe.locator('body')).toContainText('Multi demo (secondary variant)');
        // secondary's YAML `description` renders under its heading; dark-bg
        // has none, so there is exactly one description paragraph.
        await expect(iframe.locator('.sg-variant-description')).toHaveText('Tuned for a secondary-toned surface.');
        await expect(iframe.locator('.sg-variant-description')).toHaveCount(1);

        // Selecting a variant (pill or deep link) always isolates it --
        // single block, no headings, no stack.
        await switcher.getByRole('button', { name: 'Secondary style' }).click();
        await expect(page).toHaveURL(/\?variant=secondary$/);
        await expect(page.locator('iframe').first()).toHaveAttribute('src', '/styleguide/render/component/multi?variant=secondary');
        await expect(iframe.locator('.multi')).toContainText('Multi demo (secondary variant)');
        await expect(iframe.locator('.sg-variant-heading')).toHaveCount(0);
        await expect(iframe.locator('body')).not.toContainText('Multi demo (default variant)');

        // Router-push navigation (a plain sidebar click, no reload) resets the
        // variant just like a full page.goto does -- a different code path.
        // "Gizmo" rather than "sample": `sample` has no dedicated
        // styleguide.twig (ComponentParser), so it never appears as a
        // sidebar link at all.
        await page.getByRole('link', { name: 'Gizmo', exact: true }).click();
        await expect(page).toHaveURL(/\/styleguide\/component\/gizmo$/);
        await expect(page).not.toHaveURL(/variant=/);
        await expect(page.locator('iframe').first()).toHaveAttribute('src', '/styleguide/render/component/gizmo');
    });

    test('deep link with ?variant= lands with that variant isolated; an unknown id falls back to the stacked default view', async ({ page }) => {
        const switcher = page.getByTestId('variant-switcher');
        const iframe = page.frameLocator('iframe');

        // A deep link with a valid ?variant= restores the selection on load,
        // isolated (no headings). ViewportToolbar.vue's active-button class
        // is `bg-red-600` (plus `dark:bg-red-500`), not the brief's guessed
        // `bg-white`.
        await page.goto('/styleguide/component/multi?variant=secondary');
        await expect(switcher.getByRole('button', { name: 'Secondary style' })).toHaveClass(/bg-red-600/);
        await expect(iframe.locator('.multi')).toContainText('Multi demo (secondary variant)');
        await expect(iframe.locator('.sg-variant-heading')).toHaveCount(0);

        // Same for a variant with no YAML label override (raw id as label).
        await page.goto('/styleguide/component/multi?variant=dark-bg');
        await expect(switcher.getByRole('button', { name: 'dark-bg' })).toHaveClass(/bg-red-600/);
        await expect(page.locator('iframe').first()).toHaveAttribute('src', '/styleguide/render/component/multi?variant=dark-bg');
        await expect(iframe.locator('.multi')).toContainText('Multi demo (dark-bg variant, no YAML label)');
        await expect(iframe.locator('.sg-variant-heading')).toHaveCount(0);

        // An unknown/removed variant id (no matching sibling file) is
        // whitelisted away client-side (useVariant()'s computed nulls it out
        // before building the iframe src), so the actual render request
        // carries NO ?variant= at all -- indistinguishable, server-side,
        // from a bare no-variant load. It therefore lands on the same
        // stacked default view (not a single isolated block), with the
        // leading "All"/"Vše" pill showing as selected -- mirroring
        // Router::whitelistVariant()'s silent server-side fallback, just
        // resolving to the stack now that the entry has one.
        await page.goto('/styleguide/component/multi?variant=retired');
        await expect(switcher.getByRole('button', { name: /All|Vše/ })).toHaveClass(/bg-red-600/);
        await expect(page.locator('iframe').first()).toHaveAttribute('src', '/styleguide/render/component/multi');
        await expect(iframe.locator('.sg-variant-heading')).toHaveCount(3);

        // Navigating (full page load) to a different entry resets the variant
        // silently (no ?variant= carried over) -- the switcher itself also
        // disappears since `sample` has no discovered variants.
        await page.goto('/styleguide/component/sample');
        await expect(switcher).toHaveCount(0);
        await expect(page).not.toHaveURL(/variant=/);
    });
});
