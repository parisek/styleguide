import { defineConfig, devices } from '@playwright/test';

// testDir lives OUTSIDE frontend/ (one dir up, under the repo's shared
// tests/e2e/) so all e2e layers -- curl (Layer A), the legacy agent-browser
// smoke script (Layer B), and this Playwright suite (Layer C) -- sit
// together regardless of which tool runs them. The tradeoff: Node's
// require()/import resolution walks up from the FILE being loaded, and
// tests/e2e/playwright has no node_modules in its own ancestry (it isn't
// inside frontend/), so a bare `import '@playwright/test'` from the spec
// file fails to resolve. `npm run test:e2e` sets NODE_PATH=./node_modules
// (see package.json) to route around that -- Node's CJS require() (which is
// what Playwright's spec-file loader actually uses under the hood, even for
// this ESM-looking import) honors NODE_PATH as an extra search root, ESM
// import() does not, but Playwright transpiles test files to CJS before
// requiring them, so this works.
export default defineConfig({
    testDir: '../tests/e2e/playwright',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? 'github' : 'list',
    use: {
        baseURL: 'http://127.0.0.1:8421',
        trace: 'retain-on-failure',
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    ],
    // Reuses the exact boot command tests/e2e/run.sh already uses for Layer A/B
    // (php -S 127.0.0.1:8421 -t tests/fixtures tests/fixtures/index.php) so
    // there is exactly one fixture-server invocation pattern in the repo.
    webServer: {
        command: 'php -S 127.0.0.1:8421 -t ../tests/fixtures ../tests/fixtures/index.php',
        cwd: '.',
        url: 'http://127.0.0.1:8421/styleguide/',
        reuseExistingServer: !process.env.CI,
        timeout: 10_000,
    },
});
