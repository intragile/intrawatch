#!/bin/sh
set -eu

DATA_DIR="${DATA_DIR:-/data}"
cd /app

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
    (umask 077 && php artisan key:generate --show >"$DATA_DIR/app.key")
  fi
  APP_KEY="$(cat "$DATA_DIR/app.key")"
  export APP_KEY
fi

REVERB_FILE="$DATA_DIR/reverb.env"
if [ ! -s "$REVERB_FILE" ]; then
  (
    umask 077
    {
      echo "REVERB_APP_ID=$(od -An -N6 -tu8 /dev/urandom | tr -d ' ' | cut -c1-9)"
      echo "REVERB_APP_KEY=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n' | cut -c1-20)"
      echo "REVERB_APP_SECRET=$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' | cut -c1-40)"
    } >"$REVERB_FILE"
  )
fi
# Explicit environment variables win over the persisted defaults.
for var in REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET; do
  eval "current=\${$var:-}"
  if [ -z "$current" ]; then
    value="$(grep "^$var=" "$REVERB_FILE" | head -n 1 | cut -d= -f2-)"
    export "$var=$value"
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

exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
