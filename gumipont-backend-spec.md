# gumipont.hu — backend fejlesztési terv (Claude Code-hoz)

Ez a dokumentum egy kész, átadható specifikáció, amit be tudsz másolni a Claude Code-ba induló promptként. A cél: a jelenlegi, böngészőben futó demó (egyetlen HTML fájl, `window.storage`-dzsal) helyett egy valódi backend + adatbázis + jelszó-hashelés.

## Célarchitektúra

- **Backend**: Node.js + Express
- **Adatbázis**: SQLite (`better-sqlite3`) induláshoz — egyszerű, fájl alapú, könnyen migrálható PostgreSQL-re később
- **Jelszavak**: `bcrypt` hashelés, soha nem tárolunk nyílt szöveget
- **Munkamenet**: JWT token (`jsonwebtoken`), HttpOnly cookie-ban tárolva
- **Frontend**: a meglévő `gumipont-hu` HTML/CSS/JS megmarad, de a `window.storage.get/set` hívások helyett `fetch()`-csel hívja az alábbi API végpontokat

## Adatbázis séma

```
users
  id            INTEGER PK
  name          TEXT
  email         TEXT UNIQUE
  password_hash TEXT
  role          TEXT ('user' | 'admin')
  created_at    DATETIME

products
  id          INTEGER PK
  brand       TEXT
  model       TEXT
  width       INTEGER
  profile     INTEGER
  rim         INTEGER
  season      TEXT ('summer' | 'winter' | 'all-season')
  price       REAL
  stock       INTEGER
  speed       TEXT
  load_index  TEXT
  image       TEXT (kép URL, ne base64 — ld. lent)

orders
  id               INTEGER PK
  user_id          INTEGER NULL (vendégrendelés is lehet)
  status           TEXT ('new' | 'confirmed' | 'shipped' | 'done')
  delivery_method  TEXT ('courier' | 'pickup')
  payment_method   TEXT ('card' | 'transfer' | 'cash')
  subtotal         REAL
  discount         REAL
  shipping_cost    REAL
  total            REAL
  created_at       DATETIME

order_items
  id          INTEGER PK
  order_id    INTEGER FK -> orders.id
  product_id  INTEGER FK -> products.id
  qty         INTEGER
  unit_price  REAL

settings
  key    TEXT PK   (pl. 'currency', 'shipping', 'payment', 'bank')
  value  TEXT (JSON)

pages
  id      TEXT PK
  title   TEXT
  content TEXT (HTML, a rich text szerkesztőből)

site_content
  key     TEXT PK  ('shop_intro' | 'about' | 'contact')
  value   TEXT (JSON vagy HTML)
```

**Fontos**: a jelenlegi demóban a feltöltött képek base64 stringként vannak a JSON-ban. Élesben ez nem skálázódik — a képeket külön kell tárolni (pl. lemezen `/uploads` mappában, vagy S3-kompatibilis tárhelyen), és a `products.image` mezőben csak az elérési útvonal/URL szerepeljen.

## API végpontok

```
POST   /api/auth/register        { name, email, password, adminCode? }
POST   /api/auth/login           { email, password }
POST   /api/auth/logout
GET    /api/auth/me              -> bejelentkezett felhasználó adatai

GET    /api/products             -> lista, szűrhető query paraméterekkel (width, profile, rim, season, brand)
POST   /api/products             -> admin only, létrehozás (multipart/form-data a képhez)
PUT    /api/products/:id         -> admin only
DELETE /api/products/:id         -> admin only

GET    /api/settings
PUT    /api/settings             -> admin only

GET    /api/pages
GET    /api/pages/:id
POST   /api/pages                -> admin only (Excel feltöltés feldolgozva szerveroldalon)
DELETE /api/pages/:id            -> admin only

GET    /api/content              -> shop_intro, about, contact
PUT    /api/content/:key         -> admin only

POST   /api/orders               -> rendelés leadása (bejelentkezés nélkül is)
GET    /api/orders               -> admin only, összes rendelés
GET    /api/orders/mine          -> bejelentkezett felhasználó saját rendelései
```

## Biztonsági követelmények

- Jelszó: minimum hosszúság + `bcrypt.hash(password, 12)`
- JWT: rövid lejáratú access token + HttpOnly, Secure, SameSite=Strict cookie
- Admin végpontok: middleware, ami ellenőrzi a `role === 'admin'`-t a JWT-ből, nem a kliens állításából
- Bemenet validáció minden végponton (pl. `zod` vagy `express-validator`)
- Rate limiting a `/api/auth/*` végpontokon (pl. `express-rate-limit`) brute force ellen
- CORS: csak a saját frontend domainről engedélyezett
- `.env` fájlban a JWT titkos kulcs, soha nem commitolva

## Migrációs lépések a meglévő HTML-ből

1. A `window.storage.get/set/delete` hívásokat le kell cserélni a megfelelő `fetch('/api/...')` hívásokra.
2. A statikus SEED adatok importálhatók egy egyszeri seed scripttel az adatbázisba.
3. A base64 admin-feltöltött képeket át kell alakítani fájl-feltöltéssé (`multipart/form-data` + `multer`).
4. A jelenlegi kliens oldali "admin regisztrációs kód" logika helyett szerveroldali ellenőrzés kell (a kód sose kerüljön a kliens JS-be nyílt szövegként).

## Javasolt következő lépés Claude Code-ban

Másold be ezt promptként:

> Hozz létre egy Node.js + Express + SQLite (better-sqlite3) backend projektet a fenti séma és API végpontok alapján, bcrypt jelszó-hasheléssel és JWT autentikációval. Adj hozzá seed scriptet a meglévő 12 gumi termékkel. Írj hozzá alap integrációs teszteket a regisztráció/bejelentkezés/termék-CRUD végpontokhoz.
