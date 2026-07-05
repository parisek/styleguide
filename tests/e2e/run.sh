#!/usr/bin/env bash
#
# Orchestrates the styleguide e2e suite against the package's own fixture:
#   Layer A — HTTP smoke (curl)   always
# Boots a `php -S` fixture server, runs the layer, tears the server down.
# The package's unit suite (PHPUnit) is separate — run it via `composer test`.
#
# Browser-level SPA behaviour (hydration, navigation, viewport presets,
# search, locale/theme switching, the standalone back-bar, …) used to be
# covered here by a local-only "Layer B" (`smoke-browser.sh`, `agent-browser`
# CLI) that read state out of `window.Alpine.store(...)`. The Vue rewrite
# (Phase 1 of the Styleguide 2.0 effort) removed that global, so Layer B
# silently rotted into dead code. It's been superseded by
# `tests/e2e/playwright/styleguide.spec.js` — a stricter suite that asserts
# through the rendered DOM only (no store reach-through) and runs in CI
# (`.github/workflows/tests.yml`'s `e2e-playwright` job). Run it locally with:
#   cd frontend && npm run test:e2e
#
# Usage:
#   bash tests/e2e/run.sh                # Layer A
#   PORT=9000 bash tests/e2e/run.sh      # pick a different port
#
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
HOST="127.0.0.1"
PORT="${PORT:-8421}"
BASE="http://$HOST:$PORT"

if [ -t 1 ]; then BOLD='\033[1m'; NC='\033[0m'; else BOLD=''; NC=''; fi

# Boot the fixture server (docroot = tests/fixtures so the router's real-file
# check and `php -S` static serving agree).
php -S "$HOST:$PORT" -t "$ROOT/tests/fixtures" "$ROOT/tests/fixtures/index.php" >/tmp/sg-e2e-server.log 2>&1 &
SERVER_PID=$!
cleanup() { kill "$SERVER_PID" 2>/dev/null || true; }
trap cleanup EXIT

# Give php -S a beat to fail its bind (a port-already-in-use error exits the
# process near-instantly) BEFORE the readiness probe below — otherwise that probe
# could reach a different server already on $PORT and run the whole suite against
# it (false green). This upfront check is what makes the port-conflict guard
# reliable; the in-loop check is a secondary guard for a later crash.
sleep 0.4
if ! kill -0 "$SERVER_PID" 2>/dev/null; then
    echo "fixture server (php -S on $HOST:$PORT) failed to start — is port $PORT already in use? (log: /tmp/sg-e2e-server.log)" >&2
    exit 1
fi

# Wait for the server to answer (max ~5s). Bail clearly if our `php -S` died
# during startup — typically because $PORT is already in use. Without the
# `kill -0` check the loop would happily get a 200 from whatever stale server
# already owns the port and run the suite against it (a false green); without
# the final readiness check it would fall through into the smoke tests and
# report confusing connection-refused errors instead of "server never came up".
ready=0
for _ in $(seq 1 25); do
    if ! kill -0 "$SERVER_PID" 2>/dev/null; then
        echo "fixture server (php -S on $HOST:$PORT) exited during startup — is port $PORT already in use? (log: /tmp/sg-e2e-server.log)" >&2
        exit 1
    fi
    if curl -sk -o /dev/null "$BASE/styleguide/" 2>/dev/null; then ready=1; break; fi
    sleep 0.2
done
if [ "$ready" -ne 1 ]; then
    echo "fixture server did not answer at $BASE within ~5s (log: /tmp/sg-e2e-server.log)" >&2
    exit 1
fi

export BASE
rc=0

printf '%b\n' "${BOLD}--- Layer A — HTTP smoke ---${NC}"
bash "$ROOT/tests/e2e/smoke-http.sh" || rc=1

exit $rc
