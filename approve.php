<?php
/**
 * admin/approve.php
 *
 * A minimal, single-file approval screen for pending team/sponsor/
 * supporter/partner listings — NOT a full admin panel, just enough to
 * make "manual approval" actually usable without a database browser.
 *
 * Protected by ADMIN_KEY (set in config.php) via a URL parameter, not
 * a real login. Treat that URL like a password — don't share it, and
 * change ADMIN_KEY if you ever suspect it's leaked.
 *
 * Usage: https://yourdomain/admin/approve.php?key=YOUR_ADMIN_KEY
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/database.php';

if (!defined('ADMIN_KEY') || ADMIN_KEY === '' || ($_GET['key'] ?? '') !== ADMIN_KEY) {
    http_response_code(403);
    die('Not authorised. Add ?key=YOUR_ADMIN_KEY to the URL — see ADMIN_KEY in config.php.');
}

$db = Database::getInstance()->getConnection();

// Handle an approve/reject action.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $value = ($action === 'approve') ? 1 : 0;

    if ($type === 'team') {
        $stmt = $db->prepare("UPDATE teams SET is_public = ? WHERE id = ?");
        $stmt->execute([$value, $id]);
    } elseif (in_array($type, ['sponsor', 'supporter', 'partner'], true)) {
        $stmt = $db->prepare("UPDATE users SET is_public = ? WHERE id = ? AND account_type = ?");
        $stmt->execute([$value, $id, $type]);
    }

    header('Location: approve.php?key=' . urlencode(ADMIN_KEY));
    exit;
}

function fetchPending(PDO $db, string $type): array {
    if ($type === 'team') {
        return $db->query("SELECT id, name AS display_name, logo_path, is_public FROM teams ORDER BY created_at DESC")->fetchAll();
    }
    $stmt = $db->prepare("SELECT id, display_name, logo_path, is_public FROM users WHERE account_type = ? ORDER BY created_at DESC");
    $stmt->execute([$type]);
    return $stmt->fetchAll();
}

$sections = [
    'team' => fetchPending($db, 'team'),
    'sponsor' => fetchPending($db, 'sponsor'),
    'supporter' => fetchPending($db, 'supporter'),
    'partner' => fetchPending($db, 'partner'),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Listing Approvals — Tour of Taita Taveta</title>
<style>
  body { font-family: sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; background: #FAFAF7; color: #171712; }
  h1 { font-size: 1.4rem; } h2 { font-size: 1.1rem; margin-top: 2rem; border-bottom: 2px solid #171712; padding-bottom: 0.3rem; }
  .row { display: flex; align-items: center; gap: 1rem; padding: 0.7rem 0; border-bottom: 1px solid #ddd; }
  .row img { max-height: 40px; max-width: 80px; object-fit: contain; }
  .row .name { flex: 1; }
  .status { font-size: 0.75rem; font-family: monospace; padding: 0.15rem 0.5rem; border-radius: 2px; }
  .status.live { background: #E4F0E5; color: #1F5C24; }
  .status.pending { background: #EFEAD8; color: #171712; }
  button { padding: 0.4rem 0.8rem; border: none; cursor: pointer; font-size: 0.8rem; }
  button.approve { background: #3FA34D; color: #fff; }
  button.reject { background: #C46B08; color: #fff; }
  .empty { color: #888; font-size: 0.85rem; }
</style>
</head>
<body>
<h1>Listing Approvals</h1>
<p style="font-size:0.85rem;color:#666;">Approving here makes the entry appear on the public homepage immediately.</p>

<?php foreach ($sections as $type => $rows): ?>
  <h2><?= ucfirst($type) ?>s</h2>
  <?php if (empty($rows)): ?>
    <p class="empty">None yet.</p>
  <?php else: foreach ($rows as $row): ?>
    <div class="row">
      <?php if ($row['logo_path']): ?>
        <img src="../<?= htmlspecialchars($row['logo_path']) ?>" alt="">
      <?php endif; ?>
      <div class="name"><?= htmlspecialchars($row['display_name']) ?></div>
      <span class="status <?= $row['is_public'] ? 'live' : 'pending' ?>"><?= $row['is_public'] ? 'LIVE' : 'PENDING' ?></span>
      <form method="post" style="display:inline;">
        <input type="hidden" name="type" value="<?= $type ?>">
        <input type="hidden" name="id" value="<?= $row['id'] ?>">
        <input type="hidden" name="action" value="<?= $row['is_public'] ? 'reject' : 'approve' ?>">
        <button class="<?= $row['is_public'] ? 'reject' : 'approve' ?>" type="submit">
          <?= $row['is_public'] ? 'Unpublish' : 'Approve' ?>
        </button>
      </form>
    </div>
  <?php endforeach; endif; ?>
<?php endforeach; ?>

</body>
</html>
