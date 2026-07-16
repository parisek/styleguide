import { test, expect } from '@playwright/test';

test.describe('Styleguide SPA', () => {
    test('landing hydrates on Foundations, cs locale, sidebar shows translated labels', async ({ page }) => {
        // Replaces smoke-browser.sh section 1 (landing hydration).
        await page.goto('/styleguide/');
        await expect(page.locator('iframe').first()).toHaveAttribute('src', '/styleguide/render/foundations/index');
        await expect(page.locator('html')).toHaveAttribute('lang', 'cs');
        await expect(page.getByText('Přehled')).toBeVisible();
    });

    test('browser-tab favicon link is populated from the configured project.favicon', async ({ page }) => {
        // Producer/consumer integration guard: SpaConfigTest (PHP) proves
        // `favicon` lands in the #sg-config payload and documentChrome.spec.js
        // (Vitest) proves applyDocumentChrome() consumes it — but both passed
        // while the <link> stayed empty for 10 releases after the Vue rewrite
        // dropped the server-side tag patch without a client-side consumer.
        // Only a real-browser assert catches a missing wiring like that.
        await page.goto('/styleguide/');
        await expect(page.locator('#sg-favicon-tag')).toHaveAttribute('href', '/images/favicon.svg');
    });

    test('sidebar navigation updates the URL and the iframe src', async ({ page }) => {
        // Replaces smoke-browser.sh section 2 (navigation). Uses the "Sample
        // Doc" DOCS-section link rather than a component link simply because
        // Docs isn't part of the basic/blocks/gutenberg v-for loop -- still
        // exercises real router-push + iframe src reactivity end to end.
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

    // FIXED (task-12 follow-up, "fix round 1" in task-12-report.md): Sidebar.vue's
    // basic/blocks/gutenberg sections used to share
    //     <div v-for="section in [...]" v-show="items(section).length > 0">
    // Vue 3's v-show directive, applied to the SAME element as v-for, only
    // evaluated once at first render and never re-applied on later updates --
    // even though the sibling {{ items(section).length }} interpolation one
    // line down kept updating correctly. Real app boot calls `catalog.init()`
    // (async fetch) WITHOUT awaiting it before `app.mount()` (main.js), so the
    // very first render always saw 0 items for every category section --
    // v-show froze them at display:none forever, even after the fetch
    // resolved and populated the catalog. Fix: split into
    // `<template v-for>` wrapping an inner `<div v-show>` -- the template
    // itself carries no DOM node to freeze, so the inner div's v-show is a
    // normal (non-v-for) binding that re-evaluates on every update, matching
    // the legacy Alpine markup's `x-show` on the section wrapper.
    test('a >=3 prefix cluster renders as a collapsible group with suffix-only children; a singleton stays flat', async ({ page }) => {
        // Replaces smoke-browser.sh section 3c (issue #38 regression guard).
        await page.goto('/styleguide/');
        await expect(page.getByRole('button', { name: /^Widget/ })).toBeVisible();
        await expect(page.getByRole('link', { name: 'One', exact: true })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Widget - one', exact: true })).toHaveCount(0);
        await expect(page.getByRole('link', { name: 'Gizmo', exact: true })).toBeVisible();
    });

    test('a search query flattens the Widget group to full names', async ({ page }) => {
        // Same root cause as the fix above, now resolved: the "blocks"
        // section's wrapping div is no longer stuck at display:none from the
        // initial pre-fetch render.
        await page.goto('/styleguide/');
        await page.getByPlaceholder(/./).first().fill('widget');
        await expect(page.getByRole('link', { name: 'Widget - one', exact: true })).toBeVisible();
    });

    // Superseded (Task 5): ⌘K/Ctrl+K used to focus the sidebar's inline
    // filter input directly (useSearchShortcuts.js, retired). It now opens
    // the command palette instead -- the sidebar's own filter input keeps
    // its Esc-to-clear behavior, now scoped to itself (see
    // search-palette.spec.js's "sidebar filter independence" coverage for
    // that half of the contract, and Sidebar.spec.js for the unit-level
    // proof).
    test('Cmd+K opens the command palette; a second Cmd+K, or Escape, closes it', async ({ page }) => {
        await page.goto('/styleguide/');
        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeHidden();

        await page.keyboard.press('Meta+k');
        await expect(dialog).toBeVisible();

        await page.keyboard.press('Meta+k');
        await expect(dialog).toBeHidden();

        await page.keyboard.press('Meta+k');
        await expect(dialog).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
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

    test('the iframe theme toggle appends ?theme=dark to the iframe src and toggles it back off', async ({ page }) => {
        // Regression guard for the dark-iframe-theme-resets-on-navigation fix:
        // useViewportPreset.js's iframeSrc computed only appends ?theme=dark
        // when ui.iframeTheme === 'dark' (never emits ?theme=light for the
        // default case), so the two toggle directions assert opposite things
        // -- src contains the param vs. src has no such param at all.
        await page.goto('/styleguide/component/sample');
        const iframe = page.locator('iframe').first();
        const toggle = page.getByTestId('iframe-theme-toggle');

        await expect(iframe).toHaveAttribute('src', '/styleguide/render/component/sample');
        await toggle.click();
        await expect(iframe).toHaveAttribute('src', /\?theme=dark$/);
        await expect(page.frameLocator('iframe').first().locator('html')).toHaveClass(/dark/);

        await toggle.click();
        await expect(iframe).toHaveAttribute('src', '/styleguide/render/component/sample');
    });

    test('the Fields drawer lists a component\'s declared fields once expanded', async ({ page }) => {
        await page.goto('/styleguide/component/with-fields');
        const drawerToggle = page.getByRole('button', { name: /Fields|Pole/ });
        await expect(drawerToggle).toContainText('3');
        await drawerToggle.click();
        await expect(page.getByText('title', { exact: true })).toBeVisible();
        await expect(page.getByText('label', { exact: true })).toBeVisible();
    });

    test('sidebar buckets cover all renderable components (and pages)', async ({ page, request }) => {
        // Restores the legacy suite's named regression guard from
        // smoke-browser.sh section 1 ("sidebar buckets cover all renderable
        // components"), dropped when this spec replaced it. Protects against
        // silent category-bucket drops: sectionOf() in catalog.js maps every
        // component into exactly one of basic/blocks/gutenberg, so the sum of
        // links rendered across those three sidebar sections must equal the
        // count of renderable components (has_styleguide !== false) returned
        // by the API — an unrecognised/mistyped category label must never
        // make a component vanish from the sidebar entirely.
        const components = await (await request.get('/styleguide/api/components')).json();
        const pages = await (await request.get('/styleguide/api/pages')).json();
        const renderableComponents = components.filter((c) => c.has_styleguide !== false).length;
        const renderablePages = pages.filter((p) => p.has_styleguide !== false).length;

        // Seed the persisted section-collapse state *before* the SPA boots so
        // every section (basic/blocks/gutenberg/pages) starts expanded --
        // matches the localStorage shape usePersistedRef('sg-sections', ...)
        // reads in Sidebar.vue. Cheaper and less brittle than clicking each
        // section's collapse toggle (which would need to detect the button's
        // current state to stay idempotent, since a second click re-collapses
        // it); v-show keeps collapsed content in the DOM either way, but this
        // also proves the UI can actually reveal every link once expanded.
        await page.addInitScript(() => {
            localStorage.setItem('sg-sections', JSON.stringify({
                docs: true, basic: true, blocks: true, gutenberg: true, pages: true,
            }));
        });
        await page.goto('/styleguide/');

        // Sidebar.vue's <nav> renders five direct <div> children in a fixed
        // order: docs, then basic/blocks/gutenberg (the `template v-for`
        // section trio), then pages. Scoping by position (rather than text)
        // sidesteps locale-dependent header labels entirely.
        const sectionDivs = page.locator('aside nav > div');
        const countLinks = (index) => sectionDivs.nth(index).locator('a').count();

        // Counting raw <a> elements (not getByRole('link')) intentionally
        // includes both flat top-level items AND grouped children's leaf
        // links from the prefix-tree grouping (buildTree()/GROUP_MIN) --
        // group *headers* are <button>s, never <a>s, so they're never
        // double-counted alongside their children.
        const basicLinks = await countLinks(1);
        const blocksLinks = await countLinks(2);
        const gutenbergLinks = await countLinks(3);
        const pagesLinks = await countLinks(4);

        expect(basicLinks + blocksLinks + gutenbergLinks).toBe(renderableComponents);
        expect(pagesLinks).toBe(renderablePages);
    });

    test('clicking the theme toggle flips <html class="dark"> live, without a reload', async ({ page }) => {
        // Regression guard for the dead theme toggle: theme.js's apply()
        // used to only run once implicitly via the FOUC-prevention inline
        // script at boot — clicking the toggle updated the store's `mode`
        // and the icon, but never touched <html>'s classList until a hard
        // reload. init() now wires a watch() so this must update live.
        await page.goto('/styleguide/');
        const html = page.locator('html');
        const toggle = page.getByRole('button', { name: /Toggle theme|Přepnout vzhled/ });

        // cycle: system -> light -> dark -> system, deterministically driving
        // to a known 'dark' state regardless of the OS/browser's own
        // prefers-color-scheme (Playwright's default is light).
        await toggle.click(); // -> light
        await expect(html).not.toHaveClass(/dark/);
        await toggle.click(); // -> dark
        await expect(html).toHaveClass(/dark/);
        await toggle.click(); // -> system (light, since the test runner has no dark OS pref)
        await expect(html).not.toHaveClass(/dark/);
    });

    // Variant-switcher e2e coverage (deep links, client-side nav reset,
    // toggle -> iframe src/content, unknown-variant fallback) lives in
    // variants.spec.js -- one home for that feature, no duplicated coverage
    // here.

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

    test('/fields lists definition-kit fields and clicks through to the component with the drawer open', async ({ page }) => {
        await page.goto('/styleguide/fields');
        // canonical label rendered from the sibling defkit-card.yaml
        await expect(page.getByText('Nadpis')).toBeVisible();
        // filter narrows to the media field
        await page.getByTestId('fields-filter').fill('media');
        await expect(page.getByText('Obrázek')).toBeVisible();
        await expect(page.getByText('Nadpis')).not.toBeVisible();
        await page.getByTestId('fields-filter').fill('');
        // click-through: component heading → preview with drawer expanded.
        // Scoped to <main> because the fixture is now sidebar-listed too
        // (has_styleguide: true, needed for the /fields + sidebar tests
        // below to see it at all) -- an unscoped getByRole('link', { name:
        // 'Defkit Card' }) matches both the sidebar entry and the overview
        // heading link, a strict-mode violation.
        await page.locator('main').getByRole('link', { name: 'Defkit Card' }).click();
        await expect(page).toHaveURL(/\/styleguide\/component\/defkit-card\?fields=1$/);
        await expect(page.getByRole('cell', { name: 'Nadpis' })).toBeVisible(); // drawer already open
    });

    test('sidebar shows the Fields entry when the catalog declares fields', async ({ page }) => {
        await page.goto('/styleguide/');
        await expect(page.getByRole('link', { name: 'Pole' })).toBeVisible();
    });
});
