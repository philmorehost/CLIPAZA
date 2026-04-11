<?php
declare(strict_types=1);

$root = __DIR__;
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/layout.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireUser();

$userId   = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';
$userMode = getUserMode();

// Find pending payouts for this user
$payouts = [];
try {
    $db   = db();
    $stmt = $db->prepare(
        "SELECT p.*, c.title AS contest_title, c.end_date
         FROM payouts p
         INNER JOIN contests c ON c.id = p.contest_id
         WHERE p.user_id = ?
         ORDER BY p.created_at DESC"
    );
    $stmt->execute([$userId]);
    $payouts = $stmt->fetchAll();
} catch (Throwable) {}

// Find contests where user is a winner but hasn't claimed yet
$pendingWins = [];
try {
    $db   = db();
    // Winner = top N rank per platform for completed/active contests with funded escrow
    $stmt = $db->prepare(
        "SELECT ce.*, c.title AS contest_title, cp.prize_amount, cp.winner_count,
                (SELECT COUNT(*) FROM contest_entries ce2
                 WHERE ce2.contest_id = ce.contest_id AND ce2.platform = ce.platform
                 AND ce2.disqualified = 0 AND ce2.view_count >= ce.view_count
                 AND ce2.id <= ce.id) AS calc_rank
         FROM contest_entries ce
         INNER JOIN contests c ON c.id = ce.contest_id
         INNER JOIN contest_platforms cp ON cp.contest_id = ce.contest_id AND cp.platform = ce.platform
         LEFT JOIN payouts py ON py.entry_id = ce.id AND py.user_id = ce.user_id
         WHERE ce.user_id = ?
           AND ce.disqualified = 0
           AND c.escrow_status = 'funded'
           AND py.id IS NULL
         HAVING calc_rank <= cp.winner_count
         ORDER BY c.end_date DESC"
    );
    $stmt->execute([$userId]);
    $pendingWins = $stmt->fetchAll();
} catch (Throwable) {}

$csrf = generateCsrfToken();
renderHead('Payouts');
renderNav(true, ['username' => $username], $userMode);
?>

<div class="public-page">
  <div class="container py-5">
    <div class="row justify-content-center">
      <div class="col-lg-7">
        <h3 class="fw-900 mb-2" style="letter-spacing:-0.5px">Payouts</h3>
        <p class="text-muted mb-4" style="font-size:0.9rem">Claim your winnings from completed contests.</p>

        <!-- Pending wins -->
        <?php if (!empty($pendingWins)): ?>
          <?php foreach ($pendingWins as $win): ?>
            <?php
              $pIcon = match($win['platform'] ?? '') { 'tiktok'=>'🎵','instagram'=>'📸','facebook'=>'📘',default=>'🎬' };
              $perWinner = (int)$win['winner_count'] > 0
                  ? round((float)$win['prize_amount'] / (int)$win['winner_count'], 2)
                  : 0;
            ?>
            <div class="card-dark p-4 mb-4" style="border-color:rgba(204,255,0,0.3)">
              <div class="d-flex align-items-center gap-2 mb-3">
                <span style="font-size:1.5rem">🎉</span>
                <div>
                  <h5 class="fw-900 mb-0" style="color:var(--accent)">You Won ₦<?= number_format($perWinner, 0) ?>!</h5>
                  <p class="text-muted mb-0" style="font-size:0.85rem"><?= $pIcon ?> <?= e($win['contest_title']) ?> · Rank #<?= (int)$win['calc_rank'] ?></p>
                </div>
              </div>

              <form class="claim-form" data-entry="<?= (int)$win['id'] ?>">
                <input type="hidden" name="action" value="claim_prize">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="entry_id" value="<?= (int)$win['id'] ?>">

                <div class="row g-3 mb-3">
                  <div class="col-md-6">
                    <label class="form-label-dark">Bank</label>
                    <select name="bank_code" class="form-control-dark bank-select" required>
                      <option value="">Select bank…</option>
                    </select>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label-dark">Account Number (NUBAN)</label>
                    <div class="d-flex gap-2">
                      <input type="text" name="account_number" class="form-control-dark nuban-input" maxlength="10" pattern="\d{10}" placeholder="0123456789" required>
                      <button type="button" class="btn btn-sm btn-outline-accent verify-nuban-btn" style="white-space:nowrap">Verify</button>
                    </div>
                  </div>
                  <div class="col-12">
                    <div class="nuban-result" style="font-size:0.85rem;color:var(--accent);display:none"></div>
                    <input type="hidden" name="account_name" class="account-name-input">
                    <input type="hidden" name="bank_name" class="bank-name-input">
                  </div>
                </div>

                <div class="claim-feedback mb-2"></div>
                <button type="submit" class="btn btn-accent claim-submit-btn" disabled>Claim ₦<?= number_format($perWinner, 0) ?></button>
              </form>
            </div>
          <?php endforeach; ?>
        <?php elseif (empty($payouts)): ?>
          <div class="card-dark p-5 text-center">
            <div style="font-size:3rem;margin-bottom:16px">🏆</div>
            <h5 class="fw-700 mb-2">No winnings yet</h5>
            <p class="text-muted" style="font-size:0.9rem">Submit clips to contests and win prizes!</p>
            <a href="/contests" class="btn btn-accent mt-2">Browse Contests</a>
          </div>
        <?php endif; ?>

        <!-- Past payouts -->
        <?php if (!empty($payouts)): ?>
          <div class="card-dark p-4">
            <h6 class="fw-700 mb-3">Payout History</h6>
            <div class="leaderboard-table">
              <?php foreach ($payouts as $py): ?>
                <?php
                  $stColor = match($py['status']) {
                      'completed' => 'var(--success)',
                      'failed'    => 'var(--danger)',
                      'claimed'   => 'var(--accent)',
                      default     => 'var(--text-muted)',
                  };
                ?>
                <div class="leaderboard-row" style="justify-content:space-between">
                  <div>
                    <div class="fw-600" style="font-size:0.88rem"><?= e($py['contest_title']) ?></div>
                    <div class="text-muted" style="font-size:0.78rem"><?= e(ucfirst($py['platform'])) ?> · <?= formatDate($py['created_at']) ?></div>
                  </div>
                  <div class="text-end">
                    <div class="fw-700">₦<?= number_format((float)$py['amount'], 0) ?></div>
                    <div style="font-size:0.75rem;color:<?= $stColor ?>"><?= e(ucfirst($py['status'])) ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div>

<script>
const csrf = <?= json_encode(generateCsrfToken()) ?>;

// Load banks on page load
(async function() {
  try {
    const r = await fetch('/ajax/payout_actions.php?action=get_banks');
    const d = await r.json();
    if (d.success && d.banks) {
      document.querySelectorAll('.bank-select').forEach(sel => {
        d.banks.forEach(b => {
          const opt = document.createElement('option');
          opt.value = b.code;
          opt.textContent = b.name;
          sel.appendChild(opt);
        });
      });
    }
  } catch {}
})();

// Verify NUBAN
document.querySelectorAll('.verify-nuban-btn').forEach(btn => {
  btn.addEventListener('click', async function() {
    const form      = this.closest('form');
    const bankCode  = form.querySelector('.bank-select').value;
    const acctNum   = form.querySelector('.nuban-input').value.trim();
    const resultDiv = form.querySelector('.nuban-result');
    const submitBtn = form.querySelector('.claim-submit-btn');

    if (!bankCode || acctNum.length !== 10) {
      resultDiv.textContent = 'Select a bank and enter a 10-digit account number.';
      resultDiv.style.color = 'var(--danger)';
      resultDiv.style.display = 'block';
      return;
    }

    btn.disabled = true;
    btn.textContent = 'Verifying…';

    try {
      const r = await fetch('/ajax/payout_actions.php', {
        method: 'POST',
        body: new URLSearchParams({ action:'verify_account', account_number: acctNum, bank_code: bankCode, csrf_token: csrf })
      });
      const d = await r.json();
      if (d.success) {
        resultDiv.textContent = '✅ ' + d.account_name;
        resultDiv.style.color = 'var(--accent)';
        resultDiv.style.display = 'block';
        form.querySelector('.account-name-input').value = d.account_name;
        form.querySelector('.bank-name-input').value = form.querySelector('.bank-select option:checked').textContent;
        submitBtn.disabled = false;
      } else {
        resultDiv.textContent = '❌ ' + (d.message || 'Could not verify account.');
        resultDiv.style.color = 'var(--danger)';
        resultDiv.style.display = 'block';
        submitBtn.disabled = true;
      }
    } catch {
      resultDiv.textContent = 'Network error.';
      resultDiv.style.color = 'var(--danger)';
      resultDiv.style.display = 'block';
    }

    btn.disabled = false;
    btn.textContent = 'Verify';
  });
});

// Claim prize
document.querySelectorAll('.claim-form').forEach(form => {
  form.addEventListener('submit', async function(e) {
    e.preventDefault();
    const fb  = this.querySelector('.claim-feedback');
    const btn = this.querySelector('.claim-submit-btn');
    btn.disabled = true;
    btn.textContent = 'Claiming…';
    fb.innerHTML = '';

    try {
      const r = await fetch('/ajax/payout_actions.php', {
        method: 'POST',
        body: new URLSearchParams(new FormData(this))
      });
      const d = await r.json();
      if (d.success) {
        fb.innerHTML = '<div class="alert-dark-success" style="font-size:0.82rem">✅ Prize claimed! We will process your transfer shortly.</div>';
        btn.textContent = 'Claimed ✅';
      } else {
        fb.innerHTML = '<div class="alert-dark-danger" style="font-size:0.82rem">' + d.message + '</div>';
        btn.disabled = false;
        btn.textContent = 'Claim Prize';
      }
    } catch {
      fb.innerHTML = '<div class="alert-dark-danger" style="font-size:0.82rem">Network error.</div>';
      btn.disabled = false;
      btn.textContent = 'Claim Prize';
    }
  });
});
</script>

<?php renderFooter(); ?>
