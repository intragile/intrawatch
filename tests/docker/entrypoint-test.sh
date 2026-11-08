#!/usr/bin/env bash
# Runs docker/entrypoint.sh in a small stock PHP image with stub `php` and `sleep` commands, so the
# setup logic (umask, file modes, the multi-host warning and the migration loop) is tested without
# building the application image. Every case passes a command, so the entrypoint exits before supervisord.
# Usage: bash tests/docker/entrypoint-test.sh
#   ENTRYPOINT_TEST_IMAGE  image with /bin/sh and stat (default php:8.4-cli-alpine; pulled when missing)
set -euo pipefail

cd "$(dirname "$0")/../.."

IMAGE="${ENTRYPOINT_TEST_IMAGE:-php:8.4-cli-alpine}"
STUBS="$(mktemp -d)"
trap 'rm -rf "$STUBS"' EXIT

# php: logs every call to /tmp/php.log; `key:generate --show` prints a key; a full `migrate` (not the
# cache table one with --path) exits with the next code from /tmp/migrate-codes (one per line, 0 once the
# file is used up).
cat >"$STUBS/php" <<'EOF'
#!/bin/sh
echo "$*" >>/tmp/php.log
case "$*" in
  *key:generate*) echo "base64:dGVzdC1rZXktZm9yLXRoZS1lbnRyeXBvaW50LXRlc3Q=" ;;
  *migrate*--path=*) ;;
  *migrate*)
    code=0
    if [ -s /tmp/migrate-codes ]; then
      code="$(head -n 1 /tmp/migrate-codes)"
      sed -i 1d /tmp/migrate-codes
    fi
    exit "$code"
    ;;
esac
exit 0
EOF
printf '#!/bin/sh\nexit 0\n' >"$STUBS/sleep"
chmod 0755 "$STUBS/php" "$STUBS/sleep"

if ! timeout 30 docker image inspect "$IMAGE" >/dev/null 2>&1; then
  timeout 300 docker pull "$IMAGE" >/dev/null
fi

failures=0
case_failures=0
fail() { echo "FAIL: $*" >&2; failures=$((failures + 1)); }
# Reports the case as passed only when it added no failure since the last run_case.
ok() { [[ $failures -ne $case_failures ]] || echo "ok: $*"; }

# run_case <setup shell> <command shell> [docker run args...]: sets OUT (stdout), ERR (stderr), CODE.
# The setup runs as the container user before the entrypoint; the command runs after it.
run_case() {
  local setup="$1" command="$2"
  shift 2
  local out err
  case_failures=$failures
  out="$(mktemp)" err="$(mktemp)"
  CODE=0
  # shellcheck disable=SC2016 # $0 expands in the container, to the command.
  timeout 120 docker run --rm --entrypoint /bin/sh \
    -v "$PWD/docker/entrypoint.sh:/entrypoint.sh:ro" -v "$STUBS:/stub:ro" \
    --tmpfs /data --tmpfs /app -e PATH=/stub:/usr/local/bin:/usr/bin:/bin "$@" "$IMAGE" \
    -c "$setup"'
exec /bin/sh /entrypoint.sh /bin/sh -c "$0"' "$command" >"$out" 2>"$err" || CODE=$?
  OUT="$(cat "$out")" ERR="$(cat "$err")"
  rm -f "$out" "$err"
}

WARNING='multi-host deployments must share APP_KEY and REVERB_*'
REVERB_ENV=(-e REVERB_APP_ID=1 -e REVERB_APP_KEY=k -e REVERB_APP_SECRET=s)
STAT='umask; stat -c "%a %n" /data/database.sqlite /data/app.key /data/reverb.env; cat /tmp/php.log'

echo "== umask and file modes (sqlite)"
run_case ':' "$STAT"
[[ $CODE -eq 0 ]] || fail "sqlite start exited $CODE: $ERR"
[[ "$(head -n 1 <<<"$OUT")" == "0027" ]] || fail "umask is not 0027: $OUT"
grep -qx '640 /data/database.sqlite' <<<"$OUT" || fail "new database.sqlite is not 640: $OUT"
grep -qx '600 /data/app.key' <<<"$OUT" || fail "app.key is not 600: $OUT"
grep -qx '600 /data/reverb.env' <<<"$OUT" || fail "reverb.env is not 600: $OUT"
if grep -qF "$WARNING" <<<"$ERR"; then fail "sqlite start printed the multi-host warning"; fi
ok "umask 0027, database.sqlite 640, secrets 600, no warning for sqlite"

run_case 'touch /data/database.sqlite && chmod 644 /data/database.sqlite' "$STAT"
[[ $CODE -eq 0 ]] || fail "start with an existing database exited $CODE: $ERR"
grep -qx '640 /data/database.sqlite' <<<"$OUT" || fail "existing 644 database.sqlite was not tightened: $OUT"
ok "an existing 644 database.sqlite becomes 640"

# A database the app user may write but does not own (bind mount, custom DB_DATABASE) cannot be chmodded;
# /dev/null stands in for such a file. The start must go on with a warning, not stop under `set -e`.
run_case ':' 'echo started' --user 1000 -e DB_DATABASE=/dev/null
[[ $CODE -eq 0 && "$OUT" == "started" ]] || fail "start with a database owned by another uid failed (exit $CODE): $ERR"
grep -qF 'WARNING: could not restrict /dev/null' <<<"$ERR" || fail "no warning for a database that cannot be restricted: $ERR"
ok "a database owned by another uid only warns"

echo "== multi-host warning"
run_case ':' 'echo started' -e DB_CONNECTION=pgsql
[[ $CODE -eq 0 && "$OUT" == "started" ]] || fail "pgsql start without shared secrets did not continue (exit $CODE): $ERR"
grep -qF "WARNING: $WARNING" <<<"$ERR" || fail "no multi-host warning for pgsql with generated secrets: $ERR"
grep -q 'generated here: APP_KEY REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET' <<<"$ERR" || fail "warning does not name the generated values: $ERR"
ok "pgsql with generated APP_KEY and REVERB_*: warning, start continues"

run_case ':' 'echo started' -e DB_CONNECTION=pgsql -e APP_KEY=base64:c2hhcmVk "${REVERB_ENV[@]}"
[[ $CODE -eq 0 && "$OUT" == "started" ]] || fail "pgsql start with shared secrets failed (exit $CODE): $ERR"
if grep -qF "$WARNING" <<<"$ERR"; then fail "warning printed although every secret comes from the environment"; fi
ok "pgsql with APP_KEY and REVERB_* from the environment: no warning"

run_case ':' 'echo started' -e DB_CONNECTION=mysql -e APP_KEY=base64:c2hhcmVk -e REVERB_APP_ID=1 -e REVERB_APP_KEY=k
[[ $CODE -eq 0 && "$OUT" == "started" ]] || fail "mysql start with one missing secret failed (exit $CODE): $ERR"
grep -qF "WARNING: $WARNING (generated here: REVERB_APP_SECRET)" <<<"$ERR" || fail "missing REVERB_APP_SECRET not warned about: $ERR"
ok "one missing REVERB_* variable: warning names only it"

echo "== migration"
run_case ':' 'cat /tmp/php.log'
migrations="$(grep 'migrate' <<<"$OUT" || true)"
[[ "$migrations" == "artisan migrate --force" ]] || fail "unexpected sqlite migrate calls: $migrations"
ok "sqlite migrates without --isolated (a held lock could only be stale)"


DB=(-e DB_CONNECTION=pgsql -e APP_KEY=base64:c2hhcmVk "${REVERB_ENV[@]}")
run_case ':' 'cat /tmp/php.log' "${DB[@]}"
migrations="$(grep 'migrate' <<<"$OUT" || true)"
expected="artisan migrate --force --path=database/migrations/0001_01_01_000001_create_cache_table.php
artisan migrate --force --isolated=75"
[[ "$migrations" == "$expected" ]] || fail "unexpected pgsql migrate calls: $migrations"
ok "shared database: cache table first, then migrate --force --isolated=75"

run_case 'printf "75\n75\n0\n" >/tmp/migrate-codes' 'cat /tmp/php.log' "${DB[@]}"
[[ $CODE -eq 0 ]] || fail "start after a held migration lock exited $CODE: $ERR"
[[ "$(grep -c 'migrate --force --isolated=75' <<<"$OUT")" == "3" ]] || fail "isolated migrate not retried after exit 75: $OUT"
grep -q 'another host is migrating' <<<"$ERR" || fail "no wait message for a held lock: $ERR"
if grep -q 'migrate failed' <<<"$ERR"; then fail "a held lock counted as a failed migration: $ERR"; fi
ok "exit 75 waits for the other host and retries"

run_case 'printf "1\n1\n0\n" >/tmp/migrate-codes' 'cat /tmp/php.log' "${DB[@]}"
[[ $CODE -eq 0 ]] || fail "start after failed migrations exited $CODE: $ERR"
grep -q 'migrate failed, retrying in 2s (2/30)' <<<"$ERR" || fail "failed migrations are not counted: $ERR"
ok "a failing migration is retried and counted"

# shellcheck disable=SC2016 # runs in the container
run_case 'i=0; while [ $i -lt 400 ]; do echo 75; i=$((i + 1)); done >/tmp/migrate-codes' 'echo started' "${DB[@]}"
[[ $CODE -eq 1 && "$OUT" != "started" ]] || fail "a lock held forever did not stop the start (exit $CODE)"
grep -q 'FATAL: another host held the migration lock' <<<"$ERR" || fail "no FATAL message for a lock held forever: $ERR"
ok "a lock held for too long ends in FATAL"

run_case ':' 'cat /tmp/php.log' -e INTRAWATCH_SKIP_MIGRATE=1
[[ $CODE -eq 0 ]] || fail "INTRAWATCH_SKIP_MIGRATE start exited $CODE: $ERR"
if grep -q 'migrate' <<<"$OUT"; then fail "INTRAWATCH_SKIP_MIGRATE=1 still ran migrations: $OUT"; fi
ok "INTRAWATCH_SKIP_MIGRATE=1 runs no migration"

if [[ $failures -gt 0 ]]; then
  echo "ENTRYPOINT TEST FAILED ($failures)" >&2
  exit 1
fi
echo "ENTRYPOINT TEST OK"
