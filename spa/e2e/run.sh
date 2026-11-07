#!/usr/bin/env bash
# Builds the image, starts it on a fresh volume and runs the Playwright admin flow against it. The
# container and the volume are always removed. Usage: bash spa/e2e/run.sh
set -euo pipefail

cd "$(dirname "$0")/../.."

IMAGE="${E2E_IMAGE:-intrawatch:smoke-spa}"
PORT="${E2E_PORT:-33300}"
SUFFIX="$$"
NAME="intrawatch-e2e-${SUFFIX}"
VOLUME="intrawatch-e2e-data-${SUFFIX}"
# localhost, not 127.0.0.1: the SPA's origin must match APP_URL for Sanctum's stateful session.
BASE="http://localhost:${PORT}"

cleanup() {
  local code=$?
  if [[ $code -ne 0 ]]; then
    echo "--- container logs (last 80 lines) ---" >&2
    timeout 60 docker logs --tail 80 "$NAME" >&2 2>&1 || true
  fi
  timeout 60 docker rm -f "$NAME" >/dev/null 2>&1 || true
  timeout 60 docker volume rm -f "$VOLUME" >/dev/null 2>&1 || true
  [[ $code -eq 0 ]] && echo "E2E OK" || echo "E2E FAILED" >&2
}
trap cleanup EXIT

fail() { echo "FAIL: $*" >&2; exit 1; }

if [[ "${E2E_SKIP_BUILD:-0}" != "1" ]]; then
  echo "== build $IMAGE"
  timeout 1500 docker build -f docker/Dockerfile -t "$IMAGE" . || fail "image build failed or exceeded 1500s"
fi

echo "== start $NAME on $BASE"
timeout 120 docker run -d --name "$NAME" -e "APP_URL=${BASE}" \
  -p "127.0.0.1:${PORT}:3000" -v "${VOLUME}:/data" "$IMAGE" >/dev/null || fail "container start failed"

# Polls run against a wall-clock deadline, so slow requests cannot stretch the wait.
deadline=$((SECONDS + 90))
until [[ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "http://127.0.0.1:${PORT}/up" || true)" == "200" ]]; do
  ((SECONDS < deadline)) || fail "/up did not return 200 within 90s"
  sleep 1
done
echo "ok: /up returns 200"

echo "== playwright"
cd spa
E2E_BASE_URL="$BASE" timeout 720 npx playwright test --config playwright.config.ts "$@"
