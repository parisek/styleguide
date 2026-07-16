# ADR-0001 — Record architecture decisions

Date: 2026-07-16
Status: Accepted

## Context

Architectural decisions (the `#sg-config` single-injection point, the sibling
`<id>.yaml` priority of #91, API contract shapes) have so far lived scattered
across commit messages, PR descriptions, and `CHANGELOG.md`. Rationale gets
hard to recover — the favicon regression (#94) went unnoticed for ten releases
partly because the *intent* behind removing the server-side tag patches wasn't
recorded anywhere a later reader would look.

## Decision

Record significant architectural decisions as numbered ADRs in `docs/adr/`
(MADR-lite: Context → Decision → Consequences). An ADR is part of the PR that
implements the decision, same merge-gate rule as docs and CHANGELOG
(`AGENTS.md` § Documentation is part of the change).

## Consequences

- Future maintainers (and AI assistants) can read *why*, not just *what*.
- Small ongoing writing cost per architectural PR.
- Past decisions are not backfilled wholesale; they get an ADR only when they
  are next touched or superseded.
