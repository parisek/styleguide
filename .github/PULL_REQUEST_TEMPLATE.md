## Status

**In progress | Waiting for owner | Ready for review | Blocked** <!-- Keep one. Update it at every hand-over; it is the first thing the owner reads. -->

**Next step:** <!-- Who does what next, in one sentence: "Agent: run the Codex gate", "Owner: decide #N", "Owner: review and merge". -->

<!-- The part down to the agent record is for the owner. Plain words, no file names and no class names. Put in bold what the owner must not miss. -->

## What

<!-- The change as its user sees it. -->

## Why

<!-- The reason, and the goal or principle it serves. -->

## How

<!-- The approach, in one or two sentences. -->

## Who

<!-- Who asked, which agent built it, who reviewed it. For a cloud session, add the name of the account that owns the session in brackets and link the session: only that account can open it. -->

## Review focus

<!-- The tier, and in bold the one or two things the owner should look at or check live. "Nothing special" when true. -->

<details>
<summary>Agent record: tier, tests, docs, checklist, review</summary>

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

</details>
