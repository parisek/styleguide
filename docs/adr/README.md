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

## Index

- [ADR-0001](0001-record-architecture-decisions.md) — Record architecture decisions
- [ADR-0002](0002-canonical-fields-shape-open-contract.md) — Canonical fields shape with open verbatim pass-through contract
