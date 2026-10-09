# Frontend dependency maintenance

Dependabot checks `/frontend` each week. It groups minor and patch updates for
Vue runtime packages, the Vite toolchain, and Tailwind. Major updates stay in
separate pull requests. A group does not prove compatibility. Check peer ranges
and run the existing tests before merge.

A frontend update includes the manifest, lockfile, and rebuilt `dist/` files.
CI checks component tests, browser tests, and build reproducibility. Composer
consumers use the committed assets. They do not install npm dependencies.
Frontend development needs Node 22.18 or later. CI uses Node 26.

## Advisory policy

CI runs `npm audit --audit-level=low` against the full locked dependency tree.
Every reported severity blocks the audit job. Registry errors also block it.
Do not use `npm audit fix --force` or hide a failed audit with `continue-on-error`.

For each advisory, record its affected package and dependency path. Determine
whether the affected code ships in `dist/`, runs during development or build,
or runs only in tests. A development dependency can affect shipped output.
`npm audit --omit=dev` alone cannot establish that the browser bundle is safe.

Prefer a compatible update or remove an unused dependency. Rebuild the assets
and run component tests, browser tests, and the reproducibility check.

If no safe fix exists, ask the owner before accepting risk. Record the advisory
ID, dependency path, affected use, reason, owner, and next review date in an
issue. The audit gate remains active. This policy has no automatic exception
list and does not authorize a merge with a failed audit.

## Configuration reference

The [GitHub Dependabot options reference](https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-options-reference)
defines weekly schedules, group patterns, and semantic version update types.
Groups here apply to version updates. Security updates follow GitHub's security
update settings and are not delayed by the weekly version update schedule.
