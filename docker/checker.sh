#!/bin/sh
# Runs the check worker for local process number $1, which owns shard CHECK_SHARD_OFFSET + $1 of
# CHECK_SHARDS_TOTAL. The entrypoint validates and exports these variables.
set -eu
cd /app
exec php artisan intrawatch:worker --shard="$((CHECK_SHARD_OFFSET + $1))" --shards="$CHECK_SHARDS_TOTAL"
