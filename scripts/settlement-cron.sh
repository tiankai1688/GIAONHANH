#!/usr/bin/env bash
#
# Daily T+1 merchant settlement runner. Invoked by host cron (see deploy.sh output).
# Runs the `settlement:daily` artisan command inside the app container.
#
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose exec -T app php artisan settlement:daily
