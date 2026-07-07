#!/usr/bin/env sh
# ---------------------------------------------------------------------------
# install.sh — finalize an HKM kernel install by resolving PHP dependencies.
# Run ONCE after extracting the bundle (macOS/Windows-git-bash/manual Linux).
# The Debian .deb runs this logic automatically in its postinst.
#
#   cd <kernel dir>   # the folder holding composer.json (…/opt/hkm-kernel)
#   ./install.sh
#
# Requires on the TARGET machine: php >= 8.4 and composer, plus network access
# to download packages. vendor/ is intentionally NOT shipped — it is built here
# so the runtime matches the target's exact PHP.
# ---------------------------------------------------------------------------
set -eu

DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$DIR"

command -v php >/dev/null 2>&1 || { echo "error: php not found on PATH (need >= 8.4)"; exit 1; }

# If the bundle was built with MODULES=git, the composer PATH-repository modules
# were NOT shipped — fetch each at its pinned commit from GitHub. Skipped when
# modules/ was bundled (no modules.lock present).
if [ -f modules.lock ]; then
  command -v git >/dev/null 2>&1 || { echo "error: git required to fetch modules (modules.lock present)"; exit 1; }
  while read -r mpath murl msha; do
    [ -z "${mpath:-}" ] && continue
    if [ -f "$mpath/composer.json" ]; then continue; fi   # already present
    echo "Fetching module $mpath @ $(echo "$msha" | cut -c1-10) …"
    rm -rf "$mpath"
    git clone --quiet "$murl" "$mpath"
    git -C "$mpath" checkout --quiet "$msha"
    rm -rf "$mpath/.git"
  done < modules.lock
fi

# Locate composer: a system binary, else download composer.phar locally.
if command -v composer >/dev/null 2>&1; then
  COMPOSER="composer"
elif [ -f composer.phar ]; then
  COMPOSER="php composer.phar"
else
  echo "composer not found — fetching composer.phar locally…"
  php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
  php composer-setup.php --quiet
  rm -f composer-setup.php
  COMPOSER="php composer.phar"
fi

echo "Resolving PHP dependencies (composer install --no-dev)…"
# Allow running as root (postinst/root shells) and prefer optimized autoload.
COMPOSER_ALLOW_SUPERUSER=1 $COMPOSER install \
  --no-dev --optimize-autoloader --no-interaction --prefer-dist

echo "Done. vendor/ is ready. Verify with:  hkm doctor"
