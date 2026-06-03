#!/usr/bin/env bash
# Overwrite themes/struxa-theme/ from GitHub when git pull or CMS update did not refresh it.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
THEME="$ROOT/themes/struxa-theme"
REF="${1:-main}"
REPO="${STRUXA_GITHUB_REPO:-struxa/struxa}"

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

echo "==> Downloading ${REPO} @ ${REF} (themes/struxa-theme only)"
curl -fsSL "https://github.com/${REPO}/archive/refs/heads/${REF}.tar.gz" | tar xz -C "$TMP"
SRC=$(find "$TMP" -mindepth 1 -maxdepth 1 -type d | head -1)
PKG="$SRC/themes/struxa-theme"
if [[ ! -f "$PKG/theme.json" ]]; then
  echo "ERROR: Archive has no themes/struxa-theme/theme.json" >&2
  exit 1
fi

if [[ -d "$THEME" ]]; then
  BACKUP="$ROOT/themes/struxa-theme.backup.$(date +%Y%m%d%H%M%S)"
  echo "==> Backing up current theme to $(basename "$BACKUP")"
  cp -a "$THEME" "$BACKUP"
fi

mkdir -p "$(dirname "$THEME")"
rsync -a --delete "$PKG/" "$THEME/"

echo "==> Installed theme version:"
grep '"version"' "$THEME/theme.json" | head -1
echo ""
echo "Next:"
echo "  bash scripts/republish-bundled-theme.sh"
echo "  Admin → Struxa catalog → Regenerate catalog"
