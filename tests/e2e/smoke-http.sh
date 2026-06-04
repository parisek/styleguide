#!/usr/bin/env bash
#
# Layer A — HTTP smoke tests for the styleguide, run against the package's own
# fixture (tests/fixtures/). These assert the package's HTTP behaviour — routing,
# render endpoint, JSON APIs, asset serving, cache headers, path-traversal guard —
# at the version of the code in this repo. Consuming projects no longer carry
# these (they kept only a thin "my styleguide boots + lists my components" canary);
# the package owns its own behaviour so a behaviour change here can't silently
# break every downstream's e2e on a version bump.
#
# Usage (start a server yourself, or use run.sh which manages the lifecycle):
#   php -S 127.0.0.1:8421 tests/fixtures/index.php &
#   bash tests/e2e/smoke-http.sh
#   BASE=http://127.0.0.1:9000 bash tests/e2e/smoke-http.sh
#
set -euo pipefail

BASE="${BASE:-http://127.0.0.1:8421}"
PASS=0
FAIL=0

# Colour only when stdout is a TTY — clean output in CI logs.
if [ -t 1 ]; then
    GREEN='\033[0;32m'; RED='\033[0;31m'; DIM='\033[2m'; NC='\033[0m'
else
    GREEN=''; RED=''; DIM=''; NC=''
fi

ok() { printf "  ${GREEN}✓${NC} %s\n" "$*"; PASS=$((PASS+1)); }
ko() { printf "  ${RED}✗${NC} %s\n" "$*" >&2; FAIL=$((FAIL+1)); }

# assert_status URL expected_code description
assert_status() {
    local url="$1" want="$2" desc="$3"
    # A transport-level curl failure (php -S refusing a connection under load,
    # timeout, reset) must become a recorded test failure, not a `set -e` abort
    # that kills the whole suite mid-run. `|| got="000"` keeps the comparison
    # alive so it reports via `ko` like any other failed check.
    local got
    got=$(curl -sk -o /dev/null -w '%{http_code}' "$BASE$url") || got="000"
    if [ "$got" = "$want" ]; then
        ok "$desc ${DIM}[$want $url]${NC}"
    else
        ko "$desc: expected $want, got $got (${BASE}${url})"
    fi
}

# assert_header URL header_name expected_value_substring description
assert_header() {
    local url="$1" hdr="$2" want="$3" desc="$4"
    # `set -euo pipefail` would otherwise abort the whole suite when the header
    # is missing — grep exits non-zero on no-match and pipefail propagates that.
    # `|| true` swallows the grep exit so a missing header is reported as a test
    # failure, not a script crash that hides every check below it.
    local got
    got=$(curl -sk -I "$BASE$url" | { grep -i "^$hdr:" || true; } | head -1 | tr -d '\r') || got=""
    if printf '%s' "$got" | grep -qi "$want"; then
        ok "$desc ${DIM}[$hdr ~ $want]${NC}"
    else
        ko "$desc: header '$hdr' missing '$want' — got: $got"
    fi
}

# assert_body_contains URL needle description
#
# Re-fetches on a miss (up to $max attempts). PHP's built-in dev server
# (`php -S`) is single-threaded and "not intended to be a full-featured web
# server" — under load it occasionally hands `curl` a truncated body, which made
# this assertion intermittently fail on a *different* needle each run. A
# genuinely-absent needle still fails, just after exhausting the retries; a
# truncated response recovers on the next fetch.
assert_body_contains() {
    local url="$1" needle="$2" desc="$3"
    local attempt max=5
    for (( attempt=1; attempt<=max; attempt++ )); do
        if curl -sk "$BASE$url" | grep -q -F "$needle"; then
            ok "$desc ${DIM}[body contains $needle]${NC}"
            return
        fi
        sleep 0.2
    done
    ko "$desc: '$url' body missing '$needle' (after $max attempts)"
}

# assert_body_contains_all URL needle1 desc1 [needle2 desc2 ...]
#
# Fetches URL ONCE and asserts every (needle, description) pair against that
# single capture, re-fetching the whole body while any needle is missing (same
# php -S truncation guard as assert_body_contains). Folding several checks on one
# URL into a single request shrinks the truncation exposure N-fold versus one
# request per needle, while still emitting one pass/fail line per needle.
assert_body_contains_all() {
    local url="$1"; shift
    local -a pairs=("$@")   # needle desc needle desc ...
    local body attempt max=5 i all
    for (( attempt=1; attempt<=max; attempt++ )); do
        # `|| body=""` so a transport error doesn't `set -e`-abort before the
        # retry loop gets to re-fetch — that abort was the exact failure mode the
        # retry is meant to absorb.
        body=$(curl -sk "$BASE$url") || body=""
        all=1
        for (( i=0; i<${#pairs[@]}; i+=2 )); do
            printf '%s' "$body" | grep -q -F "${pairs[i]}" || { all=0; break; }
        done
        [ "$all" -eq 1 ] && break
        sleep 0.2
    done
    for (( i=0; i<${#pairs[@]}; i+=2 )); do
        if printf '%s' "$body" | grep -q -F "${pairs[i]}"; then
            ok "${pairs[i+1]} ${DIM}[body contains ${pairs[i]}]${NC}"
        else
            ko "${pairs[i+1]}: '$url' body missing '${pairs[i]}'"
        fi
    done
}

# assert_json_array_min URL min_items description
assert_json_array_min() {
    local url="$1" min="$2" desc="$3"
    local got
    got=$(curl -sk "$BASE$url" | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))' 2>/dev/null || echo "0")
    if [ "$got" -ge "$min" ] 2>/dev/null; then
        ok "$desc ${DIM}[$got items ≥ $min]${NC}"
    else
        ko "$desc: expected ≥ $min items, got $got at $url"
    fi
}

printf "${DIM}== Layer A — HTTP smoke (%s) ==${NC}\n" "$BASE"

# Root + landing routing. The package redirects the bare root to the SPA landing;
# a consuming app (e.g. WordPress) overrides / with its own homepage, which is why
# this assertion lives here against the fixture, not in consumers.
assert_status "/"                                       "302"  "root redirects to styleguide"
assert_header "/"                                       "location" "/styleguide/" "root redirect target"
assert_status "/styleguide"                             "200"  "/styleguide returns SPA shell"
assert_status "/styleguide/"                            "200"  "/styleguide/ returns SPA shell"
assert_header "/styleguide/"                            "content-type" "text/html" "/styleguide/ content-type"
assert_status "/styleguide/component/sample"            "200"  "deep link to component returns SPA"
assert_status "/styleguide/page/landing"                "200"  "deep link to page returns SPA"
assert_status "/styleguide/overview"                    "200"  "overview returns SPA"

# Render endpoint (iframe HTML). All body tokens are asserted against a SINGLE
# fetch — the render document is the largest, slowest response, so it was the one
# php -S kept truncating. One request + retry removes the flakiness.
assert_status        "/styleguide/render/component/sample"  "200" "render component"
assert_body_contains_all "/styleguide/render/component/sample" \
    'class="sample"'      "render emits the component body" \
    "/dist/css/style.css" "render injects the project CSS path" \
    'type="module"'       "render loads JS as ES module" \
    "sg-standalone-bar"   "render emits standalone-mode bar"

assert_status        "/styleguide/render/page/landing"     "200" "render page"

assert_status        "/styleguide/render/component/does-not-exist" "404" "render unknown → 404"

# API endpoints (fixture ships 2 components + 1 page + 1 doc)
assert_header        "/styleguide/api/components"  "content-type" "application/json" "components api content-type"
assert_json_array_min "/styleguide/api/components" 2 "components api count"
assert_json_array_min "/styleguide/api/pages"      1 "pages api count"
assert_json_array_min "/styleguide/api/docs"       1 "docs api count"
assert_body_contains  "/styleguide/api/docs"       "sample-doc" "docs api lists sample-doc"
assert_status        "/styleguide/api/fields"     "200" "fields api"

# Doc render endpoint
assert_status        "/styleguide/doc/sample-doc"  "200" "deep link to doc returns SPA"
assert_status        "/styleguide/render/doc/sample-doc" "200" "render doc"
assert_body_contains  "/styleguide/render/doc/sample-doc" "Fixture body." "render doc emits fixture body"

# Hashed SPA assets — filename is content-hashed, so extract it from the shell
# rather than hard-coding the hash (which changes on every frontend build).
HASHED_JS=$(curl -sk "$BASE/styleguide/" | grep -oE '/styleguide/assets/styleguide\.[A-Za-z0-9_-]+\.js' | head -1 || true)
HASHED_CSS=$(curl -sk "$BASE/styleguide/" | grep -oE '/styleguide/assets/styleguide\.[A-Za-z0-9_-]+\.css' | head -1 || true)
if [ -n "$HASHED_JS" ]; then
    assert_status "$HASHED_JS"                          "200" "hashed JS served"
    assert_header "$HASHED_JS"                          "cache-control" "immutable" "hashed JS cache: immutable"
else
    ko "could not extract hashed JS filename from /styleguide HTML"
fi
if [ -n "$HASHED_CSS" ]; then
    assert_status "$HASHED_CSS"                         "200" "hashed CSS served"
    assert_header "$HASHED_CSS"                         "cache-control" "immutable" "hashed CSS cache: immutable"
else
    ko "could not extract hashed CSS filename from /styleguide HTML"
fi

# Locale JSON (shipped in the package dist)
assert_status "/styleguide/assets/locales/cs.json"  "200" "cs locale served"
assert_body_contains "/styleguide/assets/locales/cs.json" "Přehled" "cs locale has nav.overview key"
assert_status "/styleguide/assets/locales/en.json"  "200" "en locale served"

# Path-traversal guard — must not leak files outside the dist root
TRAV_BODY=$(curl -sk "$BASE/styleguide/assets/%2e%2e/composer.json") || TRAV_BODY=""
if printf '%s' "$TRAV_BODY" | grep -q '"name"'; then
    ko "path traversal LEAKED composer.json content"
else
    ok "path traversal blocked (no composer.json content leaked)"
fi

echo ""
if [ "$FAIL" -eq 0 ]; then
    printf "${GREEN}== Layer A OK — %d checks passed ==${NC}\n" "$PASS"
    exit 0
else
    printf "${RED}== Layer A FAILED — %d pass, %d fail ==${NC}\n" "$PASS" "$FAIL"
    exit 1
fi
