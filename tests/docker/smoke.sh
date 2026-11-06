#!/usr/bin/env bash
# Builds the image, boots it with a fresh volume and exercises the setup and
# session flow over HTTP. Usage: bash tests/docker/smoke.sh [--pgsql]
set -euo pipefail

cd "$(dirname "$0")/../.."

IMAGE="${SMOKE_IMAGE:-intrawatch:smoke}"
PORT="${SMOKE_PORT:-33000}"
SUFFIX="$$"
NAME="intrawatch-smoke-${SUFFIX}"
VOLUME="intrawatch-smoke-data-${SUFFIX}"
NETWORK="intrawatch-smoke-net-${SUFFIX}"
PG_NAME="intrawatch-smoke-pg-${SUFFIX}"
BASE="http://127.0.0.1:${PORT}"
JAR="$(mktemp)"
USE_PGSQL=0
[[ "${1:-}" == "--pgsql" ]] && USE_PGSQL=1

cleanup() {
  local code=$?
  if [[ $code -ne 0 ]]; then
    echo "--- container logs (last 60 lines) ---" >&2
    docker logs --tail 60 "$NAME" >&2 2>&1 || true
  fi
  docker rm -f "$NAME" >/dev/null 2>&1 || true
  docker rm -f "$PG_NAME" >/dev/null 2>&1 || true
  docker volume rm -f "$VOLUME" >/dev/null 2>&1 || true
  docker network rm "$NETWORK" >/dev/null 2>&1 || true
  [[ "${SMOKE_KEEP_IMAGE:-0}" == "1" ]] || docker rmi "$IMAGE" >/dev/null 2>&1 || true
  rm -f "$JAR"
  [[ $code -eq 0 ]] || echo "SMOKE FAILED" >&2
}
trap cleanup EXIT

fail() { echo "FAIL: $*" >&2; exit 1; }
ok() { echo "ok: $*"; }

STATEFUL=(-H "Origin: http://localhost:3000" -H "Referer: http://localhost:3000/" -H "Accept: application/json")

http_code() { curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$@" || true; }

wait_for_up() {
  local i
  for i in $(seq 1 60); do
    [[ "$(http_code "$BASE/up")" == "200" ]] && return 0
    sleep 1
  done
  fail "/up did not return 200 within 60s"
}

xsrf_token() {
  local raw
  raw="$(awk '$6 == "XSRF-TOKEN" { print $7 }' "$JAR" | tail -n 1)"
  [[ -n "$raw" ]] || fail "no XSRF-TOKEN cookie in jar"
  printf '%b' "${raw//%/\\x}"
}

echo "== build $IMAGE"
timeout 1500 docker build -f docker/Dockerfile -t "$IMAGE" .

ENV_ARGS=()
NET_ARGS=()
if [[ $USE_PGSQL -eq 1 ]]; then
  echo "== start postgres"
  docker network create "$NETWORK" >/dev/null
  docker run -d --name "$PG_NAME" --network "$NETWORK" \
    -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=intrawatch postgres:16 >/dev/null
  for i in $(seq 1 60); do
    docker exec "$PG_NAME" pg_isready -U postgres -d intrawatch >/dev/null 2>&1 && break
    [[ $i -eq 60 ]] && fail "postgres not ready within 60s"
    sleep 1
  done
  NET_ARGS=(--network "$NETWORK")
  ENV_ARGS=(-e DB_CONNECTION=pgsql -e DB_HOST="$PG_NAME" -e DB_PORT=5432
    -e DB_DATABASE=intrawatch -e DB_USERNAME=postgres -e DB_PASSWORD=secret)
fi

start_container() {
  docker run -d --name "$NAME" ${NET_ARGS[@]+"${NET_ARGS[@]}"} ${ENV_ARGS[@]+"${ENV_ARGS[@]}"} \
    -p "127.0.0.1:${PORT}:3000" -v "${VOLUME}:/data" "$IMAGE" >/dev/null
}

echo "== run"
start_container
wait_for_up
ok "/up returns 200"

[[ "$(curl -fsS --max-time 10 "$BASE/api/v1/setup")" == *'"required":true'* ]] || fail "setup is not reported as required"
ok "setup required"

curl -fsS --max-time 10 -c "$JAR" -b "$JAR" "${STATEFUL[@]}" "$BASE/sanctum/csrf-cookie" -o /dev/null
code="$(http_code -c "$JAR" -b "$JAR" "${STATEFUL[@]}" -H "X-XSRF-TOKEN: $(xsrf_token)" \
  -H 'Content-Type: application/json' -X POST "$BASE/api/v1/setup" \
  -d '{"name":"Smoke Owner","email":"smoke@example.com","password":"smoke-test-password","workspace_name":"Smoke Ops"}')"
[[ "$code" == "201" ]] || fail "setup returned $code, expected 201"
ok "setup completed"

[[ "$(http_code -c "$JAR" -b "$JAR" "${STATEFUL[@]}" "$BASE/api/v1/me")" == "200" ]] || fail "/me is not 200 after setup"
ok "/me returns 200"

[[ "$(curl -fsS --max-time 10 "$BASE/api/v1/setup")" == *'"required":false'* ]] || fail "setup still required after completion"

KEY="$(docker exec "$NAME" sh -c 'grep ^REVERB_APP_KEY= /data/reverb.env | cut -d= -f2')"
[[ -n "$KEY" ]] || fail "no reverb key persisted"
ws="$(curl -s -i --max-time 3 -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
  -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' \
  "$BASE/app/${KEY}?protocol=7" 2>/dev/null | head -n 1 || true)"
[[ "$ws" == *"101"* ]] || fail "websocket upgrade via /app did not return 101 (got: $ws)"
ok "reverb reachable through /app"

echo "== restart"
docker restart "$NAME" >/dev/null
wait_for_up
ok "/up returns 200 after restart"
[[ "$(http_code -c "$JAR" -b "$JAR" "${STATEFUL[@]}" "$BASE/api/v1/me")" == "200" ]] || fail "login did not survive restart"
ok "restart keeps login"

uid="$(docker exec "$NAME" id -u)"
[[ "$uid" != "0" ]] || fail "container user is root"
owners="$(docker top "$NAME" | tail -n +2 | awk '{ print $1 }' | sort -u)"
[[ -n "$owners" ]] || fail "could not list container processes"
if grep -qxE 'root|0' <<<"$owners"; then fail "a container process runs as root"; fi
ok "runs as non-root (uid $uid)"

docker exec "$NAME" test -s /data/app.key || fail "/data/app.key missing"
if [[ $USE_PGSQL -eq 0 ]]; then
  docker exec "$NAME" test -f /data/database.sqlite || fail "/data/database.sqlite missing"
fi
docker exec "$NAME" test -d /data/storage/logs || fail "/data/storage missing"
ok "state lives in /data"

echo "SMOKE OK"
