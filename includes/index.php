<?php
/**
 * api/index.php
 *
 * Single entry point: /api/index.php?endpoint=login etc.
 * Point your web server at this file, or route /api/* to it.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';

// ---- CORS: locked to real origins, never a wildcard ----
$allowedOrigins = array_filter(array_map('trim', explode(',', CORS_ALLOWED_ORIGINS)));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$auth = new Auth();
$method = $_SERVER['REQUEST_METHOD'];
$endpoint = $_GET['endpoint'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

try {
    switch (true) {
        case $endpoint === 'register' && $method === 'POST':
            echo json_encode($auth->register($input));
            break;

        case $endpoint === 'login' && $method === 'POST':
            echo json_encode($auth->login($input['email'] ?? '', $input['password'] ?? '', $ip));
            break;

        case $endpoint === 'logout' && $method === 'POST':
            echo json_encode($auth->logout());
            break;

        case $endpoint === 'profile' && $method === 'GET':
            $userId = $auth->validateToken($_COOKIE['auth_token'] ?? null);
            if (!$userId) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
                break;
            }
            $user = $auth->getUserById($userId);
            echo json_encode(['success' => true, 'user' => $user]);
            break;

        default:
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Unknown endpoint.']);
    }
} catch (Throwable $e) {
    error_log('API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error.']);
}
