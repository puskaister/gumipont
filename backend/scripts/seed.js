import 'dotenv/config';
import db from '../src/db.js';

// Mirrors the SEED array from the existing demo frontend (vulc-gumi-webshop.html).
const SEED_PRODUCTS = [
  { brand: 'Kordon', model: 'Road Grip S2', width: 205, profile: 55, rim: 16, season: 'summer', price: 98, stock: 24, speed: 'V', loadIndex: '91' },
  { brand: 'Aerowall', model: 'Silent Line', width: 205, profile: 55, rim: 16, season: 'all-season', price: 104, stock: 6, speed: 'H', loadIndex: '91' },
  { brand: 'Nortrek', model: 'Ice Command', width: 205, profile: 55, rim: 16, season: 'winter', price: 112, stock: 0, speed: 'T', loadIndex: '91' },
  { brand: 'Solmark', model: 'Apex R', width: 225, profile: 45, rim: 17, season: 'summer', price: 139, stock: 14, speed: 'W', loadIndex: '94' },
  { brand: 'Vantis', model: 'Cross Country', width: 225, profile: 65, rim: 17, season: 'all-season', price: 127, stock: 19, speed: 'H', loadIndex: '102' },
  { brand: 'Halcyon', model: 'Polar Line', width: 225, profile: 45, rim: 17, season: 'winter', price: 145, stock: 3, speed: 'V', loadIndex: '94' },
  { brand: 'Kordon', model: 'City Runner', width: 185, profile: 60, rim: 15, season: 'all-season', price: 78, stock: 31, speed: 'T', loadIndex: '84' },
  { brand: 'Solmark', model: 'Track Day', width: 245, profile: 40, rim: 18, season: 'summer', price: 189, stock: 8, speed: 'Y', loadIndex: '97' },
  { brand: 'Aerowall', model: 'Frost Guard', width: 195, profile: 65, rim: 15, season: 'winter', price: 89, stock: 0, speed: 'T', loadIndex: '91' },
  { brand: 'Vantis', model: 'Longhaul Plus', width: 215, profile: 60, rim: 16, season: 'all-season', price: 118, stock: 22, speed: 'H', loadIndex: '95' },
  { brand: 'Nortrek', model: 'Storm Wide', width: 245, profile: 45, rim: 18, season: 'winter', price: 172, stock: 11, speed: 'V', loadIndex: '100' },
  { brand: 'Halcyon', model: 'Circuit S', width: 235, profile: 35, rim: 19, season: 'summer', price: 210, stock: 5, speed: 'Y', loadIndex: '92' },
];

const insert = db.prepare(
  `INSERT INTO products (brand, model, width, profile, rim, season, price, stock, speed, load_index, image)
   VALUES (@brand, @model, @width, @profile, @rim, @season, @price, @stock, @speed, @loadIndex, NULL)`
);
const clear = db.prepare('DELETE FROM products');

const run = db.transaction((items) => {
  clear.run();
  for (const item of items) insert.run(item);
});

run(SEED_PRODUCTS);
console.log(`Seeded ${SEED_PRODUCTS.length} products.`);
