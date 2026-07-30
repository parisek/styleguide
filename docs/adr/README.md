# Architecture Decision Records

Significant architectural decisions for `parisek/styleguide` are recorded here
as ADRs — short, numbered, immutable documents (MADR-lite format: Context →
Decision → Consequences). A superseded decision gets a new ADR that links back;
the old file stays and is marked `Superseded by ADR-NNNN`.

**When to write one**: a decision that constrains future work across releases —
API contracts, doctrine choices, architectural boundaries. Not for routine
features or fixes (those live in `CHANGELOG.md` and PR descriptions).

**Process**: the ADR lands in the same PR as the work it describes (like docs
and CHANGELOG — a merge gate, not a follow-up). Decisions made during design
discussions get their ADR at design-approval time, referencing the tracking
issue.

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
