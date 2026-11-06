# IntraWatch

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

A free, self-hosted uptime monitor and status page. One container, no external
services required, all state in a single volume.

## Quick start

```bash
docker run -d --name intrawatch -p 3000:3000 -v intrawatch-data:/data \
  ghcr.io/intragile/intrawatch:latest
```

The registry image is not published yet. Until then, build it locally:

```bash
docker build -f docker/Dockerfile -t intrawatch .
docker run -d --name intrawatch -p 3000:3000 -v intrawatch-data:/data intrawatch
```

Then finish the first-run setup, which creates the owner account and the first
workspace. The endpoint accepts a single use only:

```bash
curl -X POST http://localhost:3000/api/v1/setup \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -H 'Origin: http://localhost:3000' \
  -d '{"name":"Ada Owner","email":"ada@example.com","password":"at-least-10-chars","workspace_name":"Acme Ops"}'
```

`GET /api/v1/setup` reports whether setup is still required. Further users can
be created from the command line:

```bash
docker exec -it intrawatch php artisan intrawatch:user:create
```

The API reference is served at `/api/v1/openapi.json`, and `GET /up` is the
health endpoint used by the container healthcheck.

### What lives in `/data`

| Path | Content |
| --- | --- |
| `app.key` | Application key, generated on first start (mode 0600) |
| `reverb.env` | Generated websocket credentials |
| `database.sqlite` | The database, when `DB_CONNECTION=sqlite` (default) |
| `storage/` | Logs, uploads and framework files |

Back up this volume and you have backed up everything. The container runs as a
non-root user (uid 1000); when bind-mounting a host directory, make it writable
for that uid. Only port 3000 is exposed; websockets are proxied internally.

### Configuration

| Variable | Default | Purpose |
| --- | --- | --- |
| `APP_URL` | `http://localhost:3000` | Public URL, used in password-reset links |
| `APP_KEY` | generated | Override the persisted application key |
| `DB_CONNECTION` | `sqlite` | `sqlite`, `mysql` or `pgsql` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | | External database settings |
| `TRUSTED_PROXIES` | none | Comma-separated proxy IPs/CIDRs, or `*`. Set it behind a reverse proxy so client IPs are correct for rate limits and the audit log |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost:3000,127.0.0.1:3000` | Hosts (with port) of the web frontend that use cookie sessions |
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

Licensed under the MIT license. Contact: info@intragile.eu

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
