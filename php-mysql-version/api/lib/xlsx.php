<?php
declare(strict_types=1);

// Minimális, függőség nélküli Excel/CSV -> HTML táblázat konverter, csak
// beépített PHP kiterjesztésekkel (ZipArchive + SimpleXML) — nincs szükség
// Composerre / külső könyvtárra, ami a legtöbb megosztott tárhelyen sem
// biztos, hogy elérhető. Nem támogat képleteket (a gyorsítótárazott értéket
// mutatja), egyesített cellákat vagy formázást — egyszerű, "adattábla"
// jellegű Excel/CSV fájlokhoz készült.

function xlsx_or_csv_to_html(string $filePath, string $originalName): string {
    $ext = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext === 'csv') {
        return csv_to_html($filePath);
    }
    return xlsx_to_html($filePath);
}

function csv_to_html(string $filePath): string {
    $rows = [];
    $handle = fopen($filePath, 'r');
    if ($handle !== false) {
        // Explicit escape-karakter (a PHP-alapértelmezett), hogy PHP 8.5+
        // alatt ne írjon deprecation-figyelmeztetést.
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);
    }
    return rows_to_html_table($rows);
}

function xlsx_to_html(string $filePath): string {
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new RuntimeException('Nem sikerült megnyitni a fájlt (nem érvényes .xlsx).');
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sst = @simplexml_load_string($sharedXml);
        if ($sst !== false) {
            foreach ($sst->si as $si) {
                $sharedStrings[] = xlsx_shared_string_text($si);
            }
        }
    }

    $sheetTargets = [];
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($relsXml !== false) {
        $rels = @simplexml_load_string($relsXml);
        if ($rels !== false) {
            foreach ($rels->Relationship as $rel) {
                $sheetTargets[(string) $rel['Id']] = (string) $rel['Target'];
            }
        }
    }

    $sheets = [];
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    if ($workbookXml !== false) {
        $wb = @simplexml_load_string($workbookXml);
        if ($wb !== false) {
            $ns = $wb->getNamespaces(true);
            $rNs = $ns['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
            foreach ($wb->sheets->sheet as $sheet) {
                $attrs = $sheet->attributes($rNs);
                $rId = (string) $attrs['id'];
                $target = $sheetTargets[$rId] ?? null;
                if ($target === null) continue;
                // A Target vagy csomag-gyökérhez képest abszolút (pl. "/xl/worksheets/sheet1.xml",
                // ezt ír pl. openpyxl), vagy az xl/ mappához képest relatív (pl. "worksheets/sheet1.xml",
                // ezt ír pl. Excel/LibreOffice) — a kettőt máshogy kell feloldani.
                $path = (strpos($target, '/') === 0)
                    ? ltrim($target, '/')
                    : 'xl/' . ltrim($target, '/');
                $sheets[] = ['name' => (string) $sheet['name'], 'path' => $path];
            }
        }
    }
    if (!$sheets) {
        $sheets[] = ['name' => 'Sheet1', 'path' => 'xl/worksheets/sheet1.xml'];
    }

    $html = '';
    foreach ($sheets as $sheet) {
        $sheetXml = $zip->getFromName($sheet['path']);
        if ($sheetXml === false) continue;
        $rows = xlsx_parse_sheet($sheetXml, $sharedStrings);
        $html .= '<h4>' . htmlspecialchars($sheet['name'], ENT_QUOTES, 'UTF-8') . '</h4>';
        $html .= rows_to_html_table($rows);
    }
    $zip->close();

    return $html !== '' ? $html : '<div class="excel-empty">Ez a fájl üres.</div>';
}

function xlsx_shared_string_text($si): string {
    if (isset($si->t)) return (string) $si->t;
    $text = '';
    if (isset($si->r)) {
        foreach ($si->r as $run) {
            $text .= (string) $run->t;
        }
    }
    return $text;
}

function xlsx_col_to_index(string $ref): int {
    preg_match('/^([A-Z]+)/', $ref, $m);
    $col = $m[1] ?? 'A';
    $index = 0;
    for ($i = 0, $len = strlen($col); $i < $len; $i++) {
        $index = $index * 26 + (ord($col[$i]) - 64);
    }
    return $index - 1;
}

function xlsx_parse_sheet(string $xml, array $sharedStrings): array {
    $sheet = @simplexml_load_string($xml);
    $rows = [];
    if ($sheet === false || !isset($sheet->sheetData->row)) return $rows;

    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        $nextIndex = 0;
        foreach ($row->c as $c) {
            $ref = (string) $c['r'];
            $colIndex = $ref !== '' ? xlsx_col_to_index($ref) : $nextIndex;
            $type = (string) $c['t'];
            $rawValue = isset($c->v) ? (string) $c->v : '';

            if ($type === 's') {
                $value = $sharedStrings[(int) $rawValue] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = isset($c->is) ? xlsx_shared_string_text($c->is) : '';
            } elseif ($type === 'b') {
                $value = $rawValue === '1' ? 'TRUE' : 'FALSE';
            } else {
                $value = $rawValue;
            }
            $cells[$colIndex] = $value;
            $nextIndex = $colIndex + 1;
        }
        if (!$cells) {
            $rows[] = [];
            continue;
        }
        $max = max(array_keys($cells));
        $line = [];
        for ($i = 0; $i <= $max; $i++) {
            $line[] = $cells[$i] ?? '';
        }
        $rows[] = $line;
    }
    return $rows;
}

function rows_to_html_table(array $rows): string {
    $rows = array_values(array_filter($rows, fn ($r) => count($r) > 0));
    if (!$rows) return '<div class="excel-empty">Ez a lap üres.</div>';

    $header = $rows[0];
    $body = array_slice($rows, 1);

    $html = '<table><thead><tr>';
    foreach ($header as $h) {
        $html .= '<th>' . htmlspecialchars((string) $h, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($body as $row) {
        $html .= '<tr>';
        foreach (array_keys($header) as $i) {
            $val = $row[$i] ?? '';
            $html .= '<td>' . htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8') . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';
    return $html;
}
