#!/usr/bin/env bash
# Rebuild public/struxa-dist/zips/struxa-theme.zip from themes/struxa-theme/ on this server.
# Run after git pull or CMS update so the catalog ZIP matches the bundled theme.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
THEME_DIR="$ROOT/themes/struxa-theme"
ZIP="$ROOT/public/struxa-dist/zips/struxa-theme.zip"
MANIFEST="$THEME_DIR/theme.json"

if [[ ! -f "$MANIFEST" ]]; then
  echo "ERROR: Missing $MANIFEST" >&2
  echo "Deploy latest CMS (git pull or self-update) so themes/struxa-theme/ exists." >&2
  exit 1
fi

echo "==> Bundled theme version on disk:"
grep '"version"' "$MANIFEST" | head -1

VER="$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo is_array($j)?trim((string)($j["version"]??"")):"";' "$MANIFEST")"
if [[ "$VER" == "" ]]; then
  echo "ERROR: Could not read version from theme.json" >&2
  exit 1
fi

if [[ "$VER" == "1.0.38" ]]; then
  echo "WARN: Bundled theme is still 1.0.38 — run: git pull origin main  (or CMS self-update to 1.2.8+)" >&2
fi

mkdir -p "$(dirname "$ZIP")"
rm -f "$ZIP"
echo "==> Building $ZIP"
(cd "$THEME_DIR" && zip -rq "$ZIP" . -x '*.git/*' -x '.DS_Store')

echo "==> ZIP manifest version:"
unzip -p "$ZIP" theme.json | grep '"version"' | head -1

echo ""
echo "Next: Admin → Struxa catalog → Regenerate catalog"
echo "      (updates repo.json from the ZIP). CMS 1.2.8+ / struxa-admin 1.0.24+ also sync on regenerate."
