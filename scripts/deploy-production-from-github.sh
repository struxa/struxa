#!/usr/bin/env bash
# Pull current CMS + struxa-admin files from GitHub main onto this server (no FTP).
# Run ON struxapoint from the project root, e.g.:
#   cd ~/public_html/struxapoint && curl -fsSL https://raw.githubusercontent.com/struxa/struxa/main/scripts/deploy-production-from-github.sh | bash
set -euo pipefail

ROOT="${1:-$(pwd)}"
cd "$ROOT"

BASE="${STRUXA_GITHUB_RAW:-https://raw.githubusercontent.com/struxa/struxa/main}"

echo "==> Struxa deploy from GitHub main"
echo "    Root: $(pwd)"

fetch() {
  local rel="$1"
  local dir
  dir="$(dirname "$rel")"
  if [[ "$dir" != '.' ]]; then
    mkdir -p "$dir"
  fi
  echo "    $rel"
  curl -fsSL "$BASE/$rel" -o "$rel"
}

FILES=(
  src/CmsVersion.php
  bootstrap/web_app.php
  src/Plugin/StruxaCatalogAdminRouteRegistrar.php
  src/Plugin/StruxaCatalogStackShipper.php
  src/Http/Middleware/TwigCmsGlobals.php
  routes/admin_plugins.php
  templates/admin/partials/sidebar_nav.twig
  plugins/struxa-admin/plugin.json
  plugins/struxa-admin/src/GitHubRepoClient.php
  plugins/struxa-admin/views/public/_catalog_layout.twig
  scripts/verify-theme-repo-github.php
  scripts/verify-catalog-admin-routes.php
)

echo "==> Downloading ${#FILES[@]} files..."
for rel in "${FILES[@]}"; do
  fetch "$rel"
done

echo "==> PHP syntax check..."
php -l src/Plugin/StruxaCatalogAdminRouteRegistrar.php
php -l plugins/struxa-admin/src/GitHubRepoClient.php

if [[ -f scripts/verify-theme-repo-github.php ]]; then
  echo "==> Theme repo validation (airline-theme)..."
  php scripts/verify-theme-repo-github.php || true
fi

if [[ -f scripts/verify-catalog-admin-routes.php ]]; then
  echo "==> Catalog admin routes..."
  php scripts/verify-catalog-admin-routes.php 2>&1 | grep -E '^(OK|skipReason|Registered:|  admin\.|NO )' || true
fi

echo "==> Clear caches..."
php bin/cms.php cache:clear 2>/dev/null || true
rm -rf storage/cache/twig/* 2>/dev/null || true

echo ""
echo "==> Done ($(grep -o "CURRENT = '[^']*'" src/CmsVersion.php 2>/dev/null || echo 'see CmsVersion.php'))."
echo "    Reload Admin → Extensions → Plugins and /themes/submit in the browser."
