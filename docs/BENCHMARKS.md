# Benchmarks

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

Measured results of the benchmarks that ship with IntraWatch. Every number on
this page comes from a run on the hardware below; nothing is extrapolated. How
to run the benchmarks yourself is described in the
[README](../README.md#development).

## Hardware and versions

| | |
|---|---|
| Host | Apple MacBook Air (M1, `MacBookAir10,1`), macOS 15 |
| Docker | Docker Desktop, engine 29.8.2; Linux VM aarch64, 2 vCPU, 3.8 GiB memory |
| Image | `intrawatch:bench-rel`, built from `docker/Dockerfile` (FrankenPHP, PHP 8.5) |
| Databases | SQLite in the image's `/data` volume; PostgreSQL 16.15 (`postgres:16`) in a container next to it; MySQL 8.4.13 and PostgreSQL 16.15 in containers for the host runs |
| Date | 2026-11-08 |

Runs "inside the image" execute in a container of the image on the Docker VM.
Runs "on the host" execute PHP on macOS against the database containers; the
host has more CPU than the VM, so those numbers are not comparable to the
image runs.

## Scale: 5 000 monitors and 300 status pages

```bash
docker exec <container> php artisan intrawatch:bench:scale \
  --monitors=5000 --pages=300 --items=20 --requests=2000 --base-url=http://127.0.0.1:3000
```

The container runs the image's normal services (web server, one check worker,
queue workers, Reverb) with `TRUSTED_PROXIES=127.0.0.1`. The benchmark seeds
5 000 active monitors (3 000 HTTP, 500 each of TCP, ping, DNS and push) in 250
groups (50 top-level, 200 nested), 50 tags, 15 inactive channels, 90 days of day
and 48 hours of hour rollups per monitor, 300 published pages of 20 items, 2 000
alerts, 200 incidents, 50 maintenances and 2 000 audit events. The checks target
the container itself (`/robots.txt`, port 3000, `127.0.0.1`, `localhost`) at a
60-second interval, push monitors expect a ping every hour. After 60 seconds of
settling it sends the requests one at a time over HTTP: 2 000 admin list
requests (200 per list, `limit=100`, bearer token), one request per status page
(cold), then 2 000 round-robin status page requests (warm). The check worker
kept running throughout; all 5 000 monitors were up at the end, and no request
failed.

Thresholds: admin p95 below 500 ms per list, warm status page p95 below 100 ms.
Both runs passed.

### SQLite, inside the image

Checks recorded while measuring: 4 317 in 54.9 s.

| Kind | Endpoint | Requests | p50 (ms) | p95 (ms) | Max (ms) |
|---|---|---:|---:|---:|---:|
| admin | monitors, top level (`parent_id=null`) | 200 | 19.83 | 25.00 | 111.62 |
| admin | monitors, search (`q=api`) | 200 | 26.08 | 33.15 | 88.59 |
| admin | monitors, status filter (`status=up`) | 200 | 18.19 | 22.98 | 36.98 |
| admin | monitors, tag filter | 200 | 25.73 | 33.31 | 84.91 |
| admin | group children | 200 | 7.70 | 10.97 | 48.56 |
| admin | alerts | 200 | 12.82 | 17.15 | 109.08 |
| admin | status pages | 200 | 9.10 | 12.00 | 50.20 |
| admin | incidents | 200 | 10.61 | 13.52 | 147.72 |
| admin | maintenances | 200 | 11.64 | 15.25 | 128.72 |
| admin | audit | 200 | 8.80 | 11.28 | 36.00 |
| status | cold | 300 | 42.43 | 71.45 | 270.73 |
| status | warm | 2 000 | 3.61 | 5.54 | 52.22 |

### PostgreSQL 16, inside the image

PostgreSQL ran in its own container on the same Docker VM, so both shared the
two vCPUs. Checks recorded while measuring: 6 647 in 84.6 s.

| Kind | Endpoint | Requests | p50 (ms) | p95 (ms) | Max (ms) |
|---|---|---:|---:|---:|---:|
| admin | monitors, top level (`parent_id=null`) | 200 | 31.61 | 57.24 | 183.04 |
| admin | monitors, search (`q=api`) | 200 | 35.68 | 58.18 | 112.00 |
| admin | monitors, status filter (`status=up`) | 200 | 28.07 | 43.66 | 138.97 |
| admin | monitors, tag filter | 200 | 36.79 | 62.00 | 124.24 |
| admin | group children | 200 | 17.55 | 32.54 | 66.89 |
| admin | alerts | 200 | 22.07 | 49.12 | 96.36 |
| admin | status pages | 200 | 17.53 | 39.68 | 116.60 |
| admin | incidents | 200 | 20.46 | 39.36 | 126.60 |
| admin | maintenances | 200 | 21.31 | 42.38 | 193.39 |
| admin | audit | 200 | 17.29 | 34.15 | 104.18 |
| status | cold | 300 | 55.17 | 80.42 | 142.35 |
| status | warm | 2 000 | 4.07 | 7.25 | 114.76 |

## Uptime Kuma import

```bash
vendor/bin/phpunit --testsuite Bench --group bench
```

The benchmark generates a Kuma 2.x SQLite database from
`tests/Fixtures/kuma/kuma2-schema.sql`: 5 000 monitors (HTTP, keyword, TCP,
ping, DNS, push) and 250 groups, 50 tags, 15 notifications, 300 status pages of
20 items, and for 500 monitors 30 days of `stat_hourly` (360 000 rows) and 365
days of `stat_daily` (182 500 rows). It imports everything with history into a
fresh workspace on the configured connection. Limit: 300 s. All three runs
passed. Run on the host (PHP 8.5, macOS) against a SQLite file and the MySQL
and PostgreSQL containers.

| Step | SQLite (s) | MySQL 8.4 (s) | PostgreSQL 16 (s) |
|---|---:|---:|---:|
| Generate the source database | 1.02 | 1.03 | 1.12 |
| Tags | 0.03 | 0.25 | 0.21 |
| Notifications | 0.04 | 0.09 | 0.09 |
| Monitors | 19.33 | 64.31 | 67.20 |
| Status pages | 2.20 | 6.01 | 6.47 |
| Incidents | 0.00 | 0.00 | 0.00 |
| Maintenances | 0.00 | 0.00 | 0.00 |
| History | 14.57 | 35.25 | 19.52 |
| **Import total** | **36.36** | **106.11** | **93.68** |
| Peak memory (MB) | 101.0 | 101.0 | 103.0 |

The peak memory is the PHP process's, including the test framework (52.5 MB
before the import started).

## Schedule drift: 1 000 HTTP monitors

```bash
docker run --rm --cpus=2 intrawatch:bench-rel \
  php artisan intrawatch:bench --monitors=1000 --interval=60 --duration=180
```

One check worker, SQLite, inside the image, against a local target that
answers in 50 to 300 ms. Passed.

| Metric | Value |
|---|---:|
| Checks | 3 000 (expected at least 3 000) |
| Checks not up | 0 |
| Checks per minute | 999.9 |
| Drift p50 | 5 ms |
| Drift p95 | 13 ms |
| Drift max | 26 ms |
| Longest result flush | 69 ms |

## Status pages: 300 pages, in-process

```bash
docker run --rm --cpus=2 intrawatch:bench-rel \
  php artisan intrawatch:bench:status --pages=300 --items=20 --requests=3000
```

SQLite, inside the image, requests sent through the application in-process (no
web server). The warm pass mixes the page, its feeds, summary and badge.
Passed.

| Pass | Requests | p50 (ms) | p95 (ms) | Max (ms) |
|---|---:|---:|---:|---:|
| cold | 300 | 39.26 | 56.12 | 203.57 |
| warm | 3 000 | 0.88 | 1.29 | 155.71 |

Database queries during the warm pass: 0.

## Earlier results

Measured when the schedule drift and status page benchmarks were built, and
quoted here as recorded then:

- Schedule drift: 1 000 HTTP monitors at a 60-second interval on 2 CPUs, maximum
  drift 23 ms.
- Status pages: 300 pages, warm p95 about 1.9 ms, 0 database queries in the
  warm pass.

## What is not measured

- Concurrent clients: every benchmark sends one request at a time, so the
  numbers are latencies of a single client, not throughput limits.
- MySQL inside the image: the scale benchmark ran on SQLite and PostgreSQL only;
  MySQL was measured for the import only, on the host.
- Several check workers or several hosts: all runs used one check worker.
- Slow or failing targets during the scale run: every check there answers
  locally in milliseconds, so time-outs, retries and alert delivery are not
  exercised, and no notification was sent (the channels are inactive).
- Live updates: no browser was connected to Reverb during the runs.
- Long-term behaviour: retention pruning, rollup rebuilds and database growth
  over days or weeks.
- The admin single-page application's rendering in the browser; only the API
  behind it is measured.
- Imports from a Kuma 1.23 JSON backup or a MariaDB source, and imports with
  heartbeat history.

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
