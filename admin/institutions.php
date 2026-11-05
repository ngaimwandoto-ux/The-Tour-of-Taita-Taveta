<?php
/**
 * admin/institutions.php
 * Key-protected. College & University team entries, paid by invoice /
 * bank transfer. Nothing here moves money — it only records what YOU have
 * already received. An entry counts toward the prize pool only after you
 * mark it paid here.
 *
 * Usage: https://yourdomain/admin/institutions.php?key=YOUR_ADMIN_KEY
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/institutions.php';
require_once __DIR__ . '/../includes/leg-schedule.php';
require_once __DIR__ . '/../includes/mailer.php';

if (!defined('ADMIN_KEY') || ADMIN_KEY === '' || ($_GET['key'] ?? '') !== ADMIN_KEY) {
    http_response_code(403);
    die('Not authorised. Add ?key=YOUR_ADMIN_KEY to the URL.');
}

$db = Database::getInstance()->getConnection();
ensureInstitutionTable($db);
$legNames = array_map(function ($l) { return $l['leg']; }, legSchedulePublic());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    if (isset($_POST['mark_paid'])) {
        $amount = (int) preg_replace('/[^0-9]/', '', $_POST['amount'] ?? '');
        $ref = mb_substr(trim($_POST['payment_ref'] ?? ''), 0, 80);
        if ($id > 0 && $amount > 0) {
            $upd = $db->prepare("UPDATE institution_entries SET status='paid', amount=?, payment_ref=?, paid_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'");
            $upd->execute([$amount, $ref, $id]);
            if ($upd->rowCount() > 0) {
                $q = $db->prepare("SELECT * FROM institution_entries WHERE id=?");
                $q->execute([$id]);
                $row = $q->fetch(PDO::FETCH_ASSOC);
                $mailNote = ($row && mailInstitutionPaid($row)) ? 'sent' : (mailEnabled() ? 'failed' : 'off');
            }
        }
    } elseif (isset($_POST['cancel'])) {
        $db->prepare("UPDATE institution_entries SET status='cancelled' WHERE id=? AND status='pending'")->execute([$id]);
    } elseif (isset($_POST['set_leg']) && in_array($_POST['leg'] ?? '', $legNames, true)) {
        $db->prepare("UPDATE institution_entries SET leg=?, leg_assumed=0 WHERE id=? AND status='pending'")->execute([$_POST['leg'], $id]);
    } elseif (isset($_POST['save_notes'])) {
        $db->prepare("UPDATE institution_entries SET notes=? WHERE id=?")->execute([mb_substr(trim($_POST['notes'] ?? ''), 0, 500), $id]);
    }
    header('Location: institutions.php?key=' . urlencode(ADMIN_KEY) . (isset($mailNote) ? '&mail=' . $mailNote : ''));
    exit;
}

$pending = $db->query("SELECT * FROM institution_entries WHERE status='pending' ORDER BY created_at")->fetchAll(PDO::FETCH_ASSOC);
$paid    = $db->query("SELECT * FROM institution_entries WHERE status='paid' ORDER BY paid_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$cancelled = $db->query("SELECT * FROM institution_entries WHERE status='cancelled' ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>College &amp; University team entries</title>
<style>
  body { font-family: sans-serif; max-width: 900px; margin: 2rem auto; padding: 0 1rem; background: #FAFAF7; color: #171712; }
  h1 { font-size: 1.4rem; } h2 { font-size: 1.1rem; margin-top: 2rem; border-bottom: 2px solid #171712; padding-bottom: 0.3rem; }
  .card { background: #fff; border: 1px solid #ddd; padding: 0.8rem 1rem; margin-bottom: 0.7rem; font-size: 0.88rem; }
  .meta { color: #555; font-size: 0.8rem; margin: 0.2rem 0 0.6rem; }
  .tag { font-size: 0.7rem; font-family: monospace; padding: 0.1rem 0.45rem; background: #FBE9CF; color: #8a4b00; margin-left: 0.4rem; }
  form.inline { display: inline-flex; gap: 0.4rem; flex-wrap: wrap; align-items: center; margin-top: 0.4rem; }
  input[type=text], input[type=number], select { padding: 0.35rem; border: 1px solid #bbb; font-size: 0.82rem; }
  button { padding: 0.35rem 0.7rem; border: none; cursor: pointer; font-size: 0.78rem; background: #3FA34D; color: #fff; }
  button.grey { background: #888; }
  .empty { color: #888; font-size: 0.85rem; }
  .total { font-size: 0.9rem; margin: 0.4rem 0; }
</style>
</head>
<body>
<h1>College &amp; University team entries</h1>
<p style="font-size:0.85rem;color:#666;">
  Nothing here moves money. Send the institution an invoice with your bank details; when the transfer arrives,
  enter the amount and bank reference and mark it paid. Only paid entries count toward the prize pool.
  See also: <a href="bookings.php?key=<?= urlencode(ADMIN_KEY) ?>">Bookings</a> · <a href="mail-test.php?key=<?= urlencode(ADMIN_KEY) ?>">Email settings</a> · <a href="approve.php?key=<?= urlencode(ADMIN_KEY) ?>">Approvals</a>
</p>

<?php $mn = $_GET['mail'] ?? ''; if ($mn === 'sent'): ?><p style="background:#E4F4E6;padding:.6rem .8rem;font-size:.85rem;">Marked paid. The confirmation email was sent to the institution.</p>
<?php elseif ($mn === 'failed'): ?><p style="background:#FBE0DC;padding:.6rem .8rem;font-size:.85rem;">Marked paid, but the confirmation email FAILED to send. Check <a href="mail-test.php?key=<?= urlencode(ADMIN_KEY) ?>">email settings</a> and tell the institution yourself.</p>
<?php elseif ($mn === 'off'): ?><p style="background:#FBE9CF;padding:.6rem .8rem;font-size:.85rem;">Marked paid. Email is switched off, so no confirmation was sent — tell the institution yourself.</p><?php endif; ?>

<h2>Awaiting payment (<?= count($pending) ?>)</h2>
<?php if (!$pending): ?><p class="empty">None.</p><?php endif; ?>
<?php foreach ($pending as $e): ?>
  <div class="card">
    <strong><?= $h($e['institution']) ?></strong> — team “<?= $h($e['team_name']) ?>” · <?= (int) $e['riders'] ?> rider<?= $e['riders'] == 1 ? '' : 's' ?>
    <div class="meta">
      <?= $h($e['contact_name']) ?> · <a href="mailto:<?= $h($e['email']) ?>"><?= $h($e['email']) ?></a> · <?= $h($e['phone']) ?> ·
      <?= $h($e['leg']) ?><?php if ($e['leg_assumed']): ?><span class="tag">next leg assumed — confirm with them</span><?php endif; ?>
      · <?= $h($e['created_at']) ?> UTC · ref INST-<?= str_pad((string) $e['id'], 4, '0', STR_PAD_LEFT) ?>
    </div>
    <form class="inline" method="post" onsubmit="return confirm('Confirm the money for <?= $h(addslashes($e['institution'])) ?> has ALREADY arrived in the bank account?');">
      <input type="hidden" name="id" value="<?= $e['id'] ?>">
      <input type="number" name="amount" min="1" placeholder="KES received" required>
      <input type="text" name="payment_ref" placeholder="Bank reference">
      <button type="submit" name="mark_paid" value="1">Payment received</button>
    </form>
    <form class="inline" method="post">
      <input type="hidden" name="id" value="<?= $e['id'] ?>">
      <select name="leg"><?php foreach ($legNames as $n): ?><option <?= $n === $e['leg'] ? 'selected' : '' ?>><?= $h($n) ?></option><?php endforeach; ?></select>
      <button type="submit" name="set_leg" value="1" class="grey">Set leg</button>
      <button type="submit" name="cancel" value="1" class="grey" onclick="return confirm('Cancel this entry?');">Cancel entry</button>
    </form>
  </div>
<?php endforeach; ?>

<h2>Paid (<?= count($paid) ?>)</h2>
<?php if (!$paid): ?><p class="empty">None yet.</p><?php else: ?>
  <p class="total">Total received: <strong>KES <?= number_format(array_sum(array_column($paid, 'amount'))) ?></strong></p>
<?php endif; ?>
<?php foreach ($paid as $e): ?>
  <div class="card">
    <strong><?= $h($e['institution']) ?></strong> — “<?= $h($e['team_name']) ?>” · <?= $h($e['leg']) ?> · KES <?= number_format((int) $e['amount']) ?>
    <div class="meta">Ref: <?= $h($e['payment_ref']) ?: '—' ?> · paid <?= $h($e['paid_at']) ?> UTC · <?= (int) $e['riders'] ?> riders · <?= $h($e['contact_name']) ?>, <?= $h($e['email']) ?></div>
    <form class="inline" method="post">
      <input type="hidden" name="id" value="<?= $e['id'] ?>">
      <input type="text" name="notes" value="<?= $h($e['notes']) ?>" placeholder="Note (e.g. USD transfer, rate used)" size="40">
      <button type="submit" name="save_notes" value="1" class="grey">Save note</button>
    </form>
  </div>
<?php endforeach; ?>

<?php if ($cancelled): ?>
<h2>Cancelled (<?= count($cancelled) ?>)</h2>
<?php foreach ($cancelled as $e): ?>
  <div class="card" style="opacity:.65"><?= $h($e['institution']) ?> — <?= $h($e['team_name']) ?> · <?= $h($e['leg']) ?> · <?= $h($e['created_at']) ?></div>
<?php endforeach; endif; ?>
</body>
</html>
