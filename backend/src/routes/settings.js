import { Router } from 'express';
import db from '../db.js';
import { requireAdmin } from '../middleware/auth.js';

const router = Router();

function readAll() {
  const rows = db.prepare('SELECT key, value FROM settings').all();
  const settings = {};
  for (const row of rows) {
    try {
      settings[row.key] = JSON.parse(row.value);
    } catch {
      settings[row.key] = row.value;
    }
  }
  return settings;
}

router.get('/', (req, res) => {
  res.json({ settings: readAll() });
});

router.put('/', requireAdmin, (req, res) => {
  const body = req.body;
  if (!body || typeof body !== 'object' || Array.isArray(body)) {
    return res.status(400).json({ error: 'Body must be an object of settings key/value pairs' });
  }

  const upsert = db.prepare(
    `INSERT INTO settings (key, value) VALUES (?, ?)
     ON CONFLICT(key) DO UPDATE SET value = excluded.value`
  );
  const tx = db.transaction((entries) => {
    for (const [key, value] of entries) upsert.run(key, JSON.stringify(value));
  });
  tx(Object.entries(body));

  res.json({ settings: readAll() });
});

export default router;
