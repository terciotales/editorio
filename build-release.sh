#!/usr/bin/env bash
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
DIST_DIR="$PLUGIN_DIR/dist"
RELEASE_DIR="$DIST_DIR/editorio"
ZIP_FILE="$DIST_DIR/editorio.zip"

rm -rf "$DIST_DIR"
mkdir -p "$RELEASE_DIR"

cd "$PLUGIN_DIR"

# 1) Build front-end/editor assets into bundle/
npm run build

# 2) Install PHP deps for production only
if [ -f "$PLUGIN_DIR/composer.json" ]; then
  composer install --no-dev --optimize-autoloader --no-interaction
fi

# 3) Copy only runtime files
rsync -a \
  --exclude ".git/" \
  --exclude ".idea/" \
  --exclude "node_modules/" \
  --exclude "src/" \
  --exclude "dist/" \
  --exclude "package.json" \
  --exclude "package-lock.json" \
  --exclude "webpack.config.js" \
  --exclude "composer.json" \
  --exclude "composer.lock" \
  --exclude "build-release.sh" \
  --exclude ".editorconfig" \
  --exclude ".gitignore" \
  --exclude "AGENTS.md" \
  --exclude "README.md" \
  "$PLUGIN_DIR/" "$RELEASE_DIR/"

# 4) Create installable zip
cd "$DIST_DIR"
zip -qr "$(basename "$ZIP_FILE")" "$(basename "$RELEASE_DIR")"

echo "Release generated: $ZIP_FILE"

