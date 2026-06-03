#!/usr/bin/env bash
# One command on the server: rebuild struxa-theme.zip, then refresh repo.json in Admin.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
bash scripts/republish-bundled-theme.sh
echo ""
echo "==> In Admin (same site):"
echo "    Struxa catalog → Regenerate catalog"
echo "    Themes → Reinstall from catalog (v… on the struxa-theme card)"
echo ""
echo "No git required. Deploy new CMS/theme files via Admin CMS update or FTP first if themes/struxa-theme/ is old."
