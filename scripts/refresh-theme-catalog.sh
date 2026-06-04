#!/usr/bin/env bash
# One command: rebuild struxa-theme.zip and update public/struxa-dist/repo.json (no Admin step).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
php bin/refresh-theme-catalog.php
