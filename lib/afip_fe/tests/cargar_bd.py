"""Carga en la MariaDB de prueba las tablas de facturación de Reciclado (CSV exportados), para probar el adaptador."""
import csv, json, sys, subprocess

BASE = '/mnt/user-data/uploads/reciclados_cloud/export/Reciclado/'
TABLAS = ['subdiario', 'iva', 'tributo', 'Equivalencias_afip', 'fe_Actualizacion', 'fe_Configuracion', 'fe_Parametros',
          'fecaja', 'fectact', 'feestadistica']
schema = {t['tabla'].lower(): t for t in json.load(open(BASE + 'schema.json', encoding='utf-8-sig'))['tablas']}

def tipo(c):
    t = c['tipo']
    if t in ('varchar', 'char', 'nvarchar', 'nchar'):
        return 'text' if c['max_length'] == -1 else 'varchar(%d)' % max(c['max_length'], 1)
    if t in ('decimal', 'numeric'):
        return 'decimal(%d,%d)' % (c['precision'] or 18, c['scale'] or 0)
    return {'int': 'int', 'bit': 'tinyint', 'datetime': 'datetime', 'smalldatetime': 'datetime', 'money': 'decimal(19,4)',
            'float': 'double', 'text': 'text', 'ntext': 'text', 'bigint': 'bigint', 'smallint': 'smallint', 'date': 'date'}.get(t, 'text')

def lit(v):
    if v == '\\N':
        return 'NULL'
    return "'" + v.replace('\\', '\\\\').replace("'", "''") + "'"

out = ['DROP DATABASE IF EXISTS fe_test; CREATE DATABASE fe_test; USE fe_test;']
for t in TABLAS:
    s = schema[t.lower()]
    cols = s['columnas']
    out.append('CREATE TABLE %s (%s);' % (t.lower(), ', '.join('`%s` %s NULL' % (c['nombre'].lower(), tipo(c)) for c in cols)))
    filas = list(csv.reader(open(BASE + s['archivo'], encoding='utf-8-sig')))
    enc = [h.lower() for h in filas[0]]
    for i in range(1, len(filas), 300):
        bloque = filas[i:i + 300]
        out.append('INSERT INTO %s (%s) VALUES %s;' % (t.lower(), ','.join('`%s`' % h for h in enc),
                   ','.join('(' + ','.join(lit(v) for v in f) + ')' for f in bloque)))
sql = '\n'.join(out)
p = subprocess.run(['mysql', '-S', '/tmp/mdb/sock'], input=sql.encode('utf-8'), capture_output=True)
print(p.stdout.decode(), p.stderr.decode())
print('cargado' if p.returncode == 0 else 'ERROR')
