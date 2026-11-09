## Summary

<!-- What changes, and why. -->

## Tier, principle and contract

<!-- `docs/vision.md`, "How the agent decides" and "Principles". Fill in each line. -->

- Tier (1, 2 or 3):
- Principle (number from `docs/vision.md`):
- Requirement (`R` number from `docs/specification.md`; if none applies, write "none" and one reason):

## Checklist

- [ ] The PR title follows Conventional Commits.
- [ ] Tests cover the change (`composer test`, `composer phpstan`, `cd frontend && npm test`).
- [ ] `dist/` is rebuilt if `frontend/` changed.
- [ ] `CHANGELOG.md` has a note under `[Unreleased]` for consumer-visible behavior.
- [ ] `README.md` and `docs/API.md` are updated if the public surface changed.
- [ ] An ADR is added in `docs/adr/` if the change records an architectural decision.
- [ ] No default changes and nothing new is on by default, or the tier is 3.

## Review and risk

- [ ] Breaking `@api` change, new runtime dependency or release (owner decides).
- [ ] Security path: `PathGuard`, `AssetServer`, the source gate, escaping (owner decides).

## Left alone

<!-- What you rejected or did not change, and why. A diff cannot show a non-change. -->
