const IMPACT_ORDER = ['critical', 'serious', 'moderate', 'minor'];

/**
 * Reshape axe-core's `axe.run()` resolution into a display-ready grouping.
 * Pure function — no DOM, no iframe access — so the injection/run mechanics
 * in axeInject.js can stay untested-by-Vitest (they're DOM/iframe-heavy and
 * covered instead by the Playwright spec) while this formatting logic is
 * fully unit-tested.
 *
 * A violation's `impact` is nullable per axe-core's own typing (some rules
 * can report `null`) — treated as 'minor' rather than dropped, so a
 * genuinely-flagged issue never silently disappears from the panel just
 * because axe didn't classify its severity.
 */
export function formatAxeResults(results) {
    const byImpact = { critical: [], serious: [], moderate: [], minor: [] };
    for (const violation of results.violations ?? []) {
        const impact = IMPACT_ORDER.includes(violation.impact) ? violation.impact : 'minor';
        byImpact[impact].push(violation);
    }
    return { byImpact, total: results.violations?.length ?? 0 };
}
