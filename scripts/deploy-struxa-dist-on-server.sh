#!/usr/bin/env bash
# Run ON the struxapoint server (no git required): bash scripts/deploy-struxa-dist-on-server.sh
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

# Catalog Admin reads publish.json + repo.json from public/struxa-dist
if [[ -f "$ROOT/struxa-dist/publish.json" ]]; then
  cp "$ROOT/struxa-dist/publish.json" "$DEST/publish.json"
fi
if [[ -f "$ROOT/public/struxa-dist/repo.json" ]]; then
  cp "$ROOT/public/struxa-dist/repo.json" "$DEST/repo.json"
elif [[ -f "$ROOT/struxa-dist/repo.json" ]]; then
  cp "$ROOT/struxa-dist/repo.json" "$DEST/repo.json"
fi

for slug in knowledge-base-plugin struxa-admin forum-plugin struxa-theme default; do
  zip="$slug.zip"
  if [[ -f "$ROOT/public/struxa-dist/zips/$zip" ]]; then
    cp "$ROOT/public/struxa-dist/zips/$zip" "$DEST/zips/$zip"
  elif [[ -f "$ROOT/struxa-dist/zips/$zip" ]]; then
    cp "$ROOT/struxa-dist/zips/$zip" "$DEST/zips/$zip"
  fi
done

echo "==> public/struxa-dist/repo.json"
if [[ -f "$DEST/repo.json" ]]; then
  grep -E 'generated_at|"slug"|"version"' "$DEST/repo.json" | head -20
else
  echo "MISSING: $DEST/repo.json — upload public/struxa-dist/ from your dev machine"
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
echo "Done. In Admin → Struxa catalog → Catalog settings: set dist root to:"
echo "  $DEST"
echo "Then click Import from repo.json"
