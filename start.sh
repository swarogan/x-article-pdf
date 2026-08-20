#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
PORT="${1:-8080}"
command -v php >/dev/null || { echo "Brak PHP w PATH."; exit 1; }
command -v composer >/dev/null || { echo "Brak Composera w PATH."; exit 1; }
[ -d vendor ] || composer install --ignore-platform-req=ext-gd
mkdir -p storage/tmp
echo "Otwórz w przeglądarce: http://127.0.0.1:${PORT}"
exec php -S "127.0.0.1:${PORT}" -t public
