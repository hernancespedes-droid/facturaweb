#!/usr/bin/env bash
# Variables del entorno de desarrollo y prueba (NO son las del servidor de producción).
export DB_HOST=127.0.0.1
export DB_PORT=3306
export DB_USER=facturaweb
export DB_PASS=facturaweb_dev
export DB_NAME="${DB_NAME:-facturaweb_dev}"
export APP_ENV="${APP_ENV:-development}"
