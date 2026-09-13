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
];
