<?php
/**
 * api/index.php
 * Single entry point: /api/index.php?endpoint=login etc.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/messaging.php';
require_once __DIR__ . '/../includes/directory.php';
require_once __DIR__ . '/../includes/accommodation.php';

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
$messaging = new Messaging();
$directory = new Directory();
$accommodation = new Accommodation();
$method = $_SERVER['REQUEST_METHOD'];
$endpoint = $_GET['endpoint'] ?? '';
$input = ($endpoint === 'upload-logo') ? [] : (json_decode(file_get_contents('php://input'), true) ?: []);
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

$currentUserId = $auth->validateToken($_COOKIE['auth_token'] ?? null);
$currentUser = $currentUserId ? $auth->getUserById($currentUserId) : null;

function requireAuth(?int $userId): bool {
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
        return false;
    }
    return true;
}

try {
    switch (true) {

        // ---------------- Auth (public) ----------------

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
            if (!requireAuth($currentUserId)) break;
            echo json_encode(['success' => true, 'user' => $currentUser]);
            break;

        // ---------------- Messaging (auth required) ----------------

        case $endpoint === 'find-user' && $method === 'GET':
            if (!requireAuth($currentUserId)) break;
            $found = $messaging->findUserByEmail($_GET['email'] ?? '');
            echo json_encode($found
                ? ['success' => true, 'user' => $found]
                : ['success' => false, 'error' => 'No account found with that email.']);
            break;

        case $endpoint === 'conversations' && $method === 'GET':
            if (!requireAuth($currentUserId)) break;
            echo json_encode(['success' => true, 'conversations' => $messaging->getConversations($currentUserId)]);
            break;

        case $endpoint === 'thread' && $method === 'GET':
            if (!requireAuth($currentUserId)) break;
            echo json_encode($messaging->getThread($currentUserId, (int) ($_GET['conversation_id'] ?? 0)));
            break;

        case $endpoint === 'send-message' && $method === 'POST':
            if (!requireAuth($currentUserId)) break;
            echo json_encode($messaging->sendMessage($currentUserId, (int) ($input['recipient_id'] ?? 0), $input['body'] ?? ''));
            break;

        case $endpoint === 'archive-conversation' && $method === 'POST':
            if (!requireAuth($currentUserId)) break;
            echo json_encode($messaging->archiveConversation($currentUserId, (int) ($input['conversation_id'] ?? 0)));
            break;

        case $endpoint === 'unread-count' && $method === 'GET':
            if (!requireAuth($currentUserId)) break;
            echo json_encode(['success' => true, 'count' => $messaging->getUnreadCount($currentUserId)]);
            break;

        // ---------------- Directory ----------------

        case $endpoint === 'directory' && $method === 'GET':
            echo json_encode(['success' => true, 'listings' => $directory->getAllPublicListings()]);
            break;

        case $endpoint === 'upload-logo' && $method === 'POST':
            if (!requireAuth($currentUserId)) break;
            echo json_encode($directory->uploadLogo($currentUserId, $currentUser['account_type'], $_FILES['logo'] ?? []));
            break;

        // ---------------- Teams ----------------

        case $endpoint === 'list-teams' && $method === 'GET':
            echo json_encode(['success' => true, 'teams' => $directory->listTeams()]);
            break;

        case $endpoint === 'my-team' && $method === 'GET':
            if (!requireAuth($currentUserId)) break;
            $team = $directory->getTeamByCaptain($currentUserId);
            echo json_encode($team
                ? ['success' => true, 'team' => $team]
                : ['success' => false, 'error' => 'No team found for this account.']);
            break;

        case $endpoint === 'team' && $method === 'GET':
            $team = $directory->getTeamBySlugWithRoster($_GET['slug'] ?? '');
            echo json_encode($team
                ? ['success' => true, 'team' => $team]
                : ['success' => false, 'error' => 'Team not found.']);
            break;

        // ---------------- Accommodation ----------------

        case $endpoint === 'properties' && $method === 'GET':
            // Public — the accommodation page's real listings.
            echo json_encode(['success' => true, 'properties' => $accommodation->getPublicListings()]);
            break;

        case $endpoint === 'register-property' && $method === 'POST':
            if (!requireAuth($currentUserId)) break;
            if ($currentUser['account_type'] !== 'host') {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Only host accounts can register properties.']);
                break;
            }
            echo json_encode($accommodation->registerProperty($currentUserId, $input));
            break;

        case $endpoint === 'my-properties' && $method === 'GET':
            if (!requireAuth($currentUserId)) break;
            echo json_encode(['success' => true, 'properties' => $accommodation->getMyProperties($currentUserId)]);
            break;

        case $endpoint === 'cancel-booking' && $method === 'POST':
            if (!requireAuth($currentUserId)) break;
            $bookingId = (int) ($input['booking_id'] ?? 0);
            $reason = trim($input['reason'] ?? '');
            // NOTE: this doesn't yet check that $currentUserId actually
            // owns the booking or its property — bookings are currently
            // guest-checkout with no account link, so there's no "guest
            // account" to check against. For now this endpoint is really
            // only safe to expose to HOST accounts cancelling a booking
            // on their own property, or left admin-only via
            // admin/bookings.php. Tighten this before exposing it to
            // guests directly.
            echo json_encode($accommodation->processCancellation($bookingId, $reason, 'host'));
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
