# IntraWatch

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

English | [Magyar](README.hu.md)

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

Then open `http://localhost:3000/` in a browser. It redirects to the admin
interface at `/admin/`, which starts with the first-run setup wizard: the owner
account first, then the first workspace and its timezone. See [Admin](#admin)
for what the interface covers. When the container is published on another host
or port, set `APP_URL` to the address the browser uses (for example
`-e APP_URL=http://monitor.example.com:8080`): the cookie session only works
for that origin.

The same setup can be scripted against the API. The endpoint accepts a single
use only, and it logs you in with a cookie session, so it is called like the web
frontend would: fetch the CSRF cookie first, then send it back as
`X-XSRF-TOKEN` with an `Origin` that matches `APP_URL`:

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

Further users are created from the command line (and then invited to
workspaces, see [Members and invitations](#members-and-invitations)); `--owner-of` makes the user an
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

### Admin

The admin interface is served at `/admin/` on the `APP_URL` host (`/` redirects
there). After the setup wizard it opens the first workspace's monitor list.

- **Monitors**: a list with server-side search and filters, groups that expand
  on demand, bulk actions, and a detail page with uptime, latency, check
  results, events and alerts. The editor covers every monitor type (HTTP with
  keyword, JSON and header assertions, TCP, ping, DNS, TLS, domain expiry,
  push, cron and groups).
- **Notification channels**: one editor per provider, with write-only secrets,
  message templates, a test send of the unsaved draft, and binding a channel to
  monitors in bulk. **Alert history** lists every alert and its deliveries.
- **Incidents** with an update timeline, and **maintenance windows** (one-off,
  interval, weekly, monthly or cron) with a preview of the next window.
- **Status pages**: settings, sections and their monitors, publishing and a
  custom domain.
- **Workspace**: settings, members, invitations and roles, API tokens and the
  audit log; further workspaces can be created from the workspace switcher by
  those `WORKSPACE_CREATORS` allows (see [Members and
  invitations](#members-and-invitations)).
- **Account and security**: two-factor authentication with recovery codes.
  The interface is available in English and Hungarian (the browser language,
  or the account menu).

Updates are live: monitor status changes, check results and alerts arrive over
the workspace's private websocket channel (Reverb at `/app`, authorised with
the cookie session at `/broadcasting/auth`), so the monitor list and detail
pages change without a reload. Monitors created or deleted elsewhere appear
when the window regains focus or the connection comes back. The websocket
server only accepts browsers whose page was loaded from the `APP_URL` host; if
the interface is also reached under another host name or IP address, add it
to `REVERB_ALLOWED_ORIGINS`, or live updates stay off there.

Controls follow the caller's role (`can` in `/api/v1/me`); the API enforces the
same rules on its own. On a custom status domain only the status page is
served: `/admin/`, `/api/`, `/sanctum/` and `/broadcasting/` answer 404 there.

### Members and invitations

Members are invited by e-mail. `POST /api/v1/workspaces/{workspace}/members`
(`email`, `role`; `members:manage`) answers `202` for every address, whether
or not an account exists for it, and mails a link to
`{APP_URL}/admin/invitations#token={token}`; the token sits in the fragment, so
browsers never send it and it stays out of access logs. Only an address that is
already a member is refused (422). The link is valid for 7 days and works once; inviting the
same address again replaces the pending invitation and the old link stops
working. It is accepted by a signed-in browser session (not an API token)
whose e-mail matches the invitation. Sign-up is not open, so the e-mail tells
the recipient to sign in with that address or ask an administrator for an
account (`intrawatch:user:create`, see [Quick start](#quick-start)). An
invitation also stops working when its inviter is removed from the workspace
or can no longer grant the role.

Admins and owners invite, list (`GET .../invitations`, expired ones flagged)
and revoke (`DELETE .../invitations/{id}`) pending invitations; only owners
invite as owner, or replace or revoke an owner invitation. Invitations are
limited to `INVITATIONS_PER_HOUR` per workspace (30 by default, resends
included) and 10 per minute per user. The audit log records
`member.invited`, `member.invitation_revoked` and `member.added`;
`intrawatch:prune` deletes invitations a month after they expired.

The e-mail is queued (regular queue worker, see [Queues](#queues)) and sent by
the application mailer, so invitations need working `MAIL_*` settings (see
[Channels](#channels)); the default mailer, `log`, only writes the e-mail to
the container log. Adding a user to a workspace directly is only possible on
the command line (`intrawatch:user:create --owner-of=<slug>`).

`WORKSPACE_CREATORS` decides who may create workspaces through the API and the
interface: `owners` (the default: users who already own a workspace),
`anyone` (every signed-in user) or `none` (only the command line, with
`--owner-of`). Unknown values behave like `owners`. API tokens never create
workspaces. `can_create_workspace` in `/api/v1/me` reports the caller's
permission.

### Recovery

```bash
# Lost authenticator and recovery codes: disable two-factor authentication.
docker exec -it intrawatch php artisan intrawatch:user:reset-2fa ada@example.com
# Forgotten password: set a new one (prompted for), end the user's sessions and revoke their API tokens.
docker exec -it intrawatch php artisan intrawatch:user:reset-password ada@example.com
```

Both are recorded in the audit trail with the `system` actor. A password reset,
by this command or through the e-mail link, also revokes all of the user's API
tokens and is recorded as `auth.password_reset`. Note that the
workspace audit list only shows workspace events; instance-level events such
as `auth.*` (logins, failed logins, 2FA and password resets) are stored with no
workspace and are not exposed over the API yet.

### What lives in `/data`

| Path | Content |
| --- | --- |
| `app.key` | Application key, generated on first start (mode 0600) |
| `reverb.env` | Generated websocket credentials |
| `database.sqlite` | The database, when `DB_CONNECTION=sqlite` (default) |
| `storage/` | Uploads (import files under `storage/app/private/imports`, kept at most 24 hours) and framework files |

Back up this volume and you have backed up everything. `app.key` matters even
when the database is external: it encrypts the two-factor secrets and recovery
codes stored in the database, so a database backup without it cannot restore
2FA logins. Logs are written to the container output (`docker logs`), not to
the volume.

The container runs as a non-root user (uid 1000); when bind-mounting a host
directory, make it writable for that uid (`chown -R 1000:1000 <dir>`), or the
container stops at start with a message saying so. Only port 3000 is exposed;
websockets are proxied internally.

The container runs with umask `027`: files it creates are `0640` and
directories `0750`, readable by the `app` group (gid 1000) but not by other
users, and at start an existing `database.sqlite` loses access for other users
(a warning is logged when that fails, for example because another uid owns
the file). A backup job that reads the volume under a different uid must
therefore be in group 1000. Commands started with `docker exec` keep their own
umask. The application code under `/app` is owned by root; the process can
only write `bootstrap/cache` and `storage` (which lives in `/data`). PHP sends
no `X-Powered-By` header, and every response carries
`X-Content-Type-Options: nosniff`.

### Configuration

| Variable | Default | Purpose |
| --- | --- | --- |
| `APP_URL` | `http://localhost:3000` | Public URL, used in password-reset links. Its host is a stateful (cookie session) domain by default |
| `APP_KEY` | generated | Override the persisted application key |
| `DB_CONNECTION` | `sqlite` | `sqlite`, `mysql` or `pgsql` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | | External database settings |
| `TRUSTED_PROXIES` | none | Comma-separated IPs/CIDRs of your reverse proxy (for example `172.18.0.0/16`), so client IPs are correct for rate limits and the audit log. Avoid `*`: it trusts the forwarding headers of every client |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost:3000,127.0.0.1:3000` plus the `APP_URL` host | Hosts (with port) of the web frontend that use cookie sessions; setting it replaces the default list |
| `SESSION_SECURE_COOKIE` | unset | Set to `true` when the instance is served over HTTPS (behind a TLS-terminating proxy), so session cookies are only sent over TLS |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | generated | Websocket credentials; set them (and `APP_KEY`) on every host of a multi-host deployment |
| `REVERB_ALLOWED_ORIGINS` | empty | Host names besides the `APP_URL` host whose pages may open the websocket: comma-separated hosts without port, or patterns such as `*.example.org`; `*` allows any origin (not recommended) |
| `WORKSPACE_CREATORS` | `owners` | Who may create workspaces: `owners`, `anyone` or `none` (see [Members and invitations](#members-and-invitations)) |
| `INVITATIONS_PER_HOUR` | `30` | Member invitations a workspace may send per hour |
| `STATUS_CUSTOM_DOMAINS` | empty | Host names status pages may use as custom domain; empty disables custom domains (see [Status pages](#status-pages)) |
| `STATUS_RATE_LIMIT` | `300` | Public status page requests per minute per client IP |
| `IMPORT_MAX_UPLOAD_MB` | `256` | Largest Uptime Kuma file accepted by the admin upload; PHP's `upload_max_filesize` and `post_max_size` (512 MB in the image) cap it |
| `IMPORT_MAX_JSON_MB` | `20` | Largest Kuma JSON backup the importer reads |
| `IMPORT_MAX_JSON_ITEMS` | `50000` | Most objects and lists in a Kuma JSON backup |

Configuration is cached when the container starts, so changes take effect on
restart.

A ready-made `docker/compose.example.yml` shows the SQLite default and
commented-out PostgreSQL and MySQL services. It publishes the port on
`127.0.0.1` only, for a reverse proxy on the same host; publish it on all
interfaces only when that is intended.

### Migrating from Uptime Kuma

IntraWatch imports monitors, notification channels, tags, status pages,
incidents, maintenance windows and uptime history from an Uptime Kuma instance
into a workspace. Uptime Kuma is a project by its respective authors;
IntraWatch is not affiliated with it.

Supported sources:

| Source | Kuma version | Admin upload | CLI |
| --- | --- | --- | --- |
| SQLite database (`kuma.db`) | 2.x and 1.23 | yes | yes |
| "Export Backup" JSON (monitors, notifications and tags only) | 1.23 | yes | yes |
| Live MariaDB/MySQL database, read-only, as `mysql://user:pass@host:port/db` | 2.x | no | yes |

Kuma 2.x has no JSON backup and no REST API, so for 2.x use the database. A
SQL dump cannot be uploaded.

**Take a consistent copy first.** Kuma keeps recent writes in a `-wal` file
next to `kuma.db`. Stop Kuma before copying the file, or make a consistent copy
while it runs:

```bash
sqlite3 /path/to/kuma/data/kuma.db ".backup kuma-export.db"
```

**In the admin interface** (workspace admins and owners): upload the file,
check the analysis (a dry run that lists what would be created, skipped or is
invalid, with a reason for each item), then run the import. Uploads are limited
to `IMPORT_MAX_UPLOAD_MB` (256 MB by default), or to PHP's
`upload_max_filesize` and `post_max_size` when those are lower (the image
allows up to 512 MB); the error message names the limit that applies. JSON
backups are also limited to `IMPORT_MAX_JSON_MB` (20 MB) and
`IMPORT_MAX_JSON_ITEMS` (50 000 objects and lists, and 20 times as many
values; it also caps the monitor and notification lists); both are checked before the file is decoded,
on the command line too, and the error names the variable to raise. SQLite
files with virtual tables are refused. Analysis and run happen in the
background on the `long` queue, up to 900 seconds each; a run that exceeds
that fails as "did not finish in time", one whose worker crashed or ran out of
memory as "interrupted". One import per workspace can be open at a time; the
uploaded file is deleted when the run completes or fails, when the import is
deleted, or after 24 hours. Over the API the same flow is
`POST /api/v1/workspaces/{workspace}/imports` (multipart `file`, optional
`history` and `paused` as `1`/`0`), then `POST .../imports/{id}/run` once the
status is `analyzed`; tokens need the `workspace:manage` ability.

**On the command line** (no size limit, runs as the system):

```bash
docker cp kuma-export.db intrawatch:/data/kuma-export.db
docker exec -it intrawatch php artisan intrawatch:import:kuma /data/kuma-export.db --workspace=ops --dry-run
docker exec -it intrawatch php artisan intrawatch:import:kuma /data/kuma-export.db --workspace=ops --report=/data/kuma-report.json
docker exec intrawatch rm /data/kuma-export.db
```

Options: `--dry-run` (analyse only), `--no-history`, `--paused` (import every
monitor paused) and `--report=<path>` (the full JSON report). For a Kuma that
uses an external MariaDB, pass `mysql://user:pass@host:3306/kuma` as the
source; a read-only database user is enough. The embedded MariaDB of Kuma 2.x
listens only on a socket inside the Kuma container, so dump it and load the
dump into a temporary MariaDB the CLI can reach:

```bash
docker exec uptime-kuma mariadb-dump --skip-lock-tables -S /app/data/run/mariadb.sock -u root kuma > kuma.sql
docker network create kuma-import
docker run -d --name kuma-import-db --network kuma-import -e MARIADB_ROOT_PASSWORD=secret -e MARIADB_DATABASE=kuma mariadb:11
docker exec -i kuma-import-db mariadb -uroot -psecret kuma < kuma.sql   # retry once the server accepts connections
docker network connect kuma-import intrawatch
docker exec -it intrawatch php artisan intrawatch:import:kuma 'mysql://root:secret@kuma-import-db:3306/kuma' --workspace=ops
docker network disconnect kuma-import intrawatch && docker rm -f kuma-import-db && docker network rm kuma-import
```

Prefer a read-only database user in the DSN: its password is visible in the
host's process list and in your shell history.

Re-running an import is safe: entities already imported are reported as
`existing` and never changed or recreated, so local edits and deletions win.
Importing the same instance once from a JSON backup and once from its database
cannot be matched and creates duplicates (the report warns about it).
JSON backups of two different 1.23 instances imported into the same workspace
collide on Kuma ids: the second instance's entities are reported as `existing`
and are not created. Import them into separate workspaces, or import each
instance from its SQLite database file instead.

**Not migrated:** users, API keys, Docker hosts and remote browsers (counted in
the report), minute-level history and individual heartbeats, monitor types and
notification providers IntraWatch does not support, and manual maintenance
windows. These are skipped with a note. Settings that would change behaviour
(an unsupported authentication method or proxy protocol, a JSON query that
cannot be translated, Kuma's `conditions`) import the monitor paused or the
channel inactive, again with a note. Nothing is dropped silently: every row
appears in the report.

**Push monitors keep their tokens** when the token is 1 to 48 letters and
digits and no other monitor uses it. IntraWatch also answers Kuma's push URL
shape, `GET|POST|HEAD /api/push/<token>?status=up|down&msg=...`, so push
clients only need the new host. Any other token is replaced by a new
48-character one with the note `push_token_regenerated` (which does not say
why), and that monitor's clients need the new push URL.

**Custom domains** of Kuma status pages are kept only when
`STATUS_CUSTOM_DOMAINS` allows them; otherwise an import started in the admin
interface or the API creates the page without one and adds the note
`custom_domain_not_allowed`. Command-line imports keep every domain.

**History:**

- Kuma 2.x: hourly statistics (the last 30 days) become hour rollups and daily
  statistics (up to 365 days) become day rollups. Kuma counts pending checks as
  down in these statistics, so imported 2.x history does too. Kuma days are UTC
  days; they are stored on the same calendar date in the workspace timezone,
  with a note when that timezone is not UTC.
- Kuma 1.23: heartbeats are summed into hour and day rollups, with pending kept
  as pending.
- History is imported only for monitors the run creates, and only within the
  retention settings (`RETENTION_HOUR_DAYS`, `RETENTION_DAY_DAYS`). Pass `--no-history` or `history=0` to skip
  it.

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

Checks never connect to the cloud metadata endpoints `169.254.169.254/32`,
`169.254.170.2/32`, `169.254.170.23/32`, `fd00:ec2::254/128`,
`fd20:ce::254/128` and `100.100.100.200/32`; the rest of `169.254.0.0/16`
stays reachable for link-local devices. `CHECK_BLOCKED_NETWORKS` (a CIDR list,
empty by default) adds networks to this built-in list and never replaces it;
add your internal ranges there if workspace members must not probe them.
`CHECK_ALLOW_METADATA=1` lets monitors reach the metadata endpoints (for
monitoring one on purpose); notification targets never reach them.

When an HTTP monitor uses a proxy, both the proxy and the real target, on
every redirect, are checked against these lists. A literal IP address is
checked exactly, a numeric host such as `2852039166` or `0xa9fea9fe` as the
IPv4 address the proxy would read it as, and a host name through a local
lookup capped at one second. A name that does not resolve locally is allowed,
because the proxy may resolve names the worker cannot; the proxy may also
resolve a name differently (split-horizon DNS, rebinding), so for host names
the proxy's own egress rules remain the real boundary.

On an update of a monitor with stored secrets that changes where it connects
(`http` `url` or `proxy.url`, removing the proxy included; `tcp` `host` or
`port`; `ping` `host`; `dns` `name` or `resolver`; `tls` `host`, `port` or
`servername`; `domain` `domain`), every stored secret must be sent again or
removed with `null`. Otherwise the update is refused with a 422 on `secrets`
(listing the missing keys) and on each `secrets.<key>`. Other changes keep the
secrets; the admin interface marks the secret fields as required when the
destination changes.

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

Push monitors also answer at `/api/push/{token}`, the URL shape Uptime Kuma
uses, with the same `status=up|down`, `msg` and `ping` query parameters (GET,
POST or HEAD) and the same rate limit as `/api/v1/push/{token}`. Push monitors
imported from Uptime Kuma keep their token, so their clients only need the new
host; a token that another monitor already holds, is longer than 48 characters
or contains characters other than letters and digits is replaced and the
import report says so (see [Migrating from Uptime
Kuma](#migrating-from-uptime-kuma)).

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
| `CHECK_BLOCKED_NETWORKS` | empty | Networks checks may not connect to, on top of the built-in cloud metadata list (see [Monitors](#monitors)) |
| `CHECK_ALLOW_METADATA` | `0` | `1` lets monitors reach the built-in cloud metadata endpoints |

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

Every host must also get the same `APP_KEY`, `REVERB_APP_ID`,
`REVERB_APP_KEY` and `REVERB_APP_SECRET` through its environment: values
generated in one container's `/data` are unknown to the others, so sessions,
encrypted secrets and live updates break across hosts. With an external
database and generated values, the container logs `WARNING: multi-host
deployments must share APP_KEY and REVERB_* (generated here: ...)` and still
starts.

On start only one host migrates a shared database at a time: the migration
holds a lock in the cache store, which therefore must be shared (the database
or Redis). The other hosts wait, for up to about ten minutes; then they exit
with `FATAL` and the restart policy tries again. A host killed during a
migration leaves the lock behind for up to an hour; if no host is migrating,
clear it with `php artisan cache:clear`. SQLite, which only one host can use,
migrates without the lock.

The container refuses to start when `CHECK_SHARD_OFFSET + CHECK_WORKERS`
exceeds `CHECK_SHARDS_TOTAL`. `php artisan intrawatch:worker:status` without
options checks that every shard of the installation has reported, which is
useful for monitoring the whole cluster from one place.

### Queues

Three queue workers run in the container: one for regular jobs (such as live
update broadcasts and alert evaluation), one for the `notifications` queue,
which sends alerts to notification channels, and one for the `long` queue
connection, which runs the day rollup rebuilds after a timezone change and
Uptime Kuma imports (up to 900 seconds each, with `retry_after` 960 seconds). The separate workers keep a
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
| `POST /api/v1/workspaces/{workspace}/notification-channels/test` (unsaved), `POST /api/v1/workspaces/{workspace}/notification-channels/{channel}/test` | `notifications:write` (10 per minute per user and 30 per minute per workspace; 429 with `Retry-After` beyond that) |
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

An update that changes where a channel with stored secrets sends (`webhook`
`url`; `server_url` of `telegram`, `ntfy`, `gotify` and `apprise`; `matrix`
`homeserver_url`; `email` `smtp.host`, `smtp.port` or `smtp.encryption`;
`opsgenie` `region`) must send every stored secret again or remove it with
`null`, or it is refused with a 422 on `secrets` (listing the missing keys)
and on each `secrets.<key>`. Other changes keep the secrets; the admin
interface marks the secret fields as required when the destination changes.

Users who may not manage channels (Viewers, and tokens without
`notifications:write`) see a webhook `url` as `scheme://host[:port]/***` and an
ntfy `topic` and Apprise `config_key` as `***`.

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

E-mail channels without their own `smtp` block (`null` or `{}`) send through
the application mailer, with the instance's sender address. Only workspace
owners and admins (for a token: its owner's role) may create such a channel,
test-send an unsaved one, move a channel onto the application mailer, or
change its recipients or templates; Editors can still rename, enable,
disable and test-send saved ones. The mailer is configured with the usual
Laravel variables (the default, `log`, only writes e-mails to the container
log):

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
are never followed). On top of that list, notification targets are always
refused in the cloud metadata endpoints (even with `CHECK_ALLOW_METADATA=1`),
all of `169.254.0.0/16` and `fe80::/10`, the translation prefixes
`64:ff9b::/96` and `2002::/16`, and `0.0.0.0/8` and `::/128`, which connect to
the local host. Loopback and private networks stay allowed by default, so
workspace members can point webhooks at internal services. To forbid that, add
the internal ranges, for example
`CHECK_BLOCKED_NETWORKS=10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.0/8,::1/128,fc00::/7,0.0.0.0/8,::/128`
(the last two entries extend the local-host block to checks).

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
timezone. Public pages set no cookies and are limited to `STATUS_RATE_LIMIT`
requests per minute per client IP (300 by default). Visitors behind one NAT
share an IP and so share the limit.

**Custom domain.** Custom domains are off until `STATUS_CUSTOM_DOMAINS` lists
the host names pages may claim, comma-separated: exact hosts
(`status.example.com`) or suffixes (`*.example.com`, which does not cover
`example.com` itself). While it is empty, setting a custom domain through the
API answers 403; a host outside the list answers 422. Imports started in the
admin interface or the API drop domains the list does not allow (see
[Migrating from Uptime Kuma](#migrating-from-uptime-kuma)); the command line
is exempt from the list. Domains set before keep working and can
still be removed over the API (the admin interface currently hides the field
while the list is empty).

Point the domain's DNS at your reverse proxy, terminate
TLS there (automatic certificates for custom domains are planned for a later
release) and forward the requests to the container with the original `Host`
header. Every custom domain is reserved for its page, published or not: a
published page's domain serves only the status paths (`/`, and the feed,
summary and badge paths) and `/up`, an unpublished page's domain only `/up`;
everything else on it, including `/admin/`, `/api/`, `/sanctum/` and
`/broadcasting/`, is a 404, also while the database is down (from the last
known list of domains). Any other host name or IP address reaches the
application as usual. Canonical and feed links always use
the page's own address (`https://{custom_domain}` or `APP_URL`), never the
request's `Host`.
Behind a reverse proxy, set `TRUSTED_PROXIES` to the proxy's address so the
client address is taken from its forwarding headers; otherwise every visitor
counts against the proxy's single bucket. The limit is approximate: it is
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

Only workspace admins can set or change a page's custom domain, only to a host
`STATUS_CUSTOM_DOMAINS` allows, and it can be neither the host of `APP_URL`
nor the host the API request was sent to.

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

### Building the image

`scripts/build-image.sh` builds the image for several platforms into a local
OCI archive and never pushes anything. It creates (or reuses) a buildx builder
named `intrawatch-builder`:

```bash
scripts/build-image.sh --version 1.0.0 --platforms linux/amd64,linux/arm64 --out build
```

`--out` must be `build/` (or below it) or a directory outside the repository,
so the archive never lands in the Docker build context; any other path exits
with status 2. The archive is written to `build/intrawatch-<version>.oci.tar`, and the script
lists the platforms in its `index.json` and the sizes. Add
`--load-platform linux/amd64` to also load one platform into the local Docker
engine as `intrawatch:<version>-amd64`. A platform that differs from the host
runs under QEMU emulation, which can take up to an hour for the PHP extensions.

The smoke test can run against such an image. `SMOKE_PLATFORM` passes
`--platform` to the build and the runs, `SMOKE_SKIP_BUILD=1` uses an existing
`SMOKE_IMAGE` and `--quick` runs only the monitor, status page, admin and
import sections with tripled timeouts:

```bash
SMOKE_PLATFORM=linux/amd64 SMOKE_SKIP_BUILD=1 SMOKE_IMAGE=intrawatch:1.0.0-amd64 \
  bash tests/docker/smoke.sh --quick
```

The release steps are listed in [docs/RELEASE.md](docs/RELEASE.md); changes
between versions are recorded in [CHANGELOG.md](CHANGELOG.md).

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
npm run e2e       # end-to-end run in Chromium against a freshly built image
```

`npm run e2e` (`spa/e2e/run.sh`) builds the image as `intrawatch:smoke-spa`,
starts it on port 33300 with a fresh volume (`APP_URL=http://localhost:33300`)
and runs the Playwright flow in `spa/e2e/`: the setup wizard, an HTTP monitor
that turns up live, a webhook test send, a maintenance window, a published
status page, two-factor sign-in and the custom-domain 404 for `/admin/`. The
container and its volume are always removed. Install the browser once with
`npx playwright install chromium`; `E2E_SKIP_BUILD=1` reuses an existing image.
It is not part of `npm run verify`.

The dev server proxies `/api`, `/sanctum`, `/broadcasting` and the `/app`
websocket to `VITE_BACKEND` (default `http://127.0.0.1:8000`, i.e.
`php artisan serve`). Point it at a running image
(`VITE_BACKEND=http://localhost:3000`) to get live updates through the image's
Reverb proxy as well. `npm run build` writes `spa/dist`, the directory the
`intrawatch.spa_path` setting points to.

Signing in through the dev server uses the cookie session, so the backend must
treat the Vite origin as stateful: add it to Sanctum's list, for example
`SANCTUM_STATEFUL_DOMAINS=localhost:5173,localhost:3000`.

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

The scale benchmark seeds a throwaway workspace (`bench-scale`) with 5 000
active monitors (60% HTTP, the rest TCP, ping, DNS and push, in 250 nested
groups, with 50 tags, 15 inactive channels, 90 days of day and 48 hours of hour
rollups), 300 status pages of 20 items and some alerts, incidents,
maintenances and audit events. The monitors check the measured application
itself (`/robots.txt`, its port, `127.0.0.1` and `localhost`), so the running
worker carries the real check load. After `--settle` seconds it measures, over
real HTTP against `--base-url` with an API token it creates, ten admin lists
and every status page cold and then warm, and prints a table and a JSON
summary. It exits with 1 when any admin list's 95th percentile reaches 500 ms,
the warm status page 95th percentile reaches 100 ms, or a request fails. Run it
inside a running container with `TRUSTED_PROXIES=127.0.0.1`: status page
requests come from many client addresses through `X-Forwarded-For`, so the
public rate limit stays out of the measurement. The checks reach loopback
without any network policy switch, because by default only the built-in cloud
metadata addresses are blocked (`CHECK_BLOCKED_NETWORKS` is empty). Like the other benchmarks it refuses a
database that already holds workspaces unless `--force` is given, and deletes
its data afterwards unless `--keep` is given.

> **Warning:** `TRUSTED_PROXIES=127.0.0.1` is a setting for the benchmark
> container only. It makes the application trust the `X-Forwarded-For` header
> that clients send, which defeats the per-IP rate limit of the public status
> pages. Never set it in production unless a real reverse proxy at that address
> sets the header. When status requests answer 429, the benchmark asks whether
> the setting is missing.

```bash
docker exec intrawatch php artisan intrawatch:bench:scale \
  --monitors=5000 --pages=300 --items=20 --requests=2000 --base-url=http://127.0.0.1:3000
```

The Uptime Kuma import benchmark generates a 2.x SQLite database from
`tests/Fixtures/kuma/kuma2-schema.sql` (5 000 monitors and 250 groups, 300
status pages of 20 items, 30 days of hourly and 365 days of daily statistics
for 500 monitors), imports it on the configured database connection, prints
the time of each step and the peak memory, and fails at 300 seconds. It is in
the `bench` group, which the default test run excludes:

```bash
vendor/bin/phpunit --testsuite Bench --group bench
```

Measured results and the hardware they ran on are in
[docs/BENCHMARKS.md](docs/BENCHMARKS.md).

A command passed to `docker run` runs after the usual setup instead of the
services. That setup migrates the configured database with the image's own
version; set `INTRAWATCH_SKIP_MIGRATE=1` to run a one-off command against a
database another version manages.

Guzzle is pinned to 7.x (`guzzlehttp/guzzle ^7.9`): Laravel Reverb requires
`guzzlehttp/psr7 ^2.6`, while Guzzle 8 needs psr7 3.x. Lift the pin once a
Reverb release allows psr7 3.

Licensed under the MIT license. Contact: info@intragile.eu

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
