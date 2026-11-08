#!/usr/bin/env bash
# Builds the image, boots it with a fresh volume and exercises the setup and
# session flow over HTTP, a restart, an upgrade (new container, same volume),
# a non-localhost APP_URL, a permanently failing worker and an unwritable
# /data, plus monitors checked by the in-image worker, a public status page
# by slug and by custom domain, the admin shell under /admin/, down and up alerts
# delivered to a webhook receiver inside the container, an Uptime Kuma import
# through the API, and a timed docker stop. Usage: bash tests/docker/smoke.sh [--pgsql]
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
  timeout 60 docker rm -f "$PG_NAME" "${NAME}-ping" >/dev/null 2>&1 || true
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

# Polls run against a wall-clock deadline, so slow requests cannot stretch the advertised wait.
wait_for_up() {
  local deadline=$((SECONDS + 60))
  while ((SECONDS < deadline)); do
    [[ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$BASE/up" || true)" == "200" ]] && return 0
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

echo "== monitors and checker"
WS_URL="$BASE/api/v1/workspaces/smoke-ops"
load_xsrf
token_json="$(curl -fsS --max-time 10 -c "$JAR" -b "$JAR" "${STATEFUL[@]}" -H "X-XSRF-TOKEN: $XSRF" \
  -H 'Content-Type: application/json' -X POST "$WS_URL/tokens" \
  -d '{"name":"smoke","abilities":["monitors:write","monitors:read","notifications:write","notifications:read","status-pages:write","status-pages:read","workspace:manage"]}')" || fail "token creation failed"
API_TOKEN="$(jq -r '.token // empty' <<<"$token_json")"
[[ -n "$API_TOKEN" ]] || fail "no token in response: $token_json"
AUTH=(-H "Authorization: Bearer $API_TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json')
ok "api token created"

http_json="$(curl -fsS --max-time 10 "${AUTH[@]}" -X POST "$WS_URL/monitors" \
  -d '{"name":"self","type":"http","interval_seconds":20,"config":{"url":"http://127.0.0.1:3000/up"}}')" \
  || fail "http monitor creation failed"
HTTP_ID="$(jq -r '.data.id // empty' <<<"$http_json")"
[[ -n "$HTTP_ID" ]] || fail "no http monitor id: $http_json"
push_json="$(curl -fsS --max-time 10 "${AUTH[@]}" -X POST "$WS_URL/monitors" \
  -d '{"name":"heartbeat","type":"push","interval_seconds":60}')" || fail "push monitor creation failed"
PUSH_ID="$(jq -r '.data.id // empty' <<<"$push_json")"
push_url="$(jq -r '.data.push_url // empty' <<<"$push_json")"
[[ -n "$PUSH_ID" && "$push_url" == */api/v1/push/* ]] || fail "no push monitor or push_url: $push_json"
# push_url carries APP_URL; the published port differs, so only its path is used.
[[ "$(http_code "$BASE/api/v1/push/${push_url##*/api/v1/push/}")" == "200" ]] || fail "push_url did not accept the ping"
ok "http and push monitors created, push received"

# monitor_status <id>: prints the monitor's state.status
monitor_status() {
  curl -fsS --max-time 5 "${AUTH[@]}" "$WS_URL/monitors/$1" 2>/dev/null | jq -r '.data.state.status // empty' || true
}
http_status="" push_status=""
deadline=$((SECONDS + 60))
while true; do
  http_status="$(monitor_status "$HTTP_ID")"
  push_status="$(monitor_status "$PUSH_ID")"
  [[ "$http_status" == "up" && "$push_status" == "up" ]] && break
  ((SECONDS < deadline)) || fail "monitors not up within 60s (http: ${http_status:-none}, push: ${push_status:-none})"
  sleep 1
done
ok "http and push monitors are up"

timeout 30 docker exec "$NAME" php artisan intrawatch:worker:status >/dev/null || fail "intrawatch:worker:status failed"
timeout 30 docker exec "$NAME" /usr/local/bin/healthcheck.sh >/dev/null || fail "container healthcheck failed"
ok "worker status and healthcheck pass"

status="$(timeout 30 docker exec "$NAME" supervisorctl -c /etc/supervisor/conf.d/supervisord.conf status)" \
  || fail "supervisorctl status failed: $status"
echo "$status"
grep -Eq '^intrawatch:notifications +RUNNING' <<<"$status" || fail "notifications worker is not RUNNING"
ok "notifications worker running"

echo "== public status page"
page_json="$(curl -fsS --max-time 10 "${AUTH[@]}" -X POST "$WS_URL/status-pages" \
  -d '{"slug":"smoke","title":"Smoke status","published":true,"custom_domain":"status.example.test"}')" \
  || fail "status page creation failed"
PAGE_ID="$(jq -r '.data.id // empty' <<<"$page_json")"
[[ -n "$PAGE_ID" ]] || fail "no status page id: $page_json"
[[ "$(http_code "${AUTH[@]}" -X PUT "$WS_URL/status-pages/$PAGE_ID/layout" \
  -d "{\"sections\":[{\"name\":\"Core\",\"items\":[{\"monitor_id\":${HTTP_ID},\"display_name\":\"Smoke API\"}]}]}")" == "200" ]] \
  || fail "status page layout update failed"
ok "status page created and published"

page_headers="$(mktemp)"
page_body="$(curl -fsS --max-time 10 -D "$page_headers" "$BASE/status/smoke")" || fail "/status/smoke did not return 200"
[[ "$page_body" == *"Smoke status"* && "$page_body" == *"Smoke API"* ]] || fail "status page HTML lacks its title or component"
if grep -qi '^set-cookie:' "$page_headers"; then fail "status page sets a cookie"; fi
grep -qi '^content-security-policy:' "$page_headers" || fail "status page has no CSP header"
etag="$(awk 'tolower($1) == "etag:" { print $2 }' "$page_headers" | tr -d '\r')"
rm -f "$page_headers"
[[ -n "$etag" ]] || fail "status page has no ETag"
[[ "$(http_code -H "If-None-Match: $etag" "$BASE/status/smoke")" == "304" ]] || fail "matching If-None-Match is not a 304"
[[ "$(http_code "$BASE/status/no-such-page")" == "404" ]] || fail "unknown status page is not a 404"
ok "status page served by slug, without cookies, with ETag and 304"

badge_headers="$(mktemp)"
badge_body="$(curl -fsS --max-time 10 -D "$badge_headers" "$BASE/status/smoke/badge.svg")" || fail "/status/smoke/badge.svg did not return 200"
grep -qi '^content-type: image/svg+xml' "$badge_headers" || fail "badge is not image/svg+xml"
rm -f "$badge_headers"
[[ "$badge_body" == *"<svg"* && "$badge_body" == *"Smoke status"* ]] || fail "badge lacks its svg or the page title"
[[ "$(http_code "$BASE/status/smoke/badge/${HTTP_ID}/status.svg")" == "200" ]] || fail "component badge is not a 200"
[[ "$(http_code "$BASE/status/smoke/badge/999999/status.svg")" == "404" ]] || fail "badge of a monitor not on the page is not a 404"
atom_body="$(curl -fsS --max-time 10 "$BASE/status/smoke/feed.atom")" || fail "atom feed did not return 200"
[[ "$atom_body" == '<?xml'*'<feed xmlns="http://www.w3.org/2005/Atom"'* && "$atom_body" == *"</feed>"* ]] || fail "atom feed is not well formed"
curl -fsS --max-time 10 "$BASE/status/smoke/feed.rss" | grep -q '<rss ' || fail "rss feed is not served"
summary_headers="$(mktemp)"
curl -fsS --max-time 10 -D "$summary_headers" "$BASE/status/smoke/summary.json" | jq -e '.page.title == "Smoke status" and (.components | length) == 1' >/dev/null \
  || fail "summary.json is wrong"
grep -qi '^access-control-allow-origin: \*' "$summary_headers" || fail "summary.json has no CORS header"
rm -f "$summary_headers"
[[ "$(http_code -H 'Host: status.example.test' "$BASE/badge.svg")" == "200" ]] || fail "badge is not served on the custom domain"
ok "badges, feeds and JSON summary served"

# Custom-domain contract: / on a status domain always reaches PHP and shows the page; nothing else is reachable.
domain_body="$(curl -fsS --max-time 10 -H 'Host: status.example.test' "$BASE/")" || fail "/ on the custom domain did not return 200"
[[ "$domain_body" == *"Smoke status"* ]] || fail "/ on the custom domain is not the status page"
[[ "$(http_code -H 'Host: status.example.test' "$BASE/api/v1/ping")" == "404" ]] || fail "/api/v1/ping is reachable on the custom domain"
[[ "$(http_code -H 'Host: status.example.test' "${STATEFUL[@]}" "$BASE/api/v1/me")" == "404" ]] || fail "/api/v1/me is reachable on the custom domain"
[[ "$(http_code -H 'Host: status.example.test' "$BASE/up")" == "200" ]] || fail "/up is not served on the custom domain"
ok "custom domain serves the status page and nothing else"

echo "== admin single-page application"
root_headers="$(mktemp)"
curl -s -o /dev/null -D "$root_headers" --max-time 10 "$BASE/" || true
grep -Eqi '^HTTP/[0-9.]+ 302' "$root_headers" || fail "/ is not a 302"
grep -Eqi '^location: (http://[^/]+)?/admin/'$'\r''?$' "$root_headers" || fail "/ does not redirect to /admin/"
rm -f "$root_headers"
admin_headers="$(mktemp)"
admin_body="$(curl -fsS --max-time 10 -D "$admin_headers" "$BASE/admin/")" || fail "/admin/ did not return 200"
grep -qi '^content-type: text/html' "$admin_headers" || fail "/admin/ is not HTML"
grep -qi '^content-security-policy: default-src '"'"'self'"'" "$admin_headers" || fail "/admin/ has no CSP header"
grep -qi '^cache-control: no-store' "$admin_headers" || fail "/admin/ is cacheable"
if grep -qi '^set-cookie:' "$admin_headers"; then fail "/admin/ sets a cookie"; fi
rm -f "$admin_headers"
[[ "$admin_body" == *'<meta name="intrawatch-config" content="'* ]] || fail "/admin/ lacks the runtime config meta"
ASSET="$(grep -oE '/admin/assets/[A-Za-z0-9._-]+\.js' <<<"$admin_body" | head -n 1)"
[[ -n "$ASSET" ]] || fail "/admin/ references no script asset"
asset_headers="$(mktemp)"
curl -fsS --max-time 10 -D "$asset_headers" -o /dev/null "$BASE$ASSET" || fail "$ASSET did not return 200"
grep -qi '^cache-control:.*immutable' "$asset_headers" || fail "$ASSET is not immutable"
grep -qi '^content-type: text/javascript' "$asset_headers" || fail "$ASSET is not served as JavaScript"
rm -f "$asset_headers"
deep_body="$(curl -fsS --max-time 10 "$BASE/admin/monitors")" || fail "/admin/monitors did not return 200"
[[ "$deep_body" == *'<meta name="intrawatch-config"'* ]] || fail "/admin/monitors is not the shell"
for path in /admin/ "$ASSET" /api/v1/ping; do
  [[ "$(http_code -H 'Host: status.example.test' "$BASE$path")" == "404" ]] || fail "$path is reachable on the custom domain"
done
ok "admin shell, assets and deep links served; none on the custom domain"

echo "== alerts to a webhook"
# A tiny receiver inside the container appends every request body to a file. CHECK_BLOCKED_NETWORKS keeps
# its default, which allows loopback targets.
timeout 30 docker exec "$NAME" sh -c 'mkdir -p /tmp/smoke-alerts /tmp/smoke-target && : > /tmp/smoke-alerts/received.log &&
  printf "%s\n" "<?php" "file_put_contents(\"/tmp/smoke-alerts/received.log\", file_get_contents(\"php://input\").PHP_EOL, FILE_APPEND | LOCK_EX);" "echo \"ok\";" \
  > /tmp/smoke-alerts/router.php' || fail "could not write the webhook receiver"
timeout 30 docker exec -d "$NAME" sh -c 'echo $$ > /tmp/smoke-alerts/receiver.pid; exec php -S 127.0.0.1:39999 /tmp/smoke-alerts/router.php >/dev/null 2>&1' \
  || fail "could not start the webhook receiver"
deadline=$((SECONDS + 15))
until timeout 10 docker exec "$NAME" php -r 'exit(@fsockopen("127.0.0.1", 39999, $e, $s, 2) ? 0 : 1);'; do
  ((SECONDS < deadline)) || fail "webhook receiver not listening within 15s"
  sleep 1
done

channel_json="$(curl -fsS --max-time 10 "${AUTH[@]}" -X POST "$WS_URL/notification-channels" \
  -d '{"name":"smoke hook","type":"webhook","config":{"url":"http://127.0.0.1:39999/hook"}}')" \
  || fail "webhook channel creation failed"
CHANNEL_ID="$(jq -r '.data.id // empty' <<<"$channel_json")"
[[ -n "$CHANNEL_ID" ]] || fail "no channel id: $channel_json"
# Nothing listens on 39998 yet, so the TCP check is refused and the monitor goes down on its first check.
tcp_json="$(curl -fsS --max-time 10 "${AUTH[@]}" -X POST "$WS_URL/monitors" \
  -d "{\"name\":\"closed port\",\"type\":\"tcp\",\"interval_seconds\":20,\"timeout_seconds\":5,\"retries\":0,\"config\":{\"host\":\"127.0.0.1\",\"port\":39998},\"notification_channel_ids\":[${CHANNEL_ID}]}")" \
  || fail "tcp monitor creation failed"
TCP_ID="$(jq -r '.data.id // empty' <<<"$tcp_json")"
[[ -n "$TCP_ID" ]] || fail "no tcp monitor id: $tcp_json"
ok "webhook channel and tcp monitor created"

# wait_for_alert <event> <seconds>: waits until the receiver holds a JSON payload with that event.
wait_for_alert() {
  local event="$1" seconds="$2" deadline=$((SECONDS + $2)) received
  while true; do
    received="$(timeout 10 docker exec "$NAME" cat /tmp/smoke-alerts/received.log 2>/dev/null || true)"
    if jq -e --arg event "$event" --argjson monitor "$TCP_ID" 'select(.event == $event and .monitor.id == $monitor)' \
      <<<"$received" >/dev/null 2>&1; then
      return 0
    fi
    ((SECONDS < deadline)) || fail "no \"$event\" alert within ${seconds}s (received: ${received:-nothing})"
    sleep 1
  done
}
wait_for_alert down 90
grep -q '"event":"down"' <<<"$(timeout 10 docker exec "$NAME" cat /tmp/smoke-alerts/received.log)" \
  || fail "down payload is not compact JSON"
ok "down alert delivered to the webhook"

# The target comes back: something now listens on the monitored port.
timeout 30 docker exec -d "$NAME" sh -c 'echo $$ > /tmp/smoke-alerts/target.pid; exec php -S 127.0.0.1:39998 -t /tmp/smoke-target >/dev/null 2>&1' \
  || fail "could not start the tcp target"
curl -fsS --max-time 10 "${AUTH[@]}" -X POST "$WS_URL/monitors/$TCP_ID/check" -o /dev/null || true
wait_for_alert up 90
ok "up alert delivered to the webhook"

deadline=$((SECONDS + 30))
while true; do
  alerts_json="$(curl -fsS --max-time 10 "${AUTH[@]}" "$WS_URL/monitors/$TCP_ID/alerts" 2>/dev/null || true)"
  jq -e '[.data[] | select((.kind == "down" or .kind == "up") and any(.deliveries[]; .status == "sent"))] | length >= 2' \
    <<<"$alerts_json" >/dev/null 2>&1 && break
  ((SECONDS < deadline)) || fail "alert history does not show sent down and up deliveries: ${alerts_json:-nothing}"
  sleep 1
done
ok "alert history lists both alerts with sent deliveries"

# Pause the monitor first, so it cannot raise more alerts once the helpers stop or while the container stops.
[[ "$(http_code "${AUTH[@]}" -X POST "$WS_URL/monitors/$TCP_ID/pause")" == "200" ]] || fail "could not pause the tcp monitor"
timeout 30 docker exec "$NAME" sh -c 'kill $(cat /tmp/smoke-alerts/receiver.pid /tmp/smoke-alerts/target.pid) 2>/dev/null; rm -rf /tmp/smoke-alerts /tmp/smoke-target' || true

echo "== import from Uptime Kuma"
[[ "$(timeout 30 docker exec "$NAME" php -r 'echo ini_get("upload_max_filesize"), " ", ini_get("post_max_size");')" == "512M 520M" ]] \
  || fail "PHP upload limits are not raised for imports"
# The push token of the fixture's push monitor (tests/Fixtures/kuma/kuma2.db, monitor "Push").
KUMA_PUSH_TOKEN="researchPushToken01"
UPLOAD_AUTH=(-H "Authorization: Bearer $API_TOKEN" -H 'Accept: application/json')
import_json="$(curl -sS --max-time 60 "${UPLOAD_AUTH[@]}" -F "file=@tests/Fixtures/kuma/kuma2.db" "$WS_URL/imports")" \
  || fail "import upload failed"
IMPORT_ID="$(jq -r '.data.id // empty' <<<"$import_json")"
[[ -n "$IMPORT_ID" && "$(jq -r '.data.status' <<<"$import_json")" == "pending" ]] || fail "upload did not create a pending import: $import_json"
ok "kuma2.db uploaded"

# wait_for_import <status> <seconds>: polls the import until it reaches the status; a failed import aborts.
wait_for_import() {
  local want="$1" deadline=$((SECONDS + $2)) status=""
  while true; do
    IMPORT_JSON="$(curl -fsS --max-time 10 "${AUTH[@]}" "$WS_URL/imports/$IMPORT_ID" 2>/dev/null || true)"
    status="$(jq -r '.data.status // empty' <<<"$IMPORT_JSON" 2>/dev/null || true)"
    [[ "$status" == "$want" ]] && return 0
    [[ "$status" == "failed" ]] && fail "import failed: $(jq -r '.data.error' <<<"$IMPORT_JSON")"
    ((SECONDS < deadline)) || fail "import not $want within $2s (status: ${status:-none})"
    sleep 1
  done
}
wait_for_import analyzed 60
timeout 30 docker exec "$NAME" sh -c "test \"\$(stat -c %u /data/storage/app/private/imports/$IMPORT_ID)\" = 1000" \
  || fail "the uploaded file is not stored under /data for uid 1000"
# Ten fixture monitors, one of them (Redis) a type IntraWatch cannot check.
jq -e '(.data.report.counts.monitor.would_create // 0) + (.data.report.counts.monitor.invalid // 0) == 9 and .data.report.dry_run == true' \
  <<<"$IMPORT_JSON" >/dev/null || fail "analysis counts are wrong: $(jq -c '.data.report.counts' <<<"$IMPORT_JSON")"
ok "import analysed: $(jq -c '.data.report.counts.monitor' <<<"$IMPORT_JSON")"

[[ "$(http_code "${AUTH[@]}" -X POST "$WS_URL/imports/$IMPORT_ID/run")" == "202" ]] || fail "import run was not accepted"
wait_for_import completed 120
if timeout 30 docker exec "$NAME" test -e "/data/storage/app/private/imports/$IMPORT_ID"; then fail "the uploaded file was kept after the run"; fi
curl -fsS --max-time 10 "${AUTH[@]}" "$WS_URL/monitors?limit=200" | jq -e '[.data[] | select(.name == "Example HTTP")] | length == 1' >/dev/null \
  || fail "the imported monitor \"Example HTTP\" is not listed"
[[ "$(http_code "$BASE/status/public")" == "200" ]] || fail "the imported status page /status/public is not a 200"
[[ "$(http_code "$BASE/api/push/${KUMA_PUSH_TOKEN}?status=up")" == "200" ]] || fail "the Kuma push alias did not accept the imported token"
ok "import ran: monitors, status page and Kuma push alias work"
# The imported monitors check outside hosts and alert to the fixture's channels; pause them so they cannot
# interfere with the remaining steps.
imported_ids="$(curl -fsS --max-time 10 "${AUTH[@]}" "$WS_URL/monitors?limit=200" | jq -c --argjson keep "[$HTTP_ID,$PUSH_ID,$TCP_ID]" \
  '[.data[] | .id | select(. as $id | $keep | index($id) | not)]')" || fail "could not list the imported monitors"
[[ "$(http_code "${AUTH[@]}" -X POST "$WS_URL/monitors/bulk" -d "{\"action\":\"pause\",\"ids\":${imported_ids}}")" == "200" ]] \
  || fail "could not pause the imported monitors"

[[ "$(timeout 30 docker exec "$NAME" id -u)" == "1000" ]] || fail "docker exec does not run as uid 1000"
timeout 30 docker exec "$NAME" ping -c1 -W5 127.0.0.1 >/dev/null || fail "ping as uid 1000 failed"
ok "ping works as uid 1000"
run_docker 60 "ping without NET_RAW" run --rm --name "${NAME}-ping" --cap-drop NET_RAW --entrypoint ping "$IMAGE" -c1 -W5 127.0.0.1 >/dev/null
ok "ping works without NET_RAW"

echo "== stop within the docker stop grace period"
started="$(date +%s)"
run_docker 60 "container stop" stop -t 10 "$NAME" >/dev/null
STOP_SECONDS=$(($(date +%s) - started))
stop_code="$(timeout 30 docker inspect -f '{{.State.ExitCode}}' "$NAME")" || fail "docker inspect failed"
# 137 means the 10 s grace period ran out and Docker sent SIGKILL.
[[ "$stop_code" != "137" ]] || fail "container was killed after the grace period (${STOP_SECONDS}s)"
[[ $STOP_SECONDS -lt 10 ]] || fail "docker stop took ${STOP_SECONDS}s"
ok "docker stop finished in ${STOP_SECONDS}s (exit $stop_code)"

echo "== restart"
run_docker 90 "container start" start "$NAME" >/dev/null
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
# Both default-connection workers fail; whichever reaches FATAL first stops the container.
grep -Eq "program '(queue|notifications)' could not be kept running" <<<"$logs" || fail "FATAL reason not in the container log"
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
