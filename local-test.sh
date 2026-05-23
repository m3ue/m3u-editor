#!/usr/bin/env bash
# local-test.sh — Run the Pest test suite inside a fresh container built from source.
# Uses the existing docker-compose.dev.yml test profile (SQLite in-memory, no external deps).
# Run from the repo root.
#
# Usage:
#   ./local-test.sh                    — run all tests
#   ./local-test.sh --filter=MyTest    — run a specific test or filter
#   ./local-test.sh tests/Feature/Foo  — run a specific file

set -euo pipefail

COMPOSE_FILE="docker-compose.dev.yml"

# Pass any extra args straight to php artisan test (e.g. --filter, filename)
EXTRA_ARGS=("$@")

echo "-- Building test image (with dev dependencies)..."
docker compose -f "${COMPOSE_FILE}" build --no-cache m3u-editor-dev-test

echo "-- Running test suite..."
if [ "${#EXTRA_ARGS[@]}" -gt 0 ]; then
    docker compose -f "${COMPOSE_FILE}" run --rm --profile test \
        m3u-editor-dev-test "${EXTRA_ARGS[@]}"
else
    docker compose -f "${COMPOSE_FILE}" run --rm --profile test \
        m3u-editor-dev-test
fi
