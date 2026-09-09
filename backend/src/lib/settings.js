import db from '../db.js';

export const SETTINGS_DEFAULTS = {
  currency: '€',
  shipping: { courier: 15, pickup: 0 },
  bulkDiscount: { minQty: 0, percent: 0 },
};

export function getSetting(key, fallback = SETTINGS_DEFAULTS[key]) {
  const row = db.prepare('SELECT value FROM settings WHERE key = ?').get(key);
  if (!row) return fallback;
  try {
    return JSON.parse(row.value);
  } catch {
    return fallback;
  }
}
