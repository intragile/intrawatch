# IntraWatch

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

A free, self-hosted uptime monitor and status page. One container, no external
services required, all state in a single volume.

## Quick start

```bash
docker run -d --name intrawatch --restart unless-stopped -p 3000:3000 \
  -v intrawatch-data:/data ghcr.io/intragile/intrawatch:latest
```

The registry image is not published yet. Until then, build it locally:

```bash
docker build -f docker/Dockerfile -t intrawatch .
docker run -d --name intrawatch --restart unless-stopped -p 3000:3000 \
  -v intrawatch-data:/data intrawatch
```

Keep the restart policy: when one of the internal programs (web server, queue
workers, check workers, scheduler, websocket server) cannot be kept running,
the container exits with a non-zero status so that Docker replaces it.

Then finish the first-run setup, which creates the owner account and the first
workspace. The endpoint accepts a single use only, and it logs you in with a
cookie session, so it is called like the web frontend would: fetch the CSRF
cookie first, then send it back as `X-XSRF-TOKEN` with an `Origin` that matches
`APP_URL`:

```bash
curl -s -c jar.txt -b jar.txt -H 'Origin: http://localhost:3000' \
  http://localhost:3000/sanctum/csrf-cookie
XSRF="$(printf '%b' "$(awk '$6 == "XSRF-TOKEN" { print $7 }' jar.txt | sed 's/%/\\x/g')")"
curl -s -c jar.txt -b jar.txt -X POST http://localhost:3000/api/v1/setup \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -H 'Origin: http://localhost:3000' -H "X-XSRF-TOKEN: $XSRF" \
  -d '{"name":"Ada Owner","email":"ada@example.com","password":"at-least-10-chars","workspace_name":"Acme Ops"}'
curl -s -b jar.txt -H 'Origin: http://localhost:3000' -H 'Accept: application/json' \
  http://localhost:3000/api/v1/me
```

A request without a session (no matching `Origin`, for example) is answered
with `400` and `urn:problem:session-unavailable` rather than a login that does
not stick. `GET /api/v1/setup` reports whether setup is still required.

Further users are created from the command line; `--owner-of` makes the user an
owner of the workspace with that slug (created when it does not exist yet), and
the password is prompted for when `--password` is left out:

```bash
docker exec -it intrawatch php artisan intrawatch:user:create bob@example.com \
  --name="Bob Example" --owner-of=acme-ops
```

The API reference is served at `/api/v1/openapi.json`. The container
healthcheck (`/usr/local/bin/healthcheck.sh`) requires both `GET /up` to answer
and every check worker of the container to have reported within the last 60
seconds (`php artisan intrawatch:worker:status --local`); each step has its own
time limit, so a stuck database marks the container unhealthy instead of
hanging the check. While the database is unreachable the container therefore
reports unhealthy, although the workers keep checking and buffer up to 10 000
results until it is back. A restart discards that buffer, so do not use this
healthcheck as a restart (liveness) trigger in an orchestrator, or give it a
failure threshold longer than the database outages you want to ride out.

### Recovery

```bash
# Lost authenticator and recovery codes: disable two-factor authentication.
docker exec -it intrawatch php artisan intrawatch:user:reset-2fa ada@example.com
# Forgotten password: set a new one (prompted for) and end the user's sessions.
docker exec -it intrawatch php artisan intrawatch:user:reset-password ada@example.com
```

Both are recorded in the audit trail with the `system` actor. Note that the
workspace audit list only shows workspace events; instance-level events such
as `auth.*` (logins, failed logins, 2FA and password resets) are stored with no
workspace and are not exposed over the API yet.

### What lives in `/data`

| Path | Content |
| --- | --- |
| `app.key` | Application key, generated on first start (mode 0600) |
| `reverb.env` | Generated websocket credentials |
| `database.sqlite` | The database, when `DB_CONNECTION=sqlite` (default) |
| `storage/` | Uploads and framework files |

Back up this volume and you have backed up everything. `app.key` matters even
when the database is external: it encrypts the two-factor secrets and recovery
codes stored in the database, so a database backup without it cannot restore
2FA logins. Logs are written to the container output (`docker logs`), not to
the volume.

The container runs as a non-root user (uid 1000); when bind-mounting a host
directory, make it writable for that uid (`chown -R 1000:1000 <dir>`), or the
container stops at start with a message saying so. Only port 3000 is exposed;
websockets are proxied internally.

### Configuration

| Variable | Default | Purpose |
| --- | --- | --- |
| `APP_URL` | `http://localhost:3000` | Public URL, used in password-reset links. Its host is a stateful (cookie session) domain by default |
| `APP_KEY` | generated | Override the persisted application key |
| `DB_CONNECTION` | `sqlite` | `sqlite`, `mysql` or `pgsql` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | | External database settings |
| `TRUSTED_PROXIES` | none | Comma-separated proxy IPs/CIDRs, or `*`. Set it behind a reverse proxy so client IPs are correct for rate limits and the audit log |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost:3000,127.0.0.1:3000` plus the `APP_URL` host | Hosts (with port) of the web frontend that use cookie sessions; setting it replaces the default list |
| `SESSION_SECURE_COOKIE` | unset | Set to `true` when the instance is served over HTTPS (behind a TLS-terminating proxy), so session cookies are only sent over TLS |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | generated | Websocket credentials |

A ready-made `docker/compose.example.yml` shows the SQLite default and
commented-out PostgreSQL and MySQL services.

### Monitors

| Type | Checks |
| --- | --- |
| `http` | An HTTP(S) request: accepted status codes, keyword or JSON query, response headers, redirects, basic or bearer auth, mTLS, an optional HTTP or SOCKS proxy, and TLS validity of the endpoint |
| `tcp` | A TCP connection to a host and port |
| `ping` | ICMP echo (1 to 5 packets), sent without privileges through unprivileged ICMP sockets |
| `dns` | A DNS record (A, AAAA, CNAME, MX, NS, TXT, SOA, SRV, CAA, PTR), optionally against a given resolver and expected values, and watching for changes |
| `tls` | The certificate of a TLS endpoint (with optional SMTP, IMAP or POP3 STARTTLS): expiry thresholds, chain, protocols and ciphers |
| `domain` | Domain registration expiry through RDAP, with WHOIS as the fallback (interval at least one hour) |
| `push` | A heartbeat: the monitored job calls the monitor's `push_url`; a missing call within interval plus grace counts as a failure |
| `cron` | A push monitor on a cron schedule, with start and finish reports and an optional maximum runtime |
| `group` | Aggregates its child monitors (`all`, `any` or a quorum) |

The interval is 20 seconds to 24 hours (60 seconds by default, one hour for
`tls` and `domain`). `GET /api/v1/monitor-types` lists every type with its
configuration fields and defaults.

`ping` needs no `NET_RAW` capability: it uses unprivileged ICMP sockets, which
Docker allows by default (`net.ipv4.ping_group_range`). Other container
runtimes may need that sysctl set to include uid 1000, for example
`net.ipv4.ping_group_range=0 2147483647`.

Checks may not connect to the networks in `CHECK_BLOCKED_NETWORKS` (CIDR list,
by default the cloud metadata addresses `169.254.169.254/32` and
`fd00:ec2::254/128`); add your internal ranges there if workspace members must
not probe them. When an HTTP monitor uses a proxy, only the proxy's address is
checked against this list: the proxy resolves and connects to the target
itself, so its own egress rules apply to the target.

Push and cron reports are rate limited per token (120 per minute) in the
application cache (`CACHE_STORE`, the database by default). With several
containers behind one address, keep the cache on a store they share, such as
the shared database or Redis, so that the limit applies across all of them.

The `push_url` contains the push token, which is all it takes to report a
monitor up, down or running. The API returns it only to Editors and above, and
to API tokens that hold `monitors:write`; Viewers and read-only tokens get
`null` and `has_push_token: true`. Credential headers (`Authorization`,
`Proxy-Authorization`, `Cookie`, `X-Api-Key`, `X-Auth-Token`) are refused in an
HTTP monitor's plain `headers`, which the API returns; put them in
`secret_headers`.

While the database fails, the reports that push and cron monitors send may be
refused, so the worker does not judge their deadlines on its own. A failure of
deadline reads or result writes that lasts at least 30 seconds counts as an
outage: deadlines that fell due during it are not reported, for however long
it lasts and whether reads, writes or both failed, and they are counted again
from the moment the database came back (a full interval plus grace for a push
monitor, the next scheduled run for a cron job). A miss already recorded before
the outage stays recorded; the next retry then falls due one retry interval
after the recovery. Shorter failures, such as a deadlock or a busy database,
move nothing. The same applies to deadlines that passed while the worker itself
was not running. Text a checked target sends back (a response body, a DNS
record, a push message) is stored with NUL bytes and other control characters
removed and invalid UTF-8 replaced by `?`.

### Check workers and scaling

Every container runs `CHECK_WORKERS` check worker processes. Monitors are split
into `CHECK_SHARDS_TOTAL` shards by id, and each worker runs one shard: the
workers of a container run the shards `CHECK_SHARD_OFFSET` to
`CHECK_SHARD_OFFSET + CHECK_WORKERS - 1`.

| Variable | Default | Purpose |
| --- | --- | --- |
| `CHECK_WORKERS` | `1` | Check worker processes in this container (1 to 32) |
| `CHECK_SHARD_OFFSET` | `0` | First shard this container runs |
| `CHECK_SHARDS_TOTAL` | `CHECK_WORKERS` | Number of shards across all containers |
| `CHECK_CONCURRENCY` | `200` | Checks one worker runs at the same time |
| `CHECK_BLOCKED_NETWORKS` | `169.254.169.254/32,fd00:ec2::254/128` | Networks checks may not connect to |

One worker handles about 1 000 HTTP monitors at a 60-second interval on two
CPU cores with less than two seconds of delay from the schedule
(`php artisan intrawatch:bench`, see Development). Add workers for more.

To spread the checks over several hosts, every container must use the same
external MySQL or PostgreSQL database (SQLite cannot be shared between hosts)
and a shared cache store, all must agree on `CHECK_SHARDS_TOTAL`, and together
they must cover every shard exactly once. For example, two hosts with two
workers each:

```bash
# host A runs shards 0 and 1
-e CHECK_WORKERS=2 -e CHECK_SHARD_OFFSET=0 -e CHECK_SHARDS_TOTAL=4
# host B runs shards 2 and 3
-e CHECK_WORKERS=2 -e CHECK_SHARD_OFFSET=2 -e CHECK_SHARDS_TOTAL=4
```

The container refuses to start when `CHECK_SHARD_OFFSET + CHECK_WORKERS`
exceeds `CHECK_SHARDS_TOTAL`. `php artisan intrawatch:worker:status` without
options checks that every shard of the installation has reported, which is
useful for monitoring the whole cluster from one place.

### Queues

Two queue workers run in the container: one for regular jobs (such as live
update broadcasts) and one for the `long` queue connection, which runs the day
rollup rebuilds after a timezone change (up to 900 seconds each, with
`retry_after` 960 seconds). The separate worker keeps a long rebuild from
delaying regular jobs. Outside the image, run both:

```bash
php artisan queue:work
php artisan queue:work long --queue=long --timeout=900
```

### Stopping the container

On `docker stop` every program stops in parallel. Check workers wait up to
4 seconds for checks in flight and up to 2 more to write buffered results, and
every program is killed after 8 seconds at the latest, so the container stops
within Docker's default 10-second grace period. Keep at least that grace
period (`docker stop -t`, or `stop_grace_period` in Compose; the example file
sets `stop_grace_period: 10s`). A database write already in progress, or a
database that does not answer, can take longer than these limits; results
still buffered when a worker is killed are lost. A day rollup rebuild that is still running when
the container stops is left partly done (each day is replaced atomically, so
no day is counted twice) and is not resumed; change the timezone again to
rebuild the remaining days.

### SQLite on network storage

SQLite runs in WAL mode, which needs shared memory and is not safe on network
file systems such as NFS or SMB. When `/data` lives on one, set
`DB_SQLITE_JOURNAL_MODE=DELETE`, or use MySQL or PostgreSQL.

### History, rollups and retention

Every check result is stored raw and aggregated once a minute
(`intrawatch:rollup`) into minute, hour and day rollups. Pruning runs hourly at
minute 30 (`intrawatch:prune`) and deletes in chunks with a short pause between
them, so it never locks the result tables for long.

| Variable | Default | Kept data |
| --- | --- | --- |
| `RETENTION_RAW_HOURS` | `24` | Raw check results |
| `RETENTION_MINUTE_HOURS` | `48` | Minute rollups |
| `RETENTION_HOUR_DAYS` | `90` | Hour rollups |
| `RETENTION_DAY_DAYS` | `730` | Day rollups and monitor events |
| `RETENTION_CHUNK_SIZE` | `5000` | Rows deleted per statement while pruning |
| `RETENTION_CHUNK_SLEEP_MS` | `50` | Pause between delete statements |

Cached domain registration lookups are dropped after 30 days without a check.
`RETENTION_RAW_HOURS` is at least 1 and `RETENTION_MINUTE_HOURS` at least 25
(a whole local day, so day rollups stay exact); smaller values are raised to
these minimums.

Results that reach the database late, such as outcomes a worker buffered
during a database outage, are still counted: each rollup run also recomputes
the minutes, hours and days of results inserted since the previous scan,
about two minutes after they were inserted (so rows that commit out of order
on MySQL or PostgreSQL are not missed), at most 50 000 results per run; a
larger backlog is worked off over the next runs. Results older than the raw
retention when they arrive are not counted. If the rollup state in the cache
is lost, rollups resume five minutes before the newest minute rollup, and late
results are tracked again from about two minutes later; nothing already being
tracked is skipped.

Day rollups start at midnight in the workspace timezone. Changing the timezone
rebuilds the day rollups in the background from the data still retained, so
days older than the retained data keep the boundaries of the old timezone:

- days within the minute retention (48 hours by default) are rebuilt exactly;
- older days are summed from hour rollups. That is exact for timezones with a
  whole-hour offset; for offsets such as +05:30 or +05:45 those days are shifted
  by the sub-hour part of the offset;
- days older than the hour retention (90 days by default) keep the boundaries of
  the timezone they were built in; the one old day that would overlap the first
  rebuilt day is dropped.

The rebuild replaces one day at a time, so day rollups never overlap, even if
the rebuild is interrupted. While it runs, only that workspace's day rollups
pause; they catch up afterwards. Rebuilds run on the `long` queue connection
(`retry_after` 960 s, above the job's 900 s timeout); the image runs a worker
for it (see Queues).

Availability is `(up + degraded + pending) / (total - maintenance)`; it is
empty when every check of the period fell into a maintenance window.

## Development

```bash
composer install
php artisan test
composer analyse
vendor/bin/pint --test
bash tests/docker/smoke.sh          # image smoke test (add --pgsql for PostgreSQL)
```

The schedule drift benchmark seeds a throwaway workspace with HTTP monitors
against a local target that answers in 50 to 300 ms, runs one check worker and
prints the median, 95th percentile and maximum delay from the schedule, the
checks per minute and the longest result flush. It exits with 1 when the
maximum delay reaches two seconds, when fewer than 95% of the expected checks
ran, or when any check was not up. Run it in a container of its own with a
fresh volume and the default SQLite database: it refuses to run next to a live
check worker, and against a database that already holds workspaces unless
`--force` is given. Ctrl-C or `docker stop` ends the run early and still
deletes the benchmark workspace.

```bash
docker run --rm --cpus=2 intrawatch \
  php artisan intrawatch:bench --monitors=1000 --interval=60 --duration=180
```

A command passed to `docker run` runs after the usual setup instead of the
services. That setup migrates the configured database with the image's own
version; set `INTRAWATCH_SKIP_MIGRATE=1` to run a one-off command against a
database another version manages.

Guzzle is pinned to 7.x (`guzzlehttp/guzzle ^7.9`): Laravel Reverb requires
`guzzlehttp/psr7 ^2.6`, while Guzzle 8 needs psr7 3.x. Lift the pin once a
Reverb release allows psr7 3.

Licensed under the MIT license. Contact: info@intragile.eu

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
