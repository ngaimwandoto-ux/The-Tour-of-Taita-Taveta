<?php
/**
 * teams-list.php — public, read-only. Feeds the optional "Riding for a
 * team?" dropdown on the registration form. Only teams you have approved
 * (is_public = 1) are listed. Returns id + name only.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=120');

require __DIR__ . '/includes/database.php';

try {
    $db = Database::getInstance()->getConnection();
    $teams = $db->query("SELECT id, name FROM teams WHERE is_public = 1 ORDER BY name COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'teams' => $teams]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'teams' => []]);
}
