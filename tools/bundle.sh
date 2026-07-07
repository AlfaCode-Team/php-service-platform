#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# bundle.sh — build the `hkm` launcher for every OS and assemble installable
# bundles under dist/. Run from the repo root or from tools/.
#
#   ./tools/bundle.sh            # build all: linux .deb tree, macos, windows zip
#   ./tools/bundle.sh linux      # only one target
#
# What a bundle contains:
#   • the native `hkm` + `hkm-config` launcher (Zig, statically linked)
#   • the kernel PHP source (src/) + vendor/ (composer --no-dev)
#   • the PHP CLI (bin/psp) installed AS bin/hkm so the launcher's default
#     passthrough path (<kernel>/bin/hkm) resolves.
#
# End users still need PHP >= 8.2 on PATH — `hkm doctor` verifies it.
# ---------------------------------------------------------------------------
set -euo pipefail

ZIG_VER="0.17.0-dev.657+2faf8debf"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TOOLS="$ROOT/tools"
DIST="$ROOT/dist"
VERSION="${VERSION:-$(git -C "$ROOT" describe --tags --always 2>/dev/null || echo 0.0.0)}"
VERSION="${VERSION#v}"
KERNEL_DIRNAME="hkm-kernel"   # install prefix basename → /opt/hkm-kernel

want="${1:-all}"
say() { printf '\033[36m▶ %s\033[0m\n' "$*"; }

# --- 0. prerequisites the BUILD host needs (not the end user) ---------------
command -v zig >/dev/null    || { echo "zig not found (need $ZIG_VER)"; exit 1; }
command -v composer >/dev/null || { echo "composer not found"; exit 1; }

# --- 1. kernel PHP runtime (shared by every bundle) -------------------------
say "composer install (--no-dev)"
( cd "$ROOT" && composer install --no-dev --optimize-autoloader --no-interaction )

stage_kernel() { # $1 = destination kernel root
  local k="$1"
  mkdir -p "$k/bin"

  # Ship the kernel PHP source via an EXPLICIT allowlist — never the repo root —
  # so .claude/, .github/, .git/, tests, docs and any gitignored junk (var/cache,
  # compiled manifests, node_modules, .zig-cache) can never leak into a release.
  #
  # src/  : copy ONLY git-tracked files, which by definition excludes everything
  #         in .gitignore. Falls back to a filtered cp when not in a git checkout.
  if git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    ( cd "$ROOT" && git ls-files -z src ) | while IFS= read -r -d '' f; do
      mkdir -p "$k/$(dirname "$f")"
      cp "$ROOT/$f" "$k/$f"
    done
  else
    cp -r "$ROOT/src" "$k/"
    # strip common gitignored artifacts if the fallback ran
    rm -rf "$k/src/var" "$k/src"/**/.zig-cache 2>/dev/null || true
  fi

  # vendor/ is gitignored but REQUIRED at runtime → copied wholesale, minus VCS
  # metadata and package tests to keep the bundle lean.
  cp -r "$ROOT/vendor" "$k/"
  find "$k/vendor" -type d \( -name '.git' -o -name '.github' \) -prune -exec rm -rf {} + 2>/dev/null || true

  cp    "$ROOT/composer.json" "$k/"
  cp    "$ROOT/composer.lock" "$k/" 2>/dev/null || true
  cp    "$ROOT/README.md"     "$k/" 2>/dev/null || true
  cp    "$ROOT/LICENSE"       "$k/" 2>/dev/null || true
  # PHP CLI installed under the name the launcher expects (bin/hkm).
  cp    "$ROOT/bin/psp"       "$k/bin/hkm"
  chmod +x "$k/bin/hkm"

  # Runtime install ships NO documentation or build tooling: strip every `docs`/
  # `doc` and `tools` directory from the staged tree (kernel src + vendor). None
  # are needed to run a project, and they bloat the package.
  find "$k" -depth -type d \( -name docs -o -name doc -o -name tools \) -exec rm -rf {} + 2>/dev/null || true
}

build_zig() { # $1 = zig target triple, $2 = out dir
  say "zig build $1"
  ( cd "$TOOLS" && zig build --release=small -Dtarget="$1" -p "$2" )
}

rm -rf "$DIST"; mkdir -p "$DIST"

# ─── Linux: .deb (amd64) ────────────────────────────────────────────────────
if [[ "$want" == all || "$want" == linux ]]; then
  build_zig x86_64-linux-gnu "$DIST/_zig/linux"
  PKG="hkm-kernel_${VERSION}_amd64"; P="$DIST/$PKG"
  mkdir -p "$P/DEBIAN" "$P/usr/bin"
  stage_kernel "$P/opt/$KERNEL_DIRNAME"
  cp "$DIST/_zig/linux/bin/hkm"        "$P/usr/bin/hkm"
  cp "$DIST/_zig/linux/bin/hkm-config" "$P/usr/bin/hkm-config"
  chmod +x "$P/usr/bin/hkm" "$P/usr/bin/hkm-config"
  cat > "$P/DEBIAN/control" <<EOF
Package: hkm-kernel
Version: ${VERSION}
Architecture: amd64
Maintainer: AlfacodeTeam <dev@hkm.local>
Depends: php-cli (>= 8.2), php-mbstring, php-curl, php-xml, ca-certificates
Recommends: php-mysql | php-pgsql | php-sqlite3, php-redis
Description: PhpServicePlatform (HKM) kernel and native launcher
 Installs the kernel PHP runtime under /opt/hkm-kernel and a native hkm
 launcher in /usr/bin so projects can live anywhere. Run 'hkm doctor'
 after install to verify PHP and required extensions.
EOF
  cat > "$P/DEBIAN/postinst" <<'EOF'
#!/bin/sh
set -e
echo "hkm-kernel installed. Verify your environment with:  hkm doctor"
exit 0
EOF
  chmod +x "$P/DEBIAN/postinst"
  dpkg-deb --build "$P" >/dev/null
  say "wrote $DIST/${PKG}.deb"
fi

# ─── macOS: universal .app tarball (arm64 + x86_64) ─────────────────────────
if [[ "$want" == all || "$want" == macos ]]; then
  build_zig aarch64-macos "$DIST/_zig/mac-arm"
  build_zig x86_64-macos  "$DIST/_zig/mac-x86"
  APP="$DIST/HKM.app"
  mkdir -p "$APP/Contents/MacOS"
  stage_kernel "$APP/Contents/Resources/opt/$KERNEL_DIRNAME"
  # `lipo` only exists on macOS; when cross-bundling on Linux ship the arm64
  # slice and note it. On a mac runner this produces a true universal binary.
  if command -v lipo >/dev/null; then
    lipo -create "$DIST/_zig/mac-arm/bin/hkm"        "$DIST/_zig/mac-x86/bin/hkm"        -output "$APP/Contents/MacOS/hkm"
    lipo -create "$DIST/_zig/mac-arm/bin/hkm-config" "$DIST/_zig/mac-x86/bin/hkm-config" -output "$APP/Contents/MacOS/hkm-config"
  else
    cp "$DIST/_zig/mac-arm/bin/hkm"        "$APP/Contents/MacOS/hkm"
    cp "$DIST/_zig/mac-arm/bin/hkm-config" "$APP/Contents/MacOS/hkm-config"
    echo "note: lipo unavailable — macOS bundle is arm64-only (run on a mac for universal)"
  fi
  chmod +x "$APP/Contents/MacOS/hkm" "$APP/Contents/MacOS/hkm-config"
  cat > "$APP/Contents/Info.plist" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>CFBundleName</key><string>HKM</string>
  <key>CFBundleExecutable</key><string>hkm</string>
  <key>CFBundleIdentifier</key><string>team.alfacode.hkm-kernel</string>
  <key>CFBundleVersion</key><string>${VERSION}</string>
  <key>CFBundleShortVersionString</key><string>${VERSION}</string>
  <key>CFBundlePackageType</key><string>APPL</string>
</dict></plist>
EOF
  ( cd "$DIST" && tar -czf "hkm-kernel-${VERSION}-macos-universal.tar.gz" HKM.app )
  say "wrote $DIST/hkm-kernel-${VERSION}-macos-universal.tar.gz"
fi

# ─── Windows: .zip (x86_64) ─────────────────────────────────────────────────
if [[ "$want" == all || "$want" == windows ]]; then
  build_zig x86_64-windows-gnu "$DIST/_zig/win"
  B="$DIST/hkm-kernel-win"
  stage_kernel "$B/$KERNEL_DIRNAME"
  cp "$DIST/_zig/win/bin/hkm.exe"        "$B/hkm.exe"
  cp "$DIST/_zig/win/bin/hkm-config.exe" "$B/hkm-config.exe"
  cat > "$B/INSTALL.txt" <<'EOF'
HKM Kernel — Windows
====================
1. Extract this folder to C:\hkm  (or any path).
2. setx HKM_KERNEL_HOME C:\hkm\hkm-kernel
3. Add the folder containing hkm.exe to your PATH.
4. Install PHP >= 8.2 (winget install PHP.PHP) and open a new terminal.
5. Verify:  hkm doctor
EOF
  ( cd "$DIST" && zip -qr "hkm-kernel-${VERSION}-windows-x86_64.zip" hkm-kernel-win )
  say "wrote $DIST/hkm-kernel-${VERSION}-windows-x86_64.zip"
fi

rm -rf "$DIST/_zig"
say "done — artifacts in $DIST/"
ls -la "$DIST"
