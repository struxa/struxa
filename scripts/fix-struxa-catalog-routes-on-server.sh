#!/bin/bash
# Run on the server from the Struxa project root (e.g. /home/bushell/public_html/struxapoint).
# Installs core files that register catalog admin URLs. Safe to re-run.
set -euo pipefail

ROOT="${1:-.}"
cd "$ROOT"

BASE="${STRUXA_GITHUB_RAW:-https://raw.githubusercontent.com/struxa/struxa/main}"

echo "==> Project root: $(pwd)"

fetch() {
  local rel="$1"
  echo "    $rel"
  curl -fsSL "$BASE/$rel" -o "$rel"
}

echo "==> Downloading CMS files (1.1.58+)..."
fetch bootstrap/web_app.php
fetch src/Plugin/PluginManager.php
fetch src/Plugin/StruxaCatalogAdminRouteRegistrar.php
fetch routes/admin_plugins.php
fetch templates/admin/plugins/index.twig
fetch templates/admin/plugins/browse.twig
fetch src/CmsVersion.php

echo "==> Empty plugin routes/admin.php (core registers URLs)..."
mkdir -p plugins/struxa-admin/routes
cat > plugins/struxa-admin/routes/admin.php << 'ENDPHP'
<?php

declare(strict_types=1);

use Slim\App;

return static function (App $app, \App\Plugin\PluginBootContext $ctx): void {
};
ENDPHP

echo "==> PHP syntax check..."
php -l bootstrap/web_app.php
php -l src/Plugin/StruxaCatalogAdminRouteRegistrar.php
php -l src/Plugin/PluginManager.php

echo "==> Bootstrap + route check..."
fetch scripts/verify-catalog-admin-routes.php
php scripts/verify-catalog-admin-routes.php 2>&1 | grep -v fieldFormContext || true

echo "==> Clear cache..."
php bin/cms.php cache:clear 2>/dev/null || true
rm -rf storage/cache/twig/* 2>/dev/null || true

echo "==> Done. Reload Admin → Extensions → Plugins in the browser."
