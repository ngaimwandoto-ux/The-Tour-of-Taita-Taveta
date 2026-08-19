<?php
/**
 * stats.php
 * Public, read-only. Returns live counts and prize pool data for the homepage.
 * 
 * Usage from index.html:
 *   fetch('/stats.php').then(r => r.json()).then(d => {
 *     document.querySelector('#riders-stat').textContent = d.total_riders;
 *     document.querySelector('#ticket-counter').textContent = d.total_riders;
 *     document.querySelector('#elite-pool-total').textContent = 'KES ' + d.elite_pool.toLocaleString();
 *     // etc.
 *   });
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // fine here — this endpoint has no auth and no sensitive data

require __DIR__ . '/includes/database.php';
$db = Database::getInstance()->getConnection();

// Total paid riders (all categories)
$stmt = $db->query("SELECT COUNT(*) as c FROM registrations WHERE status = 'paid'");
$totalRiders = $stmt->fetch()['c'] ?? 0;

// Pending riders (for reference)
$stmt = $db->query("SELECT COUNT(*) as c FROM registrations WHERE status = 'pending'");
$pendingRiders = $stmt->fetch()['c'] ?? 0;

// Elite prize pool
$stmt = $db->query("SELECT SUM(amount) as total FROM registrations WHERE status = 'paid' AND ticket = 'elite'");
$elitePool = $stmt->fetch()['total'] ?? 0;

// Amateur prize pool
$stmt = $db->query("SELECT SUM(amount) as total FROM registrations WHERE status = 'paid' AND ticket = 'amateur'");
$amateurPool = $stmt->fetch()['total'] ?? 0;

// Elite count
$stmt = $db->query("SELECT COUNT(*) as c FROM registrations WHERE status = 'paid' AND ticket = 'elite'");
$eliteCount = $stmt->fetch()['c'] ?? 0;

// Amateur count
$stmt = $db->query("SELECT COUNT(*) as c FROM registrations WHERE status = 'paid' AND ticket = 'amateur'");
$amateurCount = $stmt->fetch()['c'] ?? 0;

// Open count
$stmt = $db->query("SELECT COUNT(*) as c FROM registrations WHERE status = 'paid' AND ticket = 'open'");
$openCount = $stmt->fetch()['c'] ?? 0;

// Per-leg breakdown with Elite and Amateur pools
$byLeg = $db->query("
    SELECT 
        leg,
        COUNT(*) as total_riders,
        SUM(CASE WHEN ticket = 'elite' THEN 1 ELSE 0 END) as elite_count,
        SUM(CASE WHEN ticket = 'amateur' THEN 1 ELSE 0 END) as amateur_count,
        SUM(CASE WHEN ticket = 'open' THEN 1 ELSE 0 END) as open_count,
        SUM(CASE WHEN ticket = 'elite' THEN amount ELSE 0 END) as elite_pool,
        SUM(CASE WHEN ticket = 'amateur' THEN amount ELSE 0 END) as amateur_pool,
        SUM(CASE WHEN ticket IN ('elite', 'amateur') THEN amount ELSE 0 END) as leg_prize_pool
    FROM registrations 
    WHERE status = 'paid'
    GROUP BY leg
    ORDER BY leg
")->fetchAll();

// Legacy format for backwards compatibility (simple by-leg counts)
$byLegSimple = $db->query("
    SELECT leg, COUNT(*) as c FROM registrations WHERE status = 'paid' GROUP BY leg
")->fetchAll();

echo json_encode([
    'success' => true,
    'total_riders' => (int) $totalRiders,
    'pending_riders' => (int) $pendingRiders,
    'elite_count' => (int) $eliteCount,
    'amateur_count' => (int) $amateurCount,
    'open_count' => (int) $openCount,
    'elite_pool' => (int) $elitePool,
    'amateur_pool' => (int) $amateurPool,
    'total_prize_pool' => (int) ($elitePool + $amateurPool),
    'by_leg' => $byLeg,
    'by_leg_simple' => $byLegSimple,
    'generated_at' => date('c'),
]);
