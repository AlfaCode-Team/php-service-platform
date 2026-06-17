#!/bin/bash

set -e

COMMAND=$1
MODULE_NAME=$2
GIT_URL=$3
ORG=$4
MODE=$5   # optional: --offline

MODULE_PATH="modules/${MODULE_NAME}"

# -----------------------------
# HELP
# -----------------------------
if [ -z "$COMMAND" ]; then
  echo "Usage:"
  echo "  $0 add <module-name> <git-url> <org> [--offline]"
  echo "  $0 remove <module-name>"
  exit 1
fi

# -----------------------------
# REMOVE MODULE
# -----------------------------
if [ "$COMMAND" == "remove" ]; then

  if [ -z "$MODULE_NAME" ]; then
    echo "Usage: $0 remove <module-name>"
    exit 1
  fi

  echo "== Removing module: $MODULE_NAME =="

  git submodule deinit -f "$MODULE_PATH" || true
  git rm -f "$MODULE_PATH" || true
  rm -rf ".git/modules/$MODULE_PATH" || true
  rm -rf "$MODULE_PATH"

  if [ -f ".gitmodules" ]; then
    git config -f .gitmodules --remove-section "submodule.$MODULE_PATH" 2>/dev/null || true
    git add .gitmodules || true
  fi

  git commit -m "remove module $MODULE_NAME" || true

  echo "== Removed $MODULE_NAME =="
  exit 0
fi

# -----------------------------
# ADD MODULE
# -----------------------------
if [ -z "$MODULE_NAME" ] || [ -z "$GIT_URL" ] || [ -z "$ORG" ]; then
  echo "Usage: $0 add <module-name> <git-url> <org> [--offline]"
  exit 1
fi

echo "== Adding module: $MODULE_NAME =="

# Ensure jq exists
if ! command -v jq >/dev/null 2>&1; then
  echo "ERROR: jq is required. Install with: sudo apt install jq"
  exit 1
fi

# Check existing
if git config -f .gitmodules --get-regexp path 2>/dev/null | grep -q "$MODULE_PATH"; then
  echo "Module already exists"
  exit 0
fi

# Add submodule
git submodule add "$GIT_URL" "$MODULE_PATH"
git submodule update --init --recursive

# -----------------------------
# MODULE BOOTSTRAP
# -----------------------------
echo "== Checking module structure =="

MODULE_COMPOSER="${MODULE_PATH}/composer.json"
MODULE_SRC="${MODULE_PATH}/src"

# Create src if missing
if [ ! -d "$MODULE_SRC" ]; then
  echo "Creating src/..."
  mkdir -p "$MODULE_SRC"
fi

# Normalize namespace (PascalCase)
format_ns() {
  echo "$1" | sed -E 's/(^|-)([a-z])/\U\2/g'
}

NAMESPACE_ORG=$(format_ns "$ORG")
NAMESPACE_MODULE=$(format_ns "$MODULE_NAME")

# Create composer.json if missing
if [ ! -f "$MODULE_COMPOSER" ]; then
  echo "Creating module composer.json..."

  cat > "$MODULE_COMPOSER" <<EOF
{
  "name": "${ORG}/${MODULE_NAME}",
  "description": "Auto-generated module",
  "type": "library",
  "autoload": {
    "psr-4": {
      "${NAMESPACE_ORG}\\\\${NAMESPACE_MODULE}\\\\": "src/"
    }
  },
  "require": {
    "php": "^8.2"
  },
  "minimum-stability": "dev",
  "prefer-stable": true
}
EOF

fi

# -----------------------------
# UPDATE ROOT COMPOSER
# -----------------------------
echo "== Updating root composer.json =="

cp composer.json composer.json.bak

PACKAGE_NAME="${ORG}/${MODULE_NAME}"

jq --arg path "$MODULE_PATH" --arg pkg "$PACKAGE_NAME" '
  .repositories = (.repositories // [])
  | .require = (.require // {})

  | .repositories |= (
      map(select(.url != $path))
      + [{
          "type": "path",
          "url": $path
      }]
    )

  | .require[$pkg] = "*"
' composer.json > composer.tmp.json

if [ ! -s composer.tmp.json ]; then
  echo "ERROR: Failed to update composer.json"
  exit 1
fi

mv composer.tmp.json composer.json

# -----------------------------
# INSTALL DEPENDENCIES
# -----------------------------
echo "== Installing dependencies =="

if [ "$MODE" == "--offline" ]; then
  echo "Running in OFFLINE mode"
  COMPOSER_DISABLE_NETWORK=1 composer update --no-dev
else
  composer update
fi

echo "== Done successfully =="