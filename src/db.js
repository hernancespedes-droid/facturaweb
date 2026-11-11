'use strict';
const fs = require('node:fs');
const path = require('node:path');
const { DatabaseSync } = require('node:sqlite');

const SCHEMA = `
CREATE TABLE IF NOT EXISTS empresa (
  id              INTEGER PRIMARY KEY CHECK (id = 1),
  razon_social    TEXT NOT NULL DEFAULT 'Mi Empresa',
  cuit            TEXT NOT NULL DEFAULT '',
  domicilio       TEXT NOT NULL DEFAULT '',
  condicion_iva   TEXT NOT NULL DEFAULT 'Responsable Inscripto',
  inicio_actividades TEXT NOT NULL DEFAULT '',
  punto_venta     INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS clientes (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre        TEXT NOT NULL,
  cuit          TEXT NOT NULL DEFAULT '',
  condicion_iva TEXT NOT NULL DEFAULT 'Consumidor Final',
  domicilio     TEXT NOT NULL DEFAULT '',
  email         TEXT NOT NULL DEFAULT '',
  telefono      TEXT NOT NULL DEFAULT '',
  activo        INTEGER NOT NULL DEFAULT 1,
  creado        TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS productos (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  codigo        TEXT NOT NULL DEFAULT '',
  descripcion   TEXT NOT NULL,
  precio        REAL NOT NULL DEFAULT 0,      -- precio neto (sin IVA)
  alicuota_iva  REAL NOT NULL DEFAULT 21,
  activo        INTEGER NOT NULL DEFAULT 1,
  creado        TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS facturas (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tipo          TEXT NOT NULL CHECK (tipo IN ('A','B','C')),
  punto_venta   INTEGER NOT NULL,
  numero        INTEGER NOT NULL,
  fecha         TEXT NOT NULL,
  vencimiento   TEXT,
  cliente_id    INTEGER NOT NULL REFERENCES clientes(id),
  -- copia de datos del cliente al momento de emitir
  cliente_nombre        TEXT NOT NULL,
  cliente_cuit          TEXT NOT NULL,
  cliente_condicion_iva TEXT NOT NULL,
  cliente_domicilio     TEXT NOT NULL,
  neto          REAL NOT NULL,
  iva           REAL NOT NULL,
  total         REAL NOT NULL,
  estado        TEXT NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('pendiente','pagada','anulada')),
  observaciones TEXT NOT NULL DEFAULT '',
  creado        TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE (tipo, punto_venta, numero)
);

CREATE TABLE IF NOT EXISTS factura_items (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  factura_id      INTEGER NOT NULL REFERENCES facturas(id) ON DELETE CASCADE,
  producto_id     INTEGER REFERENCES productos(id),
  descripcion     TEXT NOT NULL,
  cantidad        REAL NOT NULL,
  precio_unitario REAL NOT NULL,   -- neto
  alicuota_iva    REAL NOT NULL,
  neto            REAL NOT NULL,
  iva             REAL NOT NULL,
  total           REAL NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_facturas_cliente ON facturas(cliente_id);
CREATE INDEX IF NOT EXISTS idx_facturas_fecha ON facturas(fecha);
CREATE INDEX IF NOT EXISTS idx_items_factura ON factura_items(factura_id);

INSERT OR IGNORE INTO empresa (id) VALUES (1);
`;

function openDb(file) {
  if (file !== ':memory:') fs.mkdirSync(path.dirname(file), { recursive: true });
  const db = new DatabaseSync(file);
  db.exec('PRAGMA foreign_keys = ON;');
  if (file !== ':memory:') db.exec('PRAGMA journal_mode = WAL;');
  db.exec(SCHEMA);
  return db;
}

function tx(db, fn) {
  db.exec('BEGIN IMMEDIATE');
  try {
    const r = fn();
    db.exec('COMMIT');
    return r;
  } catch (e) {
    db.exec('ROLLBACK');
    throw e;
  }
}

module.exports = { openDb, tx };
