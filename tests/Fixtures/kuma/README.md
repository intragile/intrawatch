# Uptime Kuma fixtures

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

Real data produced by running Uptime Kuma and seeding it through its own socket.io API (the calls the web UI makes). They feed the importer tests, the smoke test and the benchmark.

| File | Source |
|---|---|
| `kuma2.db` | SQLite database of `louislam/uptime-kuma:2.5.9` (10 monitors, 2 notifications, 1 tag, a status page, 6 maintenances, hourly and daily stats). |
| `kuma1.db` | SQLite database of `louislam/uptime-kuma:1.23.17` (heartbeats, no `stat_*` tables). |
| `kuma1-backup.json` | The 1.23 "Export Backup" JSON, rebuilt by `seed/export-v1-backup.js` exactly as the web UI does. |
| `kuma2-mariadb.sql` | `mariadb-dump --skip-lock-tables kuma` of a 2.5.9 instance running the embedded MariaDB. |
| `kuma1-schema.sql`, `kuma2-schema.sql` | `.schema` of the two SQLite files, for reference. |
| `seed/` | The seeding scripts (`seed.js`, `export-v1-backup.js`, `package.json`; run `npm install` there first, `node_modules` is not committed). |

## How they were produced

SQLite 2.x and 1.23:

```bash
docker run -d --name kuma -p 33401:3001 -v kuma-data:/app/data louislam/uptime-kuma:2.5.9   # or :1.23.17
cd seed && npm install
node seed.js http://127.0.0.1:33401 v2     # v1 for 1.23; v1 also takes a backup output path
docker cp kuma:/app/data/kuma.db ./kuma2.db
```

The 1.23 JSON backup comes from `node seed.js http://127.0.0.1:33401 v1 kuma1-backup.json`.

MariaDB:

```bash
docker run -d --name iw-rel-kuma-maria -v iw-rel-kuma-maria-data:/app/data \
  -p 33401:3001 -e UPTIME_KUMA_DB_TYPE=embedded-mariadb louislam/uptime-kuma:2.5.9
node seed/seed.js http://127.0.0.1:33401 v2
docker exec iw-rel-kuma-maria mariadb-dump --skip-lock-tables \
  -S /app/data/run/mariadb.sock -u root kuma > kuma2-mariadb.sql
docker rm -f iw-rel-kuma-maria && docker volume rm iw-rel-kuma-maria-data
```

The seed password is a throwaway (`Research-Pass-123!`); the `jwtSecret` values in the fixtures belong to those throwaway instances.

## Rule for every Markdown file

Every `.md` file carries "Developed and maintained by [IntrAgile Kft.](https://intragile.eu)" under the H1 and again as its footer.

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
