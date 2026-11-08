# Changelog

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

All notable changes to IntraWatch are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [1.0.0] - unreleased

First release: a free, self-hosted uptime monitor and status page, shipped as
one Docker image with all state in a single volume.

### Platform

- One image (FrankenPHP, PHP 8.5) for `linux/amd64` and `linux/arm64`; the web
  server, queue workers, check workers, scheduler and websocket server run
  under one supervisor. The container exits when a program cannot be kept
  running, so the restart policy replaces it.
- SQLite by default; MySQL 8.4 and PostgreSQL 16 through `DB_*` settings.
- Application key and websocket credentials are generated on first start and
  kept in `/data`. The container runs as an unprivileged user.
- A local multi-arch build (`scripts/build-image.sh`) and a release checklist
  (`docs/RELEASE.md`).

### Accounts, workspaces and API

- First-run setup, session login with TOTP two-factor authentication, password
  reset and recovery commands.
- Workspaces with the roles owner, admin, editor and viewer.
- API tokens with per-area abilities; every admin action is available
  on the public `/api/v1` API, which is described by a generated OpenAPI
  document served at `/api/v1/openapi.json`.
- Errors as `application/problem+json`, cursor pagination on list endpoints,
  and an audit log.

### Monitoring

- Monitor types `http` (status codes, keyword, JSON query, headers, redirects,
  basic and bearer auth, mTLS, proxy), `tcp`, `ping` (without `NET_RAW`),
  `dns` (records, expected values, change detection), `tls` (expiry, chain,
  protocols, ciphers, STARTTLS), `domain` (RDAP with a WHOIS fallback), `push`,
  `cron` (schedule, start and finish reports, run duration) and `group`.
- Check workers with sharding across processes and containers.
- Raw results, minute, hour and day rollups with configurable retention.
- Live updates over the built-in websocket server.

### Alerting

- Notification channels: e-mail, webhook, Slack, Discord, Microsoft Teams,
  Telegram, ntfy, Gotify, Pushover, Matrix, Mattermost, Google Chat,
  PagerDuty, Opsgenie and Apprise.
- Down and up alerts with retries, flap damping, degraded and slow-response
  alerts, reminders, certificate and DNS change alerts, test sends and an
  alert history with delivery status.

### Incidents, maintenance and status pages

- Incidents with updates and impact levels.
- One-off and recurring maintenance windows, visible in advance.
- Server-rendered public status pages with a per-page cache, sections and
  groups, partial status, uptime windows of 1 to 365 days, day bars and an
  optional 24-hour latency line, badges, RSS and Atom feeds, a JSON summary
  and custom domains.

### Admin interface

- A Vue single-page application at `/admin/` that uses only `/api/v1`: setup
  wizard, login with TOTP, workspaces, monitors, notifications, incidents,
  maintenance, status-page editor and a live dashboard; English and Hungarian.

### Import and documentation

- Import from Uptime Kuma 2.x and 1.23: SQLite file, Kuma 1.x JSON backup or a
  live MariaDB or MySQL database. Monitors, notification channels, tags, status
  pages, incidents, maintenance windows and uptime history; an analysis (dry
  run) precedes every run, and secrets are stored encrypted only. Uptime Kuma
  is a project of its respective authors; IntraWatch is not affiliated with it.
- A benchmark suite and measured results in [docs/BENCHMARKS.md](docs/BENCHMARKS.md)
  for 5 000 monitors and 300 status pages.
- Documentation in the [README](README.md).

### Security

Changes from the pre-release security review. Items marked **action** change
behaviour that an existing installation may rely on.

- **Action:** members are invited instead of added. `POST .../members`
  answers `202` and e-mails a single-use link valid for 7 days, which only a
  signed-in user with that address can accept. Invitations need working
  `MAIL_*` settings (the default mailer only logs); `INVITATIONS_PER_HOUR`
  limits them. Adding a member directly is only possible on the command line.
- **Action:** `WORKSPACE_CREATORS` (default `owners`) limits who may create
  workspaces; API tokens never do.
- **Action:** custom status domains are off until `STATUS_CUSTOM_DOMAINS`
  lists the allowed hosts. Existing domains keep working. Every custom domain,
  published or not, now serves only its status page and `/up`, also while the
  database is down.
- **Action:** websockets accept only the `APP_URL` host by default; add further
  hosts the interface is reached under to `REVERB_ALLOWED_ORIGINS`, or live
  updates stop there.
- **Action:** `CHECK_BLOCKED_NETWORKS` now defaults to empty and extends a
  built-in list of cloud metadata endpoints that also covers
  `169.254.170.2`, `169.254.170.23`, `fd20:ce::254` and `100.100.100.200`.
  Monitoring one of them needs `CHECK_ALLOW_METADATA=1`. Proxied HTTP monitors
  now check the real target and every redirect against the policy as well.
- **Action:** multi-host deployments must set `APP_KEY` and `REVERB_*` on
  every host (the container warns otherwise). Shared databases migrate under a
  cache lock, one host at a time.
- **Action:** the container runs with umask 027 and root-owned application
  code; backup tools that read `/data` under another uid must be in group
  1000. The example compose file publishes the port on `127.0.0.1` only.
- Changing a channel's or monitor's destination requires its stored secrets to
  be sent again or removed. Webhook URLs, ntfy topics and Apprise config keys
  are masked for users who cannot manage channels.
- E-mail channels on the instance mailer can only be configured by owners and
  admins; test sends are also limited per workspace (30 per minute).
- Kuma import: JSON backups are bounded (`IMPORT_MAX_JSON_MB`,
  `IMPORT_MAX_JSON_ITEMS`), the upload limit honours PHP's limits, SQLite files
  with virtual tables are refused, crashed runs are reported as interrupted,
  push token collisions are no longer disclosed (`push_token_regenerated`
  replaces `push_token_collision`) and imports started by users drop custom
  domains the allowlist does not cover.
- A password reset revokes all API tokens and is audited;
  `X-Content-Type-Options: nosniff` on every response; no `X-Powered-By`
  header; `STATUS_RATE_LIMIT` makes the public status rate limit configurable.
- `scripts/build-image.sh` refuses an `--out` inside the build context.

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
