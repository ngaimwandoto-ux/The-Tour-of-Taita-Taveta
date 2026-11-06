<?php
/**
 * admin/accounts-settings.php
 * Key-protected. Your tax set-up, tax rates and county levies. Every number
 * here is a placeholder until YOU (with your accountant) confirm it — the
 * accounts page just does the arithmetic with whatever you enter.
 *
 * Usage: https://yourdomain/admin/accounts-settings.php?key=YOUR_ADMIN_KEY
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/accounting.php';

if (!defined('ADMIN_KEY') || ADMIN_KEY === '' || ($_GET['key'] ?? '') !== ADMIN_KEY) {
    http_response_code(403);
    die('Not authorised. Add ?key=YOUR_ADMIN_KEY to the URL.');
}
$db = Database::getInstance()->getConnection();
ensureAccountingTables($db);
$key = urlencode(ADMIN_KEY);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rate = function ($f, $max = 100) { $v = (float) str_replace(',', '.', $_POST[$f] ?? '0'); return (string) max(0, min($max, round($v, 3))); };
    if (isset($_POST['save_settings'])) {
        acctSaveSetting($db, 'regime', in_array($_POST['regime'] ?? '', ['turnover', 'corporate', 'none'], true) ? $_POST['regime'] : 'none');
        acctSaveSetting($db, 'vat_registered', isset($_POST['vat_registered']) ? '1' : '0');
        acctSaveSetting($db, 'vat_rate', $rate('vat_rate'));
        acctSaveSetting($db, 'tot_rate', $rate('tot_rate'));
        acctSaveSetting($db, 'cit_rate', $rate('cit_rate'));
        acctSaveSetting($db, 'visits_in_sales', isset($_POST['visits_in_sales']) ? '1' : '0');
        acctSaveSetting($db, 'donations_in_base', isset($_POST['donations_in_base']) ? '1' : '0');
        acctSaveSetting($db, 'county_name', mb_substr(trim($_POST['county_name'] ?? '') ?: 'Taita Taveta County', 0, 60));
    } elseif (isset($_POST['add_levy'])) {
        $name = mb_substr(trim($_POST['name'] ?? ''), 0, 80);
        $basis = ($_POST['basis'] ?? '') === 'flat' ? 'flat' : 'percent_of_sales';
        $val = max(0, (float) preg_replace('/[^0-9.]/', '', $_POST['rate'] ?? '0'));
        if ($name !== '' && $val > 0) {
            $db->prepare("INSERT INTO acct_levies (name, basis, rate, note) VALUES (?,?,?,?)")
               ->execute([$name, $basis, $basis === 'percent_of_sales' ? min(100, $val) : $val, mb_substr(trim($_POST['note'] ?? ''), 0, 120)]);
        }
    } elseif (isset($_POST['delete_levy'])) {
        $db->prepare("DELETE FROM acct_levies WHERE id = ?")->execute([(int) ($_POST['id'] ?? 0)]);
    }
    header('Location: accounts-settings.php?key=' . $key . '&saved=1');
    exit;
}

$S = acctSettings($db);
$levies = $db->query("SELECT * FROM acct_levies ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Accounts settings</title>
<style>
  body { font-family: sans-serif; max-width: 720px; margin: 1.2rem auto; padding: 0 1rem 3rem; background: #FAFAF7; color: #171712; }
  h1 { font-size: 1.4rem; } h2 { font-size: 1.05rem; margin-top: 2rem; border-bottom: 2px solid #171712; padding-bottom: .3rem; }
  .box { background: #fff; border: 1px solid #ddd; padding: .8rem 1rem; margin-bottom: .8rem; }
  .warn { background: #FBE9CF; padding: .6rem .8rem; font-size: .82rem; } .ok { background: #E4F4E6; padding: .6rem .8rem; font-size: .85rem; }
  label { display: block; font-size: .78rem; color: #555; margin-top: .7rem; } .chk { color: #171712; font-size: .88rem; }
  input[type=text], select { padding: .45rem; border: 1px solid #bbb; font-size: .88rem; width: 100%; max-width: 360px; box-sizing: border-box; }
  .hint { font-size: .76rem; color: #777; margin: .15rem 0 0; }
  button { padding: .5rem .9rem; border: none; cursor: pointer; font-size: .82rem; background: #3FA34D; color: #fff; margin-top: .9rem; } button.x { background: none; color: #B3261E; margin: 0; font-size: 1rem; padding: 0 .3rem; }
  .scroll { overflow-x: auto; } table { border-collapse: collapse; width: 100%; background: #fff; font-size: .84rem; } th, td { padding: .45rem .6rem; border: 1px solid #e2e2dc; text-align: left; }
</style>
</head>
<body>
<h1>Accounts settings</h1>
<p style="font-size:.85rem;"><a href="accounts.php?key=<?= $key ?>">← Back to accounts</a></p>
<?php if (isset($_GET['saved'])): ?><p class="ok">Saved.</p><?php endif; ?>
<div class="warn"><strong>Placeholders — confirm before relying on them.</strong> The starting rates are common Kenyan figures at the time this was built (VAT 16%, turnover tax 3%, corporate tax 30%), but they change with Finance Acts and sources disagree on some (turnover tax was 1% before 2024). Ask your accountant which regime your business is on, and check kra.go.ke.</div>

<h2>Tax set-up</h2>
<form method="post" class="box">
  <label>Income tax regime</label>
  <select name="regime">
    <option value="turnover" <?= $S['regime'] === 'turnover' ? 'selected' : '' ?>>Turnover tax (small business, on gross sales)</option>
    <option value="corporate" <?= $S['regime'] === 'corporate' ? 'selected' : '' ?>>Corporate income tax (on profit)</option>
    <option value="none" <?= $S['regime'] === 'none' ? 'selected' : '' ?>>No income-tax estimate (e.g. registered non-profit)</option>
  </select>
  <p class="hint">Turnover tax ignores your costs; corporate tax is charged on profit. They are alternatives, not both.</p>

  <label>Turnover tax rate (%)</label><input type="text" name="tot_rate" value="<?= $h($S['tot_rate']) ?>" inputmode="decimal">
  <label>Corporate income tax rate (%)</label><input type="text" name="cit_rate" value="<?= $h($S['cit_rate']) ?>" inputmode="decimal">

  <label class="chk"><input type="checkbox" name="vat_registered" <?= $S['vat_registered'] === '1' ? 'checked' : '' ?>> We are registered for VAT</label>
  <p class="hint">If ticked, entry fees and other taxable income are treated as VAT-inclusive and VAT is split out; VAT on your costs is claimed back.</p>
  <label>VAT rate (%)</label><input type="text" name="vat_rate" value="<?= $h($S['vat_rate']) ?>" inputmode="decimal">

  <label class="chk"><input type="checkbox" name="visits_in_sales" <?= $S['visits_in_sales'] === '1' ? 'checked' : '' ?>> Count visitation fees as our sales</label>
  <p class="hint">Left unticked by default: the fees are collected for the conservation and heritage sites and passed on to them, so they are not your income. Tick only if you keep them.</p>
  <label class="chk"><input type="checkbox" name="donations_in_base" <?= $S['donations_in_base'] === '1' ? 'checked' : '' ?>> Treat donations as taxable sales</label>
  <p class="hint">Normally left unticked — donations are shown as other income but kept out of VAT and turnover tax. Confirm with your accountant.</p>

  <label>County name (shown beside levies)</label><input type="text" name="county_name" value="<?= $h($S['county_name']) ?>" maxlength="60">
  <button type="submit" name="save_settings" value="1">Save settings</button>
</form>

<h2>County levies &amp; permits</h2>
<p style="font-size:.84rem;color:#555;">Add each levy or permit that applies, using the figures in your county's Finance Act or on your permit. The accounts page then shows what to set aside. Enter what you actually pay as a “county levies &amp; permits” cost in the ledger.</p>
<div class="scroll"><table>
  <tr><th>Levy</th><th>How it's worked out</th><th>Note</th><th></th></tr>
  <?php foreach ($levies as $l): ?>
    <tr><td><?= $h($l['name']) ?></td><td><?= $l['basis'] === 'percent_of_sales' ? $h($l['rate']) . '% of revenue' : 'KES ' . number_format((float) $l['rate']) . ' fixed' ?></td><td><?= $h($l['note']) ?></td>
      <td><form method="post" onsubmit="return confirm('Remove this levy?');"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>"><button class="x" type="submit" name="delete_levy" value="1">✕</button></form></td></tr>
  <?php endforeach; ?>
  <?php if (!$levies): ?><tr><td colspan="4" style="color:#888;">None yet.</td></tr><?php endif; ?>
</table></div>
<form method="post" class="box" style="margin-top:.8rem;">
  <label>Levy name</label><input type="text" name="name" maxlength="80" placeholder="e.g. Single business permit">
  <label>Worked out as</label>
  <select name="basis"><option value="flat">A fixed amount (KES)</option><option value="percent_of_sales">A percentage of revenue</option></select>
  <label>Amount (KES) or percentage</label><input type="text" name="rate" inputmode="decimal">
  <label>Note (optional)</label><input type="text" name="note" maxlength="120" placeholder="e.g. renews each January">
  <button type="submit" name="add_levy" value="1">Add levy</button>
</form>
</body>
</html>
