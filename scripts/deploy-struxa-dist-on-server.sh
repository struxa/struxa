#!/usr/bin/env bash
# Run ON the struxapoint server after a CMS update (optional sanity check).
# Live catalog (repo.json, zips/) is owned by struxa-admin — never copied from git.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ -d "$ROOT/.git" ]]; then
  echo "==> git pull origin main"
  git pull origin main
else
  echo "==> No .git here — skipping git pull (upload/rsync CMS files from your dev machine instead)"
fi

DEST="$ROOT/public/struxa-dist"
mkdir -p "$DEST/zips"

echo ""
echo "==> Live catalog check (NOT synced from git)"
echo "    CMS updates and git pull must not overwrite repo.json or zips/."
echo "    Regenerate via Admin → Struxa catalog → Regenerate catalog after approving packages."

if [[ -f "$DEST/repo.json" ]]; then
  echo ""
  echo "==> public/struxa-dist/repo.json (on disk)"
  grep -E 'generated_at|"slug"|"version"|catalog_version' "$DEST/repo.json" | head -20
else
  echo ""
  echo "WARN: Missing $DEST/repo.json"
  echo "      Install struxa-admin, upload ZIPs, approve submissions, then Regenerate catalog."
  exit 1
fi

echo ""
echo "==> Zips on disk"
ls -la "$DEST/zips/"*.zip 2>/dev/null || echo "No zips in $DEST/zips/"

echo ""
echo "==> Live check"
if command -v curl >/dev/null 2>&1; then
  curl -sS https://struxapoint.com/struxa-dist/repo.json | grep -E 'generated_at|"slug"' | head -10 \
    || echo "WARN: repo.json not reachable at public URL (docroot or .htaccess?)"
fi

echo ""
echo "Done. Catalog dist root for struxa-admin:"
echo "  $DEST"
