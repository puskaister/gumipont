import { Router } from 'express';
import { z } from 'zod';
import db from '../db.js';
import { requireAdmin, requireAuth, optionalAuth } from '../middleware/auth.js';

const router = Router();

const orderSchema = z.object({
  deliveryMethod: z.enum(['courier', 'pickup']),
  paymentMethod: z.enum(['card', 'transfer', 'cash']),
  discount: z.coerce.number().nonnegative().default(0),
  shippingCost: z.coerce.number().nonnegative().default(0),
  items: z
    .array(
      z.object({
        productId: z.coerce.number().int().positive(),
        qty: z.coerce.number().int().positive(),
      })
    )
    .min(1),
});

// Orders can be placed without being logged in (guest checkout), so this
// route only reads the session if one is present rather than requiring it.
router.post('/', optionalAuth, (req, res) => {
  const parsed = orderSchema.safeParse(req.body);
  if (!parsed.success) {
    return res.status(400).json({ error: 'Invalid input', details: parsed.error.flatten() });
  }
  const { deliveryMethod, paymentMethod, discount, shippingCost, items } = parsed.data;

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
  const total = Math.max(0, subtotal - discount) + shippingCost;

  const placeOrder = db.transaction(() => {
    const info = db
      .prepare(
        `INSERT INTO orders (user_id, status, delivery_method, payment_method, subtotal, discount, shipping_cost, total)
         VALUES (?, 'new', ?, ?, ?, ?, ?, ?)`
      )
      .run(req.user?.id ?? null, deliveryMethod, paymentMethod, subtotal, discount, shippingCost, total);

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

router.get('/', requireAdmin, (req, res) => {
  const orders = db.prepare('SELECT * FROM orders ORDER BY created_at DESC').all();
  res.json({ orders });
});

router.get('/mine', requireAuth, (req, res) => {
  const orders = db
    .prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC')
    .all(req.user.id);
  res.json({ orders });
});

export default router;
