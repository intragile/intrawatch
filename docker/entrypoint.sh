#!/bin/sh
set -eu

DATA_DIR="${DATA_DIR:-/data}"
FATAL_MARKER=/tmp/intrawatch-fatal
cd /app

# Fail with a clear message instead of a cryptic error further down.
probe="$DATA_DIR/.write-test.$$"
if ! (: >"$probe") 2>/dev/null; then
  echo "FATAL: $DATA_DIR is not writable for uid $(id -u). Make /data writable for uid 1000 (for a bind mount: chown -R 1000:1000 <host directory>), or use a named volume." >&2
  exit 1
fi
rm -f "$probe"

mkdir -p \
  "$DATA_DIR/storage/app/public" \
  "$DATA_DIR/storage/framework/cache/data" \
  "$DATA_DIR/storage/framework/sessions" \
  "$DATA_DIR/storage/framework/views" \
  "$DATA_DIR/storage/logs"

# Keep generated secrets in the data volume so restarts preserve sessions and
# websocket credentials.
if [ -z "${APP_KEY:-}" ]; then
  if [ ! -s "$DATA_DIR/app.key" ]; then
    (umask 077 && php artisan key:generate --show >"$DATA_DIR/app.key.tmp" && mv "$DATA_DIR/app.key.tmp" "$DATA_DIR/app.key")
  fi
  APP_KEY="$(cat "$DATA_DIR/app.key")"
  export APP_KEY
fi

REVERB_FILE="$DATA_DIR/reverb.env"
REVERB_VARS="REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET"

reverb_value() {
  grep "^$1=" "$REVERB_FILE" 2>/dev/null | head -n 1 | cut -d= -f2-
}

reverb_file_valid() {
  [ -s "$REVERB_FILE" ] || return 1
  for var in $REVERB_VARS; do
    [ -n "$(reverb_value "$var")" ] || return 1
  done
}

if ! reverb_file_valid; then
  [ -e "$REVERB_FILE" ] && echo "reverb.env is missing values; generating new websocket credentials" >&2
  (
    umask 077
    tmp="$REVERB_FILE.tmp.$$"
    {
      echo "REVERB_APP_ID=$(od -An -N4 -tu4 /dev/urandom | tr -d ' ' | cut -c1-9)"
      echo "REVERB_APP_KEY=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n' | cut -c1-20)"
      echo "REVERB_APP_SECRET=$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' | cut -c1-40)"
    } >"$tmp"
    # Rename is atomic, so a crash never leaves a half-written file behind.
    mv "$tmp" "$REVERB_FILE"
  )
  reverb_file_valid || { echo "FATAL: could not write valid websocket credentials to $REVERB_FILE" >&2; exit 1; }
fi
# Explicit environment variables win over the persisted defaults.
for var in $REVERB_VARS; do
  eval "current=\${$var:-}"
  if [ -z "$current" ]; then
    export "$var=$(reverb_value "$var")"
  fi
done

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
  : "${DB_DATABASE:=$DATA_DIR/database.sqlite}"
  export DB_DATABASE
  touch "$DB_DATABASE"
fi

# External databases may still be starting; retry for up to a minute.
attempt=0
until php artisan migrate --force; do
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 30 ]; then
    echo "migrate failed after $attempt attempts" >&2
    exit 1
  fi
  echo "migrate failed, retrying in 2s ($attempt/30)" >&2
  sleep 2
done

php artisan config:cache
php artisan route:cache
php artisan event:cache

# supervisord runs as a child so that a permanently failed program (see the fatal-exit listener) turns
# into a non-zero container exit; signals from `docker stop` are forwarded.
rm -f "$FATAL_MARKER"
supervisord -c /etc/supervisor/conf.d/supervisord.conf &
supervisor_pid=$!
trap 'kill -TERM "$supervisor_pid" 2>/dev/null || true' TERM INT
status=0
wait "$supervisor_pid" || status=$?
# wait returns early when a trapped signal arrives; keep waiting until supervisord has stopped.
while kill -0 "$supervisor_pid" 2>/dev/null; do
  status=0
  wait "$supervisor_pid" || status=$?
done

if [ -e "$FATAL_MARKER" ]; then
  echo "FATAL: program $(cat "$FATAL_MARKER") failed permanently; exiting so the restart policy can recover" >&2
  exit 1
fi
exit "$status"
