# 0010. Vendor the tools UI contract

## Context

The styleguide and test-kit viewer use Vue and Tailwind. They need the same
company chrome. Their domain logic and package distribution remain separate.
A shared runtime package couples their releases. Untracked copies drift.
The owner accepts explicit synchronization in the development discussion.

## Decision

The styleguide owns a versioned, minimal UI contract in `frontend/ui-contract/`.
It starts with theme-aware tokens for existing controls and frame surfaces.
Each receiver vendors approved files from a pinned commit and records provenance.
Updates arrive as separate PRs. No installation or runtime fetch is required.

Catalog data, routing, state stores, and test-result classification remain local.
A small Vue primitive may join the contract only after both tools need it.

## Consequences

The tools can release independently and keep different contract versions.
A receiver must retain source metadata and review each update. The contract
needs light/dark and browser checks. It does not require a third package.
