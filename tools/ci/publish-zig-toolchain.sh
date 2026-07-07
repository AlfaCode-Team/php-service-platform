#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# publish-zig-toolchain.sh — ONE-TIME: host the pinned Zig build so CI can fetch
# it after upstream purges the dev build.
#
# It packages a local Zig install into the release-asset name setup-zig.sh
# expects (zig-<os>-<arch>.tar.xz) and uploads it to a GitHub release tagged
# `zig-toolchain` on this repo. Then set the repo variable ZIG_DIST_URL to:
#   https://github.com/<owner>/<repo>/releases/download/zig-toolchain
#
# Usage:
#   ZIG_HOME=/opt/zig ./tools/ci/publish-zig-toolchain.sh
#
# Requirements: gh (authenticated), tar. Only the host's OS/arch is published;
# because CI cross-compiles every target on Linux, publishing the Linux build is
# sufficient. Re-run on a mac to add the macOS asset if you ever build there.
# ---------------------------------------------------------------------------
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
VER="$(cat "$ROOT/tools/.zig-version")"
ZIG_HOME="${ZIG_HOME:-/opt/zig}"
TAG="zig-toolchain"

command -v gh >/dev/null || { echo "gh (GitHub CLI) required and must be authenticated"; exit 1; }
[ -x "$ZIG_HOME/zig" ] || { echo "no zig binary at \$ZIG_HOME/zig ($ZIG_HOME)"; exit 1; }

# Confirm the local toolchain matches the pinned version.
have="$("$ZIG_HOME/zig" version)"
[ "$have" = "$VER" ] || { echo "local zig is $have but .zig-version pins $VER"; exit 1; }

case "$(uname -s)" in Linux) ZOS=linux ;; Darwin) ZOS=macos ;; *) ZOS=linux ;; esac
case "$(uname -m)" in x86_64|amd64) ZARCH=x86_64 ;; arm64|aarch64) ZARCH=aarch64 ;; esac
ASSET="zig-${ZOS}-${ZARCH}.tar.xz"

TMP="$(mktemp -d)"
STAGE="$TMP/zig-${ZOS}-${ZARCH}-${VER}"
mkdir -p "$STAGE"
cp -a "$ZIG_HOME/." "$STAGE/"
echo "packaging $ASSET (this is large, ~40MB compressed)…"
tar -C "$TMP" -cJf "$TMP/$ASSET" "$(basename "$STAGE")"

# Create the release once, then upload/replace the OS-specific asset.
gh release view "$TAG" >/dev/null 2>&1 \
  || gh release create "$TAG" --title "Zig toolchain ($VER)" \
        --notes "Self-hosted pinned Zig toolchain for CI (upstream purges dev builds). Used by tools/ci/setup-zig.sh." \
        --prerelease
gh release upload "$TAG" "$TMP/$ASSET" --clobber

echo "✓ uploaded $ASSET to release '$TAG'"
echo "Now set the repo variable ZIG_DIST_URL:"
REPO="$(gh repo view --json nameWithOwner -q .nameWithOwner 2>/dev/null || echo '<owner>/<repo>')"
echo "  gh variable set ZIG_DIST_URL -b 'https://github.com/${REPO}/releases/download/${TAG}'"
rm -rf "$TMP"
