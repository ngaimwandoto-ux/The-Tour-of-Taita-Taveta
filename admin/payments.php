<?php
/**
 * admin/payments.php
 * Key-protected, read-only. One place to see everything that has been paid
 * through the site, check it against your M-Pesa statement, and print a
 * start list for race day. Nothing here changes any record.
 *
 *   Registrations · Visit fees · Donations · Start list (per leg)
 *   Filters: status, leg, dates, search. Every view downloads as CSV (opens in Excel).
 *
 * Usage: https://yourdomain/admin/payments.php?key=YOUR_ADMIN_KEY
 * Times are shown in East Africa Time (EAT).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/registration-columns.php';
require_once __DIR__ . '/../includes/institutions.php';
require_once __DIR__ . '/../includes/leg-schedule.php';

if (!defined('ADMIN_KEY') || ADMIN_KEY === '' || ($_GET['key'] ?? '') !== ADMIN_KEY) {
    http_response_code(403);
    die('Not authorised. Add ?key=YOUR_ADMIN_KEY to the URL.');
}

$db = Database::getInstance()->getConnection();
ensureRegistrationColumns($db);   // makes sure registrations.team_id exists before we join teams
ensureInstitutionTable($db);

$h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$legNames = array_map(function ($l) { return $l['leg']; }, legSchedulePublic());

// ---------- the four views ----------
$TABS = [
    'registrations' => [
        'label' => 'Registrations',
        'from'  => 'registrations x LEFT JOIN teams t ON t.id = x.team_id',
        'select'=> "datetime(x.created_at,'+3 hours') AS \"Time (EAT)\", x.name AS \"Name\", x.phone AS \"Phone\", x.email AS \"Email\", x.ticket AS \"Category\", x.leg AS \"Leg\", x.gender AS \"Gender\", t.name AS \"Team\", x.amount AS \"Amount (KES)\", x.status AS \"Status\", x.mpesa_receipt AS \"M-Pesa receipt\"",
        'search'=> ['x.name', 'x.phone', 'x.email', 'x.mpesa_receipt', 't.name'],
        'leg' => 'x.leg', 'created' => 'x.created_at', 'status' => 'x.status', 'amount' => 'x.amount', 'order' => 'x.created_at DESC',
    ],
    'visits' => [
        'label' => 'Visit fees',
        'from'  => 'visits x',
        'select'=> "datetime(x.created_at,'+3 hours') AS \"Time (EAT)\", x.name AS \"Name\", x.phone AS \"Phone\", x.site AS \"Site\", x.leg AS \"Leg\", x.amount AS \"Amount (KES)\", x.status AS \"Status\", x.mpesa_receipt AS \"M-Pesa receipt\"",
        'search'=> ['x.name', 'x.phone', 'x.site', 'x.mpesa_receipt'],
        'leg' => 'x.leg', 'created' => 'x.created_at', 'status' => 'x.status', 'amount' => 'x.amount', 'order' => 'x.created_at DESC',
    ],
    'donations' => [
        'label' => 'Donations',
        'from'  => 'donations x',
        'select'=> "datetime(x.created_at,'+3 hours') AS \"Time (EAT)\", x.name AS \"Name\", x.phone AS \"Phone\", x.scope AS \"Backs\", x.tier AS \"Tier\", x.amount AS \"Amount (KES)\", x.status AS \"Status\", x.mpesa_receipt AS \"M-Pesa receipt\"",
        'search'=> ['x.name', 'x.phone', 'x.scope', 'x.mpesa_receipt'],
        'leg' => 'x.scope', 'created' => 'x.created_at', 'status' => 'x.status', 'amount' => 'x.amount', 'order' => 'x.created_at DESC',
    ],
];

$tab = $_GET['tab'] ?? 'registrations';
if ($tab !== 'startlist' && !isset($TABS[$tab])) $tab = 'registrations';
$status = in_array($_GET['status'] ?? 'paid', ['paid', 'pending', 'failed', 'all'], true) ? ($_GET['status'] ?? 'paid') : 'paid';
$leg    = $_GET['leg'] ?? 'all';
if ($leg !== 'all' && !in_array($leg, $legNames, true) && $leg !== 'Tour of Taita Taveta') $leg = 'all';
$from   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
$to     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '';
$q      = mb_substr(trim($_GET['q'] ?? ''), 0, 60);
$export = ($_GET['export'] ?? '') === 'csv';

function csvCell($v): string {
    $v = (string) $v;
    if ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) $v = "'" . $v;   // stop Excel treating text as a formula
    return '"' . str_replace('"', '""', $v) . '"';
}
function sendCsv(string $name, array $header, array $rows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '-' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";                                                        // BOM so Excel reads accents correctly
    echo implode(',', array_map('csvCell', $header)) . "\r\n";
    foreach ($rows as $r) echo implode(',', array_map('csvCell', array_values($r))) . "\r\n";
    exit;
}

// ---------- START LIST ----------
if ($tab === 'startlist') {
    $up = upcomingLeg();
    $slLeg = in_array($_GET['leg'] ?? '', $legNames, true) ? $_GET['leg'] : ($up ? $up['leg'] : $legNames[0]);

    $riders = $db->prepare("
        SELECT x.ticket AS \"Category\", x.gender AS \"Gender\", x.name AS \"Name\", x.phone AS \"Phone\", t.name AS \"Team\", x.mpesa_receipt AS \"M-Pesa receipt\"
        FROM registrations x LEFT JOIN teams t ON t.id = x.team_id
        WHERE x.status = 'paid' AND LOWER(TRIM(x.leg)) = LOWER(?)
        ORDER BY CASE LOWER(x.ticket) WHEN 'elite' THEN 1 WHEN 'amateur' THEN 2 WHEN 'open' THEN 3 ELSE 4 END, x.gender, x.name COLLATE NOCASE");
    $riders->execute([$slLeg]);
    $riders = $riders->fetchAll(PDO::FETCH_ASSOC);

    $teams = $db->prepare("SELECT institution AS \"Institution\", team_name AS \"Team\", riders AS \"Riders\", contact_name AS \"Contact\", phone AS \"Phone\", email AS \"Email\", payment_ref AS \"Payment ref\"
        FROM institution_entries WHERE status = 'paid' AND LOWER(TRIM(leg)) = LOWER(?) ORDER BY institution COLLATE NOCASE");
    $teams->execute([$slLeg]);
    $teams = $teams->fetchAll(PDO::FETCH_ASSOC);

    if ($export) {
        $rows = [];
        foreach ($riders as $r) $rows[] = ['Individual', $r['Category'], $r['Gender'], $r['Name'], $r['Phone'], $r['Team'], '', $r['M-Pesa receipt']];
        foreach ($teams as $t)  $rows[] = ['College & University team', 'college', '', $t['Team'] . ' (' . $t['Institution'] . ')', $t['Phone'], $t['Team'], $t['Riders'], $t['Payment ref']];
        sendCsv('start-list-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($slLeg)),
            ['Type', 'Category', 'Gender', 'Name', 'Phone', 'Team', 'Riders in team', 'Payment reference'], $rows);
    }
} else {
    // ---------- LIST VIEWS ----------
    $cfg = $TABS[$tab];
    $where = []; $args = [];
    if ($leg !== 'all')  { $where[] = "LOWER(TRIM({$cfg['leg']})) = LOWER(?)"; $args[] = $leg; }
    if ($from !== '')    { $where[] = "date({$cfg['created']},'+3 hours') >= ?"; $args[] = $from; }
    if ($to !== '')      { $where[] = "date({$cfg['created']},'+3 hours') <= ?"; $args[] = $to; }
    if ($q !== '') {
        $like = [];
        foreach ($cfg['search'] as $col) { $like[] = "$col LIKE ?"; $args[] = '%' . $q . '%'; }
        $where[] = '(' . implode(' OR ', $like) . ')';
    }
    $baseWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    // totals by status for the current filters (ignoring the status filter, so you can see the whole picture)
    $sum = $db->prepare("SELECT {$cfg['status']} AS s, COUNT(*) AS n, COALESCE(SUM({$cfg['amount']}),0) AS kes FROM {$cfg['from']} $baseWhere GROUP BY {$cfg['status']}");
    $sum->execute($args);
    $byStatus = ['paid' => ['n' => 0, 'kes' => 0], 'pending' => ['n' => 0, 'kes' => 0], 'failed' => ['n' => 0, 'kes' => 0]];
    foreach ($sum->fetchAll(PDO::FETCH_ASSOC) as $r) $byStatus[$r['s']] = ['n' => (int) $r['n'], 'kes' => (int) $r['kes']];

    $listWhere = $baseWhere; $listArgs = $args;
    if ($status !== 'all') {
        $listWhere .= ($listWhere ? ' AND ' : ' WHERE ') . "{$cfg['status']} = ?";
        $listArgs[] = $status;
    }
    $limit = $export ? 100000 : 500;
    $st = $db->prepare("SELECT {$cfg['select']} FROM {$cfg['from']} $listWhere ORDER BY {$cfg['order']} LIMIT $limit");
    $st->execute($listArgs);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    if ($export) {
        sendCsv($tab, $rows ? array_keys($rows[0]) : ['No rows'], $rows);
    }
}

// ---------- page ----------
$K = urlencode(ADMIN_KEY);
$keep = function (array $over = []) use ($tab, $status, $leg, $from, $to, $q, $K) {
    $p = array_merge(['key' => ADMIN_KEY, 'tab' => $tab, 'status' => $status, 'leg' => $leg, 'from' => $from, 'to' => $to, 'q' => $q], $over);
    return '?' . http_build_query(array_filter($p, function ($v) { return $v !== '' && $v !== null; }));
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payments &amp; start lists</title>
<style>
  body { font-family: sans-serif; max-width: 1100px; margin: 1.2rem auto; padding: 0 0.8rem; background: #FAFAF7; color: #171712; }
  h1 { font-size: 1.3rem; margin: 0 0 0.3rem; }
  .links { font-size: 0.82rem; color: #666; margin-bottom: 0.8rem; }
  .tabs { display: flex; gap: 0.3rem; flex-wrap: wrap; margin: 0.6rem 0; }
  .tabs a { padding: 0.45rem 0.8rem; background: #fff; border: 1px solid #ccc; color: #171712; text-decoration: none; font-size: 0.85rem; }
  .tabs a.on { background: #171712; color: #F28C0F; border-color: #171712; }
  form.filters { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: end; background: #fff; border: 1px solid #ddd; padding: 0.6rem; margin-bottom: 0.7rem; font-size: 0.8rem; }
  form.filters label { display: flex; flex-direction: column; gap: 0.15rem; color: #555; }
  input, select { padding: 0.35rem; border: 1px solid #bbb; font-size: 0.82rem; }
  button, a.btn { padding: 0.4rem 0.8rem; border: none; background: #171712; color: #fff; font-size: 0.8rem; text-decoration: none; cursor: pointer; }
  a.btn.green { background: #3FA34D; }
  .sum { display: flex; gap: 0.6rem; flex-wrap: wrap; margin: 0.4rem 0 0.8rem; }
  .sum div { background: #fff; border: 1px solid #ddd; padding: 0.5rem 0.8rem; font-size: 0.8rem; }
  .sum b { display: block; font-size: 1.05rem; }
  .wrap { overflow-x: auto; background: #fff; border: 1px solid #ddd; }
  table { border-collapse: collapse; width: 100%; font-size: 0.8rem; }
  th, td { padding: 0.4rem 0.55rem; border-bottom: 1px solid #eee; text-align: left; white-space: nowrap; }
  th { background: #f3f1ea; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em; position: sticky; top: 0; }
  .paid { color: #1e7a2c; font-weight: 600; } .pending { color: #a86500; } .failed { color: #b3261e; }
  h2 { font-size: 1rem; margin: 1.2rem 0 0.4rem; border-bottom: 2px solid #171712; padding-bottom: 0.2rem; }
  .note { font-size: 0.75rem; color: #777; margin-top: 0.4rem; }
</style>
</head>
<body>
<h1>Payments &amp; start lists</h1>
<div class="links">Read-only. Also: <a href="approve.php?key=<?= $K ?>">Approvals</a> · <a href="bookings.php?key=<?= $K ?>">Bookings (payouts &amp; refunds)</a> · <a href="institutions.php?key=<?= $K ?>">College &amp; University teams</a></div>

<div class="tabs">
  <?php foreach ($TABS as $k => $t): ?><a class="<?= $tab === $k ? 'on' : '' ?>" href="<?= $h($keep(['tab' => $k, 'q' => '', 'leg' => 'all'])) ?>"><?= $h($t['label']) ?></a><?php endforeach; ?>
  <a class="<?= $tab === 'startlist' ? 'on' : '' ?>" href="<?= $h($keep(['tab' => 'startlist', 'leg' => ''])) ?>">Start list</a>
</div>

<?php if ($tab === 'startlist'): ?>
  <form class="filters" method="get">
    <input type="hidden" name="key" value="<?= $h(ADMIN_KEY) ?>"><input type="hidden" name="tab" value="startlist">
    <label>Leg <select name="leg" onchange="this.form.submit()"><?php foreach ($legNames as $n): ?><option <?= $n === $slLeg ? 'selected' : '' ?>><?= $h($n) ?></option><?php endforeach; ?></select></label>
    <a class="btn green" href="<?= $h($keep(['tab' => 'startlist', 'leg' => $slLeg, 'export' => 'csv'])) ?>">Download CSV</a>
  </form>
  <h2>Individual riders — <?= $h($slLeg) ?> (<?= count($riders) ?> paid)</h2>
  <?php if (!$riders): ?><p class="note">No paid riders for this leg yet.</p><?php else: ?>
  <div class="wrap"><table>
    <tr><th>#</th><th>Category</th><th>Gender</th><th>Name</th><th>Phone</th><th>Team</th><th>M-Pesa receipt</th></tr>
    <?php foreach ($riders as $i => $r): ?><tr><td><?= $i + 1 ?></td><td><?= $h($r['Category']) ?></td><td><?= $h($r['Gender']) ?></td><td><?= $h($r['Name']) ?></td><td><?= $h($r['Phone']) ?></td><td><?= $h($r['Team']) ?></td><td><?= $h($r['M-Pesa receipt']) ?></td></tr><?php endforeach; ?>
  </table></div><?php endif; ?>
  <h2>College &amp; University teams — <?= $h($slLeg) ?> (<?= count($teams) ?> paid)</h2>
  <?php if (!$teams): ?><p class="note">No paid teams for this leg yet.</p><?php else: ?>
  <div class="wrap"><table>
    <tr><th>Institution</th><th>Team</th><th>Riders</th><th>Contact</th><th>Phone</th><th>Email</th><th>Payment ref</th></tr>
    <?php foreach ($teams as $t): ?><tr><td><?= $h($t['Institution']) ?></td><td><?= $h($t['Team']) ?></td><td><?= (int) $t['Riders'] ?></td><td><?= $h($t['Contact']) ?></td><td><?= $h($t['Phone']) ?></td><td><?= $h($t['Email']) ?></td><td><?= $h($t['Payment ref']) ?></td></tr><?php endforeach; ?>
  </table></div><?php endif; ?>
  <p class="note">Only paid entries are listed. Pending and failed payments never appear here.</p>

<?php else: ?>
  <form class="filters" method="get">
    <input type="hidden" name="key" value="<?= $h(ADMIN_KEY) ?>"><input type="hidden" name="tab" value="<?= $h($tab) ?>">
    <label>Status <select name="status"><?php foreach (['paid' => 'Paid', 'pending' => 'Pending', 'failed' => 'Failed', 'all' => 'All'] as $v => $l): ?><option value="<?= $v ?>" <?= $status === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
    <label><?= $tab === 'donations' ? 'Backs' : 'Leg' ?> <select name="leg"><option value="all">All</option>
      <?php foreach ($legNames as $n): ?><option <?= $leg === $n ? 'selected' : '' ?>><?= $h($n) ?></option><?php endforeach; ?>
      <?php if ($tab === 'donations'): ?><option <?= $leg === 'Tour of Taita Taveta' ? 'selected' : '' ?>>Tour of Taita Taveta</option><?php endif; ?></select></label>
    <label>From <input type="date" name="from" value="<?= $h($from) ?>"></label>
    <label>To <input type="date" name="to" value="<?= $h($to) ?>"></label>
    <label>Search <input type="text" name="q" value="<?= $h($q) ?>" placeholder="name, phone, receipt"></label>
    <button type="submit">Apply</button>
    <a class="btn green" href="<?= $h($keep(['export' => 'csv'])) ?>">Download CSV</a>
  </form>

  <div class="sum">
    <div>Paid<b>KES <?= number_format($byStatus['paid']['kes']) ?></b><?= $byStatus['paid']['n'] ?> payment<?= $byStatus['paid']['n'] == 1 ? '' : 's' ?></div>
    <div>Pending<b><?= $byStatus['pending']['n'] ?></b>KES <?= number_format($byStatus['pending']['kes']) ?> not yet confirmed</div>
    <div>Failed<b><?= $byStatus['failed']['n'] ?></b>KES <?= number_format($byStatus['failed']['kes']) ?> not received</div>
  </div>

  <div class="wrap"><table>
    <?php if (!$rows): ?><tr><td>No rows match these filters.</td></tr><?php else: ?>
    <tr><?php foreach (array_keys($rows[0]) as $c): ?><th><?= $h($c) ?></th><?php endforeach; ?></tr>
    <?php foreach ($rows as $r): ?><tr><?php foreach ($r as $c => $v): ?>
      <td class="<?= $c === 'Status' ? $h($v) : '' ?>"><?= $c === 'Amount (KES)' ? number_format((int) $v) : $h($v) ?></td>
    <?php endforeach; ?></tr><?php endforeach; endif; ?>
  </table></div>
  <p class="note">Showing up to 500 rows (the CSV download has all of them). The Paid total above is what to match against your M-Pesa statement for the same dates. Times are EAT.</p>
<?php endif; ?>
</body>
</html>
