import { test, expect } from '@playwright/test';

test.describe('Styleguide SPA', () => {
    test('landing hydrates on Foundations, cs locale, sidebar shows translated labels', async ({ page }) => {
        // Replaces smoke-browser.sh section 1 (landing hydration).
        await page.goto('/styleguide/');
        await expect(page.locator('iframe').first()).toHaveAttribute('src', '/styleguide/render/foundations/index');
        await expect(page.locator('html')).toHaveAttribute('lang', 'cs');
        await expect(page.getByText('Přehled')).toBeVisible();
    });

    test('sidebar navigation updates the URL and the iframe src', async ({ page }) => {
        // Replaces smoke-browser.sh section 2 (navigation). Uses the "Sample
        // Doc" DOCS-section link rather than a component link: every
        // component fixture lives in the basic/blocks/gutenberg sections,
        // which are hit by the real Sidebar.vue bug documented below on the
        // two test.fixme cases (their wrapping <div v-show="items(section)
        // .length > 0"> never re-evaluates after the initial, pre-fetch
        // render, so those sections stay permanently display:none once the
        // catalog loads async). The Docs section isn't part of that v-for,
        // so it's unaffected and still exercises real router-push + iframe
        // src reactivity end to end.
        await page.goto('/styleguide/');
        await page.getByRole('link', { name: 'Sample Doc', exact: true }).click();
        await expect(page).toHaveURL(/\/styleguide\/doc\/sample-doc$/);
        await expect(page.locator('iframe').first()).toHaveAttribute('src', '/styleguide/render/doc/sample-doc');
    });

    test('viewport toolbar renders for a responsive:true component', async ({ page }) => {
        // Replaces smoke-browser.sh section 3a (issue #36 regression guard).
        await page.goto('/styleguide/component/sample');
        await expect(page.getByTestId('viewport-trigger')).toBeVisible();
    });

    test('selecting the Tablet preset sets the trigger label to Tablet and 768 x 1024', async ({ page }) => {
        // Replaces smoke-browser.sh section 3 (width preset).
        await page.goto('/styleguide/component/sample');
        await page.getByTestId('viewport-trigger').click();
        await page.getByTestId('viewport-preset-tablet').click();
        await expect(page.getByTestId('viewport-trigger')).toContainText('Tablet');
        await expect(page.getByTestId('viewport-trigger')).toContainText('768');
    });

    test('a responsive:false doc hides the viewport dropdown and pins the preview to Full', async ({ page }) => {
        // Replaces smoke-browser.sh section 3b (issue #34 regression guard).
        // Deliberately visited AFTER the Tablet-preset test above in the SAME
        // spec file would race on shared persisted state across tests; each
        // Playwright test gets a fresh browser context by default, so
        // sg-preview-width from the previous test does not leak here.
        await page.goto('/styleguide/doc/sample-doc');
        await expect(page.getByTestId('viewport-trigger')).toHaveCount(0);
        const wrapper = page.getByTestId('iframe-wrapper');
        await expect(wrapper).toHaveCSS('width', /.+/); // sanity: element exists and is styled
        const style = await wrapper.getAttribute('style');
        expect(style).toContain('width: 100%');
    });

    // REAL BUG, not a test-authoring issue (see task-12-report.md for the
    // isolated repro): Sidebar.vue's basic/blocks/gutenberg sections share
    //     <div v-for="section in [...]" v-show="items(section).length > 0">
    // Vue 3's v-show directive, applied to the SAME element as v-for, only
    // evaluates once at first render and never re-applies on later updates
    // -- even though the sibling {{ items(section).length }} interpolation
    // one line down DOES keep updating correctly. Real app boot calls
    // `catalog.init()` (async fetch) WITHOUT awaiting it before `app.mount()`
    // (main.js), so the very first render always sees 0 items for every
    // category section -- v-show freezes them at display:none forever, even
    // after the fetch resolves and populates the catalog. Confirmed with a
    // minimal 6-line repro component outside Sidebar.vue entirely (isolates
    // it to the v-for+v-show combination, not any app-specific logic). Net
    // effect: on a real page load, the Basic/Blocks/Gutenberg sidebar
    // sections are always empty-looking, even though their header COUNTS
    // show the correct nonzero number. Fixed with test.fixme rather than
    // rewritten around, per this task's brief: report + fixme, don't
    // paper over with a weaker assertion.
    test.fixme('a >=3 prefix cluster renders as a collapsible group with suffix-only children; a singleton stays flat', async ({ page }) => {
        // Replaces smoke-browser.sh section 3c (issue #38 regression guard).
        await page.goto('/styleguide/');
        await expect(page.getByRole('button', { name: /^Widget/ })).toBeVisible();
        await expect(page.getByRole('link', { name: 'One', exact: true })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Widget - one', exact: true })).toHaveCount(0);
        await expect(page.getByRole('link', { name: 'Gizmo', exact: true })).toBeVisible();
    });

    test.fixme('a search query flattens the Widget group to full names', async ({ page }) => {
        // Same root cause as the fixme above: the "blocks" section's
        // wrapping div is stuck display:none from the initial pre-fetch
        // render, so nothing inside it -- including this search-driven flat
        // list -- is ever visible, regardless of ui.searchQuery.
        await page.goto('/styleguide/');
        await page.getByPlaceholder(/./).first().fill('widget');
        await expect(page.getByRole('link', { name: 'Widget - one', exact: true })).toBeVisible();
    });

    test('Cmd+K focuses the search input; Escape clears it', async ({ page }) => {
        // Replaces smoke-browser.sh section 4.
        await page.goto('/styleguide/');
        await page.keyboard.press('Meta+k');
        const input = page.locator('input[type="text"]').first();
        await expect(input).toBeFocused();
        await input.fill('widget');
        await page.keyboard.press('Escape');
        await expect(input).toHaveValue('');
    });

    test('switching locale to en updates strings and <html lang>', async ({ page }) => {
        // Replaces smoke-browser.sh section 5. `exact: true` matters here:
        // Playwright's non-exact name match is a case-insensitive substring
        // test, and "Dokumentace" contains "en" (dokum-EN-tace), so the
        // loose match resolves to two buttons (strict-mode violation).
        await page.goto('/styleguide/');
        await page.getByRole('button', { name: 'en', exact: true }).click();
        await expect(page.locator('html')).toHaveAttribute('lang', 'en');
        await expect(page.getByText('Overview')).toBeVisible();
    });

    test('drag-resizing the Custom-preset handle changes the emulated width', async ({ page }) => {
        await page.goto('/styleguide/component/sample');
        await page.getByTestId('viewport-trigger').click();
        await page.getByLabel(/Custom width|Vlastní šířka/).fill('500');
        await page.keyboard.press('Enter');
        // The dropdown popover stays open after Enter (only selecting a
        // preset auto-closes it) and its custom-width input sits directly
        // on top of the drag handle at this viewport size -- a real click
        // there hits the input, not the handle underneath (confirmed via
        // elementFromPoint()). Close the popover first by re-clicking the
        // trigger, same as a real user would before grabbing the handle.
        await page.getByTestId('viewport-trigger').click();
        const handle = page.getByTestId('drag-handle-right');
        const box = await handle.boundingBox();
        await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
        await page.mouse.down();
        await page.mouse.move(box.x + 100, box.y + box.height / 2, { steps: 10 });
        await page.mouse.up();
        await expect(page.getByTestId('viewport-trigger')).not.toContainText('500 px');
    });

    test('rotating a Mobile preset swaps the effective width/height', async ({ page }) => {
        // NOTE: the brief this spec was ported from assumed a single
        // data-testid="rotate-button" toggle. ViewportToolbar.vue actually
        // exposes the orientation control as a two-button portrait/landscape
        // segmented group (no rotate-button testid exists) -- a legitimate,
        // working feature, just modeled differently than the brief guessed.
        // Selecting the landscape button by its accessible name is the
        // correct way to drive the same behavior through the real DOM.
        await page.goto('/styleguide/component/sample');
        await page.getByTestId('viewport-trigger').click();
        await page.getByTestId('viewport-preset-mobile').click();
        await expect(page.getByTestId('viewport-trigger')).toContainText('375');
        // Selecting a preset auto-closes the dropdown (setPreset(); dropdownOpen
        // = false in ViewportToolbar.vue) -- reopen it to reach the
        // orientation control that lives inside the same popover.
        await page.getByTestId('viewport-trigger').click();
        await page.getByRole('button', { name: /Landscape|Na šířku/, exact: true }).click();
        await expect(page.getByTestId('viewport-trigger')).toContainText('667');
    });

    test('canvas mode navigates the top-level page to the render URL with canvas=1', async ({ page }) => {
        await page.goto('/styleguide/component/sample');
        await page.getByRole('button', { name: /Canvas/i }).click();
        await expect(page).toHaveURL(/canvas=1/);
    });

    test('the Fields drawer lists a component\'s declared fields once expanded', async ({ page }) => {
        await page.goto('/styleguide/component/with-fields');
        const drawerToggle = page.getByRole('button', { name: /Fields|Pole/ });
        await expect(drawerToggle).toContainText('3');
        await drawerToggle.click();
        await expect(page.getByText('title', { exact: true })).toBeVisible();
        await expect(page.getByText('label', { exact: true })).toBeVisible();
    });

    test('standalone render shows the back-bar; the same render inside the SPA iframe hides it', async ({ page }) => {
        // Replaces smoke-browser.sh section 6 — render-cell.twig's back-bar is
        // plain PHP/Twig + vanilla JS, entirely untouched by this rewrite;
        // ported here so the full parity checklist lives in one suite.
        await page.goto('/styleguide/render/component/sample');
        const bar = page.locator('#sg-standalone-bar');
        await expect(bar).toBeVisible();
        await expect(bar.locator('a')).toHaveAttribute('href', '/styleguide/component/sample');

        await page.goto('/styleguide/component/sample');
        const frame = page.frameLocator('iframe').first();
        await expect(frame.locator('#sg-standalone-bar')).toBeHidden();
    });
});
