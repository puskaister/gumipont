<?php
// Másold ezt a fájlt config.php néven (ugyanebbe a mappába), és töltsd ki
// a saját tárhelyszolgáltatód MySQL adataival. A config.php fájlt SOSE oszd
// meg / commitold nyilvánosan — jelszót tartalmaz.
return [
    'db' => [
        'host'    => 'localhost',
        'name'    => 'gumipont',
        'user'    => 'gumipont_user',
        'pass'    => 'valtoztasd-meg',
        'charset' => 'utf8mb4',
    ],

    // A regisztrációkor kapott munkamenet-cookie ezen a néven jön létre.
    'session_name' => 'gumipont_session',

    // Opcionális: ha a tárhelyed natív mail() függvénye nem kézbesít
    // megbízhatóan (gyakori jelenség), töltsd ki a tárhelyed saját
    // postafiókjának SMTP adataival (ugyanaz, amit egy levelezőkliensben is
    // megadnál) — ezután a jelszó-visszaállító és az új rendelés értesítő
    // email ezen keresztül megy ki, hitelesített kapcsolattal. Ha üresen
    // hagyod (vagy törlöd ezt a kulcsot), a rendszer a natív mail()-re esik
    // vissza.
    'smtp' => [
        'host'     => 'mail.gumipont.hu',
        'port'     => 465, // az SMTP SSL/TLS port a tárhelyed levelező beállításaiból
        'username' => 'gumipont@gumipont.hu',
        'password' => 'valtoztasd-meg',
        'from'     => 'gumipont@gumipont.hu',
    ],
];
