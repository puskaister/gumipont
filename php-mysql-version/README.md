# gumipont.hu — PHP + MySQL(i) verzió

Ez a változat egy PHP backendből (natív `mysqli`, Composer/Node nélkül) és
egy statikus `index.html` frontendből áll. Bármilyen "klasszikus" megosztott
PHP+MySQL tárhelyre feltölthető (cPanel, Plesk, DirectAdmin stb.) — nincs
szükség Node.js-re, SSH-ra vagy build-lépésre.

**Követelmények a tárhelyeden:** PHP 7.4+ (8.x ajánlott) a `mysqli`, `zip`,
`SimpleXML` és `gd` kiterjesztésekkel — ezek szinte minden megosztott PHP
tárhelyen alapból elérhetők —, valamint egy MySQL vagy MariaDB adatbázis.

## Telepítés (megosztott tárhelyen)

1. **Töltsd fel** ennek a mappának a **teljes tartalmát** (az `api/`,
   `uploads/`, `scripts/` mappákkal és az `index.html`-lel együtt) a
   tárhelyed webgyökerébe (pl. `public_html/`, vagy egy almappába, ha ott
   szeretnéd — a rendszer relatív útvonalakkal dolgozik, alkönyvtárban is
   működik).
2. **Hozz létre egy MySQL adatbázist** a tárhelyed kezelőfelületén
   (cPanel → MySQL Databases, vagy ami neked elérhető), és egy hozzá
   tartozó felhasználót teljes jogosultsággal.
3. **Importáld az `install.sql`-t** ebbe az adatbázisba (cPanel →
   phpMyAdmin → Import, vagy `mysql -u FELHASZNALO -p ADATBAZIS <
   install.sql`, ha van parancssorod).
4. **Másold át** `api/config.example.php`-t `api/config.php` néven
   (ugyanabba a mappába), és írd bele a saját adatbázis-adataidat
   (host/név/felhasználó/jelszó — ezeket a tárhelyszolgáltatód adja meg).
5. **Hozd létre az első admin fiókot**:
   - Ha van SSH/parancssor hozzáférésed:
     `php scripts/create_admin.php "Teljes Neved" email@cimed.hu jelszo`
   - Ha nincs (sok olcsó csomagnál nincs): nyisd meg egyszer böngészőben:
     `https://a-domained.hu/scripts/create_admin.php?name=Teljes+Neved&email=email@cimed.hu&password=jelszo`
     — **utána azonnal töröld vagy nevezd át** a `scripts/` mappát/fájlt,
     különben bárki tudna vele admin fiókot létrehozni, aki ismeri az URL-t.
6. **(Opcionális) Töltsd fel a 12 alap terméket**: ugyanígy CLI-vel
   (`php scripts/seed.php`) vagy böngészőből (`scripts/seed.php`
   megnyitása egyszer) — ez **törli** a meglévő termékeket és lecseréli
   a demó-készletre, csak új/üres boltnál használd.
7. **Ellenőrizd az `uploads/` mappa írási jogát** (a legtöbb tárhelyen ez
   már eleve megfelelő feltöltés után, de ha a termékkép-feltöltés
   "Could not save the uploaded image" hibát ad, állítsd 755-re/775-re a
   mappa jogosultságát a fájlkezelőben vagy FTP-kliensben).
8. Nyisd meg a domained-et a böngészőben — kész.

## Amit érdemes tudni

- **Nincs Composer-függőség.** Az Excel/CSV admin-feltöltést egy saját,
  pár száz soros, csak beépített PHP kiterjesztéseket (ZipArchive +
  SimpleXML) használó feldolgozó végzi (`api/lib/xlsx.php`). Ez nem
  támogat mindent, amit egy teljes könyvtár (pl. képleteket csak a
  gyorsítótárazott értékükkel mutatja, egyesített cellákat nem kezeli),
  de a tipikus "adattábla" jellegű Excel/CSV fájlokat jól olvassa.
- **Jelszó-visszaállítás emailben**: a `mail()` PHP beépített
  függvényével próbál emailt küldeni — ez a legtöbb megosztott tárhelyen
  működik külön beállítás nélkül is. Ha a hosztod nem támogatja / nem
  konfigurált SMTP-t, a link a PHP error logba kerül (`error_log()`),
  amit a tárhelyed kezelőfelületén (cPanel → Errors, vagy hasonló) tudsz
  megnézni. Ha megbízhatóbb kézbesítés kell, cseréld le a `mail()` hívást
  `api/auth/forgot_password.php`-ban egy SMTP-szolgáltatóra (pl.
  PHPMailer + saját SMTP fiók).
- **Munkamenet**: natív PHP session (`$_SESSION`), HttpOnly cookie-val —
  nincs JWT, nincs külön session-store, a tárhelyed alapértelmezett PHP
  session-kezelése elég hozzá.
- **Rate limiting** a `/api/auth/*` végpontokon: egyszerű, adatbázis-alapú
  számláló (`rate_limits` tábla) — nem igényel Redis-t vagy más külön
  szolgáltatást.
- **Admin fiókot csak admin hozhat létre** (a bejelentkezett admin az
  "Admin létrehozása" gombbal) — a publikus regisztráció mindig sima
  vásárlói fiókot ad. Az első adminhoz kell az 5. lépésbeli bootstrap
  script.
- **HTTPS**: ha a domainedhez van SSL (a legtöbb tárhely ad ingyenes
  Let's Encrypt tanúsítványt), a munkamenet-cookie automatikusan
  `Secure`-ként viselkedik — nincs hozzá külön teendőd.

## Fájlstruktúra

```
index.html            a teljes shop (frontend)
api/
  config.example.php  másold config.php néven, töltsd ki
  bootstrap.php        közös DB-kapcsolat, session, segédfüggvények
  lib/                 xlsx.php (Excel/CSV feldolgozó), settings.php, uploads.php
  auth/                register, login, logout, me, admins, forgot/reset password
  products/            list, create, update, delete
  settings/             get, update
  pages/                list, get, create (Excel feltöltés), delete
  content/              get, update
  orders/               create, list (admin), mine
uploads/               ide kerülnek a feltöltött termékképek
scripts/
  create_admin.php     bootstrap admin (CLI vagy böngésző)
  seed.php             12 alap termék feltöltése (CLI vagy böngésző)
install.sql            MySQL séma — importáld a saját adatbázisodba
```
