<?php
opcache_reset();

$allowedOrigins = [
    'https://gandalf2532.dev',     // deine Webseite
    'http://localhost:3000',       // evtl. lokal zum Entwickeln
];

if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowedOrigins)) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
    header("Access-Control-Allow-Credentials: true");
} else {
    header("Access-Control-Allow-Origin: *"); // Für Apps oder andere Clients
}
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Preflight abfangen
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// JSON-Antwort erzwingen
header('Content-Type: application/json');
// Keine Browser-Cache
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Fehler-Logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/var/log/php_errors.log');
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();
$jwtSecret = $_ENV['jwt_secret'];

// JWT prüfen
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
if (!$authHeader) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Token fehlt']);
    exit;
}
preg_match('/Bearer\s(\S+)/', $authHeader, $matches);
$jwt = $matches[1];


try {
    $decoded = JWT::decode($jwt, new Key($jwtSecret, 'HS256'));
    $userGruppe = $decoded->gruppe ?? null;
    if (!$userGruppe) throw new Exception("Ungültige Gruppe");
} catch (Exception $e) {
    http_response_code(401);
    $value = print_r($decoded, 1);
    echo json_encode(["success" => false, "message" => "Ungültiger Token $value"]);
    exit;
}

// DB-Verbindung
$host = $_ENV['dbhost'];
$dbname = $_ENV['dbname'];
$dbuser = $_ENV['dbuser'];
$dbpass = $_ENV['dbpass'];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("SELECT * FROM `$userGruppe`");
    $stmt->execute();
    $to_dos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($to_dos as &$task) {
        $task["completed"] = match ($task["completed"]) {
            1, true, "1" => true,
            default => false,
        };
        $task["id"] = (int) $task["id"];
    }
    unset($task);

    echo json_encode([
        "success" => true,
        "tasks" => $to_dos
    ]);
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "DB-Fehler: " . $e->getMessage()]);
}
