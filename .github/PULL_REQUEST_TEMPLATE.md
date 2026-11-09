## Summary

<!-- What changes and why. Cite each R number of docs/specification.md that the change meets or changes. -->

## Tier, principle and requirement

<!-- docs/process.md, section 3, and docs/vision.md, "How the agent decides" and "Principles". Fill in each line. -->

- Tier (1, 2 or 3):
- Principle (number from `docs/vision.md`):
- Requirement (`R` number from `docs/specification.md`; if none applies, write "none" and one reason):

## Tests

<!-- Which test proves which acceptance example. Output of the gate in .agent/project.conf. -->

## Docs reviewed

<!-- The docs this change touches (the table in AGENTS.md), or "No doc impact". -->

## Package checklist

- [ ] `dist/` is rebuilt if `frontend/` changed.
- [ ] `CHANGELOG.md` has a note under `[Unreleased]` for consumer-visible behavior.
- [ ] An ADR is added in `docs/adr/` if the change records an architectural decision (the owner said yes first).
- [ ] No default changes and nothing new is on by default, or the tier is 3.

## Left alone

<!-- What you rejected or did not change, and why. A diff cannot show a non-change. -->

## Review

- [ ] The title is a Conventional Commit. The squash commit uses it.
- [ ] CI green on the head SHA.
- [ ] Codex record `<!-- agent:review -->` on the head SHA.
- [ ] Claude record `<!-- agent:review -->` on the head SHA.
- [ ] Breaking `@api` change, new runtime dependency, release or security path (`PathGuard`, `AssetServer`, the source gate, escaping): the owner decides.
