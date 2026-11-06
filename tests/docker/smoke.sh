#!/usr/bin/env bash
# Builds the image, boots it with a fresh volume and exercises the setup and
# session flow over HTTP, a restart, an upgrade (new container, same volume),
# a non-localhost APP_URL, a permanently failing worker and an unwritable
# /data. Usage: bash tests/docker/smoke.sh [--pgsql]
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
JAR2="$(mktemp)"
USE_PGSQL=0
[[ "${1:-}" == "--pgsql" ]] && USE_PGSQL=1

cleanup() {
  local code=$?
  if [[ $code -ne 0 ]]; then
    echo "--- container logs (last 60 lines) ---" >&2
    timeout 60 docker logs --tail 60 "$NAME" >&2 2>&1 || true
  fi
  timeout 60 docker rm -f "$NAME" >/dev/null 2>&1 || true
  timeout 60 docker rm -f "$PG_NAME" >/dev/null 2>&1 || true
  timeout 60 docker volume rm -f "$VOLUME" >/dev/null 2>&1 || true
  timeout 60 docker network rm "$NETWORK" >/dev/null 2>&1 || true
  [[ "${SMOKE_KEEP_IMAGE:-0}" == "1" ]] || timeout 60 docker rmi "$IMAGE" >/dev/null 2>&1 || true
  rm -f "$JAR" "$JAR2"
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

# Sets XSRF to the URL-decoded XSRF-TOKEN cookie of the given jar. Not run in
# a subshell so a failure aborts the script.
load_xsrf() {
  local jar="${1:-$JAR}" raw
  raw="$(awk '$6 == "XSRF-TOKEN" { print $7 }' "$jar" | tail -n 1)"
  [[ -n "$raw" ]] || fail "no XSRF-TOKEN cookie in jar"
  XSRF="$(printf '%b' "${raw//%/\\x}")"
}

# Waits until the container has exited and prints its exit code: wait_for_exit <name> <seconds>
wait_for_exit() {
  local name="$1" seconds="$2" i state
  for i in $(seq 1 "$seconds"); do
    state="$(timeout 30 docker inspect -f '{{.State.Status}} {{.State.ExitCode}}' "$name" 2>/dev/null || true)"
    if [[ "$state" == exited* ]]; then
      echo "${state#exited }"
      return 0
    fi
    sleep 1
  done
  fail "container $name did not exit within ${seconds}s"
}

# Bounded docker invocation: run_docker <seconds> <description> <docker args...>
run_docker() {
  local seconds="$1" what="$2"
  shift 2
  timeout "$seconds" docker "$@" || fail "$what failed or exceeded ${seconds}s"
}

echo "== build $IMAGE"
timeout 1500 docker build -f docker/Dockerfile -t "$IMAGE" .

ENV_ARGS=()
NET_ARGS=()
if [[ $USE_PGSQL -eq 1 ]]; then
  echo "== start postgres"
  run_docker 30 "network create" network create "$NETWORK" >/dev/null
  run_docker 300 "postgres start (pull included)" run -d --name "$PG_NAME" --network "$NETWORK" \
    -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=intrawatch postgres:16 >/dev/null
  for i in $(seq 1 60); do
    timeout 30 docker exec "$PG_NAME" pg_isready -U postgres -d intrawatch >/dev/null 2>&1 && break
    [[ $i -eq 60 ]] && fail "postgres not ready within 60s"
    sleep 1
  done
  NET_ARGS=(--network "$NETWORK")
  ENV_ARGS=(-e DB_CONNECTION=pgsql -e DB_HOST="$PG_NAME" -e DB_PORT=5432
    -e DB_DATABASE=intrawatch -e DB_USERNAME=postgres -e DB_PASSWORD=secret)
fi

# start_container [extra docker run args...]
start_container() {
  run_docker 300 "app container start" run -d --name "$NAME" ${NET_ARGS[@]+"${NET_ARGS[@]}"} ${ENV_ARGS[@]+"${ENV_ARGS[@]}"} \
    "$@" -p "127.0.0.1:${PORT}:3000" -v "${VOLUME}:/data" "$IMAGE" >/dev/null
}

remove_container() {
  run_docker 60 "container removal" rm -f "$1" >/dev/null
}

echo "== run"
start_container
wait_for_up
ok "/up returns 200"

[[ "$(curl -fsS --max-time 10 "$BASE/api/v1/setup")" == *'"required":true'* ]] || fail "setup is not reported as required"
ok "setup required"

curl -fsS --max-time 10 -c "$JAR" -b "$JAR" "${STATEFUL[@]}" "$BASE/sanctum/csrf-cookie" -o /dev/null
load_xsrf
code="$(http_code -c "$JAR" -b "$JAR" "${STATEFUL[@]}" -H "X-XSRF-TOKEN: $XSRF" \
  -H 'Content-Type: application/json' -X POST "$BASE/api/v1/setup" \
  -d '{"name":"Smoke Owner","email":"smoke@example.com","password":"smoke-test-password","workspace_name":"Smoke Ops"}')"
[[ "$code" == "201" ]] || fail "setup returned $code, expected 201"
ok "setup completed"

[[ "$(http_code -c "$JAR" -b "$JAR" "${STATEFUL[@]}" "$BASE/api/v1/me")" == "200" ]] || fail "/me is not 200 after setup"
ok "/me returns 200"

[[ "$(curl -fsS --max-time 10 "$BASE/api/v1/setup")" == *'"required":false'* ]] || fail "setup still required after completion"

KEY="$(timeout 30 docker exec "$NAME" sh -c 'grep ^REVERB_APP_KEY= /data/reverb.env | cut -d= -f2')"
[[ -n "$KEY" ]] || fail "no reverb key persisted"
ws="$(curl -s -i --max-time 3 -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
  -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' \
  "$BASE/app/${KEY}?protocol=7" 2>/dev/null | head -n 1 || true)"
[[ "$ws" == *"101"* ]] || fail "websocket upgrade via /app did not return 101 (got: $ws)"
ok "reverb reachable through /app"

echo "== restart"
run_docker 90 "container restart" restart "$NAME" >/dev/null
wait_for_up
ok "/up returns 200 after restart"
[[ "$(http_code -c "$JAR" -b "$JAR" "${STATEFUL[@]}" "$BASE/api/v1/me")" == "200" ]] || fail "login did not survive restart"
ok "restart keeps login"

echo "== upgrade (new container, same volume)"
remove_container "$NAME"
start_container
wait_for_up
[[ "$(http_code -c "$JAR" -b "$JAR" "${STATEFUL[@]}" "$BASE/api/v1/me")" == "200" ]] || fail "login did not survive replacing the container"
[[ "$(curl -fsS --max-time 10 "$BASE/api/v1/setup")" == *'"required":false'* ]] || fail "data did not survive replacing the container"
ok "upgrade keeps data and login"

echo "== non-localhost APP_URL"
APP_HOST="intrawatch.test:3000"
OTHER=(-H "Host: ${APP_HOST}" -H "Origin: http://${APP_HOST}" -H "Referer: http://${APP_HOST}/" -H "Accept: application/json")
remove_container "$NAME"
start_container -e "APP_URL=http://${APP_HOST}"
wait_for_up
curl -fsS --max-time 10 -c "$JAR2" -b "$JAR2" "${OTHER[@]}" "$BASE/sanctum/csrf-cookie" -o /dev/null
load_xsrf "$JAR2"
code="$(http_code -c "$JAR2" -b "$JAR2" "${OTHER[@]}" -H "X-XSRF-TOKEN: $XSRF" \
  -H 'Content-Type: application/json' -X POST "$BASE/api/v1/auth/login" \
  -d '{"email":"smoke@example.com","password":"smoke-test-password"}')"
[[ "$code" == "200" ]] || fail "login on ${APP_HOST} returned $code, expected 200"
# The cookie name derives from config('app.name'), not the framework default.
awk '$6 == "intrawatch-session" { found = 1 } END { exit !found }' "$JAR2" || fail "no intrawatch-session cookie set for ${APP_HOST}"
[[ "$(http_code -c "$JAR2" -b "$JAR2" "${OTHER[@]}" "$BASE/api/v1/me")" == "200" ]] || fail "/me is not 200 for ${APP_HOST}"
ok "session login works on a non-localhost APP_URL"

code="$(http_code -H 'Accept: application/json' -H 'Content-Type: application/json' -X POST "$BASE/api/v1/auth/login" \
  -d '{"email":"smoke@example.com","password":"smoke-test-password"}')"
[[ "$code" == "400" ]] || fail "login without a session returned $code, expected 400"
ok "login without a session is a 400 problem"

[[ "$(http_code "$BASE/api/v1/openapi.json")" == "200" ]] || fail "openapi.json is not served"
ok "openapi.json served"

uid="$(timeout 30 docker exec "$NAME" id -u)" || fail "docker exec id failed"
[[ "$uid" != "0" ]] || fail "container user is root"
owners="$(timeout 30 docker top "$NAME" | tail -n +2 | awk '{ print $1 }' | sort -u)"
[[ -n "$owners" ]] || fail "could not list container processes"
if grep -qxE 'root|0' <<<"$owners"; then fail "a container process runs as root"; fi
ok "runs as non-root (uid $uid)"

timeout 30 docker exec "$NAME" test -s /data/app.key || fail "/data/app.key missing"
if [[ $USE_PGSQL -eq 0 ]]; then
  timeout 30 docker exec "$NAME" test -f /data/database.sqlite || fail "/data/database.sqlite missing"
fi
timeout 30 docker exec "$NAME" test -d /data/storage/logs || fail "/data/storage missing"
logs="$(timeout 30 docker exec "$NAME" sh -c 'ls -A /data/storage/logs')" || fail "could not list /data/storage/logs"
[[ -z "$logs" ]] || fail "log files are written to /data/storage/logs: $logs"
ok "state lives in /data, logs go to stderr"

timeout 30 docker exec "$NAME" sh -c 'grep -q "^REVERB_APP_SECRET=." /data/reverb.env' || fail "reverb.env incomplete"
timeout 30 docker exec "$NAME" sh -c 'printf "REVERB_APP_ID=1\n" > /data/reverb.env' || fail "could not truncate reverb.env"
remove_container "$NAME"
start_container
wait_for_up
timeout 30 docker exec "$NAME" sh -c 'for v in REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET; do grep -q "^$v=." /data/reverb.env || exit 1; done' \
  || fail "incomplete reverb.env was not regenerated"
ok "incomplete reverb.env is regenerated"

echo "== permanently failing worker"
remove_container "$NAME"
# An unknown queue connection makes queue:work exit at once on every start.
start_container -e QUEUE_CONNECTION=broken
exit_code="$(wait_for_exit "$NAME" 120)"
[[ "$exit_code" != "0" ]] || fail "container exited 0 after a worker entered FATAL"
# Captured first: grep -q closing the pipe early would fail the pipeline under pipefail.
logs="$(timeout 30 docker logs "$NAME" 2>&1)" || fail "could not read container logs"
grep -q "program 'queue' could not be kept running" <<<"$logs" || fail "FATAL reason not in the container log"
ok "a FATAL worker stops the container (exit $exit_code)"

echo "== unwritable /data"
remove_container "$NAME"
run_docker 300 "read-only container start" run -d --name "$NAME" ${NET_ARGS[@]+"${NET_ARGS[@]}"} ${ENV_ARGS[@]+"${ENV_ARGS[@]}"} \
  -v "${VOLUME}:/data:ro" "$IMAGE" >/dev/null
exit_code="$(wait_for_exit "$NAME" 60)"
[[ "$exit_code" == "1" ]] || fail "unwritable /data exited $exit_code, expected 1"
logs="$(timeout 30 docker logs "$NAME" 2>&1)" || fail "could not read container logs"
grep -qi "make /data writable for uid 1000" <<<"$logs" || fail "no clear message for an unwritable /data"
ok "unwritable /data fails with a clear message"

echo "SMOKE OK"
