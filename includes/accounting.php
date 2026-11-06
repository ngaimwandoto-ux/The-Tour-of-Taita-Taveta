<?php
/**
 * includes/accounting.php
 * Bookkeeping + tax ESTIMATES for the Tour. Read by admin/accounts.php.
 *
 * Money in is read straight from the site (paid entries, team entries,
 * accommodation commission, visitation fees, donations). Money out, sponsorship
 * and anything else is typed in by you as ledger entries. Tax rates and your
 * tax set-up live in the acct_settings table (edit them in
 * admin/accounts-settings.php) — nothing about tax law is hard-coded.
 *
 * All figures are KES, whole shillings, cash basis (counted when paid).
 * Dates use Nairobi time (UTC+3). These are estimates to help you plan and
 * hand your accountant clean numbers — they are not a tax return.
 */
require_once __DIR__ . '/institutions.php';

function ensureAccountingTables(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS acct_entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        entry_date TEXT NOT NULL,
        kind TEXT NOT NULL CHECK(kind IN ('income','expense','tax_payment')),
        category TEXT NOT NULL,
        description TEXT,
        amount INTEGER NOT NULL,
        vat_amount INTEGER NOT NULL DEFAULT 0,
        wht_amount INTEGER NOT NULL DEFAULT 0,
        in_tax_base INTEGER NOT NULL DEFAULT 1,
        leg TEXT,
        reference TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS acct_settings (k TEXT PRIMARY KEY, v TEXT NOT NULL)");
    $db->exec("CREATE TABLE IF NOT EXISTS acct_levies (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        basis TEXT NOT NULL CHECK(basis IN ('percent_of_sales','flat')),
        rate REAL NOT NULL,
        note TEXT,
        active INTEGER NOT NULL DEFAULT 1
    )");
}

function nairobiToday(): string { return gmdate('Y-m-d', time() + 3 * 3600); }

/** Starting values are PLACEHOLDERS to confirm with your accountant / KRA. */
function acctDefaults(): array {
    return [
        'regime'           => 'turnover',  // turnover | corporate | none
        'vat_registered'   => '0',
        'vat_rate'         => '16',
        'tot_rate'         => '3',
        'cit_rate'         => '30',
        'visits_in_sales'  => '0',         // 0 = visitation fees pass through to the sites (default); 1 = count as our sales
        'donations_in_base'=> '0',         // 1 = treat donations as taxable sales
        'county_name'      => 'Taita Taveta County',
    ];
}

function acctSettings(PDO $db): array {
    $s = acctDefaults();
    foreach ($db->query("SELECT k, v FROM acct_settings")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (array_key_exists($r['k'], $s)) $s[$r['k']] = $r['v'];
    }
    return $s;
}

function acctSaveSetting(PDO $db, string $k, string $v): void {
    $db->prepare("INSERT INTO acct_settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v")->execute([$k, $v]);
}

/** code => [label, kind] — kind: direct = cost of the events themselves, overhead = running the business */
function acctExpenseCategories(): array {
    return [
        'prize_money'   => ['Prize money paid out', 'direct'],
        'medals'        => ['Medals, trophies & rider packs', 'direct'],
        'marshals'      => ['Marshals & security', 'direct'],
        'medical'       => ['Medical & first aid', 'direct'],
        'logistics'     => ['Route, signage, timing & equipment hire', 'direct'],
        'catering'      => ['Food, water & hydration points', 'direct'],
        'event_permits' => ['Event permits & county levies', 'direct'],
        'insurance'     => ['Event insurance', 'direct'],
        'refunds'       => ['Refunds to riders / guests', 'direct'],
        'marketing'     => ['Marketing & media', 'overhead'],
        'staff'         => ['Staff & payroll (incl. PAYE, NSSF, SHIF, housing levy)', 'overhead'],
        'professional'  => ['Professional fees (accountant, legal)', 'overhead'],
        'software'      => ['Hosting, domain & software', 'overhead'],
        'bank_mpesa'    => ['Bank & M-Pesa charges', 'overhead'],
        'travel'        => ['Travel & transport', 'overhead'],
        'office'        => ['Office, licences & admin', 'overhead'],
        'other_cost'    => ['Other costs', 'overhead'],
    ];
}

/** code => [label, counts toward VAT/turnover by default] */
function acctIncomeCategories(): array {
    return [
        'sponsorship' => ['Sponsorship', 1],
        'advertising' => ['Advertising & media rights', 1],
        'merchandise' => ['Merchandise', 1],
        'stalls'      => ['Stall & vendor fees', 1],
        'grant'       => ['Grant', 0],
        'other_inc'   => ['Other income', 1],
    ];
}

function acctTaxKinds(): array {
    return ['VAT' => 'VAT', 'TOT' => 'Turnover tax', 'CIT' => 'Corporate income tax', 'WHT' => 'Withholding tax',
            'PAYE' => 'PAYE / payroll deductions', 'COUNTY' => 'County levies & permits', 'OTHER' => 'Other'];
}

/**
 * Build the whole report for [$from, $to] (YYYY-MM-DD, inclusive).
 */
function acctReport(PDO $db, string $from, string $to): array {
    ensureAccountingTables($db);
    ensureInstitutionTable($db);
    $S = acctSettings($db);
    $reg = $S['vat_registered'] === '1';
    $vr  = max(0.0, (float) $S['vat_rate']) / 100;

    $income = [];   // d, label, group, gross, in_base, leg, desc, ref, id
    $q = function (string $sql) use ($db, $from, $to) {
        $st = $db->prepare($sql); $st->execute([$from, $to]); return $st->fetchAll(PDO::FETCH_ASSOC);
    };

    foreach ($q("SELECT ticket, leg, amount, mpesa_receipt, name, date(updated_at,'+3 hours') d FROM registrations
                 WHERE status='paid' AND date(updated_at,'+3 hours') BETWEEN ? AND ?") as $r) {
        $income[] = ['d' => $r['d'], 'label' => 'Entry fees — ' . ucfirst($r['ticket']), 'group' => 'sales', 'gross' => (int) $r['amount'],
                     'in_base' => 1, 'leg' => $r['leg'], 'desc' => $r['name'], 'ref' => $r['mpesa_receipt'], 'id' => null];
    }
    foreach ($q("SELECT institution, leg, amount, payment_ref, date(paid_at,'+3 hours') d FROM institution_entries
                 WHERE status='paid' AND date(paid_at,'+3 hours') BETWEEN ? AND ?") as $r) {
        $income[] = ['d' => $r['d'], 'label' => 'College & University team entries', 'group' => 'sales', 'gross' => (int) $r['amount'],
                     'in_base' => 1, 'leg' => $r['leg'], 'desc' => $r['institution'], 'ref' => $r['payment_ref'], 'id' => null];
    }
    // Accommodation: only the platform's 20% commission is OUR income; the host's 80% passes through.
    foreach ($q("SELECT platform_fee, guest_name, mpesa_receipt, date(created_at,'+3 hours') d FROM bookings
                 WHERE status='paid' AND date(created_at,'+3 hours') BETWEEN ? AND ?") as $r) {
        $income[] = ['d' => $r['d'], 'label' => 'Accommodation commission', 'group' => 'sales', 'gross' => (int) $r['platform_fee'],
                     'in_base' => 1, 'leg' => '', 'desc' => $r['guest_name'], 'ref' => $r['mpesa_receipt'], 'id' => null];
    }
    if ($S['visits_in_sales'] === '1') {
        foreach ($q("SELECT site, leg, amount, mpesa_receipt, name, date(updated_at,'+3 hours') d FROM visits
                     WHERE status='paid' AND date(updated_at,'+3 hours') BETWEEN ? AND ?") as $r) {
            $income[] = ['d' => $r['d'], 'label' => 'Visitation fees', 'group' => 'sales', 'gross' => (int) $r['amount'],
                         'in_base' => 1, 'leg' => $r['leg'], 'desc' => $r['name'] . ' — ' . $r['site'], 'ref' => $r['mpesa_receipt'], 'id' => null];
        }
    }
    foreach ($q("SELECT scope, amount, mpesa_receipt, name, date(updated_at,'+3 hours') d FROM donations
                 WHERE status='paid' AND date(updated_at,'+3 hours') BETWEEN ? AND ?") as $r) {
        $income[] = ['d' => $r['d'], 'label' => 'Donations', 'group' => 'other', 'gross' => (int) $r['amount'],
                     'in_base' => $S['donations_in_base'] === '1' ? 1 : 0, 'leg' => $r['scope'], 'desc' => $r['name'], 'ref' => $r['mpesa_receipt'], 'id' => null];
    }

    $incCats = acctIncomeCategories(); $expCats = acctExpenseCategories();
    $expense = []; $taxPaid = [];
    foreach ($q("SELECT * FROM acct_entries WHERE entry_date BETWEEN ? AND ? ORDER BY entry_date, id") as $r) {
        if ($r['kind'] === 'income') {
            $income[] = ['d' => $r['entry_date'], 'label' => $incCats[$r['category']][0] ?? $r['category'], 'group' => $r['category'] === 'grant' || $r['category'] === 'other_inc' ? 'other' : 'sales',
                         'gross' => (int) $r['amount'], 'in_base' => (int) $r['in_tax_base'], 'leg' => (string) $r['leg'],
                         'desc' => $r['description'], 'ref' => $r['reference'], 'id' => (int) $r['id'], 'wht' => (int) $r['wht_amount']];
        } elseif ($r['kind'] === 'expense') {
            $expense[] = ['d' => $r['entry_date'], 'cat' => $r['category'], 'label' => $expCats[$r['category']][0] ?? $r['category'],
                          'type' => $expCats[$r['category']][1] ?? 'overhead', 'gross' => (int) $r['amount'], 'vat' => (int) $r['vat_amount'],
                          'wht' => (int) $r['wht_amount'], 'leg' => (string) $r['leg'], 'desc' => $r['description'], 'ref' => $r['reference'], 'id' => (int) $r['id']];
        } else {
            $taxPaid[] = ['d' => $r['entry_date'], 'kind' => $r['category'], 'amount' => (int) $r['amount'], 'desc' => $r['description'], 'ref' => $r['reference'], 'id' => (int) $r['id']];
        }
    }

    // ---- income: split VAT out when you are VAT-registered (site prices are treated as VAT-inclusive)
    $outVat = 0; $revenue = 0; $baseNet = 0; $whtCredit = 0;
    foreach ($income as &$i) {
        $i['vat'] = ($reg && $i['in_base'] && $vr > 0) ? (int) round($i['gross'] * $vr / (1 + $vr)) : 0;
        $i['net'] = $i['gross'] - $i['vat'];
        $outVat += $i['vat']; $revenue += $i['net'];
        if ($i['in_base']) $baseNet += $i['net'];
        $whtCredit += $i['wht'] ?? 0;
    }
    unset($i);

    // ---- expenses: input VAT is only claimable (and so excluded from cost) when VAT-registered
    $direct = 0; $overhead = 0; $inVat = 0; $whtPayable = 0;
    foreach ($expense as &$e) {
        $e['cost'] = $e['gross'] - ($reg ? $e['vat'] : 0);
        if ($reg) $inVat += $e['vat'];
        if ($e['type'] === 'direct') $direct += $e['cost']; else $overhead += $e['cost'];
        $whtPayable += $e['wht'];
    }
    unset($e);

    $gross = $revenue - $direct;
    $preTax = $gross - $overhead;
    $tot = $S['regime'] === 'turnover' ? (int) round($baseNet * max(0.0, (float) $S['tot_rate']) / 100) : 0;
    $cit = $S['regime'] === 'corporate' ? (int) round(max(0, $preTax) * max(0.0, (float) $S['cit_rate']) / 100) : 0;
    $vatNet = $reg ? $outVat - $inVat : 0;

    $levies = [];
    foreach ($db->query("SELECT * FROM acct_levies WHERE active = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $levies[] = ['name' => $l['name'], 'basis' => $l['basis'], 'rate' => (float) $l['rate'], 'note' => $l['note'],
                     'due' => $l['basis'] === 'percent_of_sales' ? (int) round($revenue * $l['rate'] / 100) : (int) round($l['rate'])];
    }

    $paidBy = [];
    foreach ($taxPaid as $t) $paidBy[$t['kind']] = ($paidBy[$t['kind']] ?? 0) + $t['amount'];

    // ---- by leg
    $legs = [];
    foreach ($income as $i)  { $k = trim((string) $i['leg']) ?: 'Not assigned to a leg'; $legs[$k]['rev'] = ($legs[$k]['rev'] ?? 0) + $i['net']; }
    foreach ($expense as $e) { if ($e['type'] === 'direct') { $k = trim($e['leg']) ?: 'Not assigned to a leg'; $legs[$k]['cost'] = ($legs[$k]['cost'] ?? 0) + $e['cost']; } }
    ksort($legs);

    // ---- by month
    $months = [];
    foreach ($income as $i) {
        $m = substr($i['d'], 0, 7);
        $months[$m]['rev'] = ($months[$m]['rev'] ?? 0) + $i['net'];
        if ($i['in_base']) $months[$m]['base'] = ($months[$m]['base'] ?? 0) + $i['net'];
        $months[$m]['outvat'] = ($months[$m]['outvat'] ?? 0) + $i['vat'];
    }
    foreach ($expense as $e) {
        $m = substr($e['d'], 0, 7);
        if ($e['type'] === 'direct') $months[$m]['direct'] = ($months[$m]['direct'] ?? 0) + $e['cost'];
        else $months[$m]['over'] = ($months[$m]['over'] ?? 0) + $e['cost'];
        if ($reg) $months[$m]['invat'] = ($months[$m]['invat'] ?? 0) + $e['vat'];
        $months[$m]['wht'] = ($months[$m]['wht'] ?? 0) + $e['wht'];
    }
    ksort($months);
    foreach ($months as $m => &$row) {
        $row += ['rev' => 0, 'base' => 0, 'outvat' => 0, 'direct' => 0, 'over' => 0, 'invat' => 0, 'wht' => 0];
        $row['profit'] = $row['rev'] - $row['direct'] - $row['over'];
        $row['vat'] = $reg ? $row['outvat'] - $row['invat'] : 0;
        $row['tot'] = $S['regime'] === 'turnover' ? (int) round($row['base'] * max(0.0, (float) $S['tot_rate']) / 100) : 0;
        $row['due'] = gmdate('Y-m-20', strtotime($m . '-01 +1 month'));
    }
    unset($row);

    // ---- by stream / category
    $streams = [];
    foreach ($income as $i) { $streams[$i['label']] = ($streams[$i['label']] ?? ['n' => 0, 'net' => 0, 'group' => $i['group']]); $streams[$i['label']]['n']++; $streams[$i['label']]['net'] += $i['net']; }
    $costs = [];
    foreach ($expense as $e) { $costs[$e['label']] = ($costs[$e['label']] ?? ['cost' => 0, 'type' => $e['type']]); $costs[$e['label']]['cost'] += $e['cost']; }
    ksort($streams); uasort($costs, function ($a, $b) { return $b['cost'] <=> $a['cost']; });

    // liability: host share of accommodation not yet paid out (all time)
    $hostOwed = (int) $db->query("SELECT COALESCE(SUM(host_payout_amount),0) FROM bookings WHERE status='paid' AND payout_status='not_paid'")->fetchColumn();

    return compact('S', 'income', 'expense', 'taxPaid', 'revenue', 'baseNet', 'direct', 'overhead', 'gross', 'preTax',
        'outVat', 'inVat', 'vatNet', 'tot', 'cit', 'whtPayable', 'whtCredit', 'levies', 'paidBy', 'legs', 'months', 'streams', 'costs', 'hostOwed', 'reg')
        + ['from' => $from, 'to' => $to];
}

/** Spreadsheet-safe cell: stops =,+,-,@ being run as a formula when opened in Excel. */
function acctCsvCell($v): string {
    $s = (string) $v;
    if ($s !== '' && strpos("=+-@\t\r", $s[0]) !== false) $s = "'" . $s;
    return $s;
}
