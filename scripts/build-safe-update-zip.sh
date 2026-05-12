#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "==> composer install --no-dev (refresh vendor for package)"
composer install --no-dev --optimize-autoloader --no-interaction

STAMP=$(date +%Y%m%d-%H%M%S)
OUTDIR="$ROOT/dist"
mkdir -p "$OUTDIR"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

DEST="$TMP/cms-update"
mkdir -p "$DEST"

echo "==> staging files (excludes .env, storage, uploads, git, tests)"
rsync -a \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='.cursor' \
  --exclude='.idea' \
  --exclude='.env' \
  --exclude='storage' \
  --exclude='public/uploads' \
  --exclude='tests' \
  --exclude='node_modules' \
  --exclude='dist' \
  --exclude='*.zip' \
  "$ROOT/" "$DEST/"

mkdir -p "$DEST/public/uploads"
if [[ -f "$ROOT/public/uploads/.htaccess" ]]; then
  cp "$ROOT/public/uploads/.htaccess" "$DEST/public/uploads/"
fi

cp "$ROOT/scripts/FTP_UPDATE_README.txt" "$DEST/FTP_UPDATE_README.txt"

ZIP="$OUTDIR/struxa-cms-safe-update-${STAMP}.zip"
rm -f "$ZIP"
(cd "$TMP" && zip -r -q "$ZIP" cms-update)

echo "==> Created: $ZIP"
ls -lh "$ZIP"

echo "==> composer install (restore dev dependencies for local workspace)"
composer install --no-interaction
