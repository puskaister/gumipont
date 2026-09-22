<?php
declare(strict_types=1);
// Heti automatikus mentés: kiexportálja a teljes adatbázist (SQL dump) és a
// feltöltött képeket (uploads/), egyetlen zip fájlba csomagolva a
// backups/ mappába menti (a régebbi mentésekből csak az utolsó néhányat
// tartja meg), majd rövid értesítő emailt küld az eredményről.
//
// EZ A SZKRIPT NEM EGYSZERI — ne töröld úgy, mint a migrációs szkripteket!
// Az adatbázishoz és fájlokhoz hasonlóan ez is maradandó, a cron minden
// héten újra lefuttatja.
//
// Beállítás a tárhelyen (Cron Jobs) — pl.:
//  /opt/alt/php74/usr/bin/php-cgi /teljes/eleresi/ut/scripts/weekly_backup.php >/dev/null 2>&1
// Ez a php-cgi-s hívás (ahogy a legtöbb megosztott tárhely cron feladata is
// fut) biztonságosnak számít, mert nincs mögötte valódi HTTP-kérés (nincs
// REQUEST_METHOD) — tokent EHHEZ nem kell beállítani.
// Ha a cron csak egy URL-t tud rendszeresen meghívni (böngészőből/curl-lal
// elérhető cím), akkor vegyél fel az api/config.php-ba egy
// 'backup_token' => 'egy-hosszú-véletlen-string' sort, és a cron a
// https://a-domained/scripts/weekly_backup.php?token=EZ-A-STRING
// URL-t hívja meg. Token nélkül/hibás tokennel webről nem fut le.
// Ajánlott gyakoriság: hetente egyszer (pl. hétfőn hajnalban).

// A cron (CLI vagy php-cgi-n keresztül, valódi HTTP-kérés/REQUEST_METHOD
// nélkül) mindig megbízhatónak számít; csak a ténylegesen böngészőből/curl-lal,
// HTTP-kérésként érkező hívásnál kérünk tokent.
$isTrustedInvocation = PHP_SAPI === 'cli' || !isset($_SERVER['REQUEST_METHOD']);
$config = require __DIR__ . '/../api/config.php';

if (!$isTrustedInvocation) {
    $expectedToken = $config['backup_token'] ?? null;
    $givenToken = $_GET['token'] ?? '';
    if (!$expectedToken || !hash_equals((string) $expectedToken, (string) $givenToken)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        die("Tiltva. Állíts be egy 'backup_token'-t az api/config.php-ban, és azt add meg ?token= paraméterként.\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require __DIR__ . '/../api/lib/mailer.php';

const KEEP_BACKUPS = 4; // hány legutóbbi heti mentést tartsunk meg

function out(string $line): void {
    echo $line . "\n";
}

function dump_database(mysqli $mysqli): string {
    $sql = "-- gumipont.hu adatbázis-mentés — " . date('Y-m-d H:i:s') . "\n";
    $sql .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

    $tables = [];
    $result = $mysqli->query('SHOW TABLES');
    while ($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }

    foreach ($tables as $table) {
        $createRow = $mysqli->query("SHOW CREATE TABLE `$table`")->fetch_assoc();
        $sql .= "DROP TABLE IF EXISTS `$table`;\n" . $createRow['Create Table'] . ";\n\n";

        $rowsResult = $mysqli->query("SELECT * FROM `$table`");
        while ($row = $rowsResult->fetch_assoc()) {
            $columns = array_map(fn ($c) => "`$c`", array_keys($row));
            $values = array_map(function ($v) use ($mysqli) {
                if ($v === null) return 'NULL';
                return "'" . $mysqli->real_escape_string((string) $v) . "'";
            }, array_values($row));
            $sql .= "INSERT INTO `$table` (" . implode(',', $columns) . ") VALUES (" . implode(',', $values) . ");\n";
        }
        $sql .= "\n";
    }

    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    return $sql;
}

function add_directory_to_zip(ZipArchive $zip, string $dir, string $zipPathPrefix): void {
    if (!is_dir($dir)) return;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($files as $file) {
        if ($file->isDir()) continue;
        $relativePath = $zipPathPrefix . '/' . substr($file->getPathname(), strlen($dir) + 1);
        $zip->addFile($file->getPathname(), $relativePath);
    }
}

function rotate_backups(string $backupsDir): void {
    $files = glob($backupsDir . '/backup-*.zip') ?: [];
    sort($files);
    while (count($files) > KEEP_BACKUPS) {
        $oldest = array_shift($files);
        @unlink($oldest);
    }
}

function notify(array $config, bool $success, string $detail): void {
    $subject = $success ? 'Heti mentés kész - gumipont.hu' : 'Heti mentés SIKERTELEN - gumipont.hu';
    send_app_email($config, 'puskaisandor@gmail.com', $subject, $detail);
}

try {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('A PHP zip kiterjesztés (ZipArchive) nem elérhető ezen a tárhelyen.');
    }

    $mysqli = mysqli_init();
    if (!$mysqli->real_connect($config['db']['host'], $config['db']['user'], $config['db']['pass'], $config['db']['name'])) {
        throw new RuntimeException('Nem sikerült csatlakozni az adatbázishoz: ' . mysqli_connect_error());
    }
    $mysqli->set_charset($config['db']['charset'] ?? 'utf8mb4');

    $backupsDir = __DIR__ . '/../backups';
    if (!is_dir($backupsDir)) mkdir($backupsDir, 0755, true);
    // A mentés a teljes adatbázist tartalmazza (vevők neve, címe, telefonja,
    // jelszó-hash) — a mappát .htaccess-szel zárjuk le, hogy web felől soha
    // senki ne tudja letölteni, még a fájlnév kitalálásával/pásztázásával se.
    // Mivel a backups/ mappa ki van zárva az automata FTP-deployból (hogy a
    // mentések meg ne semmisüljenek minden feltöltéskor), ez a fájl csak itt,
    // futásidőben jön létre — nem a git-repóból települ.
    $htaccessPath = "$backupsDir/.htaccess";
    if (!file_exists($htaccessPath)) {
        file_put_contents($htaccessPath, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
    }

    $stamp = date('Y-m-d_His');
    $zipPath = "$backupsDir/backup-$stamp.zip";

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Nem sikerült létrehozni a zip fájlt: $zipPath");
    }

    out('Adatbázis exportálása...');
    $sqlDump = dump_database($mysqli);
    $zip->addFromString("database-$stamp.sql", $sqlDump);

    out('Feltöltött fájlok hozzáadása...');
    add_directory_to_zip($zip, __DIR__ . '/../uploads', 'uploads');

    $zip->close();
    rotate_backups($backupsDir);

    $sizeMb = round(filesize($zipPath) / 1024 / 1024, 2);
    $message = "A heti mentés sikeresen elkészült.\r\n\r\nFájl: backups/backup-$stamp.zip\r\nMéret: {$sizeMb} MB\r\n\r\nA mentés a szerveren, a webshop mappáján belül (backups/) tárolódik — érdemes időnként letölteni FTP-n egy másik helyre is, hogy a szerver esetleges meghibásodása esetén se vesszen el.";
    notify($config, true, $message);
    out("Kész: backup-$stamp.zip ({$sizeMb} MB)");
} catch (Throwable $e) {
    $errorMessage = "A heti mentés sikertelen volt.\r\n\r\nHiba: " . $e->getMessage();
    error_log('[gumipont heti mentes] ' . $e->getMessage());
    if (isset($config)) {
        notify($config, false, $errorMessage);
    }
    out('HIBA: ' . $e->getMessage());
    if (isset($_SERVER['REQUEST_METHOD'])) http_response_code(500);
}
