#!/usr/bin/env bash

set +x +v
set -Eeuo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="$REPO_ROOT/tests/docker/compose.yml"
PLUGIN_ZIP="$REPO_ROOT/dist/grey-rock-block-synchroniser-for-wordfence-and-cloudflare.zip"
REPORT_DIR="${TASK15_INTEGRATION_REPORT_DIR:-$REPO_ROOT/reports/integration-ci}"
PROJECT_NAME="greyrock-plugin-ci"
TEST_URL="http://127.0.0.1:18080"
TEST_PORT="18080"
PLUGIN_SLUG="grey-rock-block-synchroniser-for-wordfence-and-cloudflare"
CACHE_DIR="${GREY_ROCK_TEST_CACHE_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/.tools/test-session}"
SESSION_ENV="$CACHE_DIR/session.env"
SESSION_RESOLVER="$REPO_ROOT/scripts/resolve-test-session.sh"

mkdir -p "$REPORT_DIR" "$REPO_ROOT/.tools" "$CACHE_DIR"
rm -f "$REPORT_DIR"/*

test -f "$COMPOSE_FILE"
test -f "$PLUGIN_ZIP"
test -x "$SESSION_RESOLVER"

"$SESSION_RESOLVER" --refresh >/dev/null

# shellcheck disable=SC1090
source "$SESSION_ENV"

for command_name in docker curl openssl python3 timeout; do
	if ! command -v "$command_name" >/dev/null 2>&1; then
		echo "ERROR: Required command is unavailable: $command_name" >&2
		exit 1
	fi
done

DOCKER=(docker)

if ! timeout --signal=TERM --kill-after=5s 60s docker info >/dev/null 2>&1; then
    if command -v sudo >/dev/null 2>&1; then
        echo "Docker requires sudo on this host."

        if command -v systemctl >/dev/null 2>&1; then
            sudo -n timeout --signal=TERM --kill-after=5s 60s systemctl start                 containerd.service                 docker.socket                 docker.service
        fi

        if sudo -n timeout --signal=TERM --kill-after=5s 60s docker info >/dev/null; then
            DOCKER=(sudo -n docker)
        else
            echo "ERROR: Docker is unavailable through sudo." >&2
            exit 1
        fi
    else
        echo "ERROR: Docker is unavailable to the current user." >&2
        exit 1
    fi
fi

python3 - "$TEST_PORT" <<'PYTHON'
import socket
import sys

port = int(sys.argv[1])
sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)

try:
    sock.bind(("127.0.0.1", port))
except OSError as error:
    print(
        f"ERROR: TCP port {port} is unavailable: {error}",
        file=sys.stderr,
    )
    raise SystemExit(1)
finally:
    sock.close()

print(f"PASS: TCP port {port} is available.")
PYTHON

umask 077

RUNTIME_ENV="$(
	mktemp "$REPO_ROOT/.tools/greyrock-integration-ci.XXXXXX.env"
)"

# shellcheck source=scripts/integration-harness.sh
source "$REPO_ROOT/scripts/integration-harness.sh"

ADMIN_PASSWORD_FILE=""

COMPOSE_COMMAND=(
	"${DOCKER[@]}"
	compose
	--progress quiet
	--env-file "$RUNTIME_ENV"
	--project-name "$PROJECT_NAME"
	--file "$COMPOSE_FILE"
)

cleanup() {
	result="$?"

	trap - EXIT INT TERM
	set +e

	compose ps \
		> "$REPORT_DIR/compose-ps-final.txt" 2>&1 || { if [[ "$result" -eq 0 ]]; then result=1; fi; }

	compose logs --no-color \
		> "$REPORT_DIR/compose-final.log" 2>&1 || { if [[ "$result" -eq 0 ]]; then result=1; fi; }

	{
		echo "===== CLEANUP ====="
		date --iso-8601=seconds
		compose down --volumes --remove-orphans
		cleanup_result=$?
		echo "Cleanup exit code: $cleanup_result"
	} > "$REPORT_DIR/cleanup.log" 2>&1

	rm -f "$RUNTIME_ENV" "$ADMIN_PASSWORD_FILE" || cleanup_result=1

	if [[ "$result" -ne 0 ]]; then
		echo
		echo "===== FINAL COMPOSE LOG OUTPUT =====" >&2
		tail -n 200 "$REPORT_DIR/compose-final.log" >&2 || true
	fi

	if [[ "$cleanup_result" -ne 0 && "$result" -eq 0 ]]; then result=1; fi
	if [[ "$result" -eq 0 ]]; then
        sed 's/^RESULT=ASSERTIONS_COMPLETE_CLEANUP_PENDING$/RESULT=PASS/' "$REPORT_DIR/summary.pending" > "$REPORT_DIR/summary.txt" || result=1
        rm -f "$REPORT_DIR/summary.pending" || result=1
    fi
    if [[ "$result" -ne 0 ]]; then rm -f "$REPORT_DIR/summary.txt" "$REPORT_DIR/summary.pending"; fi
	if [[ "$result" -eq 0 ]]; then echo "INTEGRATION TEST RESULT: PASS"; else echo "INTEGRATION TEST RESULT: FAIL (see cleanup.log)" >&2; fi
	exit "$result"
}

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

TEST_DB_NAME="${TEST_DB_NAME:-wordpress}"
if [[ ! "$TEST_DB_NAME" =~ ^[a-zA-Z0-9_]+$ ]]; then
    echo "ERROR: Invalid disposable database name." >&2
    exit 2
fi
if [[ "${GREY_ROCK_TASK14_FENCING:-0}" == 1 && ! "$TEST_DB_NAME" =~ ^greyrock_task13_[a-zA-Z0-9_]+$ ]]; then
    echo "ERROR: Task 14 requires TEST_DB_NAME=greyrock_task13_<disposable-name>." >&2
    exit 2
fi

ADMIN_PASSWORD_FILE="$(mktemp "$REPO_ROOT/.tools/greyrock-admin.XXXXXX")"
openssl rand -hex 24 > "$ADMIN_PASSWORD_FILE"

cat > "$RUNTIME_ENV" <<EOF
ADMIN_PASSWORD_FILE=$ADMIN_PASSWORD_FILE
TEST_DB_NAME=$TEST_DB_NAME
TEST_DB_PASSWORD=$(openssl rand -hex 24)
TEST_DB_ROOT_PASSWORD=$(openssl rand -hex 24)
PLUGIN_ZIP=$PLUGIN_ZIP
INTEGRATION_TEST_FILE=$REPO_ROOT/tests/integration/admin-action-button-test.php
WORDFENCE_PROBE_FILE=$REPO_ROOT/tests/integration/wordfence-block-api-probe.php
OWNERSHIP_MIGRATION_TEST_FILE=$REPO_ROOT/tests/integration/ownership-migration-test.php
MANUAL_REMOVAL_OWNERSHIP_TEST_FILE=$REPO_ROOT/tests/integration/manual-removal-ownership-test.php
EOF

cat "$SESSION_ENV" >> "$RUNTIME_ENV"



echo "===== DOCKER ENVIRONMENT ====="
if [[ "${DOCKER[0]}" == sudo ]]; then
    sudo -n timeout --signal=TERM --kill-after=5s 60s docker version < /dev/null
else
    timeout --signal=TERM --kill-after=5s 60s docker version < /dev/null
fi
compose version

echo
echo "===== PULL TEST IMAGES ====="
compose pull db wordpress cli

echo
# Verify the CLI image before installing or executing the plugin.
compose run --rm --no-TTY --no-deps --entrypoint php cli \
    -r 'echo PHP_VERSION, PHP_EOL;' | tee "$REPORT_DIR/cli-php-version.txt"
python3 "$REPO_ROOT/scripts/php-runtime.py" --verify \
    "$(tr -d '\r\n' < "$REPORT_DIR/cli-php-version.txt")" "$EXPECTED_PHP_VERSION" --exact

echo "===== START WORDPRESS TEST STACK ====="
compose up --detach db wordpress

# Reject an outdated server image before WordPress or plugin installation.
compose exec --no-TTY wordpress \
    php -r 'echo PHP_VERSION, PHP_EOL;' | tee "$REPORT_DIR/php-version.txt"
python3 "$REPO_ROOT/scripts/php-runtime.py" --verify \
    "$(tr -d '\r\n' < "$REPORT_DIR/php-version.txt")" "$EXPECTED_PHP_VERSION" --exact

echo
echo "===== VERIFY PHP UPLOAD-PROGRESS MITIGATION ====="

compose run --rm --no-TTY --no-deps --entrypoint php cli \
    -r 'if (ini_get("session.upload_progress.enabled") !== "0") { fwrite(STDERR, "ERROR: CLI PHP upload progress enabled\\n"); exit(1); } echo "PASS: CLI PHP upload progress disabled\\n";'

compose exec --no-TTY wordpress \
    php -r 'if (ini_get("session.upload_progress.enabled") !== "0") { fwrite(STDERR, "ERROR: WordPress PHP upload progress enabled\\n"); exit(1); } echo "PASS: WordPress PHP upload progress disabled\\n";'

echo "===== WAIT FOR WORDPRESS ====="

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
echo "===== DOWNLOAD RESOLVED STABLE WORDPRESS ====="
compose run --rm --no-TTY cli \
    core download --version="$EXPECTED_WORDPRESS_VERSION" --force --skip-content
# Discard any Apache opcode cache populated while waiting for the base image.
compose restart wordpress

echo "===== INSTALL WORDPRESS ====="

# Include preserves strict_types on PHP 8.2–8.4; eval-file otherwise prepends a closing tag.
# Only this disposable CLI process runs as container root to read the 0600
# bind-mounted credential. PHP reads it inside the container; the secret
# never traverses host command arguments or stdin.
compose run --rm --no-TTY --user 0:0 --entrypoint sh cli -c '
# Suppress every byte inside the container, before sudo I/O logging.
# Exceptions or installer hooks may include arguments in their diagnostics.
# Capture potentially sensitive installer output in a private temporary file.
# Print only fixed, allowlisted stage identifiers; never print raw diagnostics.
diagnostic_file="$(mktemp)" || exit 1
chmod 600 "$diagnostic_file"

if wp --allow-root --skip-wordpress eval-file --use-include /artifacts/install-test-wordpress.php "$1" >"$diagnostic_file" 2>&1; then
    rm -f "$diagnostic_file"
    echo "Disposable WordPress installed."
else
    grep -oE "GREYROCK_INSTALL_(FATAL|EXCEPTION)_STAGE=(input|bootstrap|upgrade|credentials|database-check|wp-install|options|verify)" "$diagnostic_file" >&2 || true
    rm -f "$diagnostic_file"
    echo "ERROR: Disposable WordPress installation failed (details withheld)." >&2
    exit 1
fi
' sh "$TEST_URL"

echo
echo "===== INSTALL WORDFENCE ====="

compose run --rm --no-TTY cli \
	plugin install wordfence \
	--activate

echo
echo "===== INSTALL GREY ROCK RELEASE ZIP ====="

compose run --rm --no-TTY cli \
	plugin install /artifacts/greyrock-plugin.zip \
	--force \
	--activate

echo
echo "===== VERIFY WORDPRESS AND PLUGINS ====="

compose run --rm --no-TTY cli \
	core version |
	tee "$REPORT_DIR/wordpress-version.txt"

compose exec --no-TTY wordpress \
	php -r "echo PHP_VERSION, PHP_EOL;" |
	tee "$REPORT_DIR/php-version.txt"

compose run --rm --no-TTY cli \
	plugin list --format=table |
	tee "$REPORT_DIR/plugin-list.txt"

compose run --rm --no-TTY cli \
	plugin is-active wordfence

compose run --rm --no-TTY cli \
	plugin get wordfence --field=version |
	tee "$REPORT_DIR/wordfence-version.txt"

compose run --rm --no-TTY cli \
	cli version |
	sed -E 's/^WP-CLI[[:space:]]+//' |
	tee "$REPORT_DIR/wpcli-version.txt"

compose exec --no-TTY db \
	mariadb --version |
	tee "$REPORT_DIR/mariadb-version.txt"

compose run --rm --no-TTY cli \
	plugin is-active "$PLUGIN_SLUG"

echo "===== WORDPRESS COMPATIBILITY INTEGRATION ====="
# Include strict-typed fixtures directly so PHP 8.2–8.4 can compile them.
compose run --rm --no-TTY cli \
    eval-file --use-include /artifacts/wordpress-compatibility-test.php

echo "===== ADMIN ACTION BUTTON INTEGRATION ====="
compose run --rm --no-TTY cli \
    eval-file /artifacts/admin-action-button-test.php

echo "===== OWNERSHIP MIGRATION INTEGRATION ====="
compose run --rm --no-TTY cli \
    eval-file --use-include /artifacts/ownership-migration-test.php

echo "===== MANUAL REMOVAL OWNERSHIP INTEGRATION ====="
compose run --rm --no-TTY cli \
    eval-file --use-include /artifacts/manual-removal-ownership-test.php

echo "===== WORDFENCE BLOCK API PROBE ====="
compose run --rm --no-TTY cli \
    eval-file /artifacts/wordfence-block-api-probe.php

if [[ "${GREY_ROCK_TASK14_FENCING:-0}" == 1 ]]; then
    echo "===== ATOMIC MARIADB FENCING (DISPOSABLE MULTISITE) ====="
    compose run --rm --no-TTY cli core multisite-convert --title="Task 14 disposable network"
    compose run --rm --no-TTY -e GREY_ROCK_TASK13_DISPOSABLE=YES --entrypoint php cli \
        /artifacts/task14-mariadb-suite.php /var/www/html         "${GREY_ROCK_TASK14_SCENARIO:-all}" forced         ${GREY_ROCK_TASK14_CASE:+"$GREY_ROCK_TASK14_CASE"} |
        tee "$REPORT_DIR/task14-mariadb.txt"
    python3 "$REPO_ROOT/scripts/task15-concurrency-result.py" "$REPORT_DIR/task14-mariadb.txt"

fi

compose run --rm --no-TTY cli \
	plugin get "$PLUGIN_SLUG" --field=version |
	tee "$REPORT_DIR/greyrock-version.txt"

echo
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

echo
echo "===== APPLICATION LOG CHECK ====="

compose logs --no-color \
	> "$REPORT_DIR/compose.log" 2>&1

compose exec --no-TTY wordpress sh -c '
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

wordpress_version="$(
	tr -d '\r\n' < "$REPORT_DIR/wordpress-version.txt"
)"

php_version="$(
	tr -d '\r\n' < "$REPORT_DIR/php-version.txt"
)"

wordfence_version="$(
	tr -d '\r\n' < "$REPORT_DIR/wordfence-version.txt"
)"

wpcli_version="$(
	tr -d '\r\n' < "$REPORT_DIR/wpcli-version.txt"
)"

mariadb_version="$(
	tr -d '\r\n' < "$REPORT_DIR/mariadb-version.txt"
)"

plugin_version="$(
	tr -d '\r\n' < "$REPORT_DIR/greyrock-version.txt"
)"

if [[ "$wordpress_version" != "$EXPECTED_WORDPRESS_VERSION" ]]; then
	echo "ERROR: WordPress version mismatch." >&2
	printf "Expected: %s\nActual:   %s\n" \
		"$EXPECTED_WORDPRESS_VERSION" \
		"$wordpress_version" >&2
	exit 1
fi

if [[ "$php_version" != "$EXPECTED_PHP_VERSION" ]]; then
	echo "ERROR: PHP version mismatch." >&2
	printf "Expected: %s\nActual:   %s\n" \
		"$EXPECTED_PHP_VERSION" \
		"$php_version" >&2
	exit 1
fi

if [[ "$wordfence_version" != "$EXPECTED_WORDFENCE_VERSION" ]]; then
	echo "ERROR: Wordfence version mismatch." >&2
	printf "Expected: %s\nActual:   %s\n" \
		"$EXPECTED_WORDFENCE_VERSION" \
		"$wordfence_version" >&2
	exit 1
fi

if [[ "$wpcli_version" != "$EXPECTED_WPCLI_VERSION" ]]; then
	echo "ERROR: WP-CLI version mismatch." >&2
	printf "Expected: %s\nActual:   %s\n" \
		"$EXPECTED_WPCLI_VERSION" \
		"$wpcli_version" >&2
	exit 1
fi

cat > "$REPORT_DIR/summary.pending" <<EOF
RESULT=ASSERTIONS_COMPLETE_CLEANUP_PENDING
WORDPRESS_VERSION=$wordpress_version
PHP_VERSION=$php_version
WORDFENCE_VERSION=$wordfence_version
WPCLI_VERSION=$wpcli_version
MARIADB_VERSION=$mariadb_version
GREYROCK_PLUGIN_VERSION=$plugin_version
WORDFENCE_ACTIVE=yes
GREYROCK_PLUGIN_ACTIVE=yes
FRONT_PAGE_HTTP_STATUS=$front_status
LOGIN_PAGE_HTTP_STATUS=$login_status
TEST_URL=$TEST_URL
EOF

echo
echo "Integration assertions completed; cleanup must succeed before PASS."
echo "Containers and volumes will now be removed."
