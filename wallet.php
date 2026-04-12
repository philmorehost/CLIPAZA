<?php
declare(strict_types=1);

$root = __DIR__;
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/layout.php';
require_once $root . '/includes/payhub.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireUser();

$userId   = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';
$userMode = getUserMode();

// Load profile
$profile = [];
try {
    $db   = db();
    $stmt = $db->prepare('SELECT * FROM user_profiles WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $profile = $stmt->fetch() ?: [];
} catch (Throwable) {}

$walletBalance = (float)($profile['wallet_balance'] ?? 0);

// Load transactions (deposits/credits/withdrawals)
$txHistory = [];
try {
    $db   = db();
    $stmt = $db->prepare(
        "SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 20"
    );
    $stmt->execute([$userId]);
    $txHistory = $stmt->fetchAll();
} catch (Throwable) {}

// Load payout requests
$payoutRequests = [];
try {
    $db   = db();
    $stmt = $db->prepare(
        "SELECT * FROM payout_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 10"
    );
    $stmt->execute([$userId]);
    $payoutRequests = $stmt->fetchAll();
} catch (Throwable) {}

$csrf = generateCsrfToken();
$paystackPk = getSetting('paystack_public_key', '');
$minWithdrawal = (float)getSetting('min_withdrawal_amount', '1000');
$maxWithdrawal = (float)getSetting('max_withdrawal_amount', '500000');
$withdrawalFeePercent = (float)getSetting('withdrawal_fee_percent', '0');
$withdrawalFeeFlat    = (float)getSetting('withdrawal_fee_flat', '0');
$payhubOn = payhubEnabled();

// Load existing virtual account if PayHub enabled
$virtualAccount = null;
if ($payhubOn) {
    try {
        $db   = db();
        $stmt = $db->prepare("SELECT * FROM payhub_virtual_accounts WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $virtualAccount = $stmt->fetch() ?: null;
    } catch (Throwable) {}
}

renderHead('My Wallet');
renderNav(true, ['username' => $username], $userMode);
?>

<div class="public-page">
  <div class="container py-4">
    <div class="row justify-content-center">
      <div class="col-lg-8">

        <h3 class="fw-900 mb-4" style="letter-spacing:-0.5px">💳 My Wallet</h3>

        <!-- Wallet Balance -->
        <div class="card-dark p-4 mb-4" style="background:linear-gradient(135deg,#111 0%,#0a0a0a 100%);border-color:rgba(204,255,0,0.2)">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <div class="text-muted mb-1" style="font-size:0.82rem;text-transform:uppercase;letter-spacing:0.1em">Available Balance</div>
              <div style="font-size:2.5rem;font-weight:900;color:var(--accent);letter-spacing:-1px">₦<?= number_format($walletBalance, 2) ?></div>
            </div>
            <div style="font-size:3rem;opacity:0.2">💰</div>
          </div>
          <div class="d-flex gap-2 mt-4">
            <button class="btn btn-accent" id="depositBtn">+ Deposit</button>
            <button class="btn btn-outline-accent" id="withdrawBtn">↑ Request Payout</button>
          </div>
        </div>

        <!-- Deposit Modal -->
        <div id="depositPanel" style="display:none" class="card-dark p-4 mb-4">
          <h6 class="fw-700 mb-3">💳 Deposit Funds</h6>
          <form id="depositForm">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="init_deposit">
            <div class="mb-3">
              <label class="form-label-dark">Payment Method</label>
              <div class="d-flex gap-3">
                <label style="cursor:pointer;display:flex;align-items:center;gap:8px">
                  <input type="radio" name="deposit_gateway" value="paystack" checked> Paystack
                </label>
                <?php if ($payhubOn): ?>
                <label style="cursor:pointer;display:flex;align-items:center;gap:8px">
                  <input type="radio" name="deposit_gateway" value="payhub"> PayHub
                </label>
                <?php endif; ?>
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label-dark">Amount (₦)</label>
              <input type="number" name="amount" class="form-control-dark" min="100" step="1" placeholder="Enter amount in Naira" required>
            </div>
            <div id="depositFeedback" class="mb-2"></div>
            <div class="d-flex gap-2">
              <button type="submit" class="btn btn-accent" id="depositSubmitBtn">Pay with Paystack</button>
              <button type="button" class="btn btn-outline-accent" id="cancelDepositBtn">Cancel</button>
            </div>
          </form>
        </div>

        <?php if ($payhubOn): ?>
        <!-- PayHub Virtual Account -->
        <div class="card-dark p-4 mb-4">
          <h6 class="fw-700 mb-3">🏦 Virtual Bank Account</h6>
          <p class="text-muted mb-3" style="font-size:0.88rem">Get a dedicated bank account for instant deposits — transfer directly and your wallet is credited automatically.</p>
          <?php if ($virtualAccount): ?>
            <div style="background:#0d0d0d;border-radius:8px;padding:16px">
              <div class="mb-2" style="font-size:0.85rem"><span class="text-muted">Bank:</span> <strong><?= e($virtualAccount['bank_name']) ?></strong></div>
              <div class="mb-2" style="font-size:0.85rem"><span class="text-muted">Account Number:</span> <strong style="letter-spacing:2px"><?= e($virtualAccount['account_number']) ?></strong></div>
              <div style="font-size:0.85rem"><span class="text-muted">Account Name:</span> <strong><?= e($virtualAccount['account_name']) ?></strong></div>
            </div>
          <?php else: ?>
            <div id="virtualAccountResult"></div>
            <button class="btn btn-outline-accent" id="generateVirtualAcctBtn">Generate Virtual Account</button>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Withdrawal Modal -->
        <div id="withdrawPanel" style="display:none" class="card-dark p-4 mb-4">
          <h6 class="fw-700 mb-3">↑ Request Payout</h6>
          <?php if (($profile['kyc_status'] ?? 'none') !== 'approved'): ?>
          <div class="alert-dark-warning mb-3">
            <strong>⚠ KYC Required:</strong> You must complete KYC verification before requesting a payout.
            <a href="/kyc" class="ms-2" style="color:var(--warning)">Complete KYC →</a>
          </div>
          <?php else: ?>
          <form id="withdrawForm">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="request_payout">
            <div class="mb-3">
              <label class="form-label-dark">Amount (₦)</label>
              <input type="number" name="amount" id="withdrawAmount" class="form-control-dark"
                     min="<?= $minWithdrawal ?>" max="<?= $walletBalance ?>" step="1"
                     placeholder="Min ₦<?= number_format($minWithdrawal, 0) ?>" required>
              <?php if ($withdrawalFeePercent > 0 || $withdrawalFeeFlat > 0): ?>
              <div style="font-size:0.78rem;color:#888;margin-top:4px">
                Fee: <?= $withdrawalFeePercent > 0 ? $withdrawalFeePercent . '% + ' : '' ?>₦<?= number_format($withdrawalFeeFlat, 0) ?>
                <span id="feeCalc"></span>
              </div>
              <?php endif; ?>
            </div>
            <div class="row g-3 mb-3">
              <div class="col-md-6">
                <label class="form-label-dark">Bank</label>
                <select name="bank_code" id="bankSelect" class="form-control-dark" required>
                  <option value="">Select bank…</option>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label-dark">Account Number (NUBAN)</label>
                <div class="d-flex gap-2">
                  <input type="text" name="account_number" id="acctNumInput" class="form-control-dark"
                         maxlength="10" pattern="\d{10}" placeholder="0123456789" required>
                  <button type="button" class="btn btn-sm btn-outline-accent" id="verifyAcctBtn">Verify</button>
                </div>
              </div>
              <div class="col-12">
                <div id="acctVerifyResult" style="font-size:0.85rem;display:none"></div>
                <input type="hidden" name="account_name" id="acctNameHidden">
                <input type="hidden" name="bank_name" id="bankNameHidden">
              </div>
            </div>
            <div id="withdrawFeedback" class="mb-2"></div>
            <div class="d-flex gap-2">
              <button type="submit" class="btn btn-accent" id="withdrawSubmitBtn" disabled>Submit Request</button>
              <button type="button" class="btn btn-outline-accent" id="cancelWithdrawBtn">Cancel</button>
            </div>
          </form>
          <?php endif; ?>
        </div>

        <!-- Payout Requests -->
        <?php if (!empty($payoutRequests)): ?>
        <div class="card-dark mb-4">
          <div class="card-header">Payout Requests</div>
          <div class="card-body p-0">
            <div style="overflow-x:auto">
              <table class="table-dark-custom w-100">
                <thead>
                  <tr><th>Amount</th><th>Bank</th><th>Status</th><th>Date</th><th>Note</th></tr>
                </thead>
                <tbody>
                <?php foreach ($payoutRequests as $pr): ?>
                <?php
                  $prClass = match($pr['status']) {
                      'pending'   => 'badge-warning',
                      'approved'  => 'badge-success',
                      'rejected'  => 'badge-danger',
                      'cancelled' => 'badge-muted',
                      'on_hold'   => 'badge-info',
                      default     => 'badge-muted',
                  };
                ?>
                <tr>
                  <td><strong style="color:#fff">₦<?= number_format((float)$pr['amount'], 0) ?></strong></td>
                  <td style="font-size:0.82rem;color:#aaa"><?= e($pr['bank_name'] ?? '—') ?></td>
                  <td><span class="<?= $prClass ?>" style="font-size:0.72rem"><?= e(ucfirst($pr['status'])) ?></span></td>
                  <td style="font-size:0.78rem;color:#888"><?= e(formatDate($pr['created_at'], 'M j, Y')) ?></td>
                  <td style="font-size:0.78rem;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?php if ($pr['status'] === 'rejected' && $pr['rejection_reason']): ?>
                      <span style="color:var(--danger)"><?= e($pr['rejection_reason']) ?></span>
                    <?php elseif ($pr['status'] === 'cancelled' && $pr['cancel_reason']): ?>
                      <span style="color:var(--warning)"><?= e($pr['cancel_reason']) ?></span>
                      <?php if (empty($pr['appeal_message'])): ?>
                      <button class="btn btn-xs appeal-btn ms-1" style="font-size:0.68rem;padding:2px 8px;background:rgba(0,153,255,0.1);color:var(--info);border:1px solid rgba(0,153,255,0.2);border-radius:4px"
                              data-id="<?= (int)$pr['id'] ?>">Appeal</button>
                      <?php else: ?>
                      <span style="color:var(--info)"> · Appeal submitted</span>
                      <?php endif; ?>
                    <?php elseif ($pr['status'] === 'on_hold'): ?>
                      <span style="color:var(--info)">Under review</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Transaction History -->
        <div class="card-dark">
          <div class="card-header">Transaction History</div>
          <div class="card-body p-0">
            <?php if (empty($txHistory)): ?>
            <div class="text-center py-5" style="color:#888">
              <div style="font-size:2.5rem;margin-bottom:12px">📋</div>
              <p>No transactions yet.</p>
            </div>
            <?php else: ?>
            <div style="overflow-x:auto">
              <table class="table-dark-custom w-100">
                <thead>
                  <tr><th>Type</th><th>Amount</th><th>Status</th><th>Description</th><th>Date</th></tr>
                </thead>
                <tbody>
                <?php foreach ($txHistory as $tx): ?>
                <?php
                  $txClass = match($tx['type']) {
                      'credit'     => 'badge-success',
                      'debit'      => 'badge-danger',
                      'withdrawal' => 'badge-warning',
                      'refund'     => 'badge-info',
                      default      => 'badge-muted',
                  };
                  $amtColor = in_array($tx['type'], ['credit','refund']) ? 'var(--success)' : 'var(--danger)';
                  $amtPrefix = in_array($tx['type'], ['credit','refund']) ? '+' : '-';
                ?>
                <tr>
                  <td><span class="<?= $txClass ?>" style="font-size:0.72rem"><?= e(ucfirst($tx['type'])) ?></span></td>
                  <td style="font-weight:700;color:<?= $amtColor ?>"><?= $amtPrefix ?>₦<?= number_format((float)$tx['amount'], 0) ?></td>
                  <td>
                    <?php $sc = $tx['status']==='completed'?'badge-success':($tx['status']==='failed'?'badge-danger':'badge-muted'); ?>
                    <span class="<?= $sc ?>" style="font-size:0.72rem"><?= e(ucfirst($tx['status'])) ?></span>
                  </td>
                  <td style="font-size:0.8rem;color:#888;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($tx['description'] ?? '') ?></td>
                  <td style="white-space:nowrap;font-size:0.78rem;color:#888"><?= e(formatDate($tx['created_at'], 'M j, Y')) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<!-- Appeal Modal -->
<div class="modal fade modal-dark" id="appealModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title fw-700">Submit Appeal</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="appealForm">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="action" value="submit_appeal">
          <input type="hidden" name="payout_request_id" id="appealPayoutId">
          <div class="mb-3">
            <label class="form-label-dark">Appeal Message</label>
            <textarea name="appeal_message" class="form-control-dark" rows="4" required
                      placeholder="Explain why you believe this payout should be approved…"></textarea>
          </div>
          <div id="appealFeedback" class="mb-2"></div>
          <button type="submit" class="btn btn-accent">Submit Appeal</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php if (!empty($paystackPk)): ?>
<script src="https://js.paystack.co/v1/inline.js"></script>
<?php endif; ?>
<script>
const csrf = <?= json_encode($csrf) ?>;
const walletBalance = <?= json_encode($walletBalance) ?>;
const minWithdrawal = <?= json_encode($minWithdrawal) ?>;
const feePercent = <?= json_encode($withdrawalFeePercent) ?>;
const feeFlat = <?= json_encode($withdrawalFeeFlat) ?>;
const paystackPk = <?= json_encode($paystackPk) ?>;
const userEmail = <?= json_encode($_SESSION['user_email'] ?? '') ?>;

// Toggle panels
document.getElementById('depositBtn').addEventListener('click', () => {
  document.getElementById('depositPanel').style.display = 'block';
  document.getElementById('withdrawPanel').style.display = 'none';
  document.getElementById('depositBtn').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
});
document.getElementById('cancelDepositBtn').addEventListener('click', () => {
  document.getElementById('depositPanel').style.display = 'none';
});
document.getElementById('withdrawBtn')?.addEventListener('click', () => {
  document.getElementById('withdrawPanel').style.display = 'block';
  document.getElementById('depositPanel').style.display = 'none';
  loadBanks();
});
document.getElementById('cancelWithdrawBtn')?.addEventListener('click', () => {
  document.getElementById('withdrawPanel').style.display = 'none';
});

// Fee calculation
document.getElementById('withdrawAmount')?.addEventListener('input', function() {
  const amt = parseFloat(this.value) || 0;
  const fee = (amt * feePercent / 100) + feeFlat;
  const calc = document.getElementById('feeCalc');
  if (calc && (feePercent > 0 || feeFlat > 0)) {
    calc.textContent = ' (You receive: ₦' + (amt - fee).toLocaleString() + ')';
  }
});

// Load banks
let banksLoaded = false;
async function loadBanks() {
  if (banksLoaded) return;
  try {
    const r = await fetch('/ajax/wallet_actions.php?action=get_banks');
    const d = await r.json();
    if (d.success && d.banks) {
      const sel = document.getElementById('bankSelect');
      d.banks.forEach(b => {
        const opt = document.createElement('option');
        opt.value = b.code;
        opt.textContent = b.name;
        opt.dataset.name = b.name;
        sel.appendChild(opt);
      });
      banksLoaded = true;
    }
  } catch {}
}

// Verify account
document.getElementById('verifyAcctBtn')?.addEventListener('click', async function() {
  const bankCode = document.getElementById('bankSelect').value;
  const acctNum  = document.getElementById('acctNumInput').value.trim();
  const resultEl = document.getElementById('acctVerifyResult');
  const submitBtn = document.getElementById('withdrawSubmitBtn');

  if (!bankCode || acctNum.length !== 10) {
    resultEl.textContent = 'Select a bank and enter 10-digit account number.';
    resultEl.style.color = 'var(--danger)';
    resultEl.style.display = 'block';
    return;
  }
  this.disabled = true; this.textContent = 'Verifying…';
  try {
    const r = await fetch('/ajax/wallet_actions.php', {
      method: 'POST',
      body: new URLSearchParams({ action: 'verify_account', account_number: acctNum, bank_code: bankCode, csrf_token: csrf })
    });
    const d = await r.json();
    if (d.success) {
      resultEl.textContent = '✅ ' + d.account_name;
      resultEl.style.color = 'var(--accent)';
      resultEl.style.display = 'block';
      document.getElementById('acctNameHidden').value = d.account_name;
      const sel = document.getElementById('bankSelect');
      document.getElementById('bankNameHidden').value = sel.options[sel.selectedIndex]?.textContent || '';
      submitBtn.disabled = false;
    } else {
      resultEl.textContent = '❌ ' + (d.message || 'Verification failed.');
      resultEl.style.color = 'var(--danger)';
      resultEl.style.display = 'block';
      submitBtn.disabled = true;
    }
  } catch {
    resultEl.textContent = 'Network error.';
    resultEl.style.color = 'var(--danger)';
    resultEl.style.display = 'block';
  }
  this.disabled = false; this.textContent = 'Verify';
});

// Gateway-aware deposit form
document.querySelectorAll('input[name="deposit_gateway"]').forEach(radio => {
  radio.addEventListener('change', function() {
    const btn = document.getElementById('depositSubmitBtn');
    if (btn) btn.textContent = this.value === 'payhub' ? 'Pay with PayHub' : 'Pay with Paystack';
  });
});

document.getElementById('depositForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const fb = document.getElementById('depositFeedback');
  const btn = document.getElementById('depositSubmitBtn') || this.querySelector('[type="submit"]');
  const amount = parseFloat(this.querySelector('[name="amount"]').value);
  const gateway = this.querySelector('input[name="deposit_gateway"]:checked')?.value ?? 'paystack';
  if (!amount || amount < 100) {
    fb.innerHTML = '<div class="alert-dark-danger" style="font-size:0.82rem">Minimum deposit is ₦100.</div>';
    return;
  }
  btn.disabled = true; btn.textContent = 'Initializing…'; fb.innerHTML = '';
  try {
    const params = new URLSearchParams(new FormData(this));
    if (gateway === 'payhub') {
      params.set('action', 'init_deposit_payhub');
    } else {
      params.set('action', 'init_deposit');
    }
    const r = await fetch('/ajax/wallet_actions.php', { method: 'POST', body: params });
    const d = await r.json();
    if (gateway === 'payhub' && d.success && d.checkout_url) {
      window.location.href = d.checkout_url;
    } else if (gateway !== 'payhub' && d.success && d.authorization_url) {
      window.location.href = d.authorization_url;
    } else {
      fb.innerHTML = '<div class="alert-dark-danger" style="font-size:0.82rem">' + (d.message || 'Error') + '</div>';
      btn.disabled = false;
      btn.textContent = gateway === 'payhub' ? 'Pay with PayHub' : 'Pay with Paystack';
    }
  } catch {
    fb.innerHTML = '<div class="alert-dark-danger" style="font-size:0.82rem">Network error.</div>';
    btn.disabled = false;
    btn.textContent = gateway === 'payhub' ? 'Pay with PayHub' : 'Pay with Paystack';
  }
});

// Virtual account generation
document.getElementById('generateVirtualAcctBtn')?.addEventListener('click', async function() {
  const resultEl = document.getElementById('virtualAccountResult');
  this.disabled = true; this.textContent = 'Generating…';
  try {
    const r = await fetch('/ajax/wallet_actions.php', {
      method: 'POST',
      body: new URLSearchParams({ action: 'get_virtual_account', csrf_token: csrf })
    });
    const d = await r.json();
    if (d.success) {
      resultEl.innerHTML = '<div style="background:#0d0d0d;border-radius:8px;padding:16px;margin-bottom:12px">'
        + '<div class="mb-2" style="font-size:0.85rem"><span class="text-muted">Bank:</span> <strong>' + d.bank_name + '</strong></div>'
        + '<div class="mb-2" style="font-size:0.85rem"><span class="text-muted">Account Number:</span> <strong style="letter-spacing:2px">' + d.account_number + '</strong></div>'
        + '<div style="font-size:0.85rem"><span class="text-muted">Account Name:</span> <strong>' + d.account_name + '</strong></div>'
        + '</div>';
      this.style.display = 'none';
    } else {
      resultEl.innerHTML = '<div class="alert-dark-danger mb-2" style="font-size:0.82rem">' + (d.message || 'Failed to generate account.') + '</div>';
      this.disabled = false; this.textContent = 'Generate Virtual Account';
    }
  } catch {
    resultEl.innerHTML = '<div class="alert-dark-danger mb-2" style="font-size:0.82rem">Network error.</div>';
    this.disabled = false; this.textContent = 'Generate Virtual Account';
  }
});

// Withdraw form
document.getElementById('withdrawForm')?.addEventListener('submit', async function(e) {
  e.preventDefault();
  const fb = document.getElementById('withdrawFeedback');
  const btn = document.getElementById('withdrawSubmitBtn');
  btn.disabled = true; btn.textContent = 'Submitting…'; fb.innerHTML = '';
  try {
    const r = await fetch('/ajax/wallet_actions.php', { method: 'POST', body: new URLSearchParams(new FormData(this)) });
    const d = await r.json();
    if (d.success) {
      fb.innerHTML = '<div class="alert-dark-success" style="font-size:0.82rem">✅ ' + d.message + '</div>';
      btn.textContent = 'Submitted ✅';
      setTimeout(() => location.reload(), 2000);
    } else {
      fb.innerHTML = '<div class="alert-dark-danger" style="font-size:0.82rem">' + (d.message || 'Error') + '</div>';
      btn.disabled = false; btn.textContent = 'Submit Request';
    }
  } catch {
    fb.innerHTML = '<div class="alert-dark-danger" style="font-size:0.82rem">Network error.</div>';
    btn.disabled = false; btn.textContent = 'Submit Request';
  }
});

// Appeal
document.querySelectorAll('.appeal-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.getElementById('appealPayoutId').value = this.dataset.id;
    const modal = new bootstrap.Modal(document.getElementById('appealModal'));
    modal.show();
  });
});
document.getElementById('appealForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const fb = document.getElementById('appealFeedback');
  const btn = this.querySelector('[type="submit"]');
  btn.disabled = true; btn.textContent = 'Submitting…'; fb.innerHTML = '';
  try {
    const r = await fetch('/ajax/wallet_actions.php', { method: 'POST', body: new URLSearchParams(new FormData(this)) });
    const d = await r.json();
    if (d.success) {
      fb.innerHTML = '<div class="alert-dark-success" style="font-size:0.82rem">✅ Appeal submitted.</div>';
      btn.textContent = 'Submitted ✅';
      setTimeout(() => location.reload(), 1500);
    } else {
      fb.innerHTML = '<div class="alert-dark-danger" style="font-size:0.82rem">' + (d.message || 'Error') + '</div>';
      btn.disabled = false; btn.textContent = 'Submit Appeal';
    }
  } catch {
    fb.innerHTML = '<div class="alert-dark-danger" style="font-size:0.82rem">Network error.</div>';
    btn.disabled = false; btn.textContent = 'Submit Appeal';
  }
});
</script>

<?php renderFooter(); ?>
