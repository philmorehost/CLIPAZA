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
$userEmail = $_SESSION['user_email'] ?? ';

$csrf = generateCsrfToken();
renderHead('Add Funds');
renderNav(true, ['username' => $username], $userMode);
?>

<div class="public-page">
  <div class="container py-5">
    <div class="row justify-content-center">
      <div class="col-lg-5">
        <div class="card-dark p-4">
          <h4 class="fw-900 mb-4">Add <span class="text-accent">Funds</span></h4>
          <p class="text-muted small mb-4">Fund your Clipaza wallet via Paystack.</p>

          <form id="depositForm">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <div class="mb-4">
              <label class="form-label-dark">Amount (₦)</label>
              <input type="number" name="amount" id="depositAmount" class="form-control-dark" min="500" step="100" placeholder="Minimum 500" required>
            </div>

            <div id="depositFeedback" class="mb-3"></div>
            <button type="submit" class="btn btn-accent w-100 py-3 fw-700" id="depositBtn">Proceed to Paystack</button>
          </form>

          <div class="mt-4 text-center">
            <a href="wallet" class="text-muted small text-decoration-none">← Back to Wallet</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.getElementById('depositForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const btn = document.getElementById('depositBtn');
  const fb = document.getElementById('depositFeedback');
  const amt = document.getElementById('depositAmount').value;

  btn.disabled = true; btn.textContent = 'Initializing...';
  fb.innerHTML = ';

  try {
    const r = await fetch('ajax/payment_actions', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: new URLSearchParams({
        action: 'init_deposit',
        amount: amt,
        csrf_token: '<?= $csrf ?>'
      })
    });
    const d = await r.json();
    if (d.success && d.authorization_url) {
      window.location.href = d.authorization_url;
    } else {
      fb.innerHTML = '<div class="alert-dark-danger">' + (d.message || 'Initialization failed.') + '</div>';
      btn.disabled = false; btn.textContent = 'Proceed to Paystack';
    }
  } catch(e) {
    fb.innerHTML = '<div class="alert-dark-danger">Network error</div>';
    btn.disabled = false; btn.textContent = 'Proceed to Paystack';
  }
});
</script>

<?php renderFooter(); ?>
