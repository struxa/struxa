#!/usr/bin/env bash
# Thin wrapper — publishing lives in struxa-admin (Admin → Catalog settings).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
exec php bin/refresh-theme-catalog.php "$@"
