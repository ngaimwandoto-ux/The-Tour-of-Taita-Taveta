<?php
/**
 * admin/bookings.php
 * Key-protected. Two checklists: refunds owed to guests, and payouts
 * owed to hosts. Nothing on this page sends money — every button here
 * only fires AFTER you've already sent it yourself via M-Pesa, and
 * exists purely so nothing gets forgotten or double-paid.
 *
 * Usage: https://yourdomain/admin/bookings.php?key=YOUR_ADMIN_KEY
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/accommodation.php';

if (!defined('ADMIN_KEY') || ADMIN_KEY === '' || ($_GET['key'] ?? '') !== ADMIN_KEY) {
    http_response_code(403);
    die('Not authorised. Add ?key=YOUR_ADMIN_KEY to the URL.');
}

$accommodation = new Accommodation();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mark_refund_sent'])) {
        $accommodation->markRefundSent((int) $_POST['booking_id']);
    } elseif (isset($_POST['mark_payout_sent'])) {
        $accommodation->markPayoutSent((int) $_POST['booking_id']);
    } elseif (isset($_POST['mark_host_payouts_sent'])) {
        $accommodation->markAllPayoutsSentForHost((int) $_POST['host_user_id']);
    }
    header('Location: bookings.php?key=' . urlencode(ADMIN_KEY));
    exit;
}

$refundsOwed = $accommodation->getRefundsOwed();
$payoutQueue = $accommodation->getPayoutQueue();
$payoutSummary = $accommodation->getPayoutSummaryByHost();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Bookings — Refunds & Payouts</title>
<style>
  body { font-family: sans-serif; max-width: 900px; margin: 2rem auto; padding: 0 1rem; background: #FAFAF7; color: #171712; }
  h1 { font-size: 1.4rem; } h2 { font-size: 1.1rem; margin-top: 2rem; border-bottom: 2px solid #171712; padding-bottom: 0.3rem; }
  table { width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-top: 0.6rem; }
  th, td { text-align: left; padding: 0.5rem; border-bottom: 1px solid #ddd; }
  .status { font-size: 0.72rem; font-family: monospace; padding: 0.15rem 0.5rem; border-radius: 2px; }
  .status.owed, .status.not_paid { background: #FBE1E4; color: #8A0E1F; }
  .status.sent, .status.paid_out { background: #E4F0E5; color: #1F5C24; }
  button { padding: 0.35rem 0.7rem; border: none; cursor: pointer; font-size: 0.78rem; background: #3FA34D; color: #fff; }
  .empty { color: #888; font-size: 0.85rem; }
  .warn { color: #C46B08; }
  .nav-link { font-size: 0.8rem; }
  .host-summary-card { background: #fff; border: 1px solid #ddd; padding: 0.8rem 1rem; margin-bottom: 0.6rem; }
</style>
</head>
<body>
<h1>Bookings — Refunds &amp; Payouts</h1>
<p style="font-size:0.85rem;color:#666;">
  Nothing here sends money automatically. Every button confirms an M-Pesa
  transfer <strong>you have already made</strong>, and exists only to
  track it. See also: <a class="nav-link" href="approve.php?key=<?= urlencode(ADMIN_KEY) ?>">Listing Approvals →</a>
</p>

<h2>Refunds owed to guests</h2>
<?php if (empty($refundsOwed)): ?>
  <p class="empty">None outstanding.</p>
<?php else: ?>
<table>
  <thead><tr><th>Property</th><th>Guest</th><th>Phone</th><th>Dates</th><th>Refund owed</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($refundsOwed as $r): ?>
    <tr>
      <td><?= htmlspecialchars($r['property_name']) ?></td>
      <td><?= htmlspecialchars($r['guest_name']) ?></td>
      <td><?= htmlspecialchars($r['guest_phone']) ?></td>
      <td><?= htmlspecialchars($r['check_in']) ?> → <?= htmlspecialchars($r['check_out']) ?></td>
      <td>KES <?= number_format($r['refund_amount']) ?></td>
      <td>
        <form method="post" onsubmit="return confirm('Confirm you have ALREADY sent KES <?= number_format($r['refund_amount']) ?> to <?= htmlspecialchars($r['guest_phone']) ?> via M-Pesa?');">
          <input type="hidden" name="booking_id" value="<?= $r['id'] ?>">
          <button type="submit" name="mark_refund_sent" value="1">I've Sent This Refund</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<h2>Payouts owed to hosts</h2>
<?php if (empty($payoutSummary)): ?>
  <p class="empty">None outstanding.</p>
<?php else: ?>
  <p style="font-size:0.85rem;">Pay a host their full total in one M-Pesa transfer, then mark all their outstanding bookings paid at once.</p>
  <?php foreach ($payoutSummary as $h): ?>
    <div class="host-summary-card">
      <strong><?= htmlspecialchars($h['host_name']) ?></strong> —
      KES <?= number_format($h['total_owed']) ?> across <?= $h['booking_count'] ?> booking<?= $h['booking_count'] == 1 ? '' : 's' ?>
      <?php if ($h['mpesa_payout_number']): ?>
        · <span style="font-family:monospace;"><?= htmlspecialchars($h['mpesa_payout_number']) ?></span>
      <?php else: ?>
        · <span class="warn">⚠ No payout number on file — contact the host directly</span>
      <?php endif; ?>
      <form method="post" style="margin-top:0.5rem;" onsubmit="return confirm('Confirm you have ALREADY sent KES <?= number_format($h['total_owed']) ?> to <?= htmlspecialchars($h['host_name']) ?> via M-Pesa?');">
        <input type="hidden" name="host_user_id" value="<?= $h['host_user_id'] ?>">
        <button type="submit" name="mark_host_payouts_sent" value="1">I've Sent This Host's Total</button>
      </form>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<h2>All paid bookings (detail)</h2>
<?php if (empty($payoutQueue)): ?>
  <p class="empty">No paid bookings yet.</p>
<?php else: ?>
<table>
  <thead><tr><th>Property</th><th>Host</th><th>Guest</th><th>Dates</th><th>Host owed</th><th>Status</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($payoutQueue as $b): ?>
    <tr>
      <td><?= htmlspecialchars($b['property_name']) ?></td>
      <td><?= htmlspecialchars($b['host_name']) ?></td>
      <td><?= htmlspecialchars($b['guest_name']) ?></td>
      <td><?= htmlspecialchars($b['check_in']) ?> → <?= htmlspecialchars($b['check_out']) ?></td>
      <td>KES <?= number_format($b['host_payout_amount']) ?></td>
      <td><span class="status <?= $b['payout_status'] ?>"><?= $b['payout_status'] === 'paid_out' ? 'PAID OUT' : 'OWED' ?></span></td>
      <td>
        <?php if ($b['payout_status'] !== 'paid_out'): ?>
        <form method="post" onsubmit="return confirm('Confirm you have ALREADY sent this specific KES <?= number_format($b['host_payout_amount']) ?> payout?');">
          <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
          <button type="submit" name="mark_payout_sent" value="1">Mark This One Sent</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

</body>
</html>
