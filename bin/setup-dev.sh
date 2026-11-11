#!/usr/bin/env bash
# Deja listo el entorno de desarrollo y prueba desde cero (Ubuntu, como root).
# Es idempotente: se puede correr las veces que haga falta. Tarda un par de minutos la primera vez.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

step() { printf '\n==> %s\n' "$1"; }

step "1/4 MySQL 8"
if ! command -v mysqld >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq || true   # algún repositorio externo puede fallar; alcanza con el de Ubuntu
    apt-get install -y -qq mysql-server >/dev/null
fi
if ! mysqladmin ping --silent >/dev/null 2>&1; then
    mkdir -p /var/run/mysqld && chown mysql:mysql /var/run/mysqld
    (mysqld_safe >/tmp/mysqld_safe.log 2>&1 &)
    for _ in $(seq 1 40); do
        mysqladmin ping --silent >/dev/null 2>&1 && break
        sleep 1
    done
fi
mysqladmin ping --silent >/dev/null 2>&1 || { echo "MySQL no arrancó. Ver /tmp/mysqld_safe.log"; exit 1; }
echo "MySQL $(mysql -N -e 'SELECT VERSION()') corriendo"

step "2/4 Bases y usuario de desarrollo"
mysql <<'SQL'
CREATE DATABASE IF NOT EXISTS facturaweb_dev  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS facturaweb_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'facturaweb'@'localhost' IDENTIFIED BY 'facturaweb_dev';
CREATE USER IF NOT EXISTS 'facturaweb'@'127.0.0.1' IDENTIFIED BY 'facturaweb_dev';
GRANT ALL PRIVILEGES ON facturaweb_dev.*  TO 'facturaweb'@'localhost';
GRANT ALL PRIVILEGES ON facturaweb_test.* TO 'facturaweb'@'localhost';
GRANT ALL PRIVILEGES ON facturaweb_dev.*  TO 'facturaweb'@'127.0.0.1';
GRANT ALL PRIVILEGES ON facturaweb_test.* TO 'facturaweb'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

step "3/4 Pruebas de navegador"
node -e "require('playwright')" 2>/dev/null && echo "Playwright disponible" || echo "Sin Playwright: bin/test.sh --unit corre solo las de PHP"

step "4/4 Migraciones de la base de desarrollo"
# shellcheck disable=SC1091
source "$ROOT/bin/env.sh"
php bin/migrate.php

printf '\nListo. Correr las pruebas: bin/test.sh   |   Levantar el sistema: bin/dev-server.sh\n'
