import { test, expect } from '@playwright/test';

// Fixture: tests/fixtures/templates/component/a11y-demo ships a single bare
// <img> with no alt attribute -- a guaranteed axe-core "image-alt" (impact:
// critical) violation, isolated from any other markup. It has no
// styleguide.twig sibling / `styleguide:` YAML flag (hasStyleguide stays
// false, matching most of this fixture set -- see ComponentParserTest), so
// it's reached via a direct goto rather than a sidebar link, same as
// `sample` elsewhere in this suite.
//
// The fixture server's default locale is `cs` (tests/fixtures/index.php) --
// every assertion below locates the check button/panel by data-testid or by
// impact-specific text/testid rather than the button's i18n'd accessible
// name, so this spec is locale-independent.
//
// render-cell.twig's own iframe chrome (see templates/render-cell.twig)
// wraps `{{ body|raw }}` with no landmark element, which axe-core flags as
// its own baseline "region" (impact: moderate) violation independent of
// whatever the component itself renders. Assertions here deliberately check
// for the presence of a Critical group + alt-text wording rather than an
// exact violation count, so that baseline chrome noise can never make this
// spec flaky.
test.describe('on-demand accessibility check', () => {
    test('a11y-demo component surfaces a Critical image-alt violation', async ({ page }) => {
        await page.goto('/styleguide/component/a11y-demo');
        await page.getByTestId('a11y-check-button').click();

        const panel = page.getByTestId('a11y-panel');
        await expect(panel.getByTestId('a11y-impact-critical')).toBeVisible();
        await expect(panel).toContainText(/alt/i);
    });

    test('a clean component shows no Critical group', async ({ page }) => {
        await page.goto('/styleguide/component/sample');
        await page.getByTestId('a11y-check-button').click();

        const panel = page.getByTestId('a11y-panel');
        await expect(panel).toBeVisible();
        await expect(panel.getByTestId('a11y-impact-critical')).toHaveCount(0);
    });

    test('navigating to a different component clears the results panel', async ({ page }) => {
        await page.goto('/styleguide/component/a11y-demo');
        await page.getByTestId('a11y-check-button').click();
        await expect(page.getByTestId('a11y-panel')).toBeVisible();

        // Client-side (router-push) navigation via a real sidebar link --
        // exercises ui.setRoute()'s reset, not just a fresh SPA boot. Gizmo
        // (styleguide: true) is already the proven sidebar-nav target used
        // elsewhere in this suite (see variants.spec.js).
        await page.getByRole('link', { name: 'Gizmo', exact: true }).click();
        await expect(page).toHaveURL(/\/styleguide\/component\/gizmo$/);
        await expect(page.getByTestId('a11y-panel')).toHaveCount(0);
    });
});
