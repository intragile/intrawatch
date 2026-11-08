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

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
