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

// Load current KYC status
$profile = [];
try {
    $db = db();
    $stmt = $db->prepare('SELECT * FROM user_profiles WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $profile = $stmt->fetch() ?: [];
} catch (Throwable $e) {}

$kycStatus = $profile['kyc_status'] ?? 'none';
$csrf = generateCsrfToken();

renderHead('Identity Verification (KYC)');
renderNav(true, ['username' => $username], $userMode);
?>

<div class="public-page">
  <div class="container py-5">
    <div class="row justify-content-center">
      <div class="col-lg-8">
        <div class="d-flex align-items-center gap-3 mb-4">
          <a href="profile" class="text-muted text-decoration-none">← Back</a>
          <h2 class="fw-900 mb-0">Identity <span class="text-accent">Verification</span></h2>
        </div>

        <?php if ($kycStatus === 'approved'): ?>
          <div class="card-dark p-5 text-center border-success" style="border-width:2px">
            <div style="font-size:4rem;margin-bottom:20px">✅</div>
            <h4 class="fw-900">Verification Complete</h4>
            <p class="text-muted">Your identity has been verified. you can now receive payouts to your bank account.</p>
            <div class="mt-4 p-3 bg-black rounded">
              <div class="fw-600 text-accent"><?= e($profile['account_name']) ?></div>
              <div class="text-muted small"><?= e($profile['bank_name']) ?> • <?= e($profile['account_number']) ?></div>
            </div>
          </div>
        <?php elseif ($kycStatus === 'pending'): ?>
          <div class="card-dark p-5 text-center border-warning">
            <div style="font-size:4rem;margin-bottom:20px">⏳</div>
            <h4 class="fw-900">Verification Pending</h4>
            <p class="text-muted">We are reviewing your documents. This usually takes 24-48 hours. You will receive an email once we're done.</p>
          </div>
        <?php else: ?>
          <?php if ($kycStatus === 'rejected'): ?>
            <div class="alert alert-dark-danger mb-4">
              <strong>Verification Rejected:</strong> <?= e($profile['kyc_rejection_reason'] ?: 'Please check your documents and try again.') ?>
            </div>
          <?php endif; ?>

          <form id="kycForm" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

            <!-- Step 1: Bank Details -->
            <div class="card-dark p-4 mb-4" id="step1">
              <h5 class="fw-700 mb-3 text-accent">1. Bank Account Details</h5>
              <p class="text-muted small mb-4">The bank account name must match your legal name.</p>

              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label-dark">Select Bank</label>
                  <select name="bank_code" id="bankSelect" class="form-control-dark" required>
                    <option value="">Choose a bank...</option>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label-dark">Account Number (NUBAN)</label>
                  <div class="input-group">
                    <input type="text" name="account_number" id="accountNumber" class="form-control-dark" maxlength="10" placeholder="0123456789" required>
                    <button type="button" class="btn btn-outline-accent" id="verifyBankBtn">Verify</button>
                  </div>
                </div>
                <div class="col-12">
                  <div id="bankResult" class="mt-2 fw-600 text-accent" style="display:none"></div>
                  <input type="hidden" name="account_name" id="accountName">
                  <input type="hidden" name="bank_name" id="bankName">
                </div>
              </div>
            </div>

            <!-- Step 2: ID Upload -->
            <div class="card-dark p-4 mb-4" id="step2">
              <h5 class="fw-700 mb-3 text-accent">2. Government Issued ID</h5>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label-dark">ID Type</label>
                  <select name="kyc_id_type" class="form-control-dark" required>
                    <option value="nin">NIN Slip</option>
                    <option value="voters_card">Voter's Card</option>
                    <option value="passport">International Passport</option>
                    <option value="driver_license">Driver's License</option>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label-dark">Expiry Date (if applicable)</label>
                  <input type="date" name="kyc_id_expiry" class="form-control-dark">
                </div>
                <div class="col-12">
                  <label class="form-label-dark">Upload ID Document</label>
                  <input type="file" name="kyc_id_file" class="form-control-dark" accept="image/*,application/pdf" required>
                  <small class="text-muted">Clear photo or scan of your ID.</small>
                </div>
              </div>
            </div>

            <!-- Step 3: Live Snapshot -->
            <div class="card-dark p-4 mb-4" id="step3">
              <h5 class="fw-700 mb-3 text-accent">3. Live Snapshot</h5>
              <p class="text-muted small mb-3">Please take a clear photo of your face using your camera.</p>

              <div class="text-center">
                <div id="cameraContainer" class="mb-3 mx-auto rounded overflow-hidden shadow-lg" style="width:320px;height:240px;background:#000;border:2px solid #222">
                  <video id="video" width="320" height="240" autoplay playsinline style="object-fit:cover"></video>
                </div>
                <canvas id="canvas" width="320" height="240" style="display:none"></canvas>
                <img id="snapshotPreview" class="mb-3 mx-auto rounded d-none" style="width:320px;height:240px;object-fit:cover;border:2px solid var(--accent)">

                <div>
                  <button type="button" class="btn btn-outline-light btn-sm" id="startCameraBtn">Start Camera</button>
                  <button type="button" class="btn btn-accent btn-sm d-none" id="captureBtn">Take Photo</button>
                  <button type="button" class="btn btn-outline-danger btn-sm d-none" id="retakeBtn">Retake</button>
                </div>
                <input type="hidden" name="kyc_snapshot" id="snapshotData">
              </div>
            </div>

            <div id="kycFeedback" class="mb-3"></div>
            <button type="submit" class="btn btn-accent btn-lg w-100 py-3 fw-900" id="submitKycBtn" disabled>Submit Verification</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
// Bank Resolution
const bankSelect = document.getElementById('bankSelect');
const verifyBtn = document.getElementById('verifyBankBtn');
const bankResult = document.getElementById('bankResult');

fetch('ajax/payout_actions.php?action=get_banks')
  .then(r => r.json())
  .then(d => {
    if (d.success) {
      d.banks.forEach(b => {
        const opt = document.createElement('option');
        opt.value = b.code;
        opt.textContent = b.name;
        bankSelect.appendChild(opt);
      });
    }
  });

verifyBtn.addEventListener('click', async function() {
  const code = bankSelect.value;
  const num = document.getElementById('accountNumber').value;
  if (!code || num.length !== 10) return alert('Select bank and enter 10-digit account number.');

  this.disabled = true; this.textContent = 'Verifying...';
  try {
    const r = await fetch('ajax/payout_actions', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({action:'verify_account', account_number:num, bank_code:code, csrf_token: '<?= $csrf ?>'})
    });
    const d = await r.json();
    if (d.success) {
      bankResult.textContent = '✅ ' + d.account_name;
      bankResult.style.display = 'block';
      document.getElementById('accountName').value = d.account_name;
      document.getElementById('bankName').value = bankSelect.options[bankSelect.selectedIndex].text;
      checkFormReady();
    } else {
      bankResult.textContent = '❌ ' + (d.message || 'Verification failed.');
      bankResult.style.display = 'block';
    }
  } catch(e) { alert('Network error'); }
  this.disabled = false; this.textContent = 'Verify';
});

// Camera
const video = document.getElementById('video');
const canvas = document.getElementById('canvas');
const startBtn = document.getElementById('startCameraBtn');
const captureBtn = document.getElementById('captureBtn');
const retakeBtn = document.getElementById('retakeBtn');
const preview = document.getElementById('snapshotPreview');
const snapshotInput = document.getElementById('snapshotData');

startBtn.addEventListener('click', async function() {
  try {
    const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
    video.srcObject = stream;
    this.classList.add('d-none');
    captureBtn.classList.remove('d-none');
  } catch(e) { alert('Camera access denied or not available.'); }
});

captureBtn.addEventListener('click', function() {
  const context = canvas.getContext('2d');
  context.drawImage(video, 0, 0, 320, 240);
  const data = canvas.toDataURL('image/jpeg', 0.8);
  snapshotInput.value = data;
  preview.src = data;
  preview.classList.remove('d-none');
  document.getElementById('cameraContainer').classList.add('d-none');
  this.classList.add('d-none');
  retakeBtn.classList.remove('d-none');
  checkFormReady();
});

retakeBtn.addEventListener('click', function() {
  preview.classList.add('d-none');
  document.getElementById('cameraContainer').classList.remove('d-none');
  this.classList.add('d-none');
  captureBtn.classList.remove('d-none');
  snapshotInput.value = ';
  checkFormReady();
});

function checkFormReady() {
  const name = document.getElementById('accountName').value;
  const snapshot = snapshotInput.value;
  document.getElementById('submitKycBtn').disabled = !(name && snapshot);
}

// Submission
document.getElementById('kycForm')?.addEventListener('submit', async function(e) {
  e.preventDefault();
  const fb = document.getElementById('kycFeedback');
  const btn = document.getElementById('submitKycBtn');
  btn.disabled = true; btn.textContent = 'Submitting...';

  try {
    const r = await fetch('ajax/kyc_actions', {
      method: 'POST',
      body: new FormData(this)
    });
    const d = await r.json();
    if (d.success) {
      location.reload();
    } else {
      fb.innerHTML = '<div class="alert-dark-danger">' + d.message + '</div>';
      btn.disabled = false; btn.textContent = 'Submit Verification';
    }
  } catch(e) { alert('Network error'); btn.disabled = false; }
});
</script>

<?php renderFooter(); ?>
