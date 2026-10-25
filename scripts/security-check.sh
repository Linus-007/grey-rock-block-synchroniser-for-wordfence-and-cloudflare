#!/usr/bin/env bash

set -u
set -o pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPORT_DIR="$REPO_ROOT/reports/security"
PLUGIN_MAIN="$REPO_ROOT/src/grey-rock-block-synchroniser-for-wordfence-and-cloudflare.php"
cd "$REPO_ROOT" || exit 1

mkdir -p "$REPORT_DIR"
rm -f "$REPORT_DIR"/*

if [[ "${RUN_SEMGREP:-1}" != "0" && "${RUN_SEMGREP:-1}" != "1" ]]; then
    echo "ERROR: RUN_SEMGREP must be 0 or 1." >&2
    exit 1
fi

status=0

echo "===== SECURITY TEST CONTEXT ====="
printf "Repository: %s\n" "$REPO_ROOT"
printf "Commit:     %s\n" "$(git rev-parse HEAD)"
printf "Branch:     %s\n" "$(git branch --show-current)"
printf "PHP:        %s\n" "$(php -r 'echo PHP_VERSION;')"
printf "Composer:   %s\n" "$(composer --version --no-ansi 2>/dev/null)"
echo

echo "===== PHP SYNTAX ====="
: > "$REPORT_DIR/php-lint.txt"

while IFS= read -r -d '' php_file; do
	if ! php -l "$php_file" >> "$REPORT_DIR/php-lint.txt" 2>&1; then
		status=1
	fi
done < <(find src -type f -name '*.php' -print0 | sort -z)

cat "$REPORT_DIR/php-lint.txt"
echo

echo "===== COMPOSER SECURITY AUDIT ====="
if python3 scripts/composer_audit.py > "$REPORT_DIR/composer-audit.txt" 2>&1; then
    cat "$REPORT_DIR/composer-audit.txt"
else
    cat "$REPORT_DIR/composer-audit.txt"
    status=1
fi
echo

echo "===== WORDPRESS SECURITY STANDARDS ====="
if [[ ! -x "$REPO_ROOT/vendor/bin/phpcs" ]]; then
	echo "ERROR: vendor/bin/phpcs is missing."
	status=1
else
	if "$REPO_ROOT/vendor/bin/phpcs" \
		--standard="$REPO_ROOT/phpcs.xml.dist" \
		--report=full \
		--report-width=120 \
		src > "$REPORT_DIR/phpcs-security.txt" 2>&1; then
		cat "$REPORT_DIR/phpcs-security.txt"
	else
		cat "$REPORT_DIR/phpcs-security.txt"
		status=1
	fi
fi
echo

echo "===== COMPOSER VALIDATION AND PLATFORM ====="
if ! composer validate --strict --no-interaction --no-check-publish; then
    status=1
fi
if ! composer check-platform-reqs --no-interaction; then
    status=1
fi

if [[ "${RUN_SEMGREP:-1}" == "1" ]]; then
    echo "===== SEMGREP CE ====="
    if ! ./scripts/semgrep-check.sh; then
        status=1
    fi
else
    echo "SEMGREP=SKIPPED (separate mandatory workflow job; this script is a partial check)"
fi

echo "===== RELEASE PACKAGE VALIDATION ====="

version="$(
	sed -nE \
		's/^[[:space:]]*\*[[:space:]]Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*$/\1/p' \
		"$PLUGIN_MAIN" |
	head -n 1
)"

if [[ -z "$version" ]]; then
	echo "ERROR: Could not determine the plugin version."
	status=1
elif make release VERSION="$version" \
	> "$REPORT_DIR/release-build.txt" 2>&1; then
	cat "$REPORT_DIR/release-build.txt"
else
	cat "$REPORT_DIR/release-build.txt"
	status=1
fi

echo
echo "===== REPORT FILES ====="
find "$REPORT_DIR" -maxdepth 1 -type f -printf '%f  %s bytes\n' | sort

echo
if [[ "$status" -eq 0 ]]; then
	echo "PHP SECURITY CHECK RESULT: PASS"
else
	echo "PHP SECURITY CHECK RESULT: FAIL"
fi

exit "$status"
