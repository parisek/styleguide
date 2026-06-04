#!/usr/bin/env bash
#
# Layer B - Browser smoke tests for the styleguide SPA, run against the package's
# own fixture (tests/fixtures/). Asserts hydration + interaction behaviour Layer A
# (curl) can't reach: Alpine stores populated, sidebar bucket coverage, router
# navigation, Cmd+K focus, width preset, locale switch + <html lang>, standalone
# back-bar visibility. Browser-only — kept out of CI by default (run.sh runs it
# only when agent-browser is installed); see CONTRIBUTING for the local recipe.
#
# The agent-browser CLI exposes a JS execution primitive for reading state out of
# the browser; we use it only to read Alpine store values - no untrusted input.
#
# Usage (run.sh manages the fixture server; or start one yourself):
#   php -S 127.0.0.1:8421 tests/fixtures/index.php &
#   bash tests/e2e/smoke-browser.sh
#
# Requires: `agent-browser` CLI (npm i -g agent-browser && agent-browser install).
#
set -euo pipefail

BASE="${BASE:-http://127.0.0.1:8421}"
PASS=0
FAIL=0

if [ -t 1 ]; then
    GREEN='\033[0;32m'; RED='\033[0;31m'; DIM='\033[2m'; NC='\033[0m'
else
    GREEN=''; RED=''; DIM=''; NC=''
fi

ok() { printf "  ${GREEN}OK${NC}  %s\n" "$*"; PASS=$((PASS+1)); }
ko() { printf "  ${RED}FAIL${NC} %s\n" "$*" >&2; FAIL=$((FAIL+1)); }

if ! command -v agent-browser >/dev/null 2>&1; then
    echo "agent-browser CLI not found - install with: npm i -g agent-browser && agent-browser install" >&2
    exit 2
fi
if ! command -v jq >/dev/null 2>&1; then
    echo "jq not found - install with: brew install jq (macOS) / apt-get install jq (Debian/Ubuntu)" >&2
    exit 2
fi

# Run JS in the browser via agent-browser; the CLI returns the JS result as a
# JSON-encoded string, unwrapped with jq fromjson. Pipefail tolerance: when
# `agent-browser eval` fails transiently (page not hydrated, daemon recycling)
# the function returns empty rather than aborting the whole script via errexit —
# wait_for_hydration() relies on calling ab_run repeatedly until it succeeds.
ab_run() {
    local raw
    if ! raw=$(agent-browser eval "$1" 2>/dev/null); then
        return 0
    fi
    printf '%s' "$raw" | jq -rc 'fromjson? // .' 2>/dev/null || true
}

# Wait for Alpine.store('components').loading === false (max ~3s).
wait_for_hydration() {
    for _ in 1 2 3 4 5 6; do
        local got
        got=$(ab_run "JSON.stringify({loading: window.Alpine?.store('components')?.loading ?? true})" 2>/dev/null || echo '{"loading":true}')
        if printf '%s' "$got" | grep -q '"loading":false'; then return 0; fi
        sleep 0.5
    done
    return 1
}

# assert_eq label actual expected
assert_eq() {
    local label="$1" got="$2" want="$3"
    if [ "$got" = "$want" ]; then ok "$label ${DIM}[$got]${NC}"; else ko "$label: expected '$want', got '$got'"; fi
}

# assert_ge label actual min
assert_ge() {
    local label="$1" got="$2" min="$3"
    if [ "$got" -ge "$min" ] 2>/dev/null; then ok "$label ${DIM}[$got >= $min]${NC}"; else ko "$label: expected >= $min, got '$got'"; fi
}

cleanup() { agent-browser close --all >/dev/null 2>&1 || true; }
trap cleanup EXIT

# Start clean so we test the freshly-built SPA, not a stale browser state.
agent-browser close --all >/dev/null 2>&1 || true

printf "${DIM}== Layer B - browser smoke (%s) ==${NC}\n" "$BASE"

# --- 1. Landing hydration ---
agent-browser open "$BASE/styleguide/" >/dev/null 2>&1
wait_for_hydration || { ko "SPA failed to hydrate within 3s"; exit 1; }
ok "SPA hydrated"

state=$(ab_run "JSON.stringify({
    items: window.Alpine.store('components').items.length,
    pages: window.Alpine.store('components').pages.length,
    routeType: window.Alpine.store('ui').route.type,
    locale: window.Alpine.store('i18n').locale,
    overviewLabel: window.Alpine.store('i18n').strings.nav?.overview ?? null,
})")
items=$(printf '%s' "$state" | jq -r .items)
pages=$(printf '%s' "$state" | jq -r .pages)
routeType=$(printf '%s' "$state" | jq -r .routeType)
locale=$(printf '%s' "$state" | jq -r .locale)
overviewLabel=$(printf '%s' "$state" | jq -r .overviewLabel)

# Fixture ships 2 components + 1 page.
assert_ge "components store populated"  "$items" 2
assert_ge "pages store populated"       "$pages" 1
# Opening `/styleguide/` lands on Foundations, not Overview: the router maps the
# `landing` route to `foundations`, pinned as the default landing view.
assert_eq "landing routes to foundations" "$routeType" "foundations"
assert_eq "default locale is cs"        "$locale" "cs"
assert_eq "i18n loaded (nav.overview)"  "$overviewLabel" "Přehled"

# Every renderable component lands in exactly one sidebar bucket — catches silent
# category-bucket drops. `bySection()` excludes skeleton-only templates
# (`hasStyleguide === false`), so the baseline is the renderable count, not total
# items; `sectionOf()` maps every category into one bucket, so the sum equals the
# renderable count unless a component is genuinely dropped.
buckets=$(ab_run "JSON.stringify({
    basic: window.Alpine.store('components').bySection('basic').length,
    blocks: window.Alpine.store('components').bySection('blocks').length,
    gutenberg: window.Alpine.store('components').bySection('gutenberg').length,
    renderable: window.Alpine.store('components').items.filter((c) => c.hasStyleguide !== false).length,
})")
sum=$(printf '%s' "$buckets" | jq '[.basic, .blocks, .gutenberg] | add')
renderable=$(printf '%s' "$buckets" | jq -r '.renderable')
assert_eq "sidebar buckets cover all renderable components" "$sum" "$renderable"

# --- 2. Navigation ---
agent-browser eval "window.sgNavigate('/styleguide/component/sample')" >/dev/null
sleep 0.3
route=$(ab_run "JSON.stringify({type: window.Alpine.store('ui').route.type, slug: window.Alpine.store('ui').route.slug, path: location.pathname})")
assert_eq "sgNavigate updated route type"   "$(printf '%s' "$route" | jq -r .type)" "component"
assert_eq "sgNavigate updated route slug"   "$(printf '%s' "$route" | jq -r .slug)" "sample"
assert_eq "sgNavigate pushed URL"           "$(printf '%s' "$route" | jq -r .path)" "/styleguide/component/sample"

# Iframe source matches the render endpoint
sleep 0.5
ifr=$(ab_run "JSON.stringify({src: document.querySelector('iframe')?.src ?? null})")
assert_eq "iframe src points at render endpoint" \
    "$(printf '%s' "$ifr" | jq -r .src)" "$BASE/styleguide/render/component/sample"

# --- 3a. viewport toolbar RENDERS for responsive:true entries (issue #36) ---
# Regression guard: the toolbar's <template x-if> must actually render its
# controls in the DOM for a responsive:true component (sample, the current
# route). The suite previously only called setPreset() as a method, so a
# completely missing toolbar — regressed in 0.4.0 by a find() call inside the
# x-if gate — passed unnoticed. The px readout's x-text is unique to the
# viewport controls, so its presence proves the toolbar rendered.
tb=$(ab_run "JSON.stringify({rendered: [...document.querySelectorAll('[x-text]')].some(e => /px/.test(e.getAttribute('x-text') || ''))})")
assert_eq "viewport toolbar renders for responsive:true component" "$(printf '%s' "$tb" | jq -r .rendered)" "true"

# --- 3. Width preset ---
agent-browser eval "window.Alpine.\$data(document.querySelector('[x-data=\"preview\"]')).setPreset('tablet')" >/dev/null
sleep 0.3
width=$(ab_run "JSON.stringify({w: window.Alpine.store('ui').previewWidth})")
assert_eq "Tablet preset sets width to 768px" "$(printf '%s' "$width" | jq -r .w)" "768px"

# --- 3b. responsive:false pins the preview to full width (issue #34) ---
# A `responsive: false` entry hides the viewport toolbar AND must pin the preview
# to full width — otherwise a non-Full width persisted in sg-preview-width (Tablet
# 768px, still active from section 3) strands the iframe at that device size with
# no toolbar to reset it. Navigating to the responsive:false fixture doc must
# collapse the preview component's effectiveWidth back to Full (null).
agent-browser eval "window.sgNavigate('/styleguide/doc/sample-doc')" >/dev/null
sleep 0.4
ew=$(ab_run "JSON.stringify({ew: window.Alpine.\$data(document.querySelector('[x-data=\"preview\"]')).effectiveWidth})")
assert_eq "responsive:false doc pins effectiveWidth to Full (null)" "$(printf '%s' "$ew" | jq -r .ew)" "null"

# --- 4. Search (Cmd+K) ---
agent-browser eval "window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true }))" >/dev/null
sleep 0.1
focused=$(ab_run "JSON.stringify({focused: document.activeElement?.getAttribute('x-ref') ?? null})")
assert_eq "Cmd+K focuses the search input" "$(printf '%s' "$focused" | jq -r .focused)" "input"

# --- 5. Locale switch ---
agent-browser eval "window.Alpine.store('i18n').load('en')" >/dev/null
sleep 0.4
loc=$(ab_run "JSON.stringify({locale: window.Alpine.store('i18n').locale, basic: window.Alpine.store('i18n').strings.sections?.basic, lang: document.documentElement.lang})")
assert_eq "locale switched to en"              "$(printf '%s' "$loc" | jq -r .locale)" "en"
assert_eq "en strings loaded (sections.basic)" "$(printf '%s' "$loc" | jq -r .basic)" "Basic elements"
assert_eq "<html lang> updated"                "$(printf '%s' "$loc" | jq -r .lang)" "en"

agent-browser eval "window.Alpine.store('i18n').load('cs')" >/dev/null

# --- 6. Standalone render - back-bar visible only outside iframe ---
# render-cell.twig hides the bar with `style="display:none"` and JS flips it to
# `display:flex` only in standalone mode — so visibility must be read off the
# COMPUTED display, not the `.hidden` property (which the bar never sets). Reading
# `.hidden` is why this check was fragile across package versions.
agent-browser open "$BASE/styleguide/render/component/sample" >/dev/null
sleep 0.5
bar=$(ab_run "JSON.stringify({
    visible: (() => { const el = document.getElementById('sg-standalone-bar'); return el ? getComputedStyle(el).display !== 'none' : null; })(),
    href: document.querySelector('#sg-standalone-bar a')?.getAttribute('href') ?? null,
})")
assert_eq "standalone back-bar visible"    "$(printf '%s' "$bar" | jq -r .visible)" "true"
assert_eq "standalone back-bar links home" "$(printf '%s' "$bar" | jq -r .href)" "/styleguide/component/sample"

# Inside the SPA iframe the bar must stay hidden (it would be meaningless clutter
# in every preview). Compute display via the iframe's own window.
agent-browser open "$BASE/styleguide/component/sample" >/dev/null
sleep 0.6
ifrBar=$(ab_run "JSON.stringify({
    visible: (() => { const ifr = document.querySelector('iframe'); const el = ifr?.contentDocument?.getElementById('sg-standalone-bar'); return el ? ifr.contentWindow.getComputedStyle(el).display !== 'none' : null; })(),
})")
assert_eq "iframe back-bar hidden" "$(printf '%s' "$ifrBar" | jq -r .visible)" "false"

printf "\n"
if [ "$FAIL" -gt 0 ]; then
    printf "${RED}== Layer B FAILED - %d pass, %d fail ==${NC}\n" "$PASS" "$FAIL"
    exit 1
fi
printf "${GREEN}== Layer B OK - %d checks passed ==${NC}\n" "$PASS"
