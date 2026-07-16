#!/usr/bin/env bash

set -u
set -o pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="$REPO_ROOT/tests/docker/compose.yml"
PROJECT_NAME="greyrock-plugin-test"
RUNTIME_ENV="${1:-}"

status=0

if [[ -n "$RUNTIME_ENV" && -f "$RUNTIME_ENV" ]]; then
    sudo docker compose \
        --env-file "$RUNTIME_ENV" \
        --project-name "$PROJECT_NAME" \
        --file "$COMPOSE_FILE" \
        down --volumes --remove-orphans || status=1
else
    echo "No runtime environment file was available for Compose cleanup."
fi

sudo systemctl stop \
    docker.socket \
    docker.service \
    containerd.service || status=1

exit "$status"
