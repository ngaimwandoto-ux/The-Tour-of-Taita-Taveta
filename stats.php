<?php
/**
 * stats.php  — public, read-only live numbers.
 *
 * Powers:
 *   - Homepage: total riders paid + the Elite / Amateur prize pools across ALL legs
 *   - Each leg page: that leg's paid-rider count + that leg's Elite / Amateur pools
 *
 * Only status = 'paid' rows count. Pending / failed never show.
 * Elite and Amateur each have their OWN pool. Open and College & University entries
 * are counted as riders but are NOT part of any prize pool.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // no auth, no personal data — aggregates only
header('Cache-Control: public, max-age=60');

require __DIR__ . '/includes/database.php';

// Share of each paid Elite/Amateur entry fee that goes into the pool.
// 1.0 = 100%. Change this ONE number if a cut comes off (e.g. 0.9 = 90%).
const POOL_SHARE = 1.0;

// Same spelling as the <option value> in the registration form's leg dropdown.
$LEGS = ['Sagalla', 'Vuria/Wusi', 'Bura', 'Kasighau', 'Mbololo/Ronge', 'Mbale', 'Funju'];

try {
    $db = Database::getInstance()->getConnection();

    $pending = (int) $db->query("SELECT COUNT(*) c FROM registrations WHERE status = 'pending'")->fetch()['c'];

    // ticket is stored lowercase ('elite','amateur','open'); LOWER() guards against stray capitals
    $rows = $db->query("
        SELECT LOWER(TRIM(leg)) AS leg, LOWER(TRIM(ticket)) AS ticket,
               COUNT(*) AS c, COALESCE(SUM(amount), 0) AS s
        FROM registrations
        WHERE status = 'paid'
        GROUP BY LOWER(TRIM(leg)), LOWER(TRIM(ticket))
    ")->fetchAll(PDO::FETCH_ASSOC);

    $blank = function ($name) {
        return [
            'leg' => $name,
            'paid_count' => 0,
            'elite_count' => 0, 'amateur_count' => 0, 'open_count' => 0, 'college_count' => 0, 'other_count' => 0,
            'elite_pool' => 0, 'amateur_pool' => 0, 'leg_prize_pool' => 0,
        ];
    };

    $perLeg = [];
    foreach ($LEGS as $name) { $perLeg[strtolower($name)] = $blank($name); }

    foreach ($rows as $r) {
        $key = $r['leg'];
        if (!isset($perLeg[$key])) { $perLeg[$key] = $blank($r['leg']); } // unknown leg spelling: still counted
        $n = (int) $r['c'];
        $sum = (int) $r['s'];
        $perLeg[$key]['paid_count'] += $n;

        if ($r['ticket'] === 'elite') {
            $perLeg[$key]['elite_count'] += $n;
            $perLeg[$key]['elite_pool']  += (int) round($sum * POOL_SHARE);
        } elseif ($r['ticket'] === 'amateur') {
            $perLeg[$key]['amateur_count'] += $n;
            $perLeg[$key]['amateur_pool']  += (int) round($sum * POOL_SHARE);
        } elseif ($r['ticket'] === 'open') {
            $perLeg[$key]['open_count'] += $n;        // counted as a rider, never in the pool
        } elseif ($r['ticket'] === 'college') {
            $perLeg[$key]['college_count'] += $n;     // counted as a rider, never in the pool
        } else {
            $perLeg[$key]['other_count'] += $n;
        }
    }

    $totals = $blank('All legs');
    foreach ($perLeg as $k => &$l) {
        $l['leg_prize_pool'] = $l['elite_pool'] + $l['amateur_pool'];
        foreach (['paid_count','elite_count','amateur_count','open_count','college_count','other_count','elite_pool','amateur_pool','leg_prize_pool'] as $f) {
            $totals[$f] += $l[$f];
        }
    }
    unset($l);

    echo json_encode([
        'success'        => true,
        // top-level fields the homepage script reads
        'total_riders'   => $totals['paid_count'],
        'paid_riders'    => $totals['paid_count'],   // older name, kept
        'pending_riders' => $pending,
        'elite_count'    => $totals['elite_count'],
        'elite_pool'     => $totals['elite_pool'],
        'amateur_count'  => $totals['amateur_count'],
        'amateur_pool'   => $totals['amateur_pool'],
        'open_count'     => $totals['open_count'],
        'college_count'  => $totals['college_count'],
        'totals'         => $totals,
        'per_leg'        => array_values($perLeg),
        'generated_at'   => date('c'),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Stats unavailable']);
}
