#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/struxa-dist"
ZIPS="$DIST/zips"
PUBLISH="$DIST/publish.json"
mkdir -p "$ZIPS"

# Public catalog allowlist (struxapoint.com + git). Edit struxa-dist/publish.json to change.
read_publish() {
  if [[ -f "$PUBLISH" ]]; then
    PUBLISH_THEMES=$(php -r '$p=json_decode(file_get_contents($argv[1]),true); foreach($p["themes"]??["default"] as $t){echo $t," ";}' "$PUBLISH")
    PUBLISH_PLUGINS=$(php -r '$p=json_decode(file_get_contents($argv[1]),true); echo !empty($p["include_plugins"])?"1":"0";' "$PUBLISH")
  else
    PUBLISH_THEMES="default "
    PUBLISH_PLUGINS=0
  fi
}
read_publish

echo "==> Publish allowlist: themes=(${PUBLISH_THEMES%/}) plugins=$([[ "$PUBLISH_PLUGINS" == 1 ]] && echo yes || echo no)"

echo "==> Building theme ZIPs into struxa-dist/zips/"
for theme_dir in "$ROOT"/themes/*/; do
  [[ -d "$theme_dir" ]] || continue
  slug="$(basename "$theme_dir")"
  [[ -f "$theme_dir/theme.json" ]] || continue
  if [[ " ${PUBLISH_THEMES} " != *" ${slug} "* ]]; then
    continue
  fi
  out="$ZIPS/${slug}.zip"
  echo "  $slug"
  rm -f "$out"
  (cd "$theme_dir" && zip -rq "$out" . -x '*.git/*' -x '.DS_Store')
done

if [[ "$PUBLISH_PLUGINS" == 1 ]]; then
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
else
  echo "==> Skipping plugin ZIPs (include_plugins=false in publish.json)"
fi

echo "==> Removing ZIPs not in publish allowlist"
for zip in "$ZIPS"/*.zip; do
  [[ -f "$zip" ]] || continue
  base="$(basename "$zip" .zip)"
  keep=0
  if [[ " ${PUBLISH_THEMES} " == *" ${base} "* ]]; then
    keep=1
  elif [[ "$PUBLISH_PLUGINS" == 1 && -f "$ROOT/plugins/${base}/plugin.json" ]]; then
    keep=1
  fi
  if [[ "$keep" == 0 ]]; then
    echo "  remove $(basename "$zip")"
    rm -f "$zip"
  fi
done

echo "==> Generating repo.json from manifests"
php "$ROOT/bin/build-struxa-dist-catalog.php"

echo "==> Done. Publish $DIST to https://struxapoint.com/struxa-dist/ (repo.json + zips/)"
