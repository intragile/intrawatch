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
worker, scheduler, websocket server) cannot be kept running, the container
exits with a non-zero status so that Docker replaces it.

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

The API reference is served at `/api/v1/openapi.json`, and `GET /up` is the
health endpoint used by the container healthcheck.

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

## Development

```bash
composer install
php artisan test
composer analyse
vendor/bin/pint --test
bash tests/docker/smoke.sh          # image smoke test (add --pgsql for PostgreSQL)
```

Guzzle is pinned to 7.x (`guzzlehttp/guzzle ^7.9`): Laravel Reverb requires
`guzzlehttp/psr7 ^2.6`, while Guzzle 8 needs psr7 3.x. Lift the pin once a
Reverb release allows psr7 3.

Licensed under the MIT license. Contact: info@intragile.eu

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
