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

use Dotenv\Dotenv;
use Firebase\JWT\JWT;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// JSON-Daten vom Client einlesen
$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['username']) || !isset($input['password'])) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Username und Passwort erforderlich",
        "gruppe" => null,
        "userId" => null,
    ]);
    exit;
}

$username = $input['username'];
$password = $input['password'];

$host = $_ENV['dbhost'];       
$dbname = $_ENV['dbname']; 
$dbuser = $_ENV['dbuser'];  
$dbpass = $_ENV['dbpass'];

$jwtSecret = $_ENV['jwt_secret'];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Nutzer in DB suchen
    $stmt = $pdo->prepare("SELECT id, username, password_hash, gruppe FROM users WHERE username = :username LIMIT 1");
    $stmt->execute([":username" => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        // Login erfolgreich
        $abschnittsnamen = match($user['gruppe']){
            "familie" => ["Allgemein", "Mama", "Papa", "Flo"],
            "robotik" => ["Allgemein", "Forschung", "Robo-design", "Robo-game"],
            default => ["Error", "Error", "Error", "Error"]
        };

        $payload = [
            'id' => $user['id'],
            'gruppe' => $user['gruppe'],
            'iat' => time(),             // issued at
            'exp' => time() + 3600       // expireing
        ];

        $jwt = JWT::encode($payload, $jwtSecret, 'HS256');


        echo json_encode([
            "success" => true,
            "message" => null,
            "gruppe" => $user['gruppe'],
            "userId" => $user['id'],
            "abschnittsnamen" => $abschnittsnamen,
            "token" => $jwt,
        ]);
    } else {
        // Falsche Daten
        echo json_encode([
            "success" => false,
            "message" => "Ungültiger Benutzername oder Passwort"
        ]);
    }
} catch (PDOException $e) {
    echo json_encode([
        "success" => false,
        "message" => "DB-Fehler: " . $e->getMessage()
    ]);
}