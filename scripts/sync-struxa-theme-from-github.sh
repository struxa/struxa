#!/usr/bin/env bash
# Overwrite themes/struxa-theme/ from GitHub when git pull or CMS update did not refresh it.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
THEME="$ROOT/themes/struxa-theme"
REF="${1:-main}"
REPO="${STRUXA_GITHUB_REPO:-struxa/struxa}"
CATALOG_ZIP="${STRUXA_THEME_ZIP:-$ROOT/public/struxa-dist/zips/struxa-theme.zip}"
CATALOG_URL="${STRUXA_THEME_ZIP_URL:-https://struxapoint.com/struxa-dist/zips/struxa-theme.zip}"

find_theme_package() {
  local root="$1" f dir
  while IFS= read -r f; do
    dir=$(dirname "$f")
    if [[ -d "$dir/views" && -d "$dir/assets" ]]; then
      echo "$dir"
      return 0
    fi
  done < <(find "$root" -name theme.json -type f 2>/dev/null)
  return 1
}

install_from_dir() {
  local pkg="$1"
  if [[ -d "$THEME" ]]; then
    BACKUP="$ROOT/themes/struxa-theme.backup.$(date +%Y%m%d%H%M%S)"
    echo "==> Backing up current theme to $(basename "$BACKUP")"
    cp -a "$THEME" "$BACKUP"
  fi
  mkdir -p "$(dirname "$THEME")"
  rsync -a --delete "$pkg/" "$THEME/"
  echo "==> Installed theme version:"
  grep '"version"' "$THEME/theme.json" | head -1
  echo ""
  echo "Next:"
  echo "  bash scripts/republish-bundled-theme.sh"
  echo "  Admin → Struxa catalog → Regenerate catalog"
}

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

echo "==> Downloading ${REPO} @ ${REF}"
if curl -fsSL "https://github.com/${REPO}/archive/refs/heads/${REF}.tar.gz" | tar xz -C "$TMP"; then
  SRC=$(find "$TMP" -mindepth 1 -maxdepth 1 -type d | head -1)
  PKG="$SRC/themes/struxa-theme"
  if [[ ! -f "$PKG/theme.json" ]]; then
    PKG=$(find_theme_package "$SRC" || true)
  fi
  if [[ -n "${PKG:-}" && -f "$PKG/theme.json" ]]; then
    echo "==> Using theme from GitHub archive: $PKG"
    install_from_dir "$PKG"
    exit 0
  fi
  echo "WARN: GitHub archive has no themes/struxa-theme (export-ignore on old .gitattributes?). Trying catalog ZIP…" >&2
else
  echo "WARN: GitHub download failed. Trying catalog ZIP…" >&2
fi

ZIP_WORK="$TMP/catalog.zip"
if [[ -f "$CATALOG_ZIP" ]]; then
  echo "==> Using local catalog ZIP: $CATALOG_ZIP"
  cp "$CATALOG_ZIP" "$ZIP_WORK"
elif curl -fsSL -o "$ZIP_WORK" "$CATALOG_URL"; then
  echo "==> Downloaded catalog ZIP from $CATALOG_URL"
else
  echo "ERROR: Could not restore theme from GitHub or catalog ZIP." >&2
  echo "  - Deploy latest CMS (fixes .gitattributes so GitHub archives include the theme)" >&2
  echo "  - Or place struxa-theme.zip at $CATALOG_ZIP" >&2
  echo "  - Or: git fetch origin && git checkout origin/main -- themes/struxa-theme  (full git clone only)" >&2
  exit 1
fi

EXTRACT="$TMP/zip-extract"
mkdir -p "$EXTRACT"
unzip -q "$ZIP_WORK" -d "$EXTRACT"
PKG=$(find_theme_package "$EXTRACT" || true)
if [[ -z "${PKG:-}" || ! -f "$PKG/theme.json" ]]; then
  echo "ERROR: Catalog ZIP has no valid theme (theme.json + views/ + assets/)." >&2
  exit 1
fi
echo "==> Using theme from catalog ZIP: $PKG"
install_from_dir "$PKG"
