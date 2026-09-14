<?php
declare(strict_types=1);

// mysqli_stmt::get_result() a mysqlnd drivert igényli, ami sok olcsó
// megosztott tárhelyen nincs telepítve (helyette a régebbi libmysqlclient
// fut). Ez a két segédfüggvény ugyanazt tudja bind_result()+fetch()
// alapon, oszlopnevektől függetlenül (result_metadata()-ból olvassa ki
// őket), így mysqlnd nélkül is működik.
function stmt_fetch_all(mysqli_stmt $stmt): array {
    $meta = $stmt->result_metadata();
    if (!$meta) return [];

    $row = [];
    $bind = [];
    while ($field = $meta->fetch_field()) {
        $row[$field->name] = null;
        $bind[] = &$row[$field->name];
    }
    $meta->close();
    call_user_func_array([$stmt, 'bind_result'], $bind);

    $rows = [];
    while ($stmt->fetch()) {
        $rows[] = array_map(fn ($v) => $v, $row);
    }
    return $rows;
}

function stmt_fetch_one(mysqli_stmt $stmt): ?array {
    return stmt_fetch_all($stmt)[0] ?? null;
}
