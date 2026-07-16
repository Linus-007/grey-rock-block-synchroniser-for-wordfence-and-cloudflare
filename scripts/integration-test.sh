#!/usr/bin/env bash

set -Eeuo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="$REPO_ROOT/tests/docker/compose.yml"
PLUGIN_ZIP="$REPO_ROOT/dist/grey-rock-block-synchroniser-for-wordfence-and-cloudflare.zip"
REPORT_DIR="$REPO_ROOT/reports/integration"
PROJECT_NAME="greyrock-plugin-test"
TEST_URL="http://127.0.0.1:18080"
TEST_PORT="18080"
PLUGIN_SLUG="grey-rock-block-synchroniser-for-wordfence-and-cloudflare"

mkdir -p "$REPORT_DIR" "$REPO_ROOT/.tools"
rm -f "$REPORT_DIR"/*

test -f "$COMPOSE_FILE"
test -f "$PLUGIN_ZIP"

for command_name in sudo docker curl openssl python3; do
    if ! command -v "$command_name" >/dev/null 2>&1; then
        echo "ERROR: Required command is unavailable: $command_name" >&2
        exit 1
    fi
done

python3 - "$TEST_PORT" <<'PYTHON'
import socket
import sys

port = int(sys.argv[1])
sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)

try:
    sock.bind(("127.0.0.1", port))
except OSError as error:
    print(f"ERROR: TCP port {port} is unavailable: {error}", file=sys.stderr)
    raise SystemExit(1)
finally:
    sock.close()

print(f"PASS: TCP port {port} is available.")
PYTHON

umask 077

RUNTIME_ENV="$(mktemp "$REPO_ROOT/.tools/greyrock-test.XXXXXX.env")"
TEST_ADMIN_PASSWORD="$(openssl rand -hex 24)"

cat > "$RUNTIME_ENV" <<EOF
TEST_DB_PASSWORD=$(openssl rand -hex 24)
TEST_DB_ROOT_PASSWORD=$(openssl rand -hex 24)
PLUGIN_ZIP=$PLUGIN_ZIP
EOF

COMPOSE=(
    sudo docker compose
    --env-file "$RUNTIME_ENV"
    --project-name "$PROJECT_NAME"
    --file "$COMPOSE_FILE"
)

cleanup() {
    local result="$1"

    trap - EXIT INT TERM
    set +e

    {
        echo "===== CLEANUP ====="
        date --iso-8601=seconds
        "$REPO_ROOT/scripts/stop-test-environment.sh" "$RUNTIME_ENV"
        cleanup_result=$?
        rm -f "$RUNTIME_ENV"

        echo
        echo "Cleanup exit code: $cleanup_result"
    } >> "$REPORT_DIR/cleanup.log" 2>&1

    exit "$result"
}

trap 'cleanup $?' EXIT
trap 'cleanup 130' INT
trap 'cleanup 143' TERM

echo "===== START DOCKER ====="

sudo systemctl start \
    containerd.service \
    docker.socket \
    docker.service

sudo docker info >/dev/null

echo "===== PULL TEST IMAGES ====="
"${COMPOSE[@]}" pull db wordpress cli

echo "===== START WORDPRESS TEST STACK ====="
"${COMPOSE[@]}" up --detach db wordpress

echo "===== WAIT FOR WORDPRESS HTTP SERVICE ====="

wordpress_ready=0

for attempt in $(seq 1 90); do
    if curl \
        --fail \
        --silent \
        --output /dev/null \
        "$TEST_URL/wp-login.php"; then
        wordpress_ready=1
        break
    fi

    sleep 2
done

if [[ "$wordpress_ready" -ne 1 ]]; then
    echo "ERROR: WordPress did not become available." >&2
    "${COMPOSE[@]}" ps > "$REPORT_DIR/compose-ps-failure.txt" 2>&1 || true
    "${COMPOSE[@]}" logs --no-color > "$REPORT_DIR/compose-failure.log" 2>&1 || true
    exit 1
fi

echo "PASS: WordPress HTTP service is available."

echo "===== INSTALL WORDPRESS ====="

"${COMPOSE[@]}" run --rm --no-TTY cli \
    core install \
    --url="$TEST_URL" \
    --title="Grey Rock Automated Plugin Test" \
    --admin_user="greyrock-test-admin" \
    --admin_password="$TEST_ADMIN_PASSWORD" \
    --admin_email="greyrock-test@example.invalid" \
    --skip-email

echo "===== INSTALL WORDFENCE ====="

"${COMPOSE[@]}" run --rm --no-TTY cli \
    plugin install wordfence \
    --activate

echo "===== INSTALL LOCAL GREY ROCK ZIP ====="

"${COMPOSE[@]}" run --rm --no-TTY cli \
    plugin install /artifacts/greyrock-plugin.zip \
    --force \
    --activate

echo "===== VERIFY WORDPRESS AND PLUGINS ====="

"${COMPOSE[@]}" run --rm --no-TTY cli \
    core version |
    tee "$REPORT_DIR/wordpress-version.txt"

"${COMPOSE[@]}" run --rm --no-TTY cli \
    plugin list --format=table |
    tee "$REPORT_DIR/plugin-list.txt"

"${COMPOSE[@]}" run --rm --no-TTY cli \
    plugin is-active wordfence

"${COMPOSE[@]}" run --rm --no-TTY cli \
    plugin is-active "$PLUGIN_SLUG"

"${COMPOSE[@]}" run --rm --no-TTY cli \
    plugin get "$PLUGIN_SLUG" --field=version |
    tee "$REPORT_DIR/greyrock-version.txt"

echo "===== HTTP SMOKE TESTS ====="

front_status="$(
    curl \
        --silent \
        --output /dev/null \
        --write-out '%{http_code}' \
        "$TEST_URL/"
)"

login_status="$(
    curl \
        --silent \
        --output /dev/null \
        --write-out '%{http_code}' \
        "$TEST_URL/wp-login.php"
)"

printf "Front page HTTP status: %s\n" "$front_status"
printf "Login page HTTP status: %s\n" "$login_status"

if [[ "$front_status" != "200" ]]; then
    echo "ERROR: Front page did not return HTTP 200." >&2
    exit 1
fi

if [[ "$login_status" != "200" ]]; then
    echo "ERROR: Login page did not return HTTP 200." >&2
    exit 1
fi

echo "===== COLLECT APPLICATION LOGS ====="

"${COMPOSE[@]}" logs --no-color \
    > "$REPORT_DIR/compose.log" 2>&1

"${COMPOSE[@]}" exec --no-TTY wordpress sh -c '
if [ -f /var/www/html/wp-content/debug.log ]; then
    cat /var/www/html/wp-content/debug.log
fi
' > "$REPORT_DIR/wordpress-debug.log" 2>&1

if grep -Eiq \
    'PHP (Fatal error|Parse error)|Uncaught (Error|Exception)' \
    "$REPORT_DIR/compose.log" \
    "$REPORT_DIR/wordpress-debug.log"; then
    echo "ERROR: A fatal PHP application error was detected." >&2
    grep -Ein \
        'PHP (Fatal error|Parse error)|Uncaught (Error|Exception)' \
        "$REPORT_DIR/compose.log" \
        "$REPORT_DIR/wordpress-debug.log" >&2
    exit 1
fi

"${COMPOSE[@]}" ps > "$REPORT_DIR/compose-ps.txt"
"${COMPOSE[@]}" images > "$REPORT_DIR/compose-images.txt"

plugin_version="$(tr -d '\r\n' < "$REPORT_DIR/greyrock-version.txt")"
wordpress_version="$(tr -d '\r\n' < "$REPORT_DIR/wordpress-version.txt")"

cat > "$REPORT_DIR/summary.txt" <<EOF
RESULT=PASS
WORDPRESS_VERSION=$wordpress_version
GREYROCK_PLUGIN_VERSION=$plugin_version
WORDFENCE_ACTIVE=yes
GREYROCK_PLUGIN_ACTIVE=yes
FRONT_PAGE_HTTP_STATUS=$front_status
LOGIN_PAGE_HTTP_STATUS=$login_status
TEST_URL=$TEST_URL
EOF

echo
echo "INTEGRATION TEST RESULT: PASS"
echo "Containers and volumes will now be removed."
echo "Docker services will now be stopped."
