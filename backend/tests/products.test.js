import { describe, it, expect } from 'vitest';
import request from 'supertest';
import app from '../src/app.js';
import { adminAgent } from './helpers.js';

const sampleProduct = {
  brand: 'Kordon',
  model: 'Road Grip S2',
  width: 205,
  profile: 55,
  rim: 16,
  season: 'summer',
  price: 98,
  stock: 24,
};

describe('products', () => {
  it('lists products, empty by default', async () => {
    const res = await request(app).get('/api/products');
    expect(res.status).toBe(200);
    expect(res.body.products).toEqual([]);
  });

  it('rejects product creation without an admin session', async () => {
    const anon = await request(app).post('/api/products').send(sampleProduct);
    expect(anon.status).toBe(401);

    const agent = request.agent(app);
    await agent
      .post('/api/auth/register')
      .send({ name: 'User', email: 'plain-user@example.com', password: 'jelszo1234' });
    const asUser = await agent.post('/api/products').send(sampleProduct);
    expect(asUser.status).toBe(403);
  });

  it('lets an admin create, filter, update and delete a product', async () => {
    const agent = await adminAgent('products-admin@example.com');

    const create = await agent.post('/api/products').send(sampleProduct);
    expect(create.status).toBe(201);
    const id = create.body.product.id;

    const filtered = await request(app).get('/api/products').query({ season: 'summer', rim: 16 });
    expect(filtered.body.products.some((p) => p.id === id)).toBe(true);

    const mismatched = await request(app).get('/api/products').query({ season: 'winter' });
    expect(mismatched.body.products.some((p) => p.id === id)).toBe(false);

    const update = await agent.put(`/api/products/${id}`).send({ price: 105, stock: 10 });
    expect(update.status).toBe(200);
    expect(update.body.product.price).toBe(105);
    expect(update.body.product.stock).toBe(10);

    const del = await agent.delete(`/api/products/${id}`);
    expect(del.status).toBe(204);

    const after = await request(app).get('/api/products');
    expect(after.body.products.some((p) => p.id === id)).toBe(false);
  });

  it('validates required fields on creation', async () => {
    const agent = await adminAgent('validation-admin@example.com');
    const res = await agent.post('/api/products').send({ brand: 'Kordon' });
    expect(res.status).toBe(400);
  });
});
