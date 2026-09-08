import Database from 'better-sqlite3';
import path from 'node:path';
import fs from 'node:fs';

const dbPath = process.env.DB_PATH || './data/gumipont.db';

if (dbPath !== ':memory:') {
  const dir = path.dirname(dbPath);
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
}

const db = new Database(dbPath);
db.pragma('foreign_keys = ON');
if (dbPath !== ':memory:') db.pragma('journal_mode = WAL');

db.exec(`
CREATE TABLE IF NOT EXISTS users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  name          TEXT NOT NULL,
  email         TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  role          TEXT NOT NULL CHECK(role IN ('user','admin')) DEFAULT 'user',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS products (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  brand       TEXT NOT NULL,
  model       TEXT NOT NULL,
  width       INTEGER NOT NULL,
  profile     INTEGER NOT NULL,
  rim         INTEGER NOT NULL,
  season      TEXT NOT NULL CHECK(season IN ('summer','winter','all-season')),
  price       REAL NOT NULL,
  stock       INTEGER NOT NULL DEFAULT 0,
  speed       TEXT,
  load_index  TEXT,
  image       TEXT
);

CREATE TABLE IF NOT EXISTS orders (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id          INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  status           TEXT NOT NULL CHECK(status IN ('new','confirmed','shipped','done')) DEFAULT 'new',
  delivery_method  TEXT NOT NULL CHECK(delivery_method IN ('courier','pickup')),
  payment_method   TEXT NOT NULL CHECK(payment_method IN ('card','transfer','cash')),
  subtotal         REAL NOT NULL,
  discount         REAL NOT NULL DEFAULT 0,
  shipping_cost    REAL NOT NULL DEFAULT 0,
  total            REAL NOT NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS order_items (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id    INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  product_id  INTEGER NOT NULL REFERENCES products(id),
  qty         INTEGER NOT NULL,
  unit_price  REAL NOT NULL
);

CREATE TABLE IF NOT EXISTS settings (
  key    TEXT PRIMARY KEY,
  value  TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS pages (
  id      TEXT PRIMARY KEY,
  title   TEXT NOT NULL,
  content TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS site_content (
  key    TEXT PRIMARY KEY,
  value  TEXT NOT NULL
);
`);

export default db;
