#!/usr/bin/env bash
# Arma el paquete de entrega dist/facturaweb-<version>.zip listo para subir al servidor:
# sin pruebas ni herramientas de desarrollo, y sin config/local.php ni datos de storage/
# (para no pisar nada de lo que ya está en el servidor).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
VERSION="$(tr -d '[:space:]' < VERSION)"
OUT="$ROOT/dist/facturaweb-${VERSION}.zip"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

tar \
    --exclude='./.git' --exclude='./dist' \
    --exclude='./e2e' --exclude='./tests' --exclude='./CLAUDE.md' \
    --exclude='./bin/setup-dev.sh' --exclude='./bin/test.sh' \
    --exclude='./bin/dev-server.sh' --exclude='./bin/package.sh' --exclude='./bin/empaquetar.sh' --exclude='./bin/pruebas.sh' --exclude='./bin/env.sh' --exclude='./vfp' --exclude='./.claude' --exclude='./lib/afip_fe/tests' --exclude='./bin/demo-usuarios.php' --exclude='./bin/alta-cliente.php' --exclude='./bin/licencia.php' --exclude='./docs/clientes' \
    --exclude='./config/local.php' \
    --exclude='./storage/afip_simulado' --exclude='./storage/logs/*' --exclude='./storage/uploads/*' --exclude='./storage/sessions/*' \
    -cf - . | tar -xf - -C "$STAGE"

# Si el proyecto llega a usar librerías de Composer, se instalan solo las de producción.
if [[ -f composer.lock ]]; then
    (cd "$STAGE" && composer install --no-dev --optimize-autoloader --no-interaction --quiet)
fi

mkdir -p "$STAGE/storage/logs" "$STAGE/storage/uploads" "$STAGE/storage/sessions"
touch "$STAGE/storage/logs/.gitkeep" "$STAGE/storage/uploads/.gitkeep" "$STAGE/storage/sessions/.gitkeep"

mkdir -p "$ROOT/dist"
rm -f "$OUT"
(cd "$STAGE" && python3 -m zipfile -c "$OUT" .)

echo "Paquete listo: $OUT ($(du -h "$OUT" | cut -f1))"
