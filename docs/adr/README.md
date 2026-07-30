# Architecture Decision Records

Significant architectural decisions for `parisek/styleguide` are recorded here
as ADRs — short, numbered, immutable documents. Structure is the **Nygard
triad**: `## Context` / `## Decision` / `## Consequences`, no status line, no
further ceremony. A superseded decision gets a new ADR that links back; the old
file stays and is marked `Superseded by ADR-NNNN`.

This practice is shared verbatim with `parisek/timber-kit`,
`parisek/definition-kit` and `parisek/acf-json-schema` — four Composer packages,
one set of rules. Change it in one and change it in all four.

**When to write one**: a decision that constrains future work across releases —
API contracts, doctrine choices, architectural boundaries. Not for routine
features or fixes (those live in `CHANGELOG.md` and PR descriptions).

Offer one **sparingly** — only when **all three** hold (the `parisek/timber-kit`
test, adopted here verbatim because it is the restraint this file was missing):

1. **Hard to reverse** — the cost of changing your mind later is meaningful.
2. **Surprising without context** — a future reader will wonder *"why did they
   do it this way?"*
3. **The result of a real trade-off** — there were genuine alternatives and one
   was picked for specific reasons.

Most changes are none of these. Propose the ADR, get a yes, *then* write it.
Don't auto-create — AGENTS.md's documentation gate makes an ADR mandatory for an
architectural decision, and without this test that pressure reads as "when in
doubt, add one", which buries the handful that matter.

**Process**: the ADR lands in the same PR as the work it describes (like docs
and CHANGELOG — a merge gate, not a follow-up). Decisions made during design
discussions get their ADR at design-approval time, referencing the tracking
issue.

Numbers are permanent — never renumber or reuse, even if an ADR is later
superseded. One file per decision: `NNNN-kebab-title.md`, zero-padded and
sequential. Every ADR is listed in the Index below; a file that is not in the
index is invisible, which is the failure mode `parisek/timber-kit` currently
has.

## Template

```markdown
# NNNN. Short title in the imperative

## Context

What forces are at play — the problem, constraints, and what made the obvious
path unworkable.

## Decision

What we decided, stated plainly.

## Consequences

What follows — the good, the bad, and what now has to stay true. Name the
guard (test, CI check, convention) that keeps it from drifting, if any.
```

**Citing a sibling repo's ADR**: always qualify it with the repo —
`tailwind-base ADR-0007`, never a bare `ADR 0007`. The numbering spaces are
independent, so a bare number sends the reader to this repo's `docs/adr/`,
where it either does not exist or is a different decision entirely. Several
comments in `src/` cite `tailwind-base ADR-0007` (`<id>.yaml` replaces the twig
front-comment) — that decision belongs to the consumer that drove it, and is
referenced here rather than duplicated.

## Index

- [ADR-0001](0001-record-architecture-decisions.md) — Record architecture decisions
- [ADR-0002](0002-canonical-fields-shape-open-contract.md) — Canonical fields shape with open verbatim pass-through contract
