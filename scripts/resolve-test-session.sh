#!/usr/bin/env bash

set -Eeuo pipefail

CACHE_DIR="${GREY_ROCK_TEST_CACHE_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/.tools/test-session}"
SESSION_ENV="$CACHE_DIR/session.env"

mkdir -p "$CACHE_DIR"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
python3 - "$SESSION_ENV" "$REPO_ROOT/scripts/php-runtime.py" "$REPO_ROOT/scripts/wordpress_policy.py" <<'PYTHON'
import json
import importlib.util
import os
import pathlib
import re
import tempfile
import sys
import urllib.parse
import urllib.request

destination = pathlib.Path(sys.argv[1])

def get_json(url):
    request = urllib.request.Request(
        url,
        headers={
            "Accept": "application/json",
            "User-Agent": "Grey-Rock-Test-Resolver",
        },
    )
    with urllib.request.urlopen(request, timeout=30) as response:
        return json.load(response)

spec = importlib.util.spec_from_file_location("php_runtime", sys.argv[2])
php_runtime = importlib.util.module_from_spec(spec)
spec.loader.exec_module(php_runtime)
php_version = php_runtime.resolve()
# Detect a release changing during a workflow instead of silently testing two targets.
if os.environ.get("EXPECTED_PHP_VERSION"):
    php_runtime.verify_runtime(php_version, os.environ["EXPECTED_PHP_VERSION"], exact=True)
php_branch = ".".join(php_version.split(".")[:2])

wordpress_payload = get_json(
    "https://api.wordpress.org/core/version-check/1.7/"
)
wp_spec = importlib.util.spec_from_file_location("wordpress_policy", sys.argv[3])
wordpress_policy = importlib.util.module_from_spec(wp_spec)
wp_spec.loader.exec_module(wordpress_policy)
wordpress_version = wordpress_policy.latest_stable(wordpress_payload)

plugin_query = urllib.parse.urlencode(
    {
        "action": "plugin_information",
        "request[slug]": "wordfence",
    }
)
wordfence_payload = get_json(
    "https://api.wordpress.org/plugins/info/1.2/?" + plugin_query
)
wordfence_version = str(wordfence_payload.get("version", ""))

if not wordfence_version:
    raise SystemExit("Could not resolve current stable Wordfence release.")

wpcli_payload = get_json(
    "https://api.github.com/repos/wp-cli/wp-cli/releases/latest"
)
wpcli_version = str(wpcli_payload.get("tag_name", "")).lstrip("v")

if not wpcli_version:
    raise SystemExit("Could not resolve current stable WP-CLI release.")

# Values are later sourced by Bash, so accept only stable numeric versions.
for name, version in [
    ("WordPress", wordpress_version),
    ("Wordfence", wordfence_version),
    ("WP-CLI", wpcli_version),
]:
    if not re.fullmatch(r"[0-9]+\.[0-9]+(?:\.[0-9]+)?", version, flags=re.ASCII):
        raise SystemExit(f"Invalid stable {name} version in release response.")

lines = [
    f"EXPECTED_WORDPRESS_VERSION={wordpress_version}",
    f"EXPECTED_PHP_VERSION={php_version}",
    f"EXPECTED_PHP_BRANCH={php_branch}",
    f"EXPECTED_WORDFENCE_VERSION={wordfence_version}",
    f"EXPECTED_WPCLI_VERSION={wpcli_version}",
    f"WORDPRESS_IMAGE=wordpress:php{php_branch}-apache",
    f"WPCLI_IMAGE=wordpress:cli-php{php_branch}",
    "MARIADB_IMAGE=mariadb:latest",
]

# A unique mode-0600 temporary file avoids concurrent-writer collisions and
# following an existing session.tmp symlink. Replace only a complete session.
with tempfile.NamedTemporaryFile(
    mode="w", encoding="utf-8", dir=destination.parent,
    prefix="session-", suffix=".tmp", delete=False,
) as handle:
    temporary = pathlib.Path(handle.name)
    try:
        handle.write("\n".join(lines) + "\n")
        handle.flush()
        temporary.replace(destination)
    finally:
        temporary.unlink(missing_ok=True)

PYTHON

printf '%s\n' "$SESSION_ENV"
