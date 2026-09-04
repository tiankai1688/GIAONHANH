#!/usr/bin/env bash
#
# GIAONHANH deployment script (Docker stack).
# Run on the production host after `git pull`. Requires: docker + docker compose v2.
#
set -euo pipefail
cd "$(dirname "$0")/.."

echo "==> GIAONHANH deploy"

if [ ! -f backend/.env ]; then
  echo "ERROR: backend/.env not found. Copy backend/.env.example to backend/.env and fill in real values."
  echo "       Required: DB_*, APP_KEY (run 'php artisan key:generate'), MoMo/ZaloPay or AGGREGATOR creds, SANCTUM_STATEFUL_DOMAINS, CORS_ALLOWED_ORIGINS."
  exit 1
fi

echo "==> Building & starting containers"
docker compose build app
docker compose up -d

echo "==> Generating app key if missing"
docker compose exec -T app php artisan key:generate --force || true

echo "==> Running migrations"
docker compose exec -T app php artisan migrate --force

echo "==> Seeding (safe to re-run; admin password comes from ADMIN_SEED_PASSWORD)"
docker compose exec -T app php artisan db:seed --force

echo "==> Clearing & caching config/routes"
docker compose exec -T app php artisan config:cache
docker compose exec -T app php artisan route:cache
docker compose exec -T app php artisan view:cache || true

echo "==> Health check"
if curl -fsS "http://localhost:8080/api/health"; then
  echo "  -> healthy"
else
  echo "  -> WARN: /api/health not reachable; check container logs: docker compose logs app"
fi

echo ""
echo "==> Daily merchant settlement cron (add to host crontab):"
echo "    0 2 * * * $(pwd)/scripts/settlement-cron.sh >> /var/log/giaonhanh-settlement.log 2>&1"
echo "==> Done."
