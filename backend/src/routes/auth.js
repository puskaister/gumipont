import { Router } from 'express';
import bcrypt from 'bcrypt';
import { z } from 'zod';
import rateLimit from 'express-rate-limit';
import db from '../db.js';
import { signToken } from '../lib/jwt.js';
import { requireAuth, COOKIE_NAME } from '../middleware/auth.js';

const router = Router();

const authLimiter = rateLimit({
  windowMs: 15 * 60 * 1000,
  limit: 20,
  standardHeaders: true,
  legacyHeaders: false,
});
router.use(authLimiter);

const cookieOptions = {
  httpOnly: true,
  secure: process.env.NODE_ENV === 'production',
  sameSite: 'strict',
  maxAge: 15 * 60 * 1000,
};

const registerSchema = z.object({
  name: z.string().trim().min(1).max(120),
  email: z.string().trim().toLowerCase().email(),
  password: z.string().min(8).max(200),
  adminCode: z.string().optional(),
});

router.post('/register', (req, res) => {
  const parsed = registerSchema.safeParse(req.body);
  if (!parsed.success) {
    return res.status(400).json({ error: 'Invalid input', details: parsed.error.flatten() });
  }
  const { name, email, password, adminCode } = parsed.data;

  const existing = db.prepare('SELECT id FROM users WHERE email = ?').get(email);
  if (existing) return res.status(409).json({ error: 'Email already registered' });

  const isAdmin =
    !!adminCode &&
    !!process.env.ADMIN_REGISTRATION_CODE &&
    adminCode === process.env.ADMIN_REGISTRATION_CODE;
  const role = isAdmin ? 'admin' : 'user';

  const passwordHash = bcrypt.hashSync(password, 12);
  const info = db
    .prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)')
    .run(name, email, passwordHash, role);

  const user = { id: info.lastInsertRowid, name, email, role };
  const token = signToken({ id: user.id, role: user.role });
  res.cookie(COOKIE_NAME, token, cookieOptions);
  res.status(201).json({ user });
});

const loginSchema = z.object({
  email: z.string().trim().toLowerCase().email(),
  password: z.string().min(1),
});

router.post('/login', (req, res) => {
  const parsed = loginSchema.safeParse(req.body);
  if (!parsed.success) return res.status(400).json({ error: 'Invalid input' });
  const { email, password } = parsed.data;

  const row = db.prepare('SELECT * FROM users WHERE email = ?').get(email);
  if (!row || !bcrypt.compareSync(password, row.password_hash)) {
    return res.status(401).json({ error: 'Invalid email or password' });
  }

  const token = signToken({ id: row.id, role: row.role });
  res.cookie(COOKIE_NAME, token, cookieOptions);
  res.json({ user: { id: row.id, name: row.name, email: row.email, role: row.role } });
});

router.post('/logout', (req, res) => {
  res.clearCookie(COOKIE_NAME);
  res.status(204).end();
});

router.get('/me', requireAuth, (req, res) => {
  const row = db
    .prepare('SELECT id, name, email, role, created_at FROM users WHERE id = ?')
    .get(req.user.id);
  if (!row) return res.status(404).json({ error: 'User not found' });
  res.json({ user: row });
});

export default router;
