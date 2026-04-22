#!/usr/bin/env bash
# local-up.sh — Build m3u-editor from source and start the full external-services stack.
# Run from the repo root.

set -euo pipefail

COMPOSE_FILE="local/docker-compose.yml"
ENV_FILE="local/.env"

# Bootstrap .env from example if missing
if [ ! -f "${ENV_FILE}" ]; then
    echo "-- No local/.env found, creating from example..."
    cp "local/.env.example" "${ENV_FILE}"
    echo "-- Edit local/.env to customise credentials, then re-run this script."
fi

# Ensure m3u-proxy:local exists (pull + retag from Docker Hub if absent)
if ! docker image inspect sparkison/m3u-proxy:local &>/dev/null; then
    echo "-- sparkison/m3u-proxy:local not found, pulling latest and retagging..."
    docker pull sparkison/m3u-proxy:latest
    docker tag  sparkison/m3u-proxy:latest sparkison/m3u-proxy:local
    echo "-- Retagged sparkison/m3u-proxy:latest -> sparkison/m3u-proxy:local"
fi

echo "-- Building m3u-editor from source and starting stack..."
docker compose --env-file "${ENV_FILE}" -f "${COMPOSE_FILE}" build --no-cache
docker compose --env-file "${ENV_FILE}" -f "${COMPOSE_FILE}" up --remove-orphans
