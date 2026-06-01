#!/usr/bin/env bash
# Run ON the struxapoint server: bash scripts/deploy-struxa-dist-on-server.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

git pull origin main

DEST="$ROOT/public/struxa-dist"
mkdir -p "$DEST/zips"

# Catalog Admin reads publish.json from public/struxa-dist — keep in sync with git
cp struxa-dist/publish.json "$DEST/publish.json"
cp public/struxa-dist/repo.json "$DEST/repo.json"
cp public/struxa-dist/zips/knowledge-base-plugin.zip "$DEST/zips/" 2>/dev/null || \
  cp struxa-dist/zips/knowledge-base-plugin.zip "$DEST/zips/" 2>/dev/null || true
cp public/struxa-dist/zips/struxa-admin.zip "$DEST/zips/" 2>/dev/null || \
  cp struxa-dist/zips/struxa-admin.zip "$DEST/zips/" 2>/dev/null || true
cp public/struxa-dist/zips/default.zip "$DEST/zips/" 2>/dev/null || \
  cp struxa-dist/zips/default.zip "$DEST/zips/" 2>/dev/null || true

echo "==> public/struxa-dist/repo.json"
grep -E 'generated_at|"slug"' "$DEST/repo.json" | head -10

echo ""
echo "==> Live check"
curl -sS https://struxapoint.com/struxa-dist/repo.json | grep -E 'generated_at|knowledge-base' || echo "WARN: knowledge-base not visible at public URL yet (cache or wrong docroot?)"
