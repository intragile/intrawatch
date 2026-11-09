#!/usr/bin/env bash
# Checks that scripts/build-image.sh only writes its archive under build/ or outside the repository, so a
# release archive can never end up in the Docker build context, and that the image is labelled with its own
# revision and build time instead of the ones it would inherit from the FrankenPHP base image. A stub `docker`
# stands in for the real one: it records the `buildx build` arguments and exits 99 there, so exit 99 means the
# --out check let the path through and nothing was built.
# Usage: bash tests/docker/build-image-test.sh
set -euo pipefail

cd "$(dirname "$0")/../.."
REPO="$(pwd -P)"

TMP="$(mktemp -d)"
# The `build/../docs` case needs build/ to exist, as it does after any local build; a fresh checkout (CI) has
# none, so create it and remove it again afterwards.
CREATED_BUILD=0
if [[ ! -d "$REPO/build" ]]; then
  mkdir "$REPO/build"
  CREATED_BUILD=1
fi
trap 'rm -rf "$TMP"; rmdir "$REPO/build/build-image-test" 2>/dev/null || true; [[ $CREATED_BUILD -eq 0 ]] || rmdir "$REPO/build" 2>/dev/null || true' EXIT
mkdir -p "$TMP/bin" "$TMP/outside"
cat >"$TMP/bin/docker" <<STUB
#!/bin/sh
if [ "\$1 \$2" = "buildx build" ]; then
  printf '%s\\n' "\$@" >"$TMP/build-args"
  exit 99
fi
exit 0
STUB
chmod 0755 "$TMP/bin/docker"
ln -s "$REPO/docs" "$TMP/link-into-repo"

failures=0
fail() { echo "FAIL: $*" >&2; failures=$((failures + 1)); }

# run_out <--out value>: sets CODE and ERR.
run_out() {
  local err
  err="$(mktemp)"
  CODE=0
  PATH="$TMP/bin:$PATH" timeout 60 bash scripts/build-image.sh --platforms linux/amd64 --out "$1" >/dev/null 2>"$err" || CODE=$?
  ERR="$(cat "$err")"
  rm -f "$err"
}

MESSAGE='error: --out must be under build/ or outside the repository'
for out in docs/build-image-test ./build-image-test build-image-test/ "$REPO" "$REPO/" \
  build/../docs/build-image-test new-dir/../../intrawatch-escape "$TMP/link-into-repo/x"; do
  run_out "$out"
  # A `..` below a directory that does not exist yet cannot be resolved and has its own message.
  if [[ $CODE -eq 2 && ("$ERR" == *"$MESSAGE"* || ("$out" == new-dir/* && "$ERR" == *"error: --out must not contain .."*)) ]]; then
    echo "ok: --out $out is refused"
  else
    fail "--out $out: exit $CODE, expected 2 with the error message (stderr: $ERR)"
  fi
done
for created in docs/build-image-test build-image-test new-dir; do
  [[ ! -e "$created" ]] || fail "a refused --out created $created"
done

for out in build build/ build/build-image-test "$REPO/build/build-image-test" "$TMP/outside/archives"; do
  run_out "$out"
  if [[ $CODE -eq 99 ]]; then
    echo "ok: --out $out is accepted"
  else
    fail "--out $out: exit $CODE, expected the stub docker's 99 (stderr: $ERR)"
  fi
done

# The image carries its own revision, build time and URL; without them it shows the base image's labels.
rm -f "$TMP/build-args"
run_out build
args="$(cat "$TMP/build-args" 2>/dev/null || true)"
revision="$(git rev-parse HEAD)"
[[ -n "$(git status --porcelain)" ]] && revision="${revision}-dirty"
if grep -qxF "VCS_REF=${revision}" <<<"$args"; then
  echo "ok: the build gets VCS_REF=${revision}"
else
  fail "the build does not get VCS_REF=${revision} (args: ${args//$'\n'/ })"
fi
if grep -qE '^BUILD_DATE=[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$' <<<"$args"; then
  echo "ok: the build gets an RFC 3339 BUILD_DATE"
else
  fail "the build does not get BUILD_DATE in RFC 3339 UTC (args: ${args//$'\n'/ })"
fi
labels="$(awk '/^LABEL /,!/\\$/' docker/Dockerfile)"
for label in 'org.opencontainers.image.revision="${VCS_REF}"' 'org.opencontainers.image.created="${BUILD_DATE}"' \
  'org.opencontainers.image.url="https://github.com/intragile/intrawatch"'; do
  if grep -qF "$label" <<<"$labels"; then
    echo "ok: the Dockerfile sets $label"
  else
    fail "the Dockerfile does not set $label over the base image's label"
  fi
done

if [[ $failures -gt 0 ]]; then
  echo "BUILD-IMAGE TEST FAILED ($failures)" >&2
  exit 1
fi
echo "BUILD-IMAGE TEST OK"
