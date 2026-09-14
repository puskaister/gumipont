<?php
// Közös induló kód minden api/ végponthoz: session, DB-kapcsolat,
// JSON válasz segédfüggvények, auth-ellenőrzők, rate limit.
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0'); // ne kerüljön PHP hibaszöveg a JSON válaszba éles környezetben

$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Hiányzik az api/config.php. Másold át api/config.example.php-ból, és töltsd ki az adatbázis-adataidat.']);
    exit;
}
$config = require $configPath;
require __DIR__ . '/lib/db.php';

session_name($config['session_name'] ?? 'gumipont_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');

mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = mysqli_init();
$connected = @$mysqli->real_connect(
    $config['db']['host'],
    $config['db']['user'],
    $config['db']['pass'],
    $config['db']['name']
);
if (!$connected) {
    http_response_code(500);
    echo json_encode(['error' => 'Nem sikerült csatlakozni az adatbázishoz: ' . mysqli_connect_error()]);
    exit;
}
$mysqli->set_charset($config['db']['charset'] ?? 'utf8mb4');

function json_input(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// Multipart (fájlfeltöltős) POST-oknál a mezők a $_POST-ban vannak; JSON
// testű kéréseknél a php://input-ban. Ez a kettőt egyesíti egy tömbbe.
function request_body(): array {
    if (!empty($_POST)) return $_POST;
    return json_input();
}

function respond($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function error_response(string $message, int $status = 400, $details = null): void {
    $body = ['error' => $message];
    if ($details !== null) $body['details'] = $details;
    respond($body, $status);
}

function require_method(string $method): void {
    $actual = $_SERVER['REQUEST_METHOD'];
    // Böngészőből multipart PUT-ot nehéz küldeni/feldolgozni PHP-ban, ezért
    // a kliens POST-ot küld egy _method mezővel, amikor "igazából" PUT-ot ért.
    if ($actual === 'POST' && isset($_POST['_method'])) {
        $actual = strtoupper((string) $_POST['_method']);
    }
    if (strtoupper($actual) !== strtoupper($method)) {
        error_response('Method not allowed', 405);
    }
}

function current_user(mysqli $mysqli): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $stmt = $mysqli->prepare('SELECT id, name, email, role, created_at FROM users WHERE id = ?');
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $user = stmt_fetch_one($stmt);
    $stmt->close();
    return $user ?: null;
}

function require_auth(mysqli $mysqli): array {
    $user = current_user($mysqli);
    if (!$user) error_response('Unauthorized', 401);
    return $user;
}

function require_admin(mysqli $mysqli): array {
    $user = require_auth($mysqli);
    if ($user['role'] !== 'admin') error_response('Forbidden', 403);
    return $user;
}

// Egyszerű, adatbázis-alapú rate limit (pl. 20 kérés / 15 perc egy kulcsra).
function check_rate_limit(mysqli $mysqli, string $key, int $limit, int $windowSeconds): bool {
    $now = time();
    $stmt = $mysqli->prepare('SELECT count, UNIX_TIMESTAMP(window_start) AS window_start FROM rate_limits WHERE rl_key = ?');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = stmt_fetch_one($stmt);
    $stmt->close();

    if (!$row || ($now - (int) $row['window_start']) > $windowSeconds) {
        $stmt = $mysqli->prepare('REPLACE INTO rate_limits (rl_key, count, window_start) VALUES (?, 1, FROM_UNIXTIME(?))');
        $stmt->bind_param('si', $key, $now);
        $stmt->execute();
        $stmt->close();
        return true;
    }

    if ((int) $row['count'] >= $limit) {
        return false;
    }

    $stmt = $mysqli->prepare('UPDATE rate_limits SET count = count + 1 WHERE rl_key = ?');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->close();
    return true;
}

function require_auth_rate_limit(mysqli $mysqli): void {
    $key = 'auth:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!check_rate_limit($mysqli, $key, 20, 15 * 60)) {
        error_response('Too many requests, try again later.', 429);
    }
}
