<?php
declare(strict_types=1);

$root = __DIR__;
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/layout.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireUser();

$userId = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? ';
$userMode = getUserMode();

// Load profile and transactions
$profile = [];
$transactions = [];
try {
    $db = db();
    $stmt = $db->prepare('SELECT wallet_balance FROM user_profiles WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $profile = $stmt->fetch() ?: [];

    $stmt = $db->prepare('SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
    $stmt->execute([$userId]);
    $transactions = $stmt->fetchAll();
} catch (Throwable $e) {}

$balance = (float)($profile['wallet_balance'] ?? 0);

renderHead('My Wallet');
renderNav(true, ['username' => $username], $userMode);
?>

<div class="public-page">
  <div class="container py-5">
    <div class="row g-4">
      <!-- Balance Card -->
      <div class="col-lg-4">
        <div class="card-dark p-4 text-center">
          <div class="text-muted small mb-1 uppercase fw-700 letter-spacing-1">Available Balance</div>
          <div class="display-5 fw-900 text-accent mb-4">₦<?= number_format($balance, 2) ?></div>
          <div class="d-grid gap-2">
            <a href="deposit" class="btn btn-accent py-3 fw-700">Add Funds</a>
            <a href="payout" class="btn btn-outline-accent py-3 fw-700">Withdraw</a>
          </div>
        </div>
      </div>

      <!-- History -->
      <div class="col-lg-8">
        <div class="card-dark p-4">
          <h5 class="fw-700 mb-4">Transaction History</h5>
          <div class="table-responsive">
            <table class="table-dark-custom w-100">
              <thead><tr><th>Type</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
              <tbody>
                <?php foreach ($transactions as $t): ?>
                  <tr>
                    <td class="small fw-600"><?= e(ucfirst($t['type'])) ?></td>
                    <td class="fw-700 <?= $t['type']==='credit'?'text-success':'text-danger' ?>">
                      <?= $t['type']==='credit'?'+':'-' ?> ₦<?= number_format((float)$t['amount'], 2) ?>
                    </td>
                    <td>
                      <?php $sc = $t['status']==='completed'?'badge-success':($t['status']==='failed'?'badge-danger':'badge-warning'); ?>
                      <span class="badge <?= $sc ?>"><?= e($t['status']) ?></span>
                    </td>
                    <td class="small text-muted"><?= e(formatDate($t['created_at'], 'M j, Y')) ?></td>
                  </tr>
                <?php endforeach; if (empty($transactions)) echo '<tr><td colspan="4" class="text-center py-4 text-muted">No transactions yet</td></tr>'; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php renderFooter(); ?>
