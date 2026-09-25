#!/usr/bin/env bash
#
# The shipped front controller (resources/front-controller.php), over HTTP.
#
# Writes it with `styleguide front-controller:init` into a static directory
# nested the way a CMS theme nests it — vendor/ several levels up — and serves
# it with PHP's built-in server. Checks what only a real request shows: the
# autoloader walk, the static-file passthrough, the routes, and debug on and
# off. The kernel's own behaviour is covered by StyleguideKernelTest.
#
# Usage:
#   bash tests/e2e/front-controller.sh
#   PORT=9100 bash tests/e2e/front-controller.sh
#
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
HOST="127.0.0.1"
PORT="${PORT:-8431}"
WORK="$ROOT/.e2e-front-controller"
STATIC="$WORK/web/themes/custom/theme/static"
PIDS=()

cleanup() {
    for pid in "${PIDS[@]}"; do kill "$pid" 2>/dev/null || true; done
    rm -rf "$WORK"
}
trap cleanup EXIT

rm -rf "$WORK"
mkdir -p "$STATIC/dist/css"
printf 'body{}\n' > "$STATIC/dist/css/style.css"
cat > "$STATIC/styleguide.yaml" <<YAML
bootstrap:
  templates_path: $ROOT/tests/fixtures/templates
  static_path: .
  default_locale: en
project:
  name: Front Controller Fixture
  slug: front-controller-fixture
iframe:
  css: /dist/css/style.css
  js: ''
YAML

php "$ROOT/bin/styleguide" front-controller:init --dir="$STATIC" || exit 1

fail=0

serve() { # <port> [VAR=value ...]
    local port=$1; shift
    (cd "$STATIC" && exec env "$@" php -S "$HOST:$port" index.php >"$WORK/php-$port.log" 2>&1) &
    PIDS+=($!)
    for _ in $(seq 1 25); do
        curl -sf -o /dev/null "http://$HOST:$port/styleguide/api/health" && return 0
        sleep 0.2
    done
    echo "::error::front controller on port $port never answered" >&2
    cat "$WORK/php-$port.log" >&2
    fail=1
    return 1
}

check() { # <port> <path> <expected code>
    local code
    code=$(curl -s -o /dev/null -w '%{http_code}' "http://$HOST:$1$2")
    if [ "$code" = "$3" ]; then
        echo "  ✓ [$1] $2 → $3"
    else
        echo "::error::[$1] $2 answered $code, expected $3" >&2
        fail=1
    fi
}

toolbar() { # <port> <yes|no> <path>
    local has=no
    curl -s "http://$HOST:$1$3" | grep -q sfToolbar && has=yes
    if [ "$has" = "$2" ]; then
        echo "  ✓ [$1] $3 toolbar=$2"
    else
        echo "::error::[$1] $3: debug toolbar present=$has, expected $2" >&2
        fail=1
    fi
}

P=$PORT
D=$((PORT + 1))

echo "--- no APP_ENV: production ---"
if serve "$P"; then
    check "$P" /styleguide 200
    check "$P" /styleguide/ 200
    check "$P" /styleguide/component/sample 200
    check "$P" /styleguide/render/component/sample 200
    check "$P" /styleguide/api/components 200
    check "$P" /dist/css/style.css 200
    check "$P" /styleguide.yaml 404
    check "$P" /_profiler/ 404
    toolbar "$P" no /styleguide/
fi

echo "--- APP_ENV=dev ---"
if serve "$D" APP_ENV=dev; then
    check "$D" /styleguide/ 200
    toolbar "$D" yes /styleguide/
    toolbar "$D" no /styleguide/render/component/sample
fi

exit $fail
