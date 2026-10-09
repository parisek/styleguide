# Contributing

Thank you for helping. This guide is short. The full rules are in [`AGENTS.md`](AGENTS.md).

## Set up

```bash
composer install
cd frontend && npm install   # only when you change the SPA
```

This library does not track `composer.lock`. `composer install` resolves the newest versions that `composer.json` allows. CI does the same, so a break in a dependency shows up early.

## Run the checks

CI runs these commands. Run them before you open a pull request.

```bash
composer test                # PHPUnit
composer phpstan             # static analysis
composer adr                 # ADR index check
cd frontend && npm test      # Vitest
cd frontend && npm run build # rebuild dist/
```

After any change in `frontend/`, run `npm run build` and commit the rebuilt `dist/`.
CI fails when `dist/` does not match `frontend/`.

## Write the change

- Add or extend a test for the change. Frontend logic in `frontend/src/lib/` gets a Vitest spec first.
- Update the docs in the same pull request. The table in `AGENTS.md` shows which doc covers which change.
- Add a note under `[Unreleased]` in `CHANGELOG.md` for any behavior a consumer can see.
- Record a hard-to-reverse decision as an ADR in `docs/adr/`. Ask first. See `docs/adr/README.md`.

## Commits and pull requests

- Use [Conventional Commits](https://www.conventionalcommits.org/) for commits and for the pull request title, for example `fix(render): hide server paths`.
- The maintainer squash-merges. The squash commit title ends with `(#N)`, the pull request number.
- Do not stamp a version heading or tag by hand. See [`RELEASING.md`](RELEASING.md).

## Security

Report vulnerabilities as described in [`SECURITY.md`](SECURITY.md). Do not open a public issue.

## AI agents

Read [`AGENTS.md`](AGENTS.md) first.
