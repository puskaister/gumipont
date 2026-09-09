import 'dotenv/config';
import bcrypt from 'bcrypt';
import db from '../src/db.js';

const [, , name, email, password] = process.argv;

if (!name || !email || !password) {
  console.error('Használat: node scripts/create-admin.js "Teljes Név" email@cim.hu jelszo');
  process.exit(1);
}

const existing = db.prepare('SELECT id FROM users WHERE email = ?').get(email);
if (existing) {
  console.error(`Már létezik felhasználó ezzel az email címmel: ${email}`);
  process.exit(1);
}

const passwordHash = bcrypt.hashSync(password, 12);
db.prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)').run(
  name,
  email,
  passwordHash,
  'admin'
);

console.log(`Admin fiók létrehozva: ${email}`);
