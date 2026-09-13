# gumipont.hu — statikus, szerver nélküli verzió

Ez a változat egyetlen `index.html` fájl. Nincs hozzá backend, adatbázis,
build-lépés — bármilyen tárhelyre feltöltve (vagy akár csak duplán
rákattintva a fájlra) azonnal működik.

## Telepítés

Nincs telepítés. Töltsd fel az `index.html`-t bármilyen statikus
tárhelyre (bármilyen webtárhely, GitHub Pages, Netlify, egy pendrive is
megteszi), vagy nyisd meg közvetlenül a fájlrendszerből.

## Amit tudnod kell róla

**Minden adat ebben az egy böngészőben, `localStorage`-ban él.** Nincs
megosztott adatbázis: amit az egyik látogató a saját böngészőjében lát
vagy módosít (termékek, rendelések, beállítások, szöveges tartalmak), azt
egy másik látogató — más gépen vagy böngészőben — **nem** látja. Ez a
verzió bemutató/kirakat célra, egyszemélyes demózásra, vagy offline
prezentációra való, **nem** valódi, több-vásárlós online boltnak. Ha
tényleges, megosztott adatokkal működő boltot szeretnél, lásd a
`php-mysql-version/` vagy a `backend/` (Node.js) mappát ugyanebben a
projektben.

**Admin belépés**: egyetlen, mindenki által ismert jelszó védi (nincs
mögötte valódi felhasználó-kezelés, bcrypt, munkamenet-token stb.) —
alapértelmezett jelszó: `admin123`. Ezt a Beállítások panelen belül
tudod megváltoztatni. Az admin-belépés nem marad meg oldal-újratöltés
után (ugyanúgy, ahogy az eredeti demóban sem maradt meg).

**Termékképek** base64-ként kerülnek a mentett adatba (nincs fájl-szerver,
ami tárolná őket) — sok/nagy kép esetén ez megnövelheti a böngésző
localStorage-méretét (jellemzően 5-10 MB a limit böngészőnként/oldalanként).

**Excel/CSV feltöltés** a böngészőben, kliens oldalon történik (a
[SheetJS](https://sheetjs.com) könyvtárral, CDN-ről betöltve) — ehhez
internetkapcsolat kell (a CDN-szkript letöltéséhez), de utána a fájl
feldolgozása helyben zajlik.

## Ha mégis törölni akarod az adatokat

A böngésző fejlesztői eszközeiben (F12) → Application/Storage →
Local Storage → válaszd ki az oldal domainjét → törölheted egyenként
vagy mindet. Ugyanígy a böngésző "Süti és webhelyadat törlése"
funkciója is nullázza.
