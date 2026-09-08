import { Router } from 'express';
import multer from 'multer';
import path from 'node:path';
import crypto from 'node:crypto';
import { z } from 'zod';
import db from '../db.js';
import { requireAdmin } from '../middleware/auth.js';

const router = Router();

const uploadDir = path.join(process.cwd(), 'uploads');
const ALLOWED_IMAGE_TYPES = new Set(['image/jpeg', 'image/png', 'image/webp', 'image/gif']);

const upload = multer({
  storage: multer.diskStorage({
    destination: (req, file, cb) => cb(null, uploadDir),
    filename: (req, file, cb) => {
      const ext = path.extname(file.originalname).toLowerCase();
      cb(null, `${crypto.randomUUID()}${ext}`);
    },
  }),
  limits: { fileSize: 5 * 1024 * 1024 },
  fileFilter: (req, file, cb) => {
    if (!ALLOWED_IMAGE_TYPES.has(file.mimetype)) return cb(new Error('Unsupported image type'));
    cb(null, true);
  },
});

const productSchema = z.object({
  brand: z.string().trim().min(1),
  model: z.string().trim().min(1),
  width: z.coerce.number().int().positive(),
  profile: z.coerce.number().int().positive(),
  rim: z.coerce.number().int().positive(),
  season: z.enum(['summer', 'winter', 'all-season']),
  price: z.coerce.number().nonnegative(),
  stock: z.coerce.number().int().nonnegative().default(0),
  speed: z.string().trim().optional(),
  loadIndex: z.string().trim().optional(),
});

function serialize(row) {
  return {
    id: row.id,
    brand: row.brand,
    model: row.model,
    width: row.width,
    profile: row.profile,
    rim: row.rim,
    season: row.season,
    price: row.price,
    stock: row.stock,
    speed: row.speed,
    loadIndex: row.load_index,
    image: row.image,
  };
}

router.get('/', (req, res) => {
  const { width, profile, rim, season, brand } = req.query;
  const clauses = [];
  const params = [];
  if (width) {
    clauses.push('width = ?');
    params.push(Number(width));
  }
  if (profile) {
    clauses.push('profile = ?');
    params.push(Number(profile));
  }
  if (rim) {
    clauses.push('rim = ?');
    params.push(Number(rim));
  }
  if (season) {
    clauses.push('season = ?');
    params.push(String(season));
  }
  if (brand) {
    clauses.push('brand = ?');
    params.push(String(brand));
  }
  const where = clauses.length ? `WHERE ${clauses.join(' AND ')}` : '';
  const rows = db.prepare(`SELECT * FROM products ${where} ORDER BY id`).all(...params);
  res.json({ products: rows.map(serialize) });
});

router.post('/', requireAdmin, upload.single('image'), (req, res) => {
  const parsed = productSchema.safeParse(req.body);
  if (!parsed.success) {
    return res.status(400).json({ error: 'Invalid input', details: parsed.error.flatten() });
  }
  const p = parsed.data;
  const image = req.file ? `/uploads/${req.file.filename}` : null;
  const info = db
    .prepare(
      `INSERT INTO products (brand, model, width, profile, rim, season, price, stock, speed, load_index, image)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`
    )
    .run(p.brand, p.model, p.width, p.profile, p.rim, p.season, p.price, p.stock, p.speed ?? null, p.loadIndex ?? null, image);
  const row = db.prepare('SELECT * FROM products WHERE id = ?').get(info.lastInsertRowid);
  res.status(201).json({ product: serialize(row) });
});

router.put('/:id', requireAdmin, upload.single('image'), (req, res) => {
  const existing = db.prepare('SELECT * FROM products WHERE id = ?').get(req.params.id);
  if (!existing) return res.status(404).json({ error: 'Product not found' });

  const parsed = productSchema.partial().safeParse(req.body);
  if (!parsed.success) {
    return res.status(400).json({ error: 'Invalid input', details: parsed.error.flatten() });
  }
  const p = parsed.data;
  const image = req.file ? `/uploads/${req.file.filename}` : existing.image;

  db.prepare(
    `UPDATE products
     SET brand = ?, model = ?, width = ?, profile = ?, rim = ?, season = ?, price = ?, stock = ?, speed = ?, load_index = ?, image = ?
     WHERE id = ?`
  ).run(
    p.brand ?? existing.brand,
    p.model ?? existing.model,
    p.width ?? existing.width,
    p.profile ?? existing.profile,
    p.rim ?? existing.rim,
    p.season ?? existing.season,
    p.price ?? existing.price,
    p.stock ?? existing.stock,
    p.speed ?? existing.speed,
    p.loadIndex ?? existing.load_index,
    image,
    req.params.id
  );

  const row = db.prepare('SELECT * FROM products WHERE id = ?').get(req.params.id);
  res.json({ product: serialize(row) });
});

router.delete('/:id', requireAdmin, (req, res) => {
  const info = db.prepare('DELETE FROM products WHERE id = ?').run(req.params.id);
  if (info.changes === 0) return res.status(404).json({ error: 'Product not found' });
  res.status(204).end();
});

export default router;
