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
| Date | 2026-11-08; schedule drift and status pages 2026-11-09 |

Runs "inside the image" execute in a container of the image on the Docker VM.
Runs "on the host" execute PHP on macOS against the database containers; the
host has more CPU than the VM, so those numbers are not comparable to the
image runs.

The image runs were repeated after the security fixes. Other containers shared
the Docker VM during them, among them an IntraWatch instance checking 1 000
monitors, so single values carry some noise; a run with one more such instance
on the VM tripled the warm status page p95 on SQLite (16.7 ms) and was repeated.

## Scale: 5 000 monitors and 300 status pages

```bash
docker exec <container> php artisan intrawatch:bench:scale \
  --monitors=5000 --pages=300 --items=20 --requests=2000 --base-url=http://127.0.0.1:3000
```

The container runs the image's normal services (web server, one check worker,
queue workers, Reverb) with `TRUSTED_PROXIES=127.0.0.1`. The checks reach
loopback without any network policy switch, because `CHECK_BLOCKED_NETWORKS`
blocks only cloud metadata addresses by default. The benchmark seeds
5 000 active monitors (3 000 HTTP, 500 each of TCP, ping, DNS and push) in 250
groups (50 top-level, 200 nested), 50 tags, 15 inactive channels, 90 days of day
and 48 hours of hour rollups per monitor, 300 published pages of 20 items, 2 000
alerts, 200 incidents, 50 maintenances and 2 000 audit events. The checks target
the container itself (`/robots.txt`, port 3000, `127.0.0.1`, `localhost`) at a
60-second interval, push monitors expect a ping every hour. After 60 seconds of
settling it sends the requests one at a time over HTTP: 2 000 admin list
requests (200 per list, `limit=100`, bearer token), one request per status page
(cold), then 2 000 round-robin status page requests (warm), each status request
with an `X-Forwarded-For` address of its own. No request failed. The check
worker kept running throughout the measurement: it recorded 4 675 checks in
56.8 s on SQLite and 9 350 in 83.1 s on PostgreSQL.

> **Warning:** `TRUSTED_PROXIES=127.0.0.1` is a setting for the benchmark
> container only. It makes the application trust the `X-Forwarded-For` header
> that clients send, which defeats the per-IP rate limit of the public status
> pages. Never set it in production unless a real reverse proxy at that address
> sets the header.

Thresholds: admin p95 below 500 ms per list, warm status page p95 below 100 ms.
Both runs passed.

### SQLite, inside the image

Checks recorded while measuring: 4 675 in 56.8 s.

| Kind | Endpoint | Requests | p50 (ms) | p95 (ms) | Max (ms) |
|---|---|---:|---:|---:|---:|
| admin | monitors, top level (`parent_id=null`) | 200 | 20.05 | 33.33 | 124.74 |
| admin | monitors, search (`q=api`) | 200 | 26.39 | 48.60 | 74.97 |
| admin | monitors, status filter (`status=up`) | 200 | 18.48 | 32.07 | 88.60 |
| admin | monitors, tag filter | 200 | 25.92 | 39.52 | 95.26 |
| admin | group children | 200 | 7.70 | 13.95 | 76.34 |
| admin | alerts | 200 | 12.90 | 23.67 | 137.46 |
| admin | status pages | 200 | 9.18 | 15.49 | 27.49 |
| admin | incidents | 200 | 10.70 | 17.57 | 35.34 |
| admin | maintenances | 200 | 11.78 | 19.93 | 94.77 |
| admin | audit | 200 | 8.75 | 14.33 | 47.20 |
| status | cold | 300 | 42.00 | 60.88 | 151.72 |
| status | warm | 2 000 | 3.74 | 6.37 | 72.79 |

### PostgreSQL 16, inside the image

PostgreSQL ran in its own container on the same Docker VM, so both shared the
two vCPUs. Checks recorded while measuring: 9 350 in 83.1 s.

| Kind | Endpoint | Requests | p50 (ms) | p95 (ms) | Max (ms) |
|---|---|---:|---:|---:|---:|
| admin | monitors, top level (`parent_id=null`) | 200 | 32.19 | 46.73 | 148.77 |
| admin | monitors, search (`q=api`) | 200 | 36.52 | 53.86 | 110.40 |
| admin | monitors, status filter (`status=up`) | 200 | 28.17 | 41.79 | 65.97 |
| admin | monitors, tag filter | 200 | 37.59 | 54.35 | 92.74 |
| admin | group children | 200 | 17.75 | 27.66 | 146.70 |
| admin | alerts | 200 | 22.63 | 36.25 | 72.73 |
| admin | status pages | 200 | 17.98 | 28.99 | 54.27 |
| admin | incidents | 200 | 21.08 | 30.82 | 47.67 |
| admin | maintenances | 200 | 21.95 | 39.74 | 74.33 |
| admin | audit | 200 | 17.57 | 27.56 | 62.71 |
| status | cold | 300 | 55.37 | 82.15 | 228.04 |
| status | warm | 2 000 | 3.96 | 7.84 | 126.35 |

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
| Generate the source database | 1.08 | 1.11 | 1.04 |
| Tags | 0.03 | 0.26 | 0.16 |
| Notifications | 0.04 | 0.10 | 0.08 |
| Monitors | 19.86 | 64.12 | 70.92 |
| Status pages | 2.21 | 6.12 | 6.62 |
| Incidents | 0.00 | 0.00 | 0.00 |
| Maintenances | 0.00 | 0.00 | 0.00 |
| History | 13.78 | 39.94 | 22.99 |
| **Import total** | **36.10** | **110.81** | **100.98** |
| Peak memory (MB) | 103.0 | 103.0 | 103.0 |

The peak memory is the PHP process's, including the test framework (52.5 MB
on SQLite and 54.5 MB on MySQL and PostgreSQL before the import started).
Re-run for 1.0.0 after the push token change in the importer; the totals are
within 4–8% of the previous run, which is the noise level of this machine.

## Schedule drift: 1 000 HTTP monitors

```bash
docker run --rm --cpus=2 intrawatch:bench-rel \
  php artisan intrawatch:bench --monitors=1000 --interval=60 --duration=180
```

One check worker, SQLite, inside the image, against a local target that
answers in 50 to 300 ms. Passed.

Re-run for 1.2.0, which broadcasts group evaluations from every recompute
path, back to back with the published 1.1.1 image under the same load. The
pair shows no difference between the versions.

| Metric | 1.2.0 | 1.1.1 |
|---|---:|---:|
| Checks | 3 000 (expected at least 3 000) | 3 000 |
| Checks not up | 0 | 0 |
| Checks per minute | 999.7 | 999.9 |
| Drift p50 | 4 ms | 4 ms |
| Drift p95 | 11 ms | 11 ms |
| Drift max | 44 ms | 91 ms |
| Longest result flush | 69 ms | 61 ms |

## Status pages: 300 pages, in-process

```bash
docker run --rm --cpus=2 intrawatch:bench-rel \
  php artisan intrawatch:bench:status --pages=300 --items=20 --requests=3000
```

SQLite, inside the image, requests sent through the application in-process (no
web server). The warm pass mixes the page, its feeds, summary and badge.
Passed.

Re-run for 1.2.0, which hatches major outage days in the status page bars,
back to back with the published 1.1.1 image, as above. The warm p95 of 1.2.0
is 0.8 ms higher in this single run on the shared VM; both stay far below the
100 ms threshold.

| Version | Pass | Requests | p50 (ms) | p95 (ms) | Max (ms) |
|---|---|---:|---:|---:|---:|
| 1.2.0 | cold | 300 | 39.78 | 52.88 | 221.51 |
| 1.2.0 | warm | 3 000 | 0.90 | 2.06 | 146.91 |
| 1.1.1 | cold | 300 | 40.37 | 55.71 | 232.02 |
| 1.1.1 | warm | 3 000 | 0.87 | 1.25 | 23.43 |

Database queries during the warm pass: 0 for both.

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
- Network latency: the scale latencies include the overhead of the serial PHP
  HTTP client running inside the same VM as the application.
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
