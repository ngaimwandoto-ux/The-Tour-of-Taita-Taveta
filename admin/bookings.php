<?php
/**
 * admin/bookings.php
 * Key-protected (same ADMIN_KEY as admin/approve.php) view of paid
 * bookings, so you can track which hosts still need their 80% share
 * sent manually. Approving a booking here doesn't send any money —
 * it's just a checklist so nothing gets forgotten.
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
    $bookingId = (int) ($_POST['booking_id'] ?? 0);
    if ($bookingId) $accommodation->markPayoutSent($bookingId);
    header('Location: bookings.php?key=' . urlencode(ADMIN_KEY));
    exit;
}

$queue = $accommodation->getPayoutQueue();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Host Payouts — Tour of Taita Taveta</title>
<style>
  body { font-family: sans-serif; max-width: 900px; margin: 2rem auto; padding: 0 1rem; background: #FAFAF7; color: #171712; }
  h1 { font-size: 1.4rem; }
  table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
  th, td { text-align: left; padding: 0.6rem; border-bottom: 1px solid #ddd; }
  .status { font-size: 0.72rem; font-family: monospace; padding: 0.15rem 0.5rem; border-radius: 2px; }
  .status.paid_out { background: #E4F0E5; color: #1F5C24; }
  .status.not_paid { background: #FBE1E4; color: #8A0E1F; }
  button { padding: 0.35rem 0.7rem; border: none; cursor: pointer; font-size: 0.78rem; background: #3FA34D; color: #fff; }
  .empty { color: #888; font-size: 0.85rem; }
</style>
</head>
<body>
<h1>Host Payouts</h1>
<p style="font-size:0.85rem;color:#666;">Paid bookings — send each host their share via M-Pesa, then mark it here so nothing gets missed. This page doesn't move any money itself.</p>

<?php if (empty($queue)): ?>
  <p class="empty">No paid bookings yet.</p>
<?php else: ?>
<table>
  <thead>
    <tr><th>Property</th><th>Host</th><th>Guest</th><th>Dates</th><th>Total</th><th>Host owed (80%)</th><th>Payout #</th><th>Status</th><th></th></tr>
  </thead>
  <tbody>
    <?php foreach ($queue as $b): ?>
    <tr>
      <td><?= htmlspecialchars($b['property_name']) ?></td>
      <td><?= htmlspecialchars($b['host_name']) ?></td>
      <td><?= htmlspecialchars($b['guest_name']) ?></td>
      <td><?= htmlspecialchars($b['check_in']) ?> → <?= htmlspecialchars($b['check_out']) ?></td>
      <td>KES <?= number_format($b['total_amount']) ?></td>
      <td>KES <?= number_format($b['host_payout_amount']) ?></td>
      <td><?= htmlspecialchars($b['mpesa_payout_number'] ?? 'Not on file') ?></td>
      <td><span class="status <?= $b['payout_status'] ?>"><?= $b['payout_status'] === 'paid_out' ? 'PAID OUT' : 'OWED' ?></span></td>
      <td>
        <?php if ($b['payout_status'] !== 'paid_out'): ?>
        <form method="post"><input type="hidden" name="booking_id" value="<?= $b['id'] ?>"><button type="submit">Mark Paid Out</button></form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

</body>
</html>
