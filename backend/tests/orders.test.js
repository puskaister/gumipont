import { describe, it, expect } from 'vitest';
import request from 'supertest';
import app from '../src/app.js';
import db from '../src/db.js';
import { adminAgent } from './helpers.js';

function insertProduct(overrides = {}) {
  const p = {
    brand: 'Kordon',
    model: 'Road Grip S2',
    width: 205,
    profile: 55,
    rim: 16,
    season: 'summer',
    price: 100,
    stock: 5,
    speed: 'V',
    load_index: '91',
    image: null,
    ...overrides,
  };
  const info = db
    .prepare(
      `INSERT INTO products (brand, model, width, profile, rim, season, price, stock, speed, load_index, image)
       VALUES (@brand, @model, @width, @profile, @rim, @season, @price, @stock, @speed, @load_index, @image)`
    )
    .run(p);
  return info.lastInsertRowid;
}

const customer = {
  customerName: 'Teszt Elek',
  customerEmail: 'vevo@example.com',
  customerPhone: '+36301234567',
};

describe('orders', () => {
  it('places a guest order, decrements stock, and computes the total', async () => {
    const productId = insertProduct({ price: 100, stock: 5 });

    const res = await request(app)
      .post('/api/orders')
      .send({
        ...customer,
        shippingAddress: '1052 Budapest, Váci utca 12.',
        deliveryMethod: 'courier',
        paymentMethod: 'card',
        shippingCost: 15,
        items: [{ productId, qty: 2 }],
      });

    expect(res.status).toBe(201);
    expect(res.body.order.subtotal).toBe(200);
    expect(res.body.order.total).toBe(215);
    expect(res.body.order.user_id).toBeNull();
    expect(res.body.order.customer_name).toBe('Teszt Elek');
    expect(res.body.order.shipping_address).toBe('1052 Budapest, Váci utca 12.');

    const productRes = await request(app).get('/api/products');
    const product = productRes.body.products.find((p) => p.id === productId);
    expect(product.stock).toBe(3);
  });

  it('requires a shipping address for courier delivery but not for pickup', async () => {
    const productId = insertProduct({ stock: 5 });

    const missingAddress = await request(app)
      .post('/api/orders')
      .send({ ...customer, deliveryMethod: 'courier', paymentMethod: 'card', items: [{ productId, qty: 1 }] });
    expect(missingAddress.status).toBe(400);

    const pickup = await request(app)
      .post('/api/orders')
      .send({ ...customer, deliveryMethod: 'pickup', paymentMethod: 'cash', items: [{ productId, qty: 1 }] });
    expect(pickup.status).toBe(201);
  });

  it('rejects an order missing customer contact details', async () => {
    const productId = insertProduct({ stock: 5 });
    const res = await request(app)
      .post('/api/orders')
      .send({ deliveryMethod: 'pickup', paymentMethod: 'cash', items: [{ productId, qty: 1 }] });
    expect(res.status).toBe(400);
  });

  it('attaches the logged-in user to the order and exposes it via /mine with items', async () => {
    const productId = insertProduct({ stock: 3 });
    const agent = request.agent(app);
    await agent
      .post('/api/auth/register')
      .send({ name: 'Buyer', email: 'buyer@example.com', password: 'jelszo1234' });

    const res = await agent
      .post('/api/orders')
      .send({ ...customer, deliveryMethod: 'pickup', paymentMethod: 'cash', items: [{ productId, qty: 1 }] });
    expect(res.status).toBe(201);

    const mine = await agent.get('/api/orders/mine');
    expect(mine.body.orders).toHaveLength(1);
    expect(mine.body.orders[0].items[0]).toMatchObject({ brand: 'Kordon', qty: 1 });
    expect(mine.body.orders[0].customer_email).toBe('vevo@example.com');
  });

  it('rejects an order that exceeds available stock', async () => {
    const productId = insertProduct({ stock: 1 });
    const res = await request(app)
      .post('/api/orders')
      .send({ ...customer, deliveryMethod: 'pickup', paymentMethod: 'card', items: [{ productId, qty: 5 }] });
    expect(res.status).toBe(400);
  });

  it('lists all orders for admins, including items and customer details', async () => {
    const productId = insertProduct({ stock: 10 });
    await request(app)
      .post('/api/orders')
      .send({ ...customer, deliveryMethod: 'pickup', paymentMethod: 'transfer', items: [{ productId, qty: 2 }] });

    const agent = await adminAgent('orders-admin@example.com');
    const res = await agent.get('/api/orders');
    expect(res.status).toBe(200);
    expect(res.body.orders.length).toBeGreaterThan(0);
    expect(res.body.orders[0].items[0].brand).toBe('Kordon');
    expect(res.body.orders[0].customer_name).toBe('Teszt Elek');
  });

  it('rejects listing all orders for a non-admin', async () => {
    const res = await request(app).get('/api/orders');
    expect(res.status).toBe(401);
  });
});
