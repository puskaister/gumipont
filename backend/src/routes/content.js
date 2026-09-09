import { Router } from 'express';
import db from '../db.js';
import { requireAdmin } from '../middleware/auth.js';

const router = Router();
const ALLOWED_KEYS = new Set(['shop_intro', 'about', 'contact', 'terms']);

router.get('/', (req, res) => {
  const rows = db.prepare('SELECT key, value FROM site_content').all();
  const content = {};
  for (const row of rows) {
    try {
      content[row.key] = JSON.parse(row.value);
    } catch {
      content[row.key] = row.value;
    }
  }
  res.json({ content });
});

router.put('/:key', requireAdmin, (req, res) => {
  const { key } = req.params;
  if (!ALLOWED_KEYS.has(key)) return res.status(400).json({ error: 'Unknown content key' });

  const value = req.body?.value;
  if (value === undefined) return res.status(400).json({ error: '"value" is required' });

  db.prepare(
    `INSERT INTO site_content (key, value) VALUES (?, ?)
     ON CONFLICT(key) DO UPDATE SET value = excluded.value`
  ).run(key, JSON.stringify(value));

  res.json({ key, value });
});

export default router;
