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

Every workspace in `/api/v1/me` and `/api/v1/workspaces` carries `channel` and
`can`. `channel` is the private Reverb channel for live monitor updates
(`workspace.{id}`; `null` for a token without `monitors:read`), so clients take
the name from it instead of building it. `can` maps the workspace abilities
(`view`, `update`, `manageMonitors`, ...) to whether the caller holds them; for
an API token it is also limited to the token's own abilities. Password-reset
e-mails link to `{APP_URL}/admin/reset-password/{token}`.

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

Three queue workers run in the container: one for regular jobs (such as live
update broadcasts and alert evaluation), one for the `notifications` queue,
which sends alerts to notification channels, and one for the `long` queue
connection, which runs the day rollup rebuilds after a timezone change (up to
900 seconds each, with `retry_after` 960 seconds). The separate workers keep a
slow notification provider or a long rebuild from delaying regular jobs.
Outside the image, run all three:

```bash
php artisan queue:work
php artisan queue:work --queue=notifications --tries=5 --timeout=30
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
| `RETENTION_ALERT_DAYS` | `90` | Alert history and deliveries |
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

### Notifications

Monitors alert through notification channels. A channel belongs to a
workspace; a monitor is bound to channels with `notification_channel_ids`
(up to 50). A new monitor created without that field is bound to every active
channel marked `is_default`; `apply_to_existing: true` on a channel create or
update binds it to every existing monitor of the workspace.

| Endpoint | Ability |
| --- | --- |
| `GET /api/v1/notification-channel-types` | any signed-in user |
| `GET /api/v1/workspaces/{workspace}/notification-channels`, `GET /api/v1/workspaces/{workspace}/notification-channels/{channel}` | `notifications:read` |
| `POST /api/v1/workspaces/{workspace}/notification-channels`, `PATCH`/`DELETE /api/v1/workspaces/{workspace}/notification-channels/{channel}` | `notifications:write` |
| `POST /api/v1/workspaces/{workspace}/notification-channels/test` (unsaved), `POST /api/v1/workspaces/{workspace}/notification-channels/{channel}/test` | `notifications:write` (10 per minute per user) |
| `GET /api/v1/workspaces/{workspace}/alerts` | `notifications:read` |
| `GET /api/v1/workspaces/{workspace}/monitors/{monitor}/alerts` | `monitors:read` and `notifications:read` |

API tokens need `notifications:read` (Viewers and above) to read channels and
the alert history, and `notifications:write` (Editors and above) to manage and
test channels. Binding channels to monitors is part of the monitor API
(`monitors:write`).

Incidents and maintenance windows use `incidents:read` (Viewers and above) and
`incidents:write` (Editors and above); status pages use `status-pages:read`
(Viewers and above) and `status-pages:write` (Editors and above).

#### Channels

Keys marked * are required. Secrets are write-only: the API returns only their
names in `secret_keys`, and the audit log records only those names. On an
update, omitted secrets are kept and a secret set to `null` is removed.
`GET /api/v1/notification-channel-types` lists every type with its fields and
defaults.

| Type | `config` | `secrets` |
| --- | --- | --- |
| `email` | `to`* (list of addresses), `smtp` (`host`, `port`, `encryption`: `starttls`, `tls` or `none`, `username`, `from_address`, `from_name`) | `smtp_password` |
| `webhook` | `url`*, `method` (`POST` or `PUT`), `format` (`json` or `form`), `headers` | `secret_headers`, `signing_secret` (HMAC-SHA256 in `X-IntraWatch-Signature: t=…,v1=…`) |
| `slack` | | `webhook_url`* |
| `discord` | `username`, `thread_id` | `webhook_url`* |
| `teams` | | `webhook_url`* |
| `mattermost` | `channel`, `username` | `webhook_url`* |
| `google_chat` | | `webhook_url`* |
| `telegram` | `chat_id`*, `message_thread_id`, `server_url` (`https://api.telegram.org`), `silent` | `bot_token`* |
| `ntfy` | `server_url`*, `topic`*, `priority` | `access_token` |
| `gotify` | `server_url`*, `priority` | `app_token`* |
| `pushover` | `priority`, `sound`, `device` | `user_key`*, `app_token`* |
| `matrix` | `homeserver_url`*, `room_id`* | `access_token`* |
| `pagerduty` | | `routing_key`* (Events API v2) |
| `opsgenie` | `region`* (`us` or `eu`), `priority` (`P1` to `P5`) | `api_key`* |
| `apprise` | `server_url`*, `config_key` | `urls` (either `config_key` or `urls` is required) |

A webhook receives one JSON (or form) request per alert with `event` (the
alert kind), `severity`, `title`, `body`, `monitor` (`id`, `name`, `type`,
`target`, `url`), `status`, `previous_status`, `message`, `at`,
`duration_seconds`, `changes` and `workspace`, plus the headers
`X-IntraWatch-Event` and `X-IntraWatch-Delivery` (a stable id per delivery, for
deduplication).

E-mail channels without their own `smtp` block send through the application
mailer, configured with the usual Laravel variables:

```bash
-e MAIL_MAILER=smtp -e MAIL_HOST=smtp.example.com -e MAIL_PORT=587 \
-e MAIL_USERNAME=alerts@example.com -e MAIL_PASSWORD=… -e MAIL_SCHEME=smtp \
-e MAIL_FROM_ADDRESS=alerts@example.com -e MAIL_FROM_NAME=IntraWatch
```

`MAIL_TIMEOUT` (default `10`) is the application mailer's SMTP timeout in
seconds per socket operation. Keep it well below the 30-second timeout of the
notification send job, or a slow SMTP server makes the job time out instead of
failing and retrying cleanly.

The `apprise` channel needs an Apprise API server, which fans out to the
services Apprise supports, for example `docker run -d -p 8000:8000
caronc/apprise` and `server_url: http://apprise:8000`.

#### Alerts

Alert kinds: `down`, `up`, `degraded`, `still_down` (a reminder), `flapping`,
`stable`, `expiring` and `renewed` (certificate and domain expiry bands),
`changed` (certificate, DNS or other watched values) and `test`.

Per monitor, the `alerting` object sets:

| Setting | Default | Meaning |
| --- | --- | --- |
| `resend_interval_minutes` | `0` | Reminder (`still_down`) while down, 0 to 10 080; 0 turns reminders off |
| `notify_degraded` | `true` | Alert when the monitor is degraded |
| `degraded_alert_after_seconds` | `300` | How long degraded must persist before it alerts, 0 to 86 400 |
| `notify_changes` | `true` | Alert on certificate, DNS and expiry band changes |

A monitor that changes between up and down too often is flapping: it sends one
`flapping` alert, no further status alerts or reminders, and one `stable`
alert once it settles. A monitor whose parent group is down (and that group
alerts through an active channel) is suppressed: its alerts are recorded with
`suppressed_by_monitor_id` but not sent.

| Variable | Default | Purpose |
| --- | --- | --- |
| `ALERT_FLAP_THRESHOLD` | `4` | Status class changes within the window that start flapping (at least 3) |
| `ALERT_FLAP_WINDOW_SECONDS` | `600` | The flap detection window |
| `ALERT_FLAP_STABLE_SECONDS` | `600` | Time without a class change that ends flapping |
| `ALERT_DIGEST_THRESHOLD` | `5` | More alerts than this for one channel in one evaluation are sent as one digest (up to 200 alerts per message) |
| `RETENTION_ALERT_DAYS` | `90` | Alert history and delivery records |

PagerDuty, Opsgenie and webhook channels never receive digests: they always get
one event per alert, since they deduplicate per monitor. A send times out after
5 seconds to connect and 10 seconds in total, and failures are retried up to 5
times (after 10, 30, 120 and 300 seconds; a `Retry-After` of up to 300 seconds
on a 429 is honoured). A 4xx response other than 408 and 429 fails at once.
Error text from a provider is stored with every channel secret removed.

Alerts are transactional: the evaluation job is queued in the same database
transaction that records the check results, and the alert, its deliveries and
their send jobs are written in one transaction too. A committed result is
therefore always evaluated and a rolled-back one never alerts, and a retried
evaluation does not send an alert twice. This holds with the default database
queue on the application database; with any other queue the jobs are pushed
after commit, so a crash in between can lose them (a warning is logged once
per process).

#### Templates

A channel's optional `templates` (`title` up to 200 characters, `body` up to
4 000) replace the default text. They use logic-free `{{ variable }}`
substitution; unknown variables are rejected. Variables: `kind`, `kind_label`,
`severity`, `status`, `previous_status`, `message`, `at`, `at_local`,
`duration`, `duration_seconds`, `changes`, `monitor.id`, `monitor.name`,
`monitor.type`, `monitor.target`, `monitor.url` and `workspace.name`. Values
are escaped for each channel's format, so text from a checked target cannot
mention users (`@everyone`, `<!channel>`) or inject markup.

#### Alert history

`GET /api/v1/workspaces/{workspace}/alerts` lists alerts newest first, with
the filters `monitor_id`, `kind` and `since` (inclusive; an ISO 8601 date or a
date-time to the second with an offset, such as `2026-03-10T10:00:00Z`;
fractional seconds are rejected with a 422), and each
alert's `deliveries` (`channel`, `status`: `queued`, `sent` or `failed`,
`attempts`, `last_error`, `sent_at`). `monitor` is `null` once the monitor is
deleted and `channel` is `null` once the channel is deleted.
`GET /api/v1/workspaces/{workspace}/monitors/{monitor}/alerts` is the same
list for one monitor.

#### Notification targets and SSRF

`CHECK_BLOCKED_NETWORKS` applies to notification targets as well as checks: a
channel URL that resolves into a blocked network is refused when it is sent
(the address is resolved once and the connection pinned to it, and redirects
are never followed). The default blocks only the cloud metadata
addresses, so workspace members can point webhooks at internal services. To
forbid that, add the internal ranges, for example
`CHECK_BLOCKED_NETWORKS=169.254.169.254/32,fd00:ec2::254/128,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.0/8,::1/128,fc00::/7,0.0.0.0/8,::/128`.
Notification targets are always refused in `0.0.0.0/8` and `::/128`, which
connect to the local host; the last two entries extend that to checks.

### Maintenance windows

A maintenance mutes the monitors it targets for a period: their checks keep
running, but their status is `maintenance`, no alert is sent and the period does
not count as downtime. A maintenance belongs to a workspace and targets 1 to
1 000 of its monitors; targeting a group covers its children. It can also be
shown on up to 100 of the workspace's status pages (`status_page_ids`).

| Endpoint | Ability |
| --- | --- |
| `GET /api/v1/workspaces/{workspace}/maintenances` (`?state=active\|upcoming\|past\|all`), `GET /api/v1/workspaces/{workspace}/maintenances/{maintenance}` | `incidents:read` |
| `POST /api/v1/workspaces/{workspace}/maintenances`, `PATCH`/`DELETE /api/v1/workspaces/{workspace}/maintenances/{maintenance}`, `POST /api/v1/workspaces/{workspace}/maintenances/{maintenance}/end` | `incidents:write` |

| `strategy` | Fields | Window |
| --- | --- | --- |
| `once` | `starts_at`, optional `ends_at` | One window; without `ends_at` it stays open until `POST .../end` sets it to now |
| `interval` | `every_days` (1 to 3650), `start_time`, `duration_minutes`, optional `valid_from`/`valid_until` | Every N days, counted from `valid_from` (or the creation date) |
| `weekly` | `weekdays` (1 Monday to 7 Sunday), `start_time`, `duration_minutes` | On the given weekdays |
| `monthly` | `month_days` (1 to 31, or -1 for the last day), `start_time`, `duration_minutes` | On the given days of each month |
| `cron` | `cron`, `duration_minutes` | At every run of the cron expression |

`start_time` is a local `HH:MM`, `duration_minutes` is 1 to 10 080, and
`valid_from` / `valid_until` are local dates (`valid_until` inclusive).
Fields of other strategies are stored as null. A definition that could never
produce a window (a recurring one without start time or duration, an invalid
cron expression, empty `weekdays` or `month_days`, an `ends_at` that is not
after `starts_at`) is refused with a 422. `active: false` switches a
maintenance off without deleting it; only `once` maintenances can be ended
with `POST .../end`.

Times are interpreted in the maintenance's `timezone`, falling back to the
workspace timezone when it is null. Across daylight saving time a local start
time that does not exist (the spring-forward gap) moves forward by the gap, a
local time that occurs twice uses its first occurrence, and a window always
lasts its duration in elapsed minutes. Overlapping windows on one monitor mute
it for their union.

Each maintenance in the API carries `in_progress` and `next_window`
(`starts_at`, `ends_at`: the window in progress, or else the next one, looking
400 days ahead; null when none is left). The `state` filter uses the same
computation, so a listing narrowed by `state` selects the matching ids first and
then paginates them.

When a window starts or ends, or a maintenance is created, edited, ended or
deleted while it is in progress, scheduled monitors (HTTP, TCP, ping, DNS, TLS,
domain) are checked immediately and groups are recomputed, within seconds
(`intrawatch:maintenance:sync` runs every 15 seconds). Push and cron monitors
enter maintenance when the window starts, even with an outage already
reported, and return to their real status at their next ping or deadline check
after it ends. Alerts are muted for the whole window. An outage that is still
going on when the window ends is alerted
after it: the monitor goes from maintenance to down on its first check after
the end, a `Down` alert is sent if none was sent for that outage yet, and
reminders (`STILL DOWN`) resume.
A check result that is already in flight when a maintenance is written, up to
the worker's 5 second poll interval, may still be recorded against the previous
calendar; the next check corrects it, and a down, maintenance, down sequence
counts as one outage, so no extra alert is sent.

### Status pages

A status page shows a curated set of monitors to the public: an overall banner,
maintenance in progress, open incidents with their updates, sections of
components (status, uptime, day bars and an optional 24-hour latency line;
expanded groups list their children), upcoming maintenance and past
incidents. Pages are managed with `GET`/`POST /api/v1/workspaces/{workspace}/status-pages`,
`PATCH`/`DELETE .../status-pages/{statusPage}` and `PUT .../status-pages/{statusPage}/layout`
(`status-pages:read` / `status-pages:write`).

| Address | Serves |
| --- | --- |
| `{APP_URL}/status/{slug}` | The page, on any host name of the installation |
| `https://{custom_domain}/` | The page, when the request's `Host` is the page's custom domain |
| `{APP_URL}/status/{slug}/feed.atom`, `/feed.rss` | Atom and RSS feeds of incidents and maintenance |
| `{APP_URL}/status/{slug}/summary.json` | JSON summary, readable from any web page (CORS) |
| `{APP_URL}/status/{slug}/badge.svg`, `/badge/{monitor_id}/{kind}.svg` | Status, uptime and latency badges |

On a custom domain the same endpoints live at `/feed.atom`, `/feed.rss`,
`/summary.json`, `/badge.svg` and `/badge/{monitor_id}/{kind}.svg`.

Only published pages are served; an unpublished page answers exactly like a
page that does not exist (404). Nothing else is ever shown: no monitor
targets, URLs, error messages, check results or monitors that are not on the
page. The page is plain HTML with inline CSS and SVG, no JavaScript and no
external fonts or stylesheets, reloads itself every 60 seconds, and is
rendered in the page's locale (`en` or `hu`) with times in the workspace
timezone. Public pages set no cookies and are limited to 300 requests per
minute per client address.

**Custom domain.** Point the domain's DNS at your reverse proxy, terminate
TLS there (automatic certificates for custom domains are planned for a later
release) and forward the requests to the container with the original `Host`
header. A host that is a published page's custom domain serves only the
status paths (`/`, and the feed, summary and badge paths); everything else on
it, including the API and the admin, is a 404. Any other host name or IP
address reaches the application as usual. Canonical and feed links always use
the page's own address (`https://{custom_domain}` or `APP_URL`), never the
request's `Host`.
Behind a reverse proxy, set `TRUSTED_PROXIES` so the client address is taken
from the proxy's forwarding headers; otherwise every visitor counts against
the proxy's single 300 per minute bucket. The limit is approximate: it is
counted in the file cache without atomic increments.

**Caching.** Rendered pages are kept in a file cache
(`STATUS_PAGE_CACHE_PATH`, under `/data` in the image). A cached page is
served without any database query until it is 60 seconds old
(`status.cache.ttl_seconds`), a maintenance window on the page starts or ends,
the page or something it shows is edited, or a monitor of the workspace
changes status; then the next request renders it again, while concurrent
requests keep getting the previous copy. Responses carry
`Cache-Control: public, max-age=30, stale-while-revalidate=60, stale-if-error=86400`
and a strong `ETag` (`If-None-Match` gives a 304). If the database fails during
a render, the last good copy is served with `X-Status-Stale: 1` and the
database is tried again at most every 30 seconds; the copy is kept
for 7 days (`status.cache.stale_seconds`). With no copy at all the page answers
503 with `Retry-After: 30`. A failure to connect to the database (not an error
in a single query) also stops every other public request that would need the
database from trying it for 30 seconds:
those answer from their cached copy or with the 503 at once. Database
connections give up after 3 seconds (`DB_CONNECT_TIMEOUT`), so an unreachable
database host cannot hold requests for minutes.

Editing or deleting an incident, an incident update, a maintenance, a monitor
or a page layout drops the affected pages' last good copies as well, so removed
content never reappears during an outage; such a page answers 503 until it can
be rendered again.

Two changes reach a cached page only through the 60 second TTL: a maintenance
that targets an ancestor group of a monitor on the page (rather than the
monitor or its direct group), and a change of the workspace timezone.

Only workspace admins can set or change a page's custom domain, and it can be
neither the host of `APP_URL` nor the host the API request was sent to.

#### Badges

Badges are small SVG images for a README or a website. The overall badge
shows the page title and its status; a component badge shows one monitor of
the page (the monitor id is the number in the API), and answers 404 for a
monitor that is not shown on that published page.

| Path | Shows |
| --- | --- |
| `/status/{slug}/badge.svg` | Page title and overall status |
| `/status/{slug}/badge/{monitor_id}/status.svg` | Current status of the component |
| `/status/{slug}/badge/{monitor_id}/uptime.svg` | Uptime percentage |
| `/status/{slug}/badge/{monitor_id}/latency.svg` | Average response time of the last 24 hours (`n/a` without data) |

Query parameters, all optional; an invalid value falls back to its default
instead of breaking the image:

* `days`: uptime window, one of `1`, `7`, `30` (default), `90`, `365`.
* `label`: replaces the left-hand text, at most 40 characters. The text is
  escaped.
* `style`: `flat` (default) or `flat-square`.

```markdown
![status](https://watch.example.com/status/acme/badge.svg)
![website uptime](https://watch.example.com/status/acme/badge/42/uptime.svg?days=90&label=website)
```

Colours: operational green, degraded yellow, partial outage orange, major
outage red, maintenance blue, unknown grey; uptime is green from 99.9%,
yellow-green from 99%, yellow from 95% and red below. Uptime and latency are
hidden (`n/a`) for a component whose uptime is hidden on the page or whose
page hides latency. Badges are served with `Cache-Control: public, max-age=60`.

#### Feeds and JSON summary

`feed.atom` (Atom 1.0) and `feed.rss` (RSS 2.0) list the newest 50 incidents
and maintenance windows of the page, each with every update in order and a
link of the form `{page address}#incident-{id}` or `#maintenance-{id}`. The
feeds cover incidents open or resolved within the page's history setting and
maintenance that is in progress, upcoming within its window setting, or ended within the last 30 days.
A maintenance is a single entry: its window in progress or upcoming, otherwise
its latest window that ended within those 30 days. Earlier windows of a
recurring maintenance are not listed.

`summary.json` is meant for status widgets on other sites and is sent with
`Access-Control-Allow-Origin: *`:

```json
{
  "page": {"title": "Acme", "url": "https://watch.example.com/status/acme", "status": "operational", "updated_at": "2026-11-07T12:30:00Z"},
  "components": [{"id": 42, "name": "Website", "status": "operational"}],
  "incidents": [{"id": 7, "title": "Slow site", "impact": "minor", "status": "monitoring", "url": "https://watch.example.com/status/acme#incident-7"}],
  "maintenances": [{"id": 3, "title": "Database upgrade", "starts_at": "2026-11-08T12:30:00Z", "ends_at": "2026-11-08T13:30:00Z"}]
}
```

`incidents` lists the open incidents; `children` appears only for expanded
groups. Feeds, the summary and the badges are cached like the page, share its
invalidation, and are served without database queries while cached.

## Development

```bash
composer install
php artisan test
composer analyse
vendor/bin/pint --test
bash tests/docker/smoke.sh          # image smoke test (add --pgsql for PostgreSQL)
```

### Admin interface

The admin interface is a Vue single-page application in `spa/` (Node.js 22 or
later). The server sends its shell for every path under `/admin/`, with the
runtime configuration in a `<meta name="intrawatch-config">` tag, and its
fingerprinted files from `/admin/assets/`. Without a build, `/admin/` answers
503.

```bash
cd spa
npm ci
npm run dev       # Vite dev server on http://localhost:5173/admin/
npm run verify    # lint, type check, unit tests and production build
```

The dev server proxies `/api`, `/sanctum`, `/broadcasting` and the `/app`
websocket to `VITE_BACKEND` (default `http://127.0.0.1:8000`, i.e.
`php artisan serve`). Point it at a running image
(`VITE_BACKEND=http://localhost:3000`) to get live updates through the image's
Reverb proxy as well. `npm run build` writes `spa/dist`, the directory the
`intrawatch.spa_path` setting points to.

The image builds the application in a Node.js stage of `docker/Dockerfile`
(`npm ci` and `npm run build`) and copies only `spa/dist` into the runtime
image; Node.js is not part of the final image.

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

The status page benchmark seeds a throwaway workspace with 300 published
pages of 20 components each and 90 days of rollups, then sends requests
through the application in-process: one page request per page for the cold pass
(every page rendered once), then 3 000 round-robin for the warm pass (the page,
its feeds, summary and badge, served from the cache). It prints the median, 95th percentile and maximum of both passes
and the number of database queries during the warm pass, and exits with 1 when
the warm 95th percentile reaches 100 ms or any warm request queried the
database. It uses a cache directory of its own, refuses to run against a
database that already holds workspaces unless `--force` is given, and deletes
its data afterwards unless `--keep` is given.

```bash
php artisan intrawatch:bench:status --pages=300 --items=20 --requests=3000
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
