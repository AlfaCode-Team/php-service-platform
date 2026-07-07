#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# setup-zig.sh — install the PINNED Zig toolchain in CI, resiliently.
#
# The version in tools/.zig-version is a Zig *dev* build, which upstream purges
# from ziglang.org/builds after a few months. To keep CI reproducible we fetch
# it from a SELF-HOSTED GitHub release first (ZIG_DIST_URL), then fall back to
# the public mirrors for as long as they keep it.
#
# Publish the self-hosted copy once with tools/ci/publish-zig-toolchain.sh and
# set the repo variable ZIG_DIST_URL to that release's download base, e.g.
#   https://github.com/AlfaCode-Team/php-service-platform/releases/download/zig-toolchain
#
# Adds the extracted toolchain dir to GITHUB_PATH (or prints it locally).
# ---------------------------------------------------------------------------
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VER="$(cat "$HERE/../.zig-version")"

case "$(uname -s)" in
  Linux)  ZOS=linux ;;
  Darwin) ZOS=macos ;;
  *)      ZOS=linux ;;
esac
case "$(uname -m)" in
  x86_64|amd64)  ZARCH=x86_64 ;;
  arm64|aarch64) ZARCH=aarch64 ;;
  *)             ZARCH=x86_64 ;;
esac

VERSIONED="zig-${ZOS}-${ZARCH}-${VER}.tar.xz"   # ziglang.org / mirror naming
SELFHOSTED="zig-${ZOS}-${ZARCH}.tar.xz"          # release-asset naming (no '+')

DEST="${RUNNER_TEMP:-/tmp}/zig-toolchain"
mkdir -p "$DEST"
TARBALL="$DEST/zig.tar.xz"

# Ordered candidate URLs: self-hosted first, then public mirrors.
urls=()
[ -n "${ZIG_DIST_URL:-}" ] && urls+=("${ZIG_DIST_URL%/}/${SELFHOSTED}")
urls+=("https://ziglang.org/builds/${VERSIONED}")
urls+=("https://pkg.machengine.org/zig/${VERSIONED}")

fetched=""
for u in "${urls[@]}"; do
  echo "▶ trying $u"
  if curl -fSL --retry 3 --retry-delay 4 -o "$TARBALL" "$u"; then fetched="$u"; break; fi
done
if [ -z "$fetched" ]; then
  echo "ERROR: could not fetch Zig $VER from any source."
  if [ -z "${ZIG_DIST_URL:-}" ]; then
    echo "  The repo variable ZIG_DIST_URL is not set, and this pinned dev build has"
    echo "  been purged from the public mirrors. Publish the toolchain once with:"
    echo "    ZIG_HOME=/opt/zig ./tools/ci/publish-zig-toolchain.sh"
    echo "  then: gh variable set ZIG_DIST_URL -b '<release-download-base>'"
  fi
  exit 1
fi
echo "✓ fetched from $fetched"

tar -xf "$TARBALL" -C "$DEST"
# Locate the zig binary regardless of the extracted top-level dir name.
ZIG_BIN="$(find "$DEST" -maxdepth 2 -type f -name zig | head -1)"
[ -n "$ZIG_BIN" ] || { echo "ERROR: zig binary not found after extract"; exit 1; }
ZIG_DIR="$(dirname "$ZIG_BIN")"

if [ -n "${GITHUB_PATH:-}" ]; then
  echo "$ZIG_DIR" >> "$GITHUB_PATH"
fi
echo "Zig installed at $ZIG_DIR"
"$ZIG_BIN" version
