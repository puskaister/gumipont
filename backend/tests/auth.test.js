import { describe, it, expect } from 'vitest';
import request from 'supertest';
import app from '../src/app.js';
import { adminAgent } from './helpers.js';

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

  it('public registration always creates a plain user, even with an adminCode-like field', async () => {
    const res = await request(app).post('/api/auth/register').send({
      name: 'Not Admin',
      email: 'notadmin@example.com',
      password: 'jelszo1234',
      adminCode: 'anything',
    });
    expect(res.body.user.role).toBe('user');
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

describe('admin-created admin accounts', () => {
  it('rejects creating an admin without a session', async () => {
    const res = await request(app)
      .post('/api/auth/admins')
      .send({ name: 'New Admin', email: 'new-admin@example.com', password: 'jelszo1234' });
    expect(res.status).toBe(401);
  });

  it('rejects creating an admin when logged in as a plain user', async () => {
    const agent = request.agent(app);
    await agent
      .post('/api/auth/register')
      .send({ name: 'Plain User', email: 'plain@example.com', password: 'jelszo1234' });

    const res = await agent
      .post('/api/auth/admins')
      .send({ name: 'New Admin', email: 'new-admin@example.com', password: 'jelszo1234' });
    expect(res.status).toBe(403);
  });

  it('lets an existing admin create another admin, without switching sessions', async () => {
    const agent = await adminAgent('root-admin@example.com');

    const created = await agent
      .post('/api/auth/admins')
      .send({ name: 'Second Admin', email: 'second-admin@example.com', password: 'jelszo1234' });
    expect(created.status).toBe(201);
    expect(created.body.user.role).toBe('admin');
    expect(created.headers['set-cookie']).toBeUndefined();

    // The creating admin is still logged in as themselves.
    const me = await agent.get('/api/auth/me');
    expect(me.body.user.email).toBe('root-admin@example.com');

    // The new admin can log in with their own credentials.
    const login = await request(app)
      .post('/api/auth/login')
      .send({ email: 'second-admin@example.com', password: 'jelszo1234' });
    expect(login.status).toBe(200);
    expect(login.body.user.role).toBe('admin');
  });

  it('rejects a duplicate email', async () => {
    const agent = await adminAgent('dup-admin-owner@example.com');
    const res = await agent
      .post('/api/auth/admins')
      .send({ name: 'Dup', email: 'dup-admin-owner@example.com', password: 'jelszo1234' });
    expect(res.status).toBe(409);
  });
});
