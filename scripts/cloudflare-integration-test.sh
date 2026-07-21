#!/usr/bin/env bash

set -Eeuo pipefail

if [[ "$EUID" -ne 0 ]]; then
	echo "ERROR: Run this script through sudo." >&2
	exit 1
fi

GREYROCK_SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
REPO_ROOT="$(cd -- "${GREYROCK_SCRIPT_DIR}/.." && pwd -P)"
COMPOSE_FILE="$REPO_ROOT/tests/docker/compose.yml"
PLUGIN_ZIP="$REPO_ROOT/dist/grey-rock-block-synchroniser-for-wordfence-and-cloudflare.zip"
PHP_TEST="$REPO_ROOT/tests/integration/cloudflare-live-test.php"
CONFIG_FILE="/etc/greyrock-plugin-cloudflare-test.env"
REPORT_DIR="$REPO_ROOT/reports/cloudflare-integration"
PROJECT_NAME="greyrock-cloudflare-live"
TEST_URL="http://127.0.0.1:18080"
EXPECTED_LIST_ID="7811817676fa4bac90479557ab74ba93"
EXPECTED_LIST_NAME="wordfence_hot_blocklist"
EXPECTED_TEST_IP="8.8.8.8"

REPORT_OWNER="${SUDO_USER:-$(stat --format='%U' "$REPO_ROOT")}"
REPORT_GROUP="$(id -gn "$REPORT_OWNER")"

RUNTIME_DIR=""
RUNTIME_ENV=""
OVERRIDE_FILE=""
COMPOSE_READY=0

mkdir -p "$REPORT_DIR"
rm -f "$REPORT_DIR"/*

cleanup() {
	result="$?"

	trap - EXIT INT TERM
	set +e

	if [[ "$COMPOSE_READY" -eq 1 ]]; then
		docker compose \
			--env-file "$RUNTIME_ENV" \
			--project-name "$PROJECT_NAME" \
			--file "$COMPOSE_FILE" \
			--file "$OVERRIDE_FILE" \
			logs --no-color \
			> "$REPORT_DIR/compose-final.log" 2>&1

		docker compose \
			--env-file "$RUNTIME_ENV" \
			--project-name "$PROJECT_NAME" \
			--file "$COMPOSE_FILE" \
			--file "$OVERRIDE_FILE" \
			down --volumes --remove-orphans \
			> "$REPORT_DIR/cleanup.log" 2>&1
	fi

	systemctl stop \
		docker.socket \
		docker.service \
		containerd.service \
		>> "$REPORT_DIR/cleanup.log" 2>&1

	if [[ -n "$RUNTIME_DIR" && -d "$RUNTIME_DIR" ]]; then
		rm -rf "$RUNTIME_DIR"
	fi

	chown -R "$REPORT_OWNER:$REPORT_GROUP" "$REPORT_DIR"

	exit "$result"
}

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

for required_file in \
	"$COMPOSE_FILE" \
	"$PLUGIN_ZIP" \
	"$PHP_TEST" \
	"$CONFIG_FILE"
do
	if [[ ! -f "$required_file" ]]; then
		echo "ERROR: Required file is missing: $required_file" >&2
		exit 1
	fi
done

if [[ "$(stat --format='%U:%G' "$CONFIG_FILE")" != "root:root" ]]; then
	echo "ERROR: Cloudflare credential file ownership is incorrect." >&2
	exit 1
fi

if [[ "$(stat --format='%a' "$CONFIG_FILE")" != "600" ]]; then
	echo "ERROR: Cloudflare credential file permissions are incorrect." >&2
	exit 1
fi

# shellcheck disable=SC1090
source "$CONFIG_FILE"

if [[ "$CLOUDFLARE_LIST_ID" != "$EXPECTED_LIST_ID" ]]; then
	echo "ERROR: The configured Cloudflare list ID is not approved." >&2
	exit 1
fi

if [[ "$CLOUDFLARE_LIST_NAME" != "$EXPECTED_LIST_NAME" ]]; then
	echo "ERROR: The configured Cloudflare list name is not approved." >&2
	exit 1
fi

if [[ "$CLOUDFLARE_TEST_IP" != "$EXPECTED_TEST_IP" ]]; then
	echo "ERROR: The configured Cloudflare test IP is not approved." >&2
	exit 1
fi

if [[ -z "${CLOUDFLARE_API_TOKEN:-}" ]]; then
	echo "ERROR: The Cloudflare API token is missing." >&2
	exit 1
fi

python3 - <<'PYTHON'
import socket

sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)

try:
    sock.bind(("127.0.0.1", 18080))
except OSError as error:
    print(f"ERROR: TCP port 18080 is unavailable: {error}")
    raise SystemExit(1)
finally:
    sock.close()

print("PASS: TCP port 18080 is available.")
PYTHON

RUNTIME_DIR="$(mktemp --directory /run/greyrock-cloudflare-test.XXXXXX)"
chmod 0700 "$RUNTIME_DIR"

SECRET_JSON="$RUNTIME_DIR/cloudflare-test.json"
RUNTIME_ENV="$RUNTIME_DIR/runtime.env"
OVERRIDE_FILE="$RUNTIME_DIR/compose.override.yml"

export CLOUDFLARE_ACCOUNT_ID
export CLOUDFLARE_LIST_ID
export CLOUDFLARE_LIST_NAME
export CLOUDFLARE_TEST_IP
export CLOUDFLARE_API_TOKEN

python3 - "$SECRET_JSON" <<'PYTHON'
import json
import os
import sys

output = sys.argv[1]

payload = {
    "account_id": os.environ["CLOUDFLARE_ACCOUNT_ID"],
    "list_id": os.environ["CLOUDFLARE_LIST_ID"],
    "list_name": os.environ["CLOUDFLARE_LIST_NAME"],
    "test_ip": os.environ["CLOUDFLARE_TEST_IP"],
    "token": os.environ["CLOUDFLARE_API_TOKEN"],
}

with open(output, "w", encoding="utf-8") as handle:
    json.dump(payload, handle)
    handle.write("\n")
PYTHON

chmod 0400 "$SECRET_JSON"

unset CLOUDFLARE_API_TOKEN

cat > "$RUNTIME_ENV" <<EOF
TEST_DB_PASSWORD=$(openssl rand -hex 24)
TEST_DB_ROOT_PASSWORD=$(openssl rand -hex 24)
PLUGIN_ZIP=$PLUGIN_ZIP
CLOUDFLARE_TEST_JSON=$SECRET_JSON
CLOUDFLARE_TEST_SCRIPT=$PHP_TEST
EOF

chmod 0600 "$RUNTIME_ENV"

cat > "$OVERRIDE_FILE" <<'YAML'
services:
  cli:
    user: "0:0"
    volumes:
      - type: bind
        source: "${CLOUDFLARE_TEST_JSON:?CLOUDFLARE_TEST_JSON is required}"
        target: /run/secrets/cloudflare-test.json
        read_only: true
      - type: bind
        source: "${CLOUDFLARE_TEST_SCRIPT:?CLOUDFLARE_TEST_SCRIPT is required}"
        target: /tests/cloudflare-live-test.php
        read_only: true
YAML

chmod 0600 "$OVERRIDE_FILE"

COMPOSE=(
	docker compose
	--env-file "$RUNTIME_ENV"
	--project-name "$PROJECT_NAME"
	--file "$COMPOSE_FILE"
	--file "$OVERRIDE_FILE"
)

"${COMPOSE[@]}" config --quiet
COMPOSE_READY=1

echo "===== START DOCKER ====="

systemctl start \
	containerd.service \
	docker.service

docker info >/dev/null

echo
echo "===== START DISPOSABLE WORDPRESS STACK ====="

"${COMPOSE[@]}" pull db wordpress cli
"${COMPOSE[@]}" up --detach db wordpress

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
	exit 1
fi

echo "PASS: WordPress HTTP service is available."

echo
echo "===== INSTALL WORDPRESS AND PLUGINS ====="

"${COMPOSE[@]}" run --rm --no-TTY cli \
	--allow-root \
	core install \
	--url="$TEST_URL" \
	--title="Greyrock Cloudflare Live Test" \
	--admin_user="greyrock-test-admin" \
	--admin_password="$(openssl rand -hex 24)" \
	--admin_email="greyrock-test@example.invalid" \
	--skip-email

"${COMPOSE[@]}" run --rm --no-TTY cli \
	--allow-root \
	plugin install wordfence \
	--activate

"${COMPOSE[@]}" run --rm --no-TTY cli \
	--allow-root \
	plugin install /artifacts/greyrock-plugin.zip \
	--force \
	--activate

"${COMPOSE[@]}" run --rm --no-TTY cli \
	--allow-root \
	plugin is-active wordfence

"${COMPOSE[@]}" run --rm --no-TTY cli \
	--allow-root \
	plugin is-active \
	grey-rock-block-synchroniser-for-wordfence-and-cloudflare

echo
echo "===== RUN LIVE CLOUDFLARE PLUGIN TEST ====="

"${COMPOSE[@]}" run --rm --no-TTY cli \
	--allow-root \
	eval-file /tests/cloudflare-live-test.php \
	2>&1 | tee "$REPORT_DIR/test-output.txt"

if ! grep -Fqx \
	"CLOUDFLARE PLUGIN LIVE TEST RESULT: PASS" \
	"$REPORT_DIR/test-output.txt"
then
	echo "ERROR: The live test did not report a complete pass." >&2
	exit 1
fi

cat > "$REPORT_DIR/summary.txt" <<EOF
RESULT=PASS
LIST_NAME=$EXPECTED_LIST_NAME
LIST_ID=$EXPECTED_LIST_ID
TEST_IP=$EXPECTED_TEST_IP
PLUGIN_ADD_VERIFIED=yes
PLUGIN_REMOVE_VERIFIED=yes
EOF

echo
echo "LIVE CLOUDFLARE INTEGRATION TEST: PASS"
echo "8.8.8.8 was added, verified, removed and verified absent."
echo "The disposable WordPress environment will now be removed."
