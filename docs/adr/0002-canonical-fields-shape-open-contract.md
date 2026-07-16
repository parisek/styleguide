# ADR-0002 — Canonical fields shape with open verbatim pass-through contract

Date: 2026-07-16
Status: Accepted (tracking issue: [#95](https://github.com/parisek/styleguide/issues/95))

## Context

Two field-definition doctrines coexist downstream: the legacy twig-annotation
shape (`title`, nested `fields`) and `parisek/definition-kit`'s authored
semantic `<id>.yaml` (`label`, abstract types, `options`, `visible_when`,
`mcp`, `wp:` escape hatch; beta on the Maira project). The styleguide reads
both (#91) but passed the map through the API verbatim, so every consumer had
to understand both doctrines — and the SPA's `FieldsDrawer` only understood
the old one.

Options considered:

1. **Closed canonical schema in PHP** — normalise everything, drop unknown
   keys. Stable but every new definition-kit feature needs a styleguide
   release to become visible.
2. **Client-side normalisation** — server stays verbatim; each consumer
   (SPA, agents, future tooling) re-implements doctrine knowledge.
3. **Canonical core + open contract** — PHP normalises only the six core keys
   (`key`, `label`, `type`, `description`, `required`, `children`; old
   doctrine `title→label`, `fields→children`) and passes **every other
   authored key through verbatim**.

## Decision

Option 3. `ComponentParser` owns the normalisation for both doctrines;
`/api/components` and `/api/fields` emit the canonical shape; all remaining
authored keys (`mcp`, `wp:`, `translatable`, `kind`, `shape`, `of`,
constraints, `visible_when`, and any future key) pass through unchanged.
`docs/API.md` documents the core keys' semantics plus the open-contract rule.
Definitions contain nothing secret — full visibility is a feature (visual
inspection of agent guidance, conditional display, CMS residue).

## Consequences

- One place (PHP, unit-tested) understands both doctrines; consumers see one
  shape.
- New definition-kit keys surface in the styleguide API immediately, without
  a package release.
- The API cannot guarantee absence of unknown keys — consumers must tolerate
  extra keys (documented explicitly).
- Malformed entries are skipped with a parser warning (health-warning channel,
  #89), never silently dropped.
