#!/usr/bin/env bash
# Tests of dependabot-merge.sh with a stub `gh` (no network). Needs bash and jq.
# Each case serves one Dependabot npm pull request whose files and lockfiles
# come from fixtures, runs the script with DRY_RUN=1 and checks the verdict.
set -euo pipefail

here=$(cd "$(dirname "$0")" && pwd)
fx="$here/fixtures"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
fail=0

mkdir -p "$work/bin"
cat >"$work/bin/gh" <<'STUB'
#!/usr/bin/env bash
# Stub of `gh api`: answers from $CASE_DIR. The last argument is the path.
set -euo pipefail
path=${*: -1}
sha=0123456789abcdef0123456789abcdef01234567
case "$path" in
	*"/pulls?state=open"*)
		printf '[{"number":7,"title":"chore(deps): bump x","draft":false,"state":"open","base":{"ref":"main"},"head":{"repo":{"full_name":"o/r"},"sha":"%s","ref":"dependabot/npm_and_yarn/frontend/x-1.0.1"},"user":{"login":"dependabot[bot]","type":"Bot"}}]' "$sha" ;;
	*"/pulls/7/commits"*)
		printf '[{"author":{"login":"dependabot[bot]"},"committer":{"login":"web-flow"},"commit":{"verification":{"verified":true},"message":"bump\\n\\nupdated-dependencies:\\n- dependency-name: x\\n  update-type: version-update:semver-patch\\n"}}]' ;;
	*"/check-runs"*)
		if [ -f "$CASE_DIR/check-runs.json" ]; then cat "$CASE_DIR/check-runs.json"
		else echo '{"check_runs":[{"name":"t","status":"completed","conclusion":"success"}]}'; fi ;;
	*"/actions/runs"*) echo '{"workflow_runs":[{"name":"Tests","status":"completed","conclusion":"success"}]}' ;;
	*"/pulls/7/files"*) cat "$CASE_DIR/files.json" ;;
	*"/compare/"*) echo '{"merge_base_commit":{"sha":"base"}}' ;;
	*"/contents/"*)
		file=${path#*/contents/}; ref=${file#*ref=}; file=${file%%\?*}
		cat "$CASE_DIR/$ref/$(basename "$file")" ;;
	*) echo "stub: unknown path $path" >&2; exit 1 ;;
esac
STUB
chmod +x "$work/bin/gh"

# expect <case> <pattern> [DIST_CHECK]
expect() {
	local out
	out=$(PATH="$work/bin:$PATH" CASE_DIR="$fx/$1" GITHUB_REPOSITORY=o/r REQUIRED_WORKFLOWS=Tests DRY_RUN=1 DIST_CHECK="${3-dist}" \
		bash "$here/dependabot-merge.sh" dependabot/npm_and_yarn/frontend/x-1.0.1 0123456789abcdef0123456789abcdef01234567 2>&1) || true
	if grep -qE "$2" <<<"$out"; then echo "ok   $1"; else echo "FAIL $1: $out"; fail=1; fi
}

# The lockfile rule reads only that a lockfile changed and the check runs,
# so the cases differ in the check runs, not in the lockfile content.
expect npm-lock-dist-green 'DRY RUN: would merge #7'
expect npm-lock-no-dist 'SKIP: #7 changes a lockfile and has no successful "dist" check from GitHub Actions'
expect npm-lock-no-dist 'SKIP: #7 changes a lockfile and DIST_CHECK is not set' ''
expect npm-lock-dist-skipped 'SKIP: #7 changes a lockfile and has no successful "dist" check from GitHub Actions'
expect npm-lock-dist-other-app 'SKIP: #7 changes a lockfile and has no successful "dist" check from GitHub Actions'
expect npm-lock-v1-no-dist 'SKIP: #7 changes a lockfile and has no successful "dist" check from GitHub Actions'
expect npm-json-runtime 'SKIP: #7 changes frontend/package.json outside devDependencies'
expect npm-json-dev-only 'DRY RUN: would merge #7'

# DIST_CHECK in the merge workflow names a job of the Tests workflow, byte for byte.
root=$(cd "$here/../.." && pwd)
want=$(sed -n "s/^[[:space:]]*DIST_CHECK:[[:space:]]*'\(.*\)'[[:space:]]*$/\1/p" "$root/.github/workflows/dependabot-merge.yml")
if [ -n "$want" ] && grep -qxF "    name: $want" "$root/.github/workflows/tests.yml"; then
	echo "ok   DIST_CHECK names a Tests job"
else
	echo "FAIL DIST_CHECK '$want' is not the name of a job in tests.yml"; fail=1
fi
exit "$fail"
