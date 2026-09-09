import { Router } from 'express';
import crypto from 'node:crypto';
import bcrypt from 'bcrypt';
import { z } from 'zod';
import rateLimit from 'express-rate-limit';
import db from '../db.js';
import { signToken } from '../lib/jwt.js';
import { requireAuth, requireAdmin, COOKIE_NAME } from '../middleware/auth.js';

const router = Router();

const authLimiter = rateLimit({
  windowMs: 15 * 60 * 1000,
  // The test suite makes far more than 20 auth requests per file against a
  // single shared app instance; only relax the limit under NODE_ENV=test.
  limit: process.env.NODE_ENV === 'test' ? 1000 : 20,
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
});

// Public sign-up always creates a plain 'user' account. There is no
// client-supplied way to get the 'admin' role — see POST /admins below,
// which only an already-authenticated admin can call.
router.post('/register', (req, res) => {
  const parsed = registerSchema.safeParse(req.body);
  if (!parsed.success) {
    return res.status(400).json({ error: 'Invalid input', details: parsed.error.flatten() });
  }
  const { name, email, password } = parsed.data;

  const existing = db.prepare('SELECT id FROM users WHERE email = ?').get(email);
  if (existing) return res.status(409).json({ error: 'Email already registered' });

  const passwordHash = bcrypt.hashSync(password, 12);
  const info = db
    .prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)')
    .run(name, email, passwordHash, 'user');

  const user = { id: info.lastInsertRowid, name, email, role: 'user' };
  const token = signToken({ id: user.id, role: user.role });
  res.cookie(COOKIE_NAME, token, cookieOptions);
  res.status(201).json({ user });
});

// Admin-only: create another admin account. The caller's own session is
// untouched (no cookie is set for the newly created account).
router.post('/admins', requireAdmin, (req, res) => {
  const parsed = registerSchema.safeParse(req.body);
  if (!parsed.success) {
    return res.status(400).json({ error: 'Invalid input', details: parsed.error.flatten() });
  }
  const { name, email, password } = parsed.data;

  const existing = db.prepare('SELECT id FROM users WHERE email = ?').get(email);
  if (existing) return res.status(409).json({ error: 'Email already registered' });

  const passwordHash = bcrypt.hashSync(password, 12);
  const info = db
    .prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)')
    .run(name, email, passwordHash, 'admin');

  res.status(201).json({ user: { id: info.lastInsertRowid, name, email, role: 'admin' } });
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

const RESET_TOKEN_TTL_MS = 60 * 60 * 1000; // 1 hour

function hashToken(token) {
  return crypto.createHash('sha256').update(token).digest('hex');
}

const forgotPasswordSchema = z.object({
  email: z.string().trim().toLowerCase().email(),
});

router.post('/forgot-password', (req, res) => {
  const parsed = forgotPasswordSchema.safeParse(req.body);
  if (!parsed.success) return res.status(400).json({ error: 'Invalid input' });
  const { email } = parsed.data;

  const user = db.prepare('SELECT id FROM users WHERE email = ?').get(email);
  // Respond identically whether or not the account exists, so this endpoint
  // can't be used to find out which emails are registered.
  if (user) {
    const token = crypto.randomBytes(32).toString('hex');
    const expires = new Date(Date.now() + RESET_TOKEN_TTL_MS).toISOString();
    db.prepare('UPDATE users SET reset_token_hash = ?, reset_token_expires = ? WHERE id = ?').run(
      hashToken(token),
      expires,
      user.id
    );

    const origin = process.env.FRONTEND_ORIGIN || 'http://localhost:8080';
    const resetUrl = `${origin}/vulc-gumi-webshop-szerkesztheto.html?resetToken=${token}`;
    // No email service is configured for this project, so the link is
    // logged server-side instead of being sent out. Wire up a real mailer
    // here (and drop this console.log) before going to production.
    console.log(`[jelszó-visszaállítás] ${email} -> ${resetUrl}`);
  }

  res.json({
    message: 'Ha létezik fiók ezzel az email címmel, elküldtük a jelszó-visszaállító linket.',
  });
});

const resetPasswordSchema = z.object({
  token: z.string().min(1),
  password: z.string().min(8).max(200),
});

router.post('/reset-password', (req, res) => {
  const parsed = resetPasswordSchema.safeParse(req.body);
  if (!parsed.success) return res.status(400).json({ error: 'Invalid input' });
  const { token, password } = parsed.data;

  const row = db
    .prepare('SELECT * FROM users WHERE reset_token_hash = ? AND reset_token_expires > ?')
    .get(hashToken(token), new Date().toISOString());
  if (!row) return res.status(400).json({ error: 'Érvénytelen vagy lejárt link.' });

  const passwordHash = bcrypt.hashSync(password, 12);
  db.prepare(
    'UPDATE users SET password_hash = ?, reset_token_hash = NULL, reset_token_expires = NULL WHERE id = ?'
  ).run(passwordHash, row.id);

  const jwtToken = signToken({ id: row.id, role: row.role });
  res.cookie(COOKIE_NAME, jwtToken, cookieOptions);
  res.json({ user: { id: row.id, name: row.name, email: row.email, role: row.role } });
});

export default router;
