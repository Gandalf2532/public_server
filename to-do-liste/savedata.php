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
    echo json_encode(["success"=>false,"message"=>"Ungültiger Token"]);
    exit;
}

// JSON-Daten vom Client
$input = json_decode(file_get_contents("php://input"), true);
if (!isset($input['localdatatosave']) || !is_array($input['localdatatosave'])) {
    echo json_encode(["success"=>false,"message"=>"Keine Aufgaben übergeben oder kein Array."]);
    exit;
}

$datatosave = $input['localdatatosave'];
foreach ($datatosave as &$task) {
    $task['completed'] = !empty($task['completed']) ? 1 : 0;
}
unset($task);

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

    $insertStmt = $pdo->prepare("INSERT INTO `$userGruppe`(`title`,`completed`,`id`,`person`) VALUES (:title,:completed,:id,:person)");
    $updateStmt = $pdo->prepare("UPDATE `$userGruppe` SET `title`=:title, `completed`=:completed, `person`=:person WHERE `id`=:id");
    $deleteStmt = $pdo->prepare("DELETE FROM `$userGruppe` WHERE `id`=:id");

    // Insert / Update
    foreach ($datatosave as $task) {
        $id = intval($task['id']);
        $title = $task['title'] ?? '';
        $completed = $task['completed'];
        $person = $task['person'] ?? 'allgemein';

        $found = false;
        foreach ($to_dos as $dbtask) {
            if (intval($dbtask['id']) === $id) {
                $found = true;
                if ($dbtask['title'] !== $title || $dbtask['completed'] != $completed || $dbtask['person'] !== $person) {
                    $updateStmt->execute([
                        ":title" => $title,
                        ":completed" => $completed,
                        ":person" => $person,
                        ":id" => $id
                    ]);
                }
                break;
            }
        }
        if (!$found) {
            $insertStmt->execute([
                ":title" => $title,
                ":completed" => $completed,
                ":id" => $id,
                ":person" => $person
            ]);
        }
    }

    // Delete nicht mehr vorhandene Tasks
    foreach ($to_dos as $dbtask) {
        $exists = false;
        foreach ($datatosave as $task) {
            if (intval($task['id']) === intval($dbtask['id'])) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $deleteStmt->execute([":id" => $dbtask['id']]);
        }
    }

    echo json_encode(["success" => true]);
} catch (PDOException $e) {
    error_log("ERROR: DB Exception - " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "DB-Fehler: " . $e->getMessage()]);
}