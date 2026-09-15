<?php
declare(strict_types=1);
// Egyszeri script: létrehozza a "Felnik" cikket a pages táblában (ugyanúgy
// jelenik meg, mintha az admin az "Új oldal" gombbal hozta volna létre).
// Böngészőből egyszer meg kell nyitni, utána törölni kell ezt a fájlt.

header('Content-Type: text/plain; charset=utf-8');

$config = require __DIR__ . '/../api/config.php';

$mysqli = mysqli_init();
if (!$mysqli->real_connect($config['db']['host'], $config['db']['user'], $config['db']['pass'], $config['db']['name'])) {
    die('Nem sikerült csatlakozni: ' . mysqli_connect_error());
}
$mysqli->set_charset($config['db']['charset'] ?? 'utf8mb4');

$id = 'felnik';
$title = 'Felnik';
$content = <<<HTML
<h1>Felnik</h1>
<p>A megfelelő felni kiválasztásához három adatra van szükség: a lyukak számára, az osztókörre és a felni méretére (collban). Ha mindhárom illeszkedik az autódhoz, a felni biztosan felszerelhető.</p>
<h2>Lyukak száma</h2>
<p>A felni és a kerékagy közötti rögzítőcsavarok száma. Személyautóknál jellemzően 4 vagy 5, tehergépjárműveknél akár 6 vagy 8 is lehet. Ennek mindig meg kell egyeznie a gépkocsi gyári előírásával.</p>
<h2>Osztókör (PCD)</h2>
<p>A csavarlyukak középpontjai által kirajzolt képzeletbeli kör átmérője milliméterben (például 5x114,3 — 5 lyukas, 114,3 mm-es osztókör). A lyukak számával együtt ez adja meg, hogy a felni fizikailag felszerelhető-e a kerékagyra.</p>
<h2>Felniméret (coll)</h2>
<p>A felni átmérője collban (például R16, R17). Ennek egyeznie kell a felszerelni kívánt gumi felniméretével — ugyanaz a szám, amit a gumi oldalfalán is megtalálsz (205/55 R16 esetén 16 colos felni szükséges).</p>
<h2>Alufelni vagy acélfelni?</h2>
<ul>
<li>Az alufelni könnyebb, esztétikusabb és jobb hűtést biztosít a féknek.</li>
<li>Az acélfelni strapabíróbb, olcsóbb, jobban tűri a kátyúkat és a téli útviszonyokat.</li>
</ul>
<p>Ha nem vagy biztos a saját autód adataiban, keress minket bizalommal — a forgalmi engedélyed alapján pontosan megmondjuk, milyen felni illik rá.</p>
HTML;

$stmt = $mysqli->prepare(
    'INSERT INTO pages (id, title, content) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE title = VALUES(title), content = VALUES(content)'
);
$stmt->bind_param('sss', $id, $title, $content);
$stmt->execute();
$stmt->close();

echo "Kész: a \"Felnik\" cikk létrehozva/frissítve.\n";
