import { Router } from 'express';
import { z } from 'zod';
import db from '../db.js';
import { requireAdmin, requireAuth, optionalAuth } from '../middleware/auth.js';
import { getSetting } from '../lib/settings.js';

const router = Router();

// Note: no discount/shippingCost fields here — those are derived
// server-side from admin settings (see below), never trusted from the
// client, otherwise a guest could submit an arbitrary discount.
const orderSchema = z
  .object({
    customerName: z.string().trim().min(1).max(200),
    customerEmail: z.string().trim().toLowerCase().email(),
    customerPhone: z.string().trim().min(1).max(50),
    shippingAddress: z.string().trim().max(500).optional().default(''),
    deliveryMethod: z.enum(['courier', 'pickup']),
    paymentMethod: z.enum(['card', 'transfer', 'cash']),
    items: z
      .array(
        z.object({
          productId: z.coerce.number().int().positive(),
          qty: z.coerce.number().int().positive(),
        })
      )
      .min(1),
  })
  .refine((data) => data.deliveryMethod !== 'courier' || data.shippingAddress.length > 0, {
    message: 'Szállítási cím megadása kötelező futáros kiszállításnál.',
    path: ['shippingAddress'],
  });

// Orders can be placed without being logged in (guest checkout), so this
// route only reads the session if one is present rather than requiring it.
router.post('/', optionalAuth, (req, res) => {
  const parsed = orderSchema.safeParse(req.body);
  if (!parsed.success) {
    return res.status(400).json({ error: 'Invalid input', details: parsed.error.flatten() });
  }
  const {
    customerName,
    customerEmail,
    customerPhone,
    shippingAddress,
    deliveryMethod,
    paymentMethod,
    items,
  } = parsed.data;

  const getProduct = db.prepare('SELECT * FROM products WHERE id = ?');
  let resolved;
  try {
    resolved = items.map((item) => {
      const product = getProduct.get(item.productId);
      if (!product) throw new Error(`Product ${item.productId} not found`);
      if (product.stock < item.qty) throw new Error(`Insufficient stock for product ${item.productId}`);
      return { ...item, unitPrice: product.price };
    });
  } catch (err) {
    return res.status(400).json({ error: err.message });
  }

  const subtotal = resolved.reduce((sum, item) => sum + item.unitPrice * item.qty, 0);

  const shipping = getSetting('shipping');
  const shippingCost = Number(deliveryMethod === 'pickup' ? shipping.pickup : shipping.courier) || 0;

  const bulkDiscount = getSetting('bulkDiscount');
  const totalQty = resolved.reduce((sum, item) => sum + item.qty, 0);
  const discount =
    bulkDiscount.minQty > 0 && bulkDiscount.percent > 0 && totalQty >= bulkDiscount.minQty
      ? Math.round(subtotal * (bulkDiscount.percent / 100) * 100) / 100
      : 0;

  const total = Math.max(0, subtotal - discount) + shippingCost;

  const placeOrder = db.transaction(() => {
    const info = db
      .prepare(
        `INSERT INTO orders (
           user_id, status, delivery_method, payment_method,
           customer_name, customer_email, customer_phone, shipping_address,
           subtotal, discount, shipping_cost, total
         )
         VALUES (?, 'new', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`
      )
      .run(
        req.user?.id ?? null,
        deliveryMethod,
        paymentMethod,
        customerName,
        customerEmail,
        customerPhone,
        shippingAddress,
        subtotal,
        discount,
        shippingCost,
        total
      );

    const insertItem = db.prepare(
      'INSERT INTO order_items (order_id, product_id, qty, unit_price) VALUES (?, ?, ?, ?)'
    );
    const decrementStock = db.prepare('UPDATE products SET stock = stock - ? WHERE id = ?');
    for (const item of resolved) {
      insertItem.run(info.lastInsertRowid, item.productId, item.qty, item.unitPrice);
      decrementStock.run(item.qty, item.productId);
    }
    return info.lastInsertRowid;
  });

  const orderId = placeOrder();
  const order = db.prepare('SELECT * FROM orders WHERE id = ?').get(orderId);
  const orderItems = db.prepare('SELECT * FROM order_items WHERE order_id = ?').all(orderId);
  res.status(201).json({ order, items: orderItems });
});

function attachItems(orders) {
  if (orders.length === 0) return orders;
  const placeholders = orders.map(() => '?').join(',');
  const rows = db
    .prepare(
      `SELECT oi.order_id, oi.qty, oi.unit_price, p.brand, p.model
       FROM order_items oi
       LEFT JOIN products p ON p.id = oi.product_id
       WHERE oi.order_id IN (${placeholders})`
    )
    .all(...orders.map((o) => o.id));

  const byOrder = new Map();
  for (const row of rows) {
    if (!byOrder.has(row.order_id)) byOrder.set(row.order_id, []);
    byOrder.get(row.order_id).push({
      brand: row.brand,
      model: row.model,
      qty: row.qty,
      unitPrice: row.unit_price,
    });
  }
  return orders.map((o) => ({ ...o, items: byOrder.get(o.id) || [] }));
}

router.get('/', requireAdmin, (req, res) => {
  const orders = db.prepare('SELECT * FROM orders ORDER BY created_at DESC').all();
  res.json({ orders: attachItems(orders) });
});

router.get('/mine', requireAuth, (req, res) => {
  const orders = db
    .prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC')
    .all(req.user.id);
  res.json({ orders: attachItems(orders) });
});

export default router;
