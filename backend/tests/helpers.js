import bcrypt from 'bcrypt';
import request from 'supertest';
import db from '../src/db.js';
import app from '../src/app.js';

// Bootstraps an admin account directly in the DB (mirrors scripts/create-admin.js)
// and returns a logged-in supertest agent for it. Public registration can no
// longer grant the admin role, so tests need this instead.
export async function adminAgent(email, password = 'jelszo1234') {
  const passwordHash = bcrypt.hashSync(password, 12);
  db.prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)').run(
    'Admin',
    email,
    passwordHash,
    'admin'
  );
  const agent = request.agent(app);
  await agent.post('/api/auth/login').send({ email, password });
  return agent;
}
