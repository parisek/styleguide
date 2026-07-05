import { test, expect } from '@playwright/test';

// Fixture: tests/fixtures/templates/component/multi ships two file-convention
// siblings (styleguide.dark-bg.twig, styleguide.secondary.twig) plus a
// YAML-only `ghost` entry with no matching file (Task 1 must never surface
// it) -- exercises the full Task 1-3 chain end to end. `sample` has no
// styleguide.twig at all (falls back to its own component template), so it
// doubles as the "no variants discovered" fixture for the hidden-switcher
// case below.
test.describe('file-convention variants', () => {
    test('switcher is hidden for a single-variant component (no siblings)', async ({ page }) => {
        await page.goto('/styleguide/component/sample');
        await expect(page.getByTestId('variant-switcher')).toHaveCount(0);
    });

    test('switcher is visible for a multi-variant component, in id order, with no ghost entry', async ({ page }) => {
        // Order: Default (synthetic, always first) then the two discovered
        // siblings sorted by id (ComponentParser::discoverVariants() sorts by
        // strcmp) -- "dark-bg" < "secondary". `ghost` has no backing file, so
        // it must never appear here even though it's declared in the YAML
        // `variants:` map.
        await page.goto('/styleguide/component/multi');
        const switcher = page.getByTestId('variant-switcher');
        await expect(switcher).toBeVisible();
        // "Default" is i18n'd (toolbar.variant_default) -- the fixture server's
        // default locale is cs, so it renders as "Výchozí"; "dark-bg" (raw id,
        // no YAML label override) and "Secondary style" (explicit YAML label)
        // are locale-independent literals either way.
        await expect(switcher.getByRole('button')).toHaveText([/Default|Výchozí/, 'dark-bg', 'Secondary style']);
    });

    test('clicking a variant reloads the iframe with ?variant=, shows its content, and a client-side nav resets it', async ({ page }) => {
        await page.goto('/styleguide/component/multi');
        const iframe = page.frameLocator('iframe');
        const switcher = page.getByTestId('variant-switcher');
        // Default body comes from styleguide.twig (Renderer::renderInner()'s
        // no-variant candidate) -- not multi.twig, which is never reached
        // while a styleguide.twig sibling exists.
        await expect(iframe.locator('.multi')).toContainText('Multi demo (default variant)');

        await switcher.getByRole('button', { name: 'Secondary style' }).click();
        await expect(page).toHaveURL(/\?variant=secondary$/);
        await expect(page.locator('iframe').first()).toHaveAttribute('src', '/styleguide/render/component/multi?variant=secondary');
        await expect(iframe.locator('.multi')).toContainText('Multi demo (secondary variant)');

        // Router-push navigation (a plain sidebar click, no reload) resets the
        // variant just like a full page.goto does -- a different code path
        // (Phase 4 Task 3 review finding 2). "Gizmo" rather than "sample":
        // `sample` has no dedicated styleguide.twig (ComponentParser), so it
        // never appears as a sidebar link at all.
        await page.getByRole('link', { name: 'Gizmo', exact: true }).click();
        await expect(page).toHaveURL(/\/styleguide\/component\/gizmo$/);
        await expect(page).not.toHaveURL(/variant=/);
        await expect(page.locator('iframe').first()).toHaveAttribute('src', '/styleguide/render/component/gizmo');
    });

    test('deep link with ?variant= lands with that variant selected; unknown ids and full navigations fall back to Default', async ({ page }) => {
        const switcher = page.getByTestId('variant-switcher');
        const iframe = page.frameLocator('iframe');

        // A deep link with a valid ?variant= restores the selection on load.
        // ViewportToolbar.vue's active-button class is `bg-red-600` (plus
        // `dark:bg-red-500`), not the brief's guessed `bg-white`.
        await page.goto('/styleguide/component/multi?variant=secondary');
        await expect(switcher.getByRole('button', { name: 'Secondary style' })).toHaveClass(/bg-red-600/);
        await expect(iframe.locator('.multi')).toContainText('Multi demo (secondary variant)');

        // Same for a variant with no YAML label override (raw id as label).
        await page.goto('/styleguide/component/multi?variant=dark-bg');
        await expect(switcher.getByRole('button', { name: 'dark-bg' })).toHaveClass(/bg-red-600/);
        await expect(page.locator('iframe').first()).toHaveAttribute('src', '/styleguide/render/component/multi?variant=dark-bg');
        await expect(iframe.locator('.multi')).toContainText('Multi demo (dark-bg variant, no YAML label)');

        // An unknown/removed variant id (no matching sibling file) falls back
        // to Default silently, mirroring Router::whitelistVariant()'s
        // server-side behavior -- never surfaces as "selected".
        await page.goto('/styleguide/component/multi?variant=retired');
        await expect(switcher.getByRole('button', { name: /Default|Výchozí/ })).toHaveClass(/bg-red-600/);
        await expect(page.locator('iframe').first()).toHaveAttribute('src', '/styleguide/render/component/multi');

        // Navigating (full page load) to a different entry resets the variant
        // silently (no ?variant= carried over) -- the switcher itself also
        // disappears since `sample` has no discovered variants.
        await page.goto('/styleguide/component/sample');
        await expect(switcher).toHaveCount(0);
        await expect(page).not.toHaveURL(/variant=/);
    });
});
