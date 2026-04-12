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

// Load profile
$profile = [];
try {
    $db   = db();
    $stmt = $db->prepare('SELECT * FROM user_profiles WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $profile = $stmt->fetch() ?: [];
} catch (Throwable) {}

$kycStatus = $profile['kyc_status'] ?? 'none';

$csrf = generateCsrfToken();
renderHead('KYC Verification');
renderNav(true, ['username' => $username], $userMode);
?>

<div class="public-page">
  <div class="container py-4">
    <div class="row justify-content-center">
      <div class="col-lg-7">

        <h3 class="fw-900 mb-2" style="letter-spacing:-0.5px">🪪 Identity Verification</h3>
        <p class="text-muted mb-4" style="font-size:0.9rem">Complete KYC to unlock payout capabilities. Your documents are handled securely.</p>

        <?php if ($kycStatus === 'approved'): ?>
        <div class="alert-dark-success mb-4">
          <strong>✅ Verified!</strong> Your identity has been verified. You can now request payouts.
        </div>
        <?php elseif ($kycStatus === 'pending'): ?>
        <div class="alert-dark-warning mb-4">
          <strong>⏳ Under Review</strong> Your documents have been submitted and are being reviewed. This typically takes 1–2 business days.
        </div>
        <?php elseif ($kycStatus === 'rejected'): ?>
        <div class="alert-dark-danger mb-4">
          <strong>❌ Rejected</strong> <?= e($profile['kyc_rejection_reason'] ?? 'Your submission was rejected.') ?>
          <br><span style="font-size:0.85rem">Please re-submit with valid documents below.</span>
        </div>
        <?php endif; ?>

        <?php if (!in_array($kycStatus, ['approved', 'pending'], true)): ?>

        <!-- Steps -->
        <div class="card-dark p-4 mb-4">
          <h6 class="fw-700 mb-4">Complete the following steps</h6>

          <form id="kycForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="submit_kyc">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

            <!-- Step 1: Bank Account -->
            <div class="mb-4 pb-4" style="border-bottom:1px solid var(--border)">
              <div class="d-flex align-items-center gap-2 mb-3">
                <span class="badge-accent" style="width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.75rem;flex-shrink:0">1</span>
                <strong style="font-size:0.95rem">Bank Account (NUBAN)</strong>
              </div>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label-dark">Bank</label>
                  <select name="bank_code" id="kycBankSelect" class="form-control-dark" required>
                    <option value="">Select bank…</option>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label-dark">Account Number (10 digits)</label>
                  <div class="d-flex gap-2">
                    <input type="text" name="account_number" id="kycAcctNum" class="form-control-dark"
                           maxlength="10" pattern="\d{10}" placeholder="0123456789" required
                           value="<?= e($profile['account_number'] ?? '') ?>">
                    <button type="button" class="btn btn-sm btn-outline-accent" id="kycVerifyAcctBtn">Verify</button>
                  </div>
                </div>
                <div class="col-12">
                  <div id="kycAcctResult" style="display:none;font-size:0.85rem;padding:8px 12px;border-radius:6px"></div>
                  <input type="hidden" name="account_name" id="kycAcctName" value="<?= e($profile['account_name'] ?? '') ?>">
                  <input type="hidden" name="bank_name" id="kycBankName" value="<?= e($profile['bank_name'] ?? '') ?>">
                  <?php if (!empty($profile['account_name'])): ?>
                  <div style="font-size:0.82rem;color:var(--accent);margin-top:4px">Currently saved: <?= e($profile['account_name']) ?> — <?= e($profile['bank_name'] ?? '') ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <!-- Step 2: Government ID -->
            <div class="mb-4 pb-4" style="border-bottom:1px solid var(--border)">
              <div class="d-flex align-items-center gap-2 mb-3">
                <span class="badge-accent" style="width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.75rem;flex-shrink:0">2</span>
                <strong style="font-size:0.95rem">Government-Issued ID</strong>
              </div>
              <div class="mb-3">
                <label class="form-label-dark">ID Type</label>
                <select name="id_type" id="idTypeSelect" class="form-control-dark" required>
                  <option value="">Select ID type…</option>
                  <option value="driver_license" <?= ($profile['kyc_id_type'] ?? '') === 'driver_license' ? 'selected' : '' ?>>Driver's License</option>
                  <option value="international_passport" <?= ($profile['kyc_id_type'] ?? '') === 'international_passport' ? 'selected' : '' ?>>International Passport</option>
                  <option value="nin_slip" <?= ($profile['kyc_id_type'] ?? '') === 'nin_slip' ? 'selected' : '' ?>>NIN Slip</option>
                </select>
              </div>
              <div class="mb-3" id="expiryGroup" style="display:none">
                <label class="form-label-dark">ID Expiry Date</label>
                <input type="date" name="id_expiry" id="idExpiry" class="form-control-dark"
                       min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                       value="<?= e($profile['kyc_id_expiry'] ?? '') ?>">
                <div style="font-size:0.78rem;color:var(--text-muted);margin-top:4px">Required for Driver's License and Passport. Must not be expired.</div>
              </div>
              <div class="mb-3">
                <label class="form-label-dark">Upload ID Document</label>
                <input type="file" name="id_document" id="idDocInput" class="form-control-dark"
                       accept=".jpg,.jpeg,.png,.pdf" required>
                <div style="font-size:0.78rem;color:var(--text-muted);margin-top:4px">Accepted: JPG, PNG, PDF. Max 5MB. Document must be clear and legible.</div>
                <div id="idPreview" class="mt-2"></div>
              </div>
            </div>

            <!-- Step 3: Live Snapshot -->
            <div class="mb-4">
              <div class="d-flex align-items-center gap-2 mb-3">
                <span class="badge-accent" style="width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.75rem;flex-shrink:0">3</span>
                <strong style="font-size:0.95rem">Live Selfie (Camera Required)</strong>
              </div>
              <p style="font-size:0.85rem;color:var(--text-muted)">Take a live photo of yourself holding your ID. Ensure your face and the ID are clearly visible.</p>

              <div id="cameraContainer" style="display:none;margin-bottom:16px">
                <video id="cameraVideo" style="width:100%;max-width:340px;border-radius:8px;border:1px solid var(--border)" autoplay playsinline></video>
                <div class="d-flex gap-2 mt-2">
                  <button type="button" class="btn btn-accent" id="captureBtn">📸 Take Photo</button>
                  <button type="button" class="btn btn-outline-accent" id="retakeBtn" style="display:none">↺ Retake</button>
                  <button type="button" class="btn btn-sm" id="stopCameraBtn" style="background:rgba(255,68,68,0.1);color:var(--danger);border:1px solid rgba(255,68,68,0.2);border-radius:8px;padding:6px 14px">✕ Cancel</button>
                </div>
              </div>
              <canvas id="snapshotCanvas" style="display:none"></canvas>
              <div id="snapshotPreview" class="mt-2"></div>
              <input type="hidden" name="snapshot_data" id="snapshotData">

              <div class="d-flex gap-2 mt-3">
                <button type="button" class="btn btn-outline-accent" id="openCameraBtn">📷 Open Camera</button>
                <span style="color:var(--text-muted);font-size:0.82rem;align-self:center">or</span>
                <div>
                  <label class="btn btn-sm" style="background:var(--subtle-bg);color:var(--text-secondary);border:1px solid var(--border);border-radius:8px;padding:6px 14px;cursor:pointer">
                    📁 Upload Selfie
                    <input type="file" name="snapshot_upload" id="snapshotUpload" accept=".jpg,.jpeg,.png" style="display:none">
                  </label>
                </div>
              </div>
              <div id="snapshotStatus" class="mt-2" style="font-size:0.82rem;color:var(--text-muted)"></div>
            </div>

            <div id="kycFeedback" class="mb-3"></div>
            <button type="submit" class="btn btn-accent w-100" id="kycSubmitBtn" style="padding:14px">
              Submit KYC for Review
            </button>
          </form>
        </div>

        <?php endif; ?>

        <!-- Info Card -->
        <div class="card-dark p-4">
          <h6 class="fw-700 mb-3">📋 KYC Requirements</h6>
          <ul style="list-style:none;padding:0;margin:0;font-size:0.875rem;color:var(--text-secondary)">
            <li class="mb-2">✅ Bank account name <strong style="color:var(--text-secondary)">must match</strong> your registered name</li>
            <li class="mb-2">✅ Accepted IDs: Driver's License, International Passport, NIN Slip</li>
            <li class="mb-2">✅ Driver's License and Passport require a valid expiry date</li>
            <li class="mb-2">✅ Live selfie must clearly show your face</li>
            <li class="mb-2">✅ All documents must be clear and not expired</li>
            <li class="mb-0">✅ Admin review typically takes 1–2 business days</li>
          </ul>
        </div>

      </div>
    </div>
  </div>
</div>

<script>
const csrf = <?= json_encode($csrf) ?>;
let cameraStream = null;
let snapshotTaken = false;

// Load banks
async function loadKycBanks() {
    try {
        const r = await fetch('/ajax/wallet_actions.php?action=get_banks');
        const d = await r.json();
        if (d.success && d.banks) {
            const sel = document.getElementById('kycBankSelect');
            const savedCode = <?= json_encode($profile['bank_code'] ?? '') ?>;
            d.banks.forEach(b => {
                const opt = document.createElement('option');
                opt.value = b.code;
                opt.textContent = b.name;
                opt.dataset.name = b.name;
                if (b.code === savedCode) opt.selected = true;
                sel.appendChild(opt);
            });
        }
    } catch {}
}
loadKycBanks();

// ID type expiry toggle
document.getElementById('idTypeSelect').addEventListener('change', function() {
    const expGroup = document.getElementById('expiryGroup');
    const expInput = document.getElementById('idExpiry');
    if (['driver_license', 'international_passport'].includes(this.value)) {
        expGroup.style.display = 'block';
        expInput.required = true;
    } else {
        expGroup.style.display = 'none';
        expInput.required = false;
    }
});

// Verify account
document.getElementById('kycVerifyAcctBtn').addEventListener('click', async function() {
    const bankCode = document.getElementById('kycBankSelect').value;
    const acctNum  = document.getElementById('kycAcctNum').value.trim();
    const resultEl = document.getElementById('kycAcctResult');
    if (!bankCode || acctNum.length !== 10) {
        resultEl.style.cssText = 'display:block;background:rgba(255,68,68,0.1);color:var(--danger);';
        resultEl.textContent = 'Select a bank and enter 10-digit account number.';
        return;
    }
    this.disabled = true; this.textContent = 'Verifying…';
    try {
        const r = await fetch('/ajax/wallet_actions.php', {
            method:'POST', body: new URLSearchParams({ action:'verify_account', account_number:acctNum, bank_code:bankCode, csrf_token:csrf })
        });
        const d = await r.json();
        if (d.success) {
            resultEl.style.cssText = 'display:block;background:rgba(204,255,0,0.1);color:var(--accent);';
            resultEl.textContent = '✅ ' + d.account_name;
            document.getElementById('kycAcctName').value = d.account_name;
            const sel = document.getElementById('kycBankSelect');
            document.getElementById('kycBankName').value = sel.options[sel.selectedIndex]?.textContent || '';
        } else {
            resultEl.style.cssText = 'display:block;background:rgba(255,68,68,0.1);color:var(--danger);';
            resultEl.textContent = '❌ ' + (d.message || 'Verification failed.');
        }
    } catch {
        resultEl.style.cssText = 'display:block;background:rgba(255,68,68,0.1);color:var(--danger);';
        resultEl.textContent = 'Network error.';
    }
    this.disabled = false; this.textContent = 'Verify';
});

// ID preview
document.getElementById('idDocInput').addEventListener('change', function() {
    const preview = document.getElementById('idPreview');
    if (this.files[0]) {
        const f = this.files[0];
        if (f.name.match(/\.(jpg|jpeg|png)$/i)) {
            const url = URL.createObjectURL(f);
            preview.innerHTML = `<img src="${url}" style="max-width:200px;max-height:200px;border-radius:8px;border:1px solid var(--border);margin-top:8px;object-fit:cover">`;
        } else {
            preview.innerHTML = `<div style="font-size:0.82rem;color:var(--accent);margin-top:8px">📄 ${f.name}</div>`;
        }
    }
});

// Camera
document.getElementById('openCameraBtn').addEventListener('click', async function() {
    try {
        cameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: {ideal:640}, height: {ideal:480} } });
        document.getElementById('cameraVideo').srcObject = cameraStream;
        document.getElementById('cameraContainer').style.display = 'block';
        document.getElementById('openCameraBtn').style.display = 'none';
    } catch(e) {
        document.getElementById('snapshotStatus').textContent = 'Camera access denied. Please allow camera access or upload a photo.';
        document.getElementById('snapshotStatus').style.color = 'var(--danger)';
    }
});

document.getElementById('stopCameraBtn').addEventListener('click', function() {
    if (cameraStream) { cameraStream.getTracks().forEach(t => t.stop()); cameraStream = null; }
    document.getElementById('cameraContainer').style.display = 'none';
    document.getElementById('openCameraBtn').style.display = 'inline-block';
});

document.getElementById('captureBtn').addEventListener('click', function() {
    const video = document.getElementById('cameraVideo');
    const canvas = document.getElementById('snapshotCanvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);
    const dataUrl = canvas.toDataURL('image/jpeg', 0.9);
    document.getElementById('snapshotData').value = dataUrl;
    document.getElementById('snapshotPreview').innerHTML = `<img src="${dataUrl}" style="max-width:200px;border-radius:8px;border:2px solid var(--accent);margin-top:8px">`;
    document.getElementById('snapshotStatus').textContent = '✅ Snapshot captured.';
    document.getElementById('snapshotStatus').style.color = 'var(--accent)';
    document.getElementById('retakeBtn').style.display = 'inline-block';
    document.getElementById('captureBtn').style.display = 'none';
    snapshotTaken = true;
    if (cameraStream) { cameraStream.getTracks().forEach(t => t.stop()); cameraStream = null; }
    document.getElementById('cameraContainer').style.display = 'none';
    document.getElementById('openCameraBtn').style.display = 'inline-block';
});

document.getElementById('retakeBtn').addEventListener('click', async function() {
    document.getElementById('snapshotData').value = '';
    document.getElementById('snapshotPreview').innerHTML = '';
    document.getElementById('snapshotStatus').textContent = '';
    this.style.display = 'none';
    document.getElementById('captureBtn').style.display = 'inline-block';
    snapshotTaken = false;
    // Reopen camera
    document.getElementById('openCameraBtn').click();
});

// Snapshot file upload
document.getElementById('snapshotUpload').addEventListener('change', function() {
    if (this.files[0]) {
        const url = URL.createObjectURL(this.files[0]);
        document.getElementById('snapshotPreview').innerHTML = `<img src="${url}" style="max-width:200px;border-radius:8px;border:2px solid var(--accent);margin-top:8px">`;
        document.getElementById('snapshotStatus').textContent = '✅ Selfie uploaded: ' + this.files[0].name;
        document.getElementById('snapshotStatus').style.color = 'var(--accent)';
        snapshotTaken = true;
    }
});

// Form submit
document.getElementById('kycForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const fb = document.getElementById('kycFeedback');
    const btn = document.getElementById('kycSubmitBtn');

    // Validate snapshot
    const snapshotData = document.getElementById('snapshotData').value;
    const snapshotUpload = document.getElementById('snapshotUpload').files[0];
    if (!snapshotTaken && !snapshotUpload) {
        fb.innerHTML = '<div class="alert-dark-danger">Please take a live photo or upload a selfie.</div>';
        return;
    }

    btn.disabled = true; btn.textContent = 'Submitting…'; fb.innerHTML = '';
    try {
        const formData = new FormData(this);
        const r = await fetch('/ajax/kyc_actions.php', { method:'POST', body: formData });
        const d = await r.json();
        if (d.success) {
            fb.innerHTML = '<div class="alert-dark-success">✅ ' + d.message + '</div>';
            btn.textContent = 'Submitted ✅';
            setTimeout(() => location.reload(), 1500);
        } else {
            fb.innerHTML = '<div class="alert-dark-danger">' + (d.message||'Error') + '</div>';
            btn.disabled = false; btn.textContent = 'Submit KYC for Review';
        }
    } catch {
        fb.innerHTML = '<div class="alert-dark-danger">Network error.</div>';
        btn.disabled = false; btn.textContent = 'Submit KYC for Review';
    }
});
</script>

<?php renderFooter(); ?>
