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
#
# body_has: needle checks MUST NOT be written as `printf '%s' "$body" | grep -q`
# pipelines. This suite runs under `set -o pipefail`, and GNU grep -q exits the
# moment it matches — once a body outgrows the 64 KiB pipe buffer (the
# foundations page did when the contrast matrix landed), printf catches SIGPIPE
# mid-write, pipefail flags the pipeline as failed, and a PRESENT needle reads
# as missing. macOS/BSD grep drains stdin before exiting, which is why the bug
# only fired on Linux/CI. A herestring has no pipeline, so no SIGPIPE surface.
body_has() {
    local body="$1" needle="$2"
    grep -q -F "$needle" <<< "$body"
}

assert_body_contains() {
    local url="$1" needle="$2" desc="$3"
    local body attempt max=5
    for (( attempt=1; attempt<=max; attempt++ )); do
        body=$(curl -sk "$BASE$url") || body=""
        if body_has "$body" "$needle"; then
            ok "$desc ${DIM}[body contains $needle]${NC}"
            return
        fi
        sleep 0.2
    done
    ko "$desc: '$url' body missing '$needle' (after $max attempts)"
}

# assert_body_not_contains URL needle description
#
# Inverse of assert_body_contains — re-fetches on a transport miss (same php -S
# truncation guard) but here a truncated response could produce a FALSE pass
# (needle absent only because the body got cut short), so this checks the body
# is non-empty before asserting absence.
assert_body_not_contains() {
    local url="$1" needle="$2" desc="$3"
    local body attempt max=5
    for (( attempt=1; attempt<=max; attempt++ )); do
        body=$(curl -sk "$BASE$url") || body=""
        [ -n "$body" ] && break
        sleep 0.2
    done
    if [ -z "$body" ]; then
        ko "$desc: '$url' returned an empty body after $max attempts — cannot assert absence"
    elif body_has "$body" "$needle"; then
        ko "$desc: '$url' body unexpectedly contains '$needle'"
    else
        ok "$desc ${DIM}[body lacks $needle]${NC}"
    fi
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
            body_has "$body" "${pairs[i]}" || { all=0; break; }
        done
        [ "$all" -eq 1 ] && break
        sleep 0.2
    done
    for (( i=0; i<${#pairs[@]}; i+=2 )); do
        if body_has "$body" "${pairs[i]}"; then
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
    "sg-standalone-bar"   "render emits standalone-mode bar" \
    "bg-consumer-global"  "render carries the consumer's global iframe.body_class"

assert_status        "/styleguide/render/page/landing"     "200" "render page"

assert_status        "/styleguide/render/component/does-not-exist" "404" "render unknown → 404"
assert_status        "/styleguide/render/component/broken-sample" "500" "render error → 500"

# ?variant= resolution against the `multi` fixture (styleguide.secondary.twig
# exists; styleguide.retired.twig does not — an unknown variant must fall back
# to the default styleguide.twig, never 404, so a bookmarked deep link to a
# since-deleted variant keeps working).
assert_body_contains "/styleguide/render/component/multi"                   "multi--demo"      "no variant → default demo body"
assert_body_contains "/styleguide/render/component/multi?variant=secondary" "multi--secondary" "?variant=secondary → named variant body"
assert_body_contains "/styleguide/render/component/multi?variant=retired"   "multi--demo"       "unknown variant falls back to default demo body"

# API endpoints (fixture ships 2 components + 1 page + 1 doc)
assert_header        "/styleguide/api/components"  "content-type" "application/json" "components api content-type"
assert_json_array_min "/styleguide/api/components" 2 "components api count"
assert_json_array_min "/styleguide/api/pages"      1 "pages api count"
assert_json_array_min "/styleguide/api/docs"       1 "docs api count"
assert_body_contains  "/styleguide/api/docs"       "sample-doc" "docs api lists sample-doc"
assert_status        "/styleguide/api/fields"     "200" "fields api"
assert_header        "/styleguide/api/health"    "content-type" "application/json" "health api content-type"
assert_body_contains_all "/styleguide/api/health" \
    '"warnings"' "health api emits warnings key" \
    '"counts"'   "health api emits counts key"

# Doc render endpoint
assert_status        "/styleguide/doc/sample-doc"  "200" "deep link to doc returns SPA"
assert_status        "/styleguide/render/doc/sample-doc" "200" "render doc"
assert_body_contains  "/styleguide/render/doc/sample-doc" "Fixture body." "render doc emits fixture body"
# Rule: a doc's <body> never inherits the consumer's site-wide iframe.body_class
# (bug fix — a dark iframe.body_class broke prose readability on a doc page).
# Contrast with the component render assertion above, which DOES carry it.
assert_body_not_contains "/styleguide/render/doc/sample-doc" "bg-consumer-global" "render doc skips the consumer's global body_class"

# Flexible color model (#71) — legacy scale palette and flat named palette
# both survive normalization and render on foundations.
assert_body_contains_all "/styleguide/render/foundations/index" \
    "primary-500"                "foundations colors: legacy shades palette renders css-variable label" \
    "#FE4942"                    "foundations colors: legacy palette hex reaches the markup" \
    "brand-red"                  "foundations colors: flat palette css_variable label renders" \
    "cream"                      "foundations colors: flat palette bare-name swatch renders" \
    "#1D3557"                    "foundations colors: flat palette hex reaches the markup" \
    "robin&#039;s egg"           "foundations colors: apostrophe swatch name survives HTML escaping" \
    "#9EDDDF"                    "foundations colors: apostrophe swatch hex reaches the markup" \
    "data-hero-label>primary-500" "foundations colors: hero SSRs the default swatch label (vanilla contract, #79)" \
    'data-swatch="&#x7B;&quot;key&quot;&#x3A;&quot;red&quot;' "foundations colors: swatch payload is html_attr-escaped JSON (attribute not truncated)" \
    "oklch&#x28;61.22&#x25;&#x20;0.208&#x20;22.24&#x29;" "foundations colors: oklch computed from hex when yaml omits it"

# Contrast layer (#72) — swatch AA badges, tooltip ratios, expandable matrix.
# Fixture oracle: #FE4942 (primary-500) → white text 3.36 (fail AA), black 6.25 (AA).
assert_body_contains_all "/styleguide/render/foundations/index" \
    'data-contrast="W 3.36 B 6.25"'   "foundations contrast: primary-500 swatch carries both text ratios" \
    "contrast-matrix"                  "foundations contrast: matrix section renders" \
    'data-ratio="21"'                  "foundations contrast: white-on-black matrix cell grades 21" \
    ">AAA<"                             "foundations contrast: AAA verdict label appears in the matrix"

# Escaping regression (#72 review) — a swatch name carrying HTML/JS-hostile
# characters must reach the matrix row header only in its escaped form; the
# raw markup must never appear unescaped (XSS via a consumer-controlled yaml
# string rendered into a text node). Pre-existing rendering (regular swatch
# names) must keep working alongside it.
assert_body_contains_all "/styleguide/render/foundations/index" \
    "&lt;b&gt;evil&quot;name&lt;/b&gt;" "foundations colors: hostile swatch name renders HTML-escaped in the matrix row header" \
    "brand-red"                        "foundations colors: pre-existing flat palette rendering still works"
assert_body_not_contains "/styleguide/render/foundations/index" "<b>evil" "foundations colors: hostile swatch name never reaches the body unescaped"

# Escaping sweep (#78) — the logo and typography sections of foundations.twig
# still interpolated consumer yaml raw (same class of bug as the #72-review
# finding above, just in a different section). Every hostile value below must
# reach the body only in its escaped form; the raw HTML/JS-shaped payload
# must never appear unescaped.
assert_body_contains_all "/styleguide/render/foundations/index" \
    "Logo&lt;script&gt;alert(1)&lt;/script&gt;"          "foundations logo: hostile label renders HTML-escaped" \
    "Typography&lt;script&gt;alert(6)&lt;/script&gt;"    "foundations typography: hostile section label (labels.* outside #colors) renders HTML-escaped" \
    "Evil&lt;img src=x onerror=alert(1)&gt;Font"          "foundations typography: hostile font name renders HTML-escaped" \
    "font.css&#x3F;v&#x3D;1&quot;onmouseover&#x3D;alert&#x28;2&#x29;" "foundations typography: font url with an embedded quote is html_attr-escaped (no attribute breakout)" \
    "Headings&lt;script&gt;alert(3)&lt;/script&gt;"       "foundations typography: hostile font usage tag renders HTML-escaped" \
    "Heading&lt;script&gt;alert(x)&lt;/script&gt;"        "foundations typography: hostile heading label renders HTML-escaped (pre-escaped before |typography)" \
    "Bold&lt;script&gt;alert(5)&lt;/script&gt;"           "foundations typography: hostile weight name renders HTML-escaped"

# Escaping sweep extension (#78 review) — the coverage above missed several
# escaped-interpolation contexts in foundations.twig: project.name/description
# (header, plain `|e` text nodes), logo.src/alt/size (attribute contexts,
# `|e('html_attr')`), font.type/alphabet and heading.tag/desc and weight.value
# (plain `|e` text nodes), heading.size and weight.class (attribute contexts).
# Every escaped form below was curled off a live `php -S` render of this same
# fixture rather than guessed — html_attr encodes far more aggressively than a
# hand-written entity guess would (e.g. spaces become `&#x20;`, `=` becomes
# `&#x3D;`, parens become `&#x28;`/`&#x29;`).
assert_body_contains_all "/styleguide/render/foundations/index" \
    "Styleguide Fixture&lt;img src=x onerror=alert(7)&gt;" "foundations header: hostile project.name renders HTML-escaped" \
    "Fixture project&lt;script&gt;alert(8)&lt;/script&gt;" "foundations header: hostile project.description renders HTML-escaped" \
    "&#x2F;images&#x2F;logo.svg&quot;&#x20;onerror&#x3D;&quot;alert&#x28;9&#x29;"   "foundations logo: hostile src is html_attr-escaped (no attribute breakout)" \
    "Logo&quot;&#x20;onerror&#x3D;&quot;alert&#x28;10&#x29;"                        "foundations logo: hostile alt is html_attr-escaped (no attribute breakout)" \
    "w-full&#x20;max-w-48&#x20;h-auto&quot;&#x20;onerror&#x3D;&quot;alert&#x28;11&#x29;" "foundations logo: hostile size is html_attr-escaped into the img class list (no attribute breakout)" \
    "Display&lt;script&gt;alert(12)&lt;/script&gt;"       "foundations typography: hostile font.type renders HTML-escaped" \
    "0123456789&lt;script&gt;alert(13)&lt;/script&gt;"    "foundations typography: hostile font.alphabet renders HTML-escaped" \
    "h1&lt;script&gt;alert(14)&lt;/script&gt;"             "foundations typography: hostile heading.tag renders HTML-escaped" \
    "text-4xl&quot;&#x20;onerror&#x3D;&quot;alert&#x28;15&#x29;"                    "foundations typography: hostile heading.size is html_attr-escaped into the label class (no attribute breakout)" \
    "36px / 700&lt;script&gt;alert(16)&lt;/script&gt;"    "foundations typography: hostile heading.desc renders HTML-escaped" \
    "font-bold&quot;&#x20;onerror&#x3D;&quot;alert&#x28;17&#x29;"                    "foundations typography: hostile weight.class is html_attr-escaped into the sample class (no attribute breakout)" \
    "700&lt;script&gt;alert(18)&lt;/script&gt;"            "foundations typography: hostile weight.value renders HTML-escaped"

# body_sample (#78 review) — same |e|typography path as heading.label above,
# but had no hostile-payload coverage of its own. The digit inside the payload
# is intentionally left in (unlike heading.label, which avoids digits) so this
# also pins the |typography number-wrap interaction: the emitted form wraps
# the lone digit in <span class="numbers">…</span> *after* HTML-escaping —
# confirmed by curling the fixture render directly rather than guessed.
assert_body_contains_all "/styleguide/render/foundations/index" \
    'lazy dog. &lt;img src=x onerror=alert(<span class="numbers">2</span>)&gt;End' "foundations typography: hostile body_sample renders HTML-escaped (including the |typography number-wrap span)"
assert_body_not_contains "/styleguide/render/foundations/index" "onerror=alert(2)" "foundations typography: hostile body_sample never reaches the body as raw unescaped markup"
# Raw-payload checks target the exact hostile string per fixture entry (not a
# bare `<script>` substring) — the page legitimately emits real `<script>`
# tags (foundations.js module, standalone-bar reveal script), so a blanket
# "body lacks <script>" assertion would false-positive against those.
assert_body_not_contains "/styleguide/render/foundations/index" "<script>alert(1)</script>" "foundations logo: hostile label never reaches the body as a live <script> tag"
assert_body_not_contains "/styleguide/render/foundations/index" "<script>alert(6)</script>" "foundations typography: hostile section label never reaches the body as a live <script> tag"
assert_body_not_contains "/styleguide/render/foundations/index" "<script>alert(3)</script>" "foundations typography: hostile font usage tag never reaches the body as a live <script> tag"
assert_body_not_contains "/styleguide/render/foundations/index" "<script>alert(x)</script>" "foundations typography: hostile heading label never reaches the body as a live <script> tag"
assert_body_not_contains "/styleguide/render/foundations/index" "<script>alert(5)</script>" "foundations typography: hostile weight name never reaches the body as a live <script> tag"
assert_body_not_contains "/styleguide/render/foundations/index" "<img src=x onerror=" "foundations typography: hostile font name never reaches the body unescaped"
assert_body_not_contains "/styleguide/render/foundations/index" '"onmouseover=alert' "foundations typography: font url quote never breaks out of the href attribute"

# Raw-payload checks for the extended sweep above (#78 review) — same
# exact-string-per-fixture-entry discipline as the block above it.
assert_body_not_contains "/styleguide/render/foundations/index" "<img src=x onerror=alert(7)>" "foundations header: hostile project.name never reaches the body unescaped"
assert_body_not_contains "/styleguide/render/foundations/index" "<script>alert(8)</script>"     "foundations header: hostile project.description never reaches the body as a live <script> tag"
assert_body_not_contains "/styleguide/render/foundations/index" 'onerror="alert(9)'              "foundations logo: hostile src never breaks out of the src attribute"
assert_body_not_contains "/styleguide/render/foundations/index" 'onerror="alert(10)'             "foundations logo: hostile alt never breaks out of the alt attribute"
assert_body_not_contains "/styleguide/render/foundations/index" 'onerror="alert(11)'             "foundations logo: hostile size never breaks out of the img class attribute"
assert_body_not_contains "/styleguide/render/foundations/index" "<script>alert(12)</script>"     "foundations typography: hostile font.type never reaches the body as a live <script> tag"
assert_body_not_contains "/styleguide/render/foundations/index" "<script>alert(13)</script>"     "foundations typography: hostile font.alphabet never reaches the body as a live <script> tag"
assert_body_not_contains "/styleguide/render/foundations/index" "<script>alert(14)</script>"     "foundations typography: hostile heading.tag never reaches the body as a live <script> tag"
assert_body_not_contains "/styleguide/render/foundations/index" 'onerror="alert(15)'             "foundations typography: hostile heading.size never breaks out of the label class attribute"
assert_body_not_contains "/styleguide/render/foundations/index" "<script>alert(16)</script>"     "foundations typography: hostile heading.desc never reaches the body as a live <script> tag"
assert_body_not_contains "/styleguide/render/foundations/index" 'onerror="alert(17)'             "foundations typography: hostile weight.class never breaks out of the sample class attribute"
assert_body_not_contains "/styleguide/render/foundations/index" "<script>alert(18)</script>"     "foundations typography: hostile weight.value never reaches the body as a live <script> tag"

# Package-shipped vanilla foundations.js (#79) — injected alongside
# foundations.css for the foundations render only; see render-cell.twig.
assert_body_contains_all "/styleguide/render/foundations/index" \
    'type="module" src="'                     "foundations render injects the package foundations JS module" \
    '/styleguide/assets/foundations.'         "foundations render js module url is hashed under the foundations. prefix" \
    '.js"></script>'                          "foundations render js module url ends in .js"

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
