#!/usr/bin/env bash

set -Eeuo pipefail

CACHE_DIR="${HOME}/.cache/grey-rock-tests"
SESSION_ENV="$CACHE_DIR/session.env"
MODE="${1:-}"

mkdir -p "$CACHE_DIR"

if [[ -f "$SESSION_ENV" && "$MODE" != "--refresh" ]]; then
    printf '%s\n' "$SESSION_ENV"
    exit 0
fi

python3 - "$SESSION_ENV" <<'PYTHON'
import json
import pathlib
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

php_payload = get_json("https://www.php.net/releases/?json&max=100")
php_candidates = []

for release in php_payload.values():
    if not isinstance(release, dict):
        continue
    version = str(release.get("version", ""))
    parts = version.split(".")
    if len(parts) == 3 and all(part.isdigit() for part in parts):
        php_candidates.append((tuple(map(int, parts)), version))

if not php_candidates:
    raise SystemExit("Could not resolve current stable PHP release.")

php_version = max(php_candidates)[1]
php_branch = ".".join(php_version.split(".")[:2])

wordpress_payload = get_json(
    "https://api.wordpress.org/core/version-check/1.7/"
)
offers = wordpress_payload.get("offers", [])
wordpress_version = ""

for offer in offers:
    candidate = str(offer.get("current", ""))
    response = str(offer.get("response", ""))
    if candidate and response in {"upgrade", "latest"}:
        wordpress_version = candidate
        break

if not wordpress_version and offers:
    wordpress_version = str(offers[0].get("current", ""))

if not wordpress_version:
    raise SystemExit("Could not resolve current stable WordPress release.")

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

temporary = destination.with_suffix(".tmp")
temporary.write_text("\n".join(lines) + "\n", encoding="utf-8")
temporary.chmod(0o600)
temporary.replace(destination)
PYTHON

printf '%s\n' "$SESSION_ENV"
