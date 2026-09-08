import { Router } from 'express';
import multer from 'multer';
import * as XLSX from 'xlsx';
import { z } from 'zod';
import db from '../db.js';
import { requireAdmin } from '../middleware/auth.js';

const router = Router();
const upload = multer({ storage: multer.memoryStorage(), limits: { fileSize: 5 * 1024 * 1024 } });

router.get('/', (req, res) => {
  const rows = db.prepare('SELECT id, title, content FROM pages').all();
  res.json({ pages: rows });
});

router.get('/:id', (req, res) => {
  const row = db.prepare('SELECT id, title, content FROM pages WHERE id = ?').get(req.params.id);
  if (!row) return res.status(404).json({ error: 'Page not found' });
  res.json({ page: row });
});

const metaSchema = z.object({
  id: z.string().trim().min(1),
  title: z.string().trim().min(1),
});

// Excel/CSV uploads are parsed server-side (via SheetJS) into an HTML table
// so the client never has to ship a spreadsheet parser or raw workbook data.
router.post('/', requireAdmin, upload.single('file'), (req, res) => {
  const parsed = metaSchema.safeParse(req.body);
  if (!parsed.success) {
    return res.status(400).json({ error: 'Invalid input', details: parsed.error.flatten() });
  }
  const { id, title } = parsed.data;

  let content = req.body.content ?? '';
  if (req.file) {
    let workbook;
    try {
      workbook = XLSX.read(req.file.buffer, { type: 'buffer' });
    } catch {
      return res.status(400).json({ error: 'Could not parse the uploaded file' });
    }
    content = workbook.SheetNames.map((name) =>
      XLSX.utils.sheet_to_html(workbook.Sheets[name], { header: `<h4>${name}</h4>` })
    ).join('\n');
  }

  db.prepare(
    `INSERT INTO pages (id, title, content) VALUES (?, ?, ?)
     ON CONFLICT(id) DO UPDATE SET title = excluded.title, content = excluded.content`
  ).run(id, title, content);

  res.status(201).json({ page: { id, title, content } });
});

router.delete('/:id', requireAdmin, (req, res) => {
  const info = db.prepare('DELETE FROM pages WHERE id = ?').run(req.params.id);
  if (info.changes === 0) return res.status(404).json({ error: 'Page not found' });
  res.status(204).end();
});

export default router;
