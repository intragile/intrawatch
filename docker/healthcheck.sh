#!/bin/sh
# Container health: the web server answers and every check worker of this container has reported recently.
# Each step has its own time limit, so a stuck database never leaves the healthcheck hanging. Docker gives
# meaning only to exit codes 0 and 1, so every failure exits 1.
cd /app || exit 1
curl -fsS --max-time 5 -o /dev/null http://127.0.0.1:3000/up || exit 1
timeout -k 2 20 php artisan intrawatch:worker:status --local --max-age=60 || exit 1
