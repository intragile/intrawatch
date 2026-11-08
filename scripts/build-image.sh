#!/usr/bin/env bash
# Builds the IntraWatch image for several platforms into a local OCI archive.
# Nothing is pushed. Usage:
#   scripts/build-image.sh [--version X.Y.Z] [--platforms linux/amd64,linux/arm64] [--out build]
#                          [--load-platform linux/amd64]
# --load-platform additionally loads one platform into the local Docker engine
# as intrawatch:<version>-<arch>, for the smoke test.
set -euo pipefail

cd "$(dirname "$0")/.."

VERSION="1.0.0"
PLATFORMS="linux/amd64,linux/arm64"
OUT="build"
LOAD_PLATFORM=""
BUILDER="intrawatch-builder"
BUILD_TIMEOUT="${BUILD_TIMEOUT:-3600}"

usage() {
  sed -n '2,8p' "$0" | sed 's/^# \{0,1\}//'
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --version) VERSION="${2:?--version needs a value}"; shift 2 ;;
    --platforms) PLATFORMS="${2:?--platforms needs a value}"; shift 2 ;;
    --out) OUT="${2:?--out needs a value}"; shift 2 ;;
    --load-platform) LOAD_PLATFORM="${2:?--load-platform needs a value}"; shift 2 ;;
    -h | --help) usage; exit 0 ;;
    *) echo "unknown option: $1" >&2; usage >&2; exit 2 ;;
  esac
done

PLATFORM_RE='^linux/[a-z0-9]+(/v[0-9]+)?(,linux/[a-z0-9]+(/v[0-9]+)?)*$'
[[ "$PLATFORMS" =~ $PLATFORM_RE ]] || { echo "invalid --platforms: $PLATFORMS" >&2; exit 2; }
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z.-]+)?$ ]] || { echo "invalid version: $VERSION" >&2; exit 2; }
if [[ -n "$LOAD_PLATFORM" && ",${PLATFORMS}," != *",${LOAD_PLATFORM},"* ]]; then
  echo "--load-platform ${LOAD_PLATFORM} is not part of --platforms ${PLATFORMS}" >&2
  exit 2
fi

# Prints the absolute physical path of $1: the nearest existing ancestor resolved with `pwd -P` (symlinks
# followed), plus the part that does not exist yet. Fails when that part contains `..`.
physical_path() {
  local base="$1" rest=""
  [[ "$base" == /* ]] || base="$PWD/$base"
  while [[ "$base" == */ && "$base" != / ]]; do base="${base%/}"; done
  while [[ ! -d "$base" ]]; do
    rest="/${base##*/}${rest}"
    base="$(dirname "$base")"
  done
  [[ "${rest}/" != */../* ]] || return 1
  base="$(cd "$base" && pwd -P)"
  printf '%s\n' "${base%/}${rest}"
}

# The archive must never land in the Docker build context (the repository, minus .dockerignore): only build/
# (ignored) and paths outside the repository are allowed. Compared case-insensitively, for macOS volumes.
REPO="$(pwd -P)"
OUT_ABS="$(physical_path "$OUT")" || { echo "error: --out must not contain .. below a directory that does not exist yet: $OUT" >&2; exit 2; }
lower() { tr '[:upper:]' '[:lower:]' <<<"$1"; }
out_lc="$(lower "$OUT_ABS")" repo_lc="$(lower "$REPO")"
if [[ "$out_lc" == "$repo_lc" || "$out_lc" == "$repo_lc"/* ]] \
  && [[ "$out_lc" != "$repo_lc/build" && "$out_lc" != "$repo_lc"/build/* ]]; then
  echo "error: --out must be under build/ or outside the repository, got $OUT ($OUT_ABS)" >&2
  exit 2
fi

# Prints the os/arch[/variant] of every platform manifest in an OCI archive, one per line.
archive_platforms() {
  local archive="$1" index digest blob
  index="$(tar -xOf "$archive" index.json)"
  for digest in $(jq -r '.manifests[].digest' <<<"$index"); do
    blob="$(tar -xOf "$archive" "blobs/${digest/:/\/}")"
    if jq -e '.manifests' <<<"$blob" >/dev/null 2>&1; then
      jq -r '.manifests[] | select(.platform.os != "unknown") | "\(.platform.os)/\(.platform.architecture)\(if .platform.variant then "/" + .platform.variant else "" end)"' <<<"$blob"
    else
      jq -r --arg d "$digest" '.manifests[] | select(.digest == $d) | .platform // empty | "\(.os)/\(.architecture)"' <<<"$index"
    fi
  done | sort -u
}

command -v jq >/dev/null 2>&1 || { echo "error: jq is required to verify the platforms in the archive" >&2; exit 1; }

mkdir -p "$OUT"
ARCHIVE="${OUT}/intrawatch-${VERSION}.oci.tar"

if ! timeout 60 docker buildx inspect "$BUILDER" >/dev/null 2>&1; then
  echo "== creating buildx builder ${BUILDER}"
  timeout 120 docker buildx create --name "$BUILDER" --driver docker-container >/dev/null
fi
timeout 300 docker buildx inspect "$BUILDER" --bootstrap >/dev/null

echo "== building ${PLATFORMS} (version ${VERSION}) -> ${ARCHIVE}"
started="$(date +%s)"
timeout "$BUILD_TIMEOUT" docker buildx build \
  --builder "$BUILDER" \
  --platform "$PLATFORMS" \
  --file docker/Dockerfile \
  --build-arg "APP_VERSION=${VERSION}" \
  --tag "intrawatch:${VERSION}" \
  --output "type=oci,dest=${ARCHIVE}" \
  .
echo "== multi-platform build finished in $(($(date +%s) - started))s"

echo "== platforms in ${ARCHIVE}"
found="$(archive_platforms "$ARCHIVE")"
echo "$found"
missing=""
IFS=',' read -r -a wanted <<<"$PLATFORMS"
for platform in "${wanted[@]}"; do
  grep -qxF "$platform" <<<"$found" || missing+=" ${platform}"
done
if [[ -n "$missing" ]]; then
  echo "error: platform(s) missing from ${ARCHIVE}:${missing}" >&2
  exit 1
fi
wc -c <"$ARCHIVE" | awk '{ printf "archive size: %.1f MiB\n", $1 / 1048576 }'

if [[ -n "$LOAD_PLATFORM" ]]; then
  arch="${LOAD_PLATFORM#*/}"
  tag="intrawatch:${VERSION}-${arch//\//-}"
  echo "== loading ${LOAD_PLATFORM} as ${tag}"
  started="$(date +%s)"
  timeout "$BUILD_TIMEOUT" docker buildx build \
    --builder "$BUILDER" \
    --platform "$LOAD_PLATFORM" \
    --file docker/Dockerfile \
    --build-arg "APP_VERSION=${VERSION}" \
    --tag "$tag" \
    --load \
    .
  echo "== load finished in $(($(date +%s) - started))s"
  docker image inspect "$tag" --format 'image size: {{.Size}} bytes ({{.Os}}/{{.Architecture}})'
fi
