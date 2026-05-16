#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/struxa-dist"
ZIPS="$DIST/zips"
mkdir -p "$ZIPS"

echo "==> Building theme ZIPs into struxa-dist/zips/"
for theme_dir in "$ROOT"/themes/*/; do
  [[ -d "$theme_dir" ]] || continue
  slug="$(basename "$theme_dir")"
  [[ -f "$theme_dir/theme.json" ]] || continue
  out="$ZIPS/${slug}.zip"
  echo "  $slug"
  rm -f "$out"
  (cd "$theme_dir" && zip -rq "$out" . -x '*.git/*' -x '.DS_Store')
done

echo "==> Building plugin ZIPs into struxa-dist/zips/"
for plugin_dir in "$ROOT"/plugins/*/; do
  [[ -d "$plugin_dir" ]] || continue
  slug="$(basename "$plugin_dir")"
  [[ -f "$plugin_dir/plugin.json" ]] || continue
  out="$ZIPS/${slug}.zip"
  echo "  $slug"
  rm -f "$out"
  (cd "$plugin_dir" && zip -rq "$out" . \
    -x '*.git/*' \
    -x '.DS_Store' \
    -x 'vendor/*' \
    -x 'node_modules/*')
done

echo "==> Generating repo.json from manifests"
php "$ROOT/bin/build-struxa-dist-catalog.php"

echo "==> Done. Publish $DIST to https://struxapoint.com/struxa-dist/ (repo.json + zips/)"
