#!/bin/sh
# Container health: the web server answers and every check worker of this container has reported recently.
# Each step has its own time limit, so a stuck database never leaves the healthcheck hanging.
set -eu
cd /app
curl -fsS --max-time 5 -o /dev/null http://127.0.0.1:3000/up
exec timeout -k 2 20 php artisan intrawatch:worker:status --local --max-age=60
