# Release checklist

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

How IntraWatch 1.0.0 is verified and published. Part 1 is automatable and
changes nothing outside the working copy and the local Docker engine. Part 2
publishes and therefore needs the owner. Part 3 verifies the published image.

Run every command from the repository root. Replace `1.0.0` for later releases.

## 1. Pre-flight (automatable)

1. **Versions are bumped.** The changelog has a dated heading for the release
   and the build passes the same version as `APP_VERSION`.

   ```bash
   grep -n '^## \[1.0.0\]' CHANGELOG.md        # expect: ## [1.0.0] - <date>, not "unreleased"
   grep -n 'ARG APP_VERSION' docker/Dockerfile  # expect: ARG APP_VERSION=dev (the build passes the release version)
   ```

2. **Three-database suite** (SQLite, MySQL 8.4, PostgreSQL 16).

   ```bash
   php artisan test                                                   # SQLite
   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=intrawatch \
     DB_USERNAME=root DB_PASSWORD=secret php artisan test             # MySQL
   DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=postgres \
     DB_USERNAME=postgres DB_PASSWORD=secret php artisan test         # PostgreSQL
   ```

   Expect `Tests: ... passed` and no failures on each.

3. **Static analysis.**

   ```bash
   composer analyse          # expect: [OK] No errors
   ```

4. **Code style.**

   ```bash
   vendor/bin/pint --test    # expect: PASS, no files listed
   ```

5. **Admin interface.**

   ```bash
   (cd spa && npm ci && npm run verify)   # lint, type check, unit tests, API schema check, production build; expect exit 0
   ```

6. **OpenAPI has not drifted.** Regenerate against a throwaway database and
   compare with the committed document.

   ```bash
   touch /tmp/openapi-check.sqlite
   DB_CONNECTION=sqlite DB_DATABASE=/tmp/openapi-check.sqlite APP_ENV=testing php artisan migrate --force
   DB_CONNECTION=sqlite DB_DATABASE=/tmp/openapi-check.sqlite APP_NAME=intrawatch APP_VERSION=dev APP_ENV=testing \
     php artisan scramble:export --path=/tmp/openapi-check.json
   diff docs/openapi.json /tmp/openapi-check.json && echo "no drift"   # expect: no drift
   php artisan test --filter=OpenApiTest                               # expect: passed
   ```

7. **Image smoke test**, plain (SQLite) and with PostgreSQL. Use a free port.

   ```bash
   SMOKE_IMAGE=intrawatch:smoke-rel SMOKE_PORT=33300 timeout 1800 bash tests/docker/smoke.sh
   SMOKE_IMAGE=intrawatch:smoke-rel SMOKE_PORT=33300 timeout 1800 bash tests/docker/smoke.sh --pgsql
   ```

   Expect `SMOKE OK` at the end of each run.

8. **End-to-end tests** in a browser against a freshly built image. Do not run
   them while a smoke test uses the same port.

   ```bash
   timeout 900 bash spa/e2e/run.sh   # expect exit 0
   ```

9. **Multi-arch build and per-platform smoke.** The build writes
   `build/intrawatch-1.0.0.oci.tar`, prints the platforms found in its
   `index.json` and never pushes. On an arm64 host `linux/amd64` runs under
   QEMU emulation and takes much longer (allow up to an hour; check the build
   output every few minutes).

   ```bash
   scripts/build-image.sh --version 1.0.0 --load-platform linux/amd64
   # expect: platforms linux/amd64 and linux/arm64, then an image intrawatch:1.0.0-amd64
   SMOKE_PLATFORM=linux/amd64 SMOKE_SKIP_BUILD=1 SMOKE_IMAGE=intrawatch:1.0.0-amd64 SMOKE_PORT=33300 \
     timeout 3600 bash tests/docker/smoke.sh --quick                    # expect: SMOKE OK (quick)
   ```

   The native platform gets the full smoke test (step 7). `--quick` runs only
   the run, monitors and checker, public status page, admin application and
   import sections, with every timeout tripled for emulation.

10. **Benchmarks re-run if the engine or status-page code changed** since the
    figures in [BENCHMARKS.md](BENCHMARKS.md) were taken (check with
    `git diff <benchmark-commit>..HEAD --stat -- app/Monitoring app/Status`). Follow
    the commands in that document and update its numbers; skip this step when
    neither area changed.

11. **No attribution strings** in the tree or the commit messages.

    ```bash
    git log --format=%B main..HEAD | grep -inE 'co-authored-by|generated with|claude|anthropic' || echo clean   # expect: clean
    git grep -inE 'co-authored-by|generated with|anthropic' -- . ':!CHANGELOG.md' ':!docs/RELEASE.md' || echo clean   # expect: clean
    ```

12. **Every Markdown file carries the header and footer line** (under the H1 and
    as the last line).

    ```bash
    line='Developed and maintained by [IntrAgile Kft.](https://intragile.eu)'
    for f in $(git ls-files '*.md'); do
      [[ "$(sed -n 3p "$f")" == "$line" && "$(grep -v '^$' "$f" | tail -n 1)" == "$line" ]] || echo "missing: $f"
    done   # expect: no output
    ```

## 2. Publishing (each step requires explicit owner approval)

Nothing below is run by a script or by an assistant on its own. Each step is
irreversible or visible to others, so the owner approves it individually.

1. Create the GitHub repository `intragile/intrawatch` (public, MIT). Requires explicit owner approval.
2. Push `main` to it. Requires explicit owner approval.

   ```bash
   git remote add origin git@github.com:intragile/intrawatch.git
   git push -u origin main
   ```

3. Tag the release. Requires explicit owner approval.

   ```bash
   git tag -a v1.0.0 -m "IntraWatch 1.0.0"
   git push origin v1.0.0
   ```

4. Build and push the multi-arch image to `ghcr.io/intragile/intrawatch` and to
   Docker Hub with the tags `1.0.0`, `1.0`, `1` and `latest`. Requires explicit
   owner approval. The images are the ones verified in part 1, built from the
   tagged commit.

   ```bash
   docker buildx build --builder intrawatch-builder --platform linux/amd64,linux/arm64 \
     --file docker/Dockerfile --build-arg APP_VERSION=1.0.0 --push \
     --tag ghcr.io/intragile/intrawatch:1.0.0 --tag ghcr.io/intragile/intrawatch:1.0 \
     --tag ghcr.io/intragile/intrawatch:1 --tag ghcr.io/intragile/intrawatch:latest .
   docker buildx build --builder intrawatch-builder --platform linux/amd64,linux/arm64 \
     --file docker/Dockerfile --build-arg APP_VERSION=1.0.0 --push \
     --tag intragile/intrawatch:1.0.0 --tag intragile/intrawatch:1.0 \
     --tag intragile/intrawatch:1 --tag intragile/intrawatch:latest .
   ```

   The Docker Hub repository name and namespace must exist and match; confirm
   them before the first push.

5. Publish the GitHub release for `v1.0.0` with the `## [1.0.0]` section of
   [CHANGELOG.md](../CHANGELOG.md) as its notes. Requires explicit owner approval.

   ```bash
   gh release create v1.0.0 --title "IntraWatch 1.0.0" --notes-file <(sed -n '/^## \[1.0.0\]/,/^## \[/p' CHANGELOG.md | sed '$d')
   ```

## 3. Post-release checks

1. **Pull and run on a clean host** (no local images, no volume).

   ```bash
   docker run -d --name intrawatch --restart unless-stopped -p 3000:3000 \
     -v intrawatch-data:/data ghcr.io/intragile/intrawatch:1.0.0
   curl -fsS http://localhost:3000/up        # expect: HTTP 200
   docker image inspect ghcr.io/intragile/intrawatch:1.0.0 --format '{{.Architecture}}'
   ```

   Repeat on one `amd64` and one `arm64` host, and check that `latest`, `1.0`
   and `1` resolve to the same digest as `1.0.0`
   (`docker buildx imagetools inspect ghcr.io/intragile/intrawatch:latest`).

2. **Five-minute check: first monitor and status page.** From a clean volume,
   within five minutes: open `http://localhost:3000/`, complete the setup
   wizard, create an HTTP monitor, wait for it to turn up, create a published
   status page with that monitor as a component and open `/status/<slug>`. This
   is the first success criterion of the design (section 8).

3. Update the README quick start once the registry image exists: reword the
   "not published yet" note and the local-build fallback. This is a plain local
   edit and needs no approval; pushing the resulting commit follows the push
   approval of part 2.

4. Confirm that the GitHub release, the tag, both registries and the README
   quick start agree on the version.

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
