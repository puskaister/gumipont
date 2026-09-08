import { describe, it, expect } from 'vitest';
import request from 'supertest';
import app from '../src/app.js';

describe('auth', () => {
  it('registers a new user with the default role and sets a session cookie', async () => {
    const res = await request(app)
      .post('/api/auth/register')
      .send({ name: 'Teszt Elek', email: 'teszt@example.com', password: 'jelszo1234' });

    expect(res.status).toBe(201);
    expect(res.body.user.email).toBe('teszt@example.com');
    expect(res.body.user.role).toBe('user');
    expect(res.headers['set-cookie'][0]).toMatch(/^token=/);
  });

  it('rejects registration with an already-used email', async () => {
    await request(app)
      .post('/api/auth/register')
      .send({ name: 'A', email: 'dup@example.com', password: 'jelszo1234' });

    const res = await request(app)
      .post('/api/auth/register')
      .send({ name: 'B', email: 'dup@example.com', password: 'jelszo1234' });

    expect(res.status).toBe(409);
  });

  it('grants the admin role only with a valid admin code', async () => {
    const withCode = await request(app).post('/api/auth/register').send({
      name: 'Admin',
      email: 'admin@example.com',
      password: 'jelszo1234',
      adminCode: process.env.ADMIN_REGISTRATION_CODE,
    });
    expect(withCode.body.user.role).toBe('admin');

    const wrongCode = await request(app).post('/api/auth/register').send({
      name: 'Not Admin',
      email: 'notadmin@example.com',
      password: 'jelszo1234',
      adminCode: 'wrong-code',
    });
    expect(wrongCode.body.user.role).toBe('user');
  });

  it('logs in with correct credentials and rejects a wrong password', async () => {
    await request(app)
      .post('/api/auth/register')
      .send({ name: 'Login', email: 'login@example.com', password: 'jelszo1234' });

    const good = await request(app)
      .post('/api/auth/login')
      .send({ email: 'login@example.com', password: 'jelszo1234' });
    expect(good.status).toBe(200);

    const bad = await request(app)
      .post('/api/auth/login')
      .send({ email: 'login@example.com', password: 'wrong-password' });
    expect(bad.status).toBe(401);
  });

  it('returns the current user for /me only with a valid session', async () => {
    const agent = request.agent(app);
    await agent
      .post('/api/auth/register')
      .send({ name: 'Me', email: 'me@example.com', password: 'jelszo1234' });

    const authed = await agent.get('/api/auth/me');
    expect(authed.status).toBe(200);
    expect(authed.body.user.email).toBe('me@example.com');

    const anon = await request(app).get('/api/auth/me');
    expect(anon.status).toBe(401);
  });
});
