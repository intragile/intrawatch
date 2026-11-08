#!/usr/bin/env bash
# Checks that scripts/build-image.sh only writes its archive under build/ or outside the repository, so a
# release archive can never end up in the Docker build context. A stub `docker` that exits 99 stands in for
# the real one: exit 99 means the --out check let the path through and nothing was built.
# Usage: bash tests/docker/build-image-test.sh
set -euo pipefail

cd "$(dirname "$0")/../.."
REPO="$(pwd -P)"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"; rmdir "$REPO/build/build-image-test" 2>/dev/null || true' EXIT
mkdir -p "$TMP/bin" "$TMP/outside"
printf '#!/bin/sh\nexit 99\n' >"$TMP/bin/docker"
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

if [[ $failures -gt 0 ]]; then
  echo "BUILD-IMAGE TEST FAILED ($failures)" >&2
  exit 1
fi
echo "BUILD-IMAGE TEST OK"
