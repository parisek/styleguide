# Release flow for parisek/styleguide

Same shape as `parisek/definition-kit`, `parisek/acf-json-schema` and
`parisek/timber-kit` — the process is deliberately identical across the packages
so nobody has to remember which repo they are in. Only step 1 differs, because
only the pre-release verification is package-specific.

## Prerequisites

- Push access to the `parisek/styleguide` GitHub repository
- Packagist maintainer role (first release only — thereafter the webhook auto-updates)
- Node for the `dist/` check in step 1

## Steps

### 1. Verify locally

```bash
composer check                       # PHPUnit + PHPStan
cd frontend && npm ci && npm run build && cd ..
git diff --exit-code -- dist/        # committed dist/ must rebuild identically
```

`composer check` must be green and the `dist/` diff must be empty. The SPA bundle
is committed, so a release carrying a stale `dist/` ships a frontend that does
not match its own source — CI enforces this on every PR (`dist/ is reproducible
from frontend/`), and it is worth re-running before a tag because the failure
mode is silent for consumers.

Optionally verify a consumer against the *published* version rather than the
symlinked dev copy (`composer styleguide:remote` in the consumer, then
`composer update parisek/styleguide`) — see AGENTS.md § Local development.

### 2. Make sure changes sit under `[Unreleased]`

Behaviour-affecting changes belong under `## [Unreleased]` in `CHANGELOG.md`
(Keep a Changelog: `### Added`, `### Changed`, `### Fixed`, `### Removed`) —
normally added by their own PR. **Don't hand-stamp a version heading** — the
workflow does that, and hand-stamping skips the guards in step 3.

### 3. Trigger the Stamp Release workflow

Actions tab → **Stamp Release** → Run workflow → enter `X.Y.Z` (no `v` prefix).

It validates the version, requires a non-empty `[Unreleased]`, runs
`composer test` + `composer phpstan` as guards, stamps `[Unreleased]` →
`[X.Y.Z] - DATE`, commits `Release X.Y.Z`, tags `vX.Y.Z`, pushes, and dispatches
`release.yml` — which builds the GitHub Release from the tag's CHANGELOG section.

Also update the compare links at the bottom of `CHANGELOG.md` if they have
drifted; the workflow stamps the heading, not the link refs.

### 4. Packagist

Packagist auto-updates via the GitHub webhook. Verify the new version appears at
`https://packagist.org/packages/parisek/styleguide` within a few minutes.

### 5. Consumers

`composer update parisek/styleguide` in each project. Constraints are `^1.x`, so
a minor lands without a constraint bump — except where a consumer pins a floor
against a feature (e.g. `parisek/definition-kit` requires `^1.7` for
`ComponentParser::KIND_VALUES`).

## Why not tag by hand

`git tag vX.Y.Z && git push origin vX.Y.Z` works — `release.yml` triggers on the
tag — and this repo's AGENTS.md documented exactly that until 2026-07, from
before `release-stamp.yml` existed. It skips the workflow's guards: the version
format check, the non-empty `[Unreleased]` requirement, and the test + PHPStan
run that gate the tag. Prefer step 3.
