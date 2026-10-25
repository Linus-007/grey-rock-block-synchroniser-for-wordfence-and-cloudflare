#!/usr/bin/env bash

set -u
set -o pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="$REPO_ROOT/tests/docker/compose.yml"
PROJECT_NAME="greyrock-plugin-test"
RUNTIME_ENV="${1:-}"

source "$REPO_ROOT/scripts/integration-harness.sh"

status=0

if [[ -n "$RUNTIME_ENV" && -f "$RUNTIME_ENV" ]]; then
    COMPOSE_COMMAND=(sudo -n docker compose \
        --env-file "$RUNTIME_ENV" \
        --project-name "$PROJECT_NAME" \
        --file "$COMPOSE_FILE")
    compose down --volumes --remove-orphans || status=1
else
    echo "No runtime environment file was available for Compose cleanup."
fi

sudo -n timeout --signal=TERM --kill-after=5s 60s systemctl stop \
    docker.socket \
    docker.service \
    containerd.service || status=1

exit "$status"
