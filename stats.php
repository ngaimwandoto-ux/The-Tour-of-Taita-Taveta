<?php
/**
 * stats.php
 * Public, read-only. Returns live counts so the homepage can stop
 * hardcoding "128" and fetch a real number instead.
 *
 * Usage from index.html, replacing the hardcoded stat:
 *   fetch('/stats.php').then(r => r.json()).then(d => {
 *     document.querySelector('#ticket-counter').textContent = d.paid_riders;
 *   });
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // fine here — this endpoint has no auth and no sensitive data

require __DIR__ . '/includes/database.php';
$db = Database::getInstance()->getConnection();

$paidRiders = $db->query("SELECT COUNT(*) c FROM registrations WHERE status = 'paid'")->fetch()['c'];
$pendingRiders = $db->query("SELECT COUNT(*) c FROM registrations WHERE status = 'pending'")->fetch()['c'];

$byLeg = $db->query("
    SELECT leg, COUNT(*) c FROM registrations WHERE status = 'paid' GROUP BY leg
")->fetchAll();

echo json_encode([
    'paid_riders' => (int) $paidRiders,
    'pending_riders' => (int) $pendingRiders,
    'by_leg' => $byLeg,
    'generated_at' => date('c'),
]);
