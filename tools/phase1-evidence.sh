#!/usr/bin/env bash
#
# GIAONHANH Phase-1 evidence collector.
# Prerequisite: `docker compose up -d` already running and healthy
# (see docs/第一阶段操作手册.md Step 2). This script only RUNS the
# end-to-end smoke + a short load test and saves the logs as evidence.
#
# Usage (Git Bash / WSL / Linux):
#   bash tools/phase1-evidence.sh
#
set -u

BASE="${API_BASE:-http://localhost:8080}"
ADMIN_PHONE="${ADMIN_PHONE:-0900000001}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-SmokeAdmin#2026}"
EVIDENCE_DIR="docs/evidence"
STAMP="$(date +%Y%m%d-%H%M%S)"

echo "==> GIAONHANH Phase-1 evidence collector"
echo "    API_BASE      = $BASE"
echo "    EVIDENCE_DIR  = $EVIDENCE_DIR"

mkdir -p "$EVIDENCE_DIR"

# 0) Health gate
echo "==> [0/3] health check"
if ! curl -sf "$BASE/api/health" >/dev/null; then
  echo "    ✗ server not healthy at $BASE"
  echo "    → run: docker compose up -d --build"
  echo "    → then: docker compose exec app php artisan migrate --seed"
  exit 1
fi
echo "    ✓ server healthy"

# 1) End-to-end smoke
echo "==> [1/3] backend smoke test"
API_BASE="$BASE" ADMIN_PHONE="$ADMIN_PHONE" ADMIN_PASSWORD="$ADMIN_PASSWORD" \
  node tools/backend-smoke.mjs 2>&1 | tee "$EVIDENCE_DIR/smoke-local-$STAMP.log"
SMOKE_RC=${PIPESTATUS[0]}
if [ "$SMOKE_RC" -ne 0 ]; then
  echo "    ✗ smoke FAILED (rc=$SMOKE_RC) — see $EVIDENCE_DIR/smoke-local-$STAMP.log"
  exit 1
fi
echo "    ✓ smoke PASS"

# 2) Short load test (30s / 20 VUs) — scale-readiness evidence
echo "==> [2/3] load test (30s, 20 VUs)"
API_BASE="$BASE" DURATION=30 VUS=20 \
  node tools/load-test.mjs 2>&1 | tee "$EVIDENCE_DIR/loadtest-local-$STAMP.log"
echo "    ✓ load test done"

echo
echo "==> Evidence saved:"
echo "    $EVIDENCE_DIR/smoke-local-$STAMP.log"
echo "    $EVIDENCE_DIR/loadtest-local-$STAMP.log"
echo "==> Next: record a demo video (home→browse→cart→merged order→COD→detail→admin settlement) and save as $EVIDENCE_DIR/demo.mp4"
