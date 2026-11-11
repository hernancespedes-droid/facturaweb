#!/usr/bin/env bash
# Levanta el sistema en http://127.0.0.1:8080 con la base de desarrollo.
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck disable=SC1091
source "$ROOT/bin/env.sh"
exec php -S "127.0.0.1:${PORT:-8080}" -t "$ROOT/public" "$ROOT/public/index.php"
