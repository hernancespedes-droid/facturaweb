#!/bin/bash
# Arranca MariaDB local (solo para pruebas) y ejecuta el comando recibido
if ! mysql -S /tmp/mdb/sock -e "select 1" >/dev/null 2>&1; then
  rm -f /tmp/mdb/sock
  mariadbd --user=root --datadir=/tmp/mdb/data --socket=/tmp/mdb/sock --port=3307 --skip-grant-tables --log-error=/tmp/mdb/err.log --innodb-use-native-aio=0 --pid-file=/tmp/mdb/pid &
  for i in $(seq 1 30); do mysql -S /tmp/mdb/sock -e "select 1" >/dev/null 2>&1 && break; sleep 0.5; done
fi
"$@"
