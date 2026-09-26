#!/usr/bin/env bash

set -u
set -o pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPORT_DIR="$REPO_ROOT/reports/security"
PLUGIN_MAIN="$REPO_ROOT/src/grey-rock-block-synchroniser-for-wordfence-and-cloudflare.php"
SEMGREP_BIN="$REPO_ROOT/.tools/semgrep/bin/semgrep"
SEMGREP_PYTHON="$REPO_ROOT/.tools/semgrep/bin/python"

cd "$REPO_ROOT" || exit 1

mkdir -p "$REPORT_DIR"
rm -f "$REPORT_DIR"/*

echo "===== SEMGREP UPDATE ====="

if [[ ! -x "$SEMGREP_PYTHON" ]]; then
        echo "ERROR: Semgrep Python environment is missing: $SEMGREP_PYTHON" >&2
        exit 1
fi

if ! "$SEMGREP_PYTHON" -m pip install \
        --disable-pip-version-check \
        --upgrade \
        semgrep \
        > "$REPORT_DIR/semgrep-upgrade.txt" 2>&1; then
        cat "$REPORT_DIR/semgrep-upgrade.txt"
        echo "ERROR: Semgrep could not be upgraded to the latest stable release." >&2
        exit 1
fi

cat "$REPORT_DIR/semgrep-upgrade.txt"

if [[ ! -x "$SEMGREP_BIN" ]]; then
        echo "ERROR: Semgrep is unavailable after upgrade: $SEMGREP_BIN" >&2
        exit 1
fi

printf "Semgrep after upgrade: %s\n" "$("$SEMGREP_BIN" --version)"
echo

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
if composer audit --no-interaction --no-ansi \
	> "$REPORT_DIR/composer-audit.txt" 2>&1; then
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

if [[ "${RUN_SEMGREP:-1}" == "1" ]]; then
	echo "===== SEMGREP SAST ====="

	if [[ ! -x "$SEMGREP_BIN" ]]; then
		echo "ERROR: Semgrep is not installed at $SEMGREP_BIN"
		status=1
	else
		printf "Semgrep:    %s\n" "$("$SEMGREP_BIN" --version)"

		if "$SEMGREP_BIN" scan \
			--config auto \
			--no-git-ignore \
			--error \
			--json \
			--output "$REPORT_DIR/semgrep.json" \
			src > "$REPORT_DIR/semgrep-console.txt" 2>&1; then
			cat "$REPORT_DIR/semgrep-console.txt"
		else
			cat "$REPORT_DIR/semgrep-console.txt"
			status=1
		fi

		if [[ -s "$REPORT_DIR/semgrep.json" ]]; then
			python3 - "$REPORT_DIR/semgrep.json" <<'PYTHON'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as handle:
    report = json.load(handle)

print(f"Semgrep findings: {len(report.get('results', []))}")
print(f"Semgrep errors:   {len(report.get('errors', []))}")
PYTHON
		fi
	fi

	echo
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
	echo "SECURITY TEST RESULT: PASS"
else
	echo "SECURITY TEST RESULT: FAIL"
fi

exit "$status"
