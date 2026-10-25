#!/usr/bin/env bash
set -Eeuo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"
mkdir -p reports/semgrep
umask 077
scanner="$(python3 scripts/security_tools.py semgrep)"
cp "$(dirname "$(dirname "$scanner")")/../manifest.json" reports/semgrep/manifest.json
# Only CE, repository-versioned rules, no login, metrics or registry auto rules.
python3 scripts/semgrep_scan.py "$scanner"
