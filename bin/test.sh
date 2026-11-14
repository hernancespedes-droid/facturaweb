#!/usr/bin/env bash
# Corre todas las pruebas: de lógica/base de datos (PHP) y de navegador (Playwright).
#   bin/test.sh          todo
#   bin/test.sh --unit   solo las de PHP (rápidas, no necesitan navegador)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
# shellcheck disable=SC1091
source "$ROOT/bin/env.sh"

echo "==> Migrando base de prueba"
DB_NAME=facturaweb_test php bin/migrate.php

echo "==> Pruebas de PHP"
php tests/run.php

if [[ "${1:-}" == "--unit" ]]; then
    exit 0
fi

echo "==> Pruebas de navegador (Playwright)"
php bin/migrate.php
node e2e/recorrido.js
node e2e/arca.js
node e2e/cuenta-corriente.js
