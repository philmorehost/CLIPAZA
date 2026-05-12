<?php
declare(strict_types=1);
session_start();

$root = dirname(__FILE__);
require_once $root . '/config/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/security.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/layout.php';

requireUser();

$csrf = generateCsrfToken();
$user = $_SESSION['user'] ?? [];

$packages = [];
try {
    $stmt = db()->prepare(
        "SELECT * FROM ad_packages WHERE is_active = 1 ORDER BY sort_order ASC, id ASC"
    );
    $stmt->execute();
    $packages = $stmt->fetchAll();
    foreach ($packages as &$pkg) {
        $pkg['features']        = json_decode($pkg['features'] ?? '[]', true) ?: [];
        $pkg['placement_zones'] = json_decode($pkg['placement_zones'] ?? '[]', true) ?: [];
    }
    unset($pkg);
} catch (Throwable) {}

$bankName    = getSetting('ad_bank_name', '');
$bankAccount = getSetting('ad_bank_account', '');
$bankNumber  = getSetting('ad_bank_number', '');

renderHead('Advertise Your Movie');
renderNav(true, $user);
?>
<div style="min-height:80vh;padding:40px 0">
<div class="container">

    <!-- Hero -->
    <div class="text-center mb-5">
        <div style="font-size:3rem;margin-bottom:12px">🎬</div>
        <h1 class="fw-900" style="font-size:2.5rem;letter-spacing:-1px">Promote Your Movie</h1>
        <p class="text-muted" style="max-width:560px;margin:12px auto 0;font-size:1rem">
            Reach thousands of movie lovers on Clipaza. Choose a plan, upload your materials, and get your movie in front of the right audience.
        </p>
    </div>

    <!-- Packages -->
    <div id="packagesSection">
        <h2 class="fw-700 mb-4" style="font-size:1.25rem">Available Packages</h2>
        <?php if (empty($packages)): ?>
        <div class="card-dark p-5 text-center mb-5">
            <div style="font-size:2rem;margin-bottom:8px">📦</div>
            <p class="text-muted">No advertising packages are available right now. Check back soon.</p>
        </div>
        <?php else: ?>
        <div class="row g-4 mb-5">
            <?php foreach ($packages as $pkg): ?>
            <div class="col-md-4">
                <div class="card-dark p-4 h-100 d-flex flex-column" style="border:1px solid #222;border-radius:12px">
                    <div class="fw-700 mb-1" style="font-size:1.1rem"><?= e($pkg['name']) ?></div>
                    <?php if ($pkg['description']): ?>
                    <div class="text-muted mb-3" style="font-size:0.85rem"><?= e($pkg['description']) ?></div>
                    <?php endif; ?>
                    <div class="fw-900 mb-1" style="font-size:1.8rem;color:var(--accent)">₦<?= number_format((float)$pkg['price'], 0) ?></div>
                    <div class="text-muted mb-3" style="font-size:0.82rem"><?= (int)$pkg['duration_days'] ?> days</div>
                    <?php if (!empty($pkg['features'])): ?>
                    <ul style="padding-left:16px;margin-bottom:12px;font-size:0.85rem;color:#ccc">
                        <?php foreach ($pkg['features'] as $feat): ?>
                        <li style="margin-bottom:4px">✓ <?= e($feat) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                    <?php if (!empty($pkg['placement_zones'])): ?>
                    <div class="mb-3">
                        <?php foreach ($pkg['placement_zones'] as $zone): ?>
                        <span class="badge-muted" style="font-size:0.7rem;margin:2px"><?= e(str_replace('_', ' ', ucfirst($zone))) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <div class="mt-auto">
                        <button class="btn btn-accent w-100 choose-pkg-btn"
                                data-id="<?= (int)$pkg['id'] ?>"
                                data-name="<?= e($pkg['name']) ?>"
                                data-price="<?= e($pkg['price']) ?>">
                            Choose This Plan
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Ad Submission Form -->
    <div id="adFormSection" style="display:none">
        <div class="d-flex align-items-center gap-3 mb-4">
            <button id="backToPackages" class="btn btn-outline-accent btn-sm">← Back to Packages</button>
            <h2 class="fw-700 mb-0" style="font-size:1.25rem">Submit Your Movie Ad</h2>
        </div>

        <div class="card-dark p-4 mb-3" id="selectedPkgInfo" style="border:1px solid #2a2a2a;border-radius:10px">
            <span class="text-muted" style="font-size:0.85rem">Selected package:</span>
            <span id="selectedPkgName" class="fw-700 ms-2" style="color:var(--accent)"></span>
            <span id="selectedPkgPrice" class="text-muted ms-2" style="font-size:0.85rem"></span>
        </div>

        <form id="adSubmitForm" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="submit_ad">
            <input type="hidden" name="package_id" id="selectedPackageId">

            <div class="row g-4">
                <!-- Left column -->
                <div class="col-md-8">
                    <div class="card-dark p-4 mb-4" style="border:1px solid #1e1e1e;border-radius:10px">
                        <h6 class="fw-700 mb-3">Movie Information</h6>
                        <div class="mb-3">
                            <label class="form-label-dark">Movie Title <span style="color:var(--danger)">*</span></label>
                            <input type="text" name="movie_title" class="form-control-dark" required maxlength="255"
                                   placeholder="Enter your movie title">
                        </div>
                        <div class="mb-3">
                            <label class="form-label-dark">Tagline</label>
                            <input type="text" name="tagline" class="form-control-dark" maxlength="500"
                                   placeholder="A captivating one-liner">
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label-dark">Genre</label>
                                <input type="text" name="genre" class="form-control-dark" maxlength="100"
                                       placeholder="Action, Drama, Comedy…">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-dark">Release Date</label>
                                <input type="date" name="release_date" class="form-control-dark">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label-dark">Description</label>
                            <textarea name="description" class="form-control-dark" rows="4"
                                      placeholder="Brief synopsis or promotional description…"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label-dark">Trailer URL (YouTube / Vimeo)</label>
                            <input type="url" name="trailer_url" class="form-control-dark" maxlength="2000"
                                   placeholder="https://youtube.com/watch?v=...">
                        </div>
                    </div>

                    <div class="card-dark p-4 mb-4" style="border:1px solid #1e1e1e;border-radius:10px">
                        <h6 class="fw-700 mb-3">Contact Information</h6>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label-dark">Contact Email</label>
                                <input type="email" name="contact_email" class="form-control-dark" maxlength="255"
                                       placeholder="producer@example.com">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-dark">Contact Phone</label>
                                <input type="text" name="contact_phone" class="form-control-dark" maxlength="30"
                                       placeholder="+234 800 000 0000">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label-dark">Website URL</label>
                            <input type="url" name="website_url" class="form-control-dark" maxlength="2000"
                                   placeholder="https://yourmovie.com">
                        </div>
                    </div>
                </div>

                <!-- Right column -->
                <div class="col-md-4">
                    <div class="card-dark p-4 mb-4" style="border:1px solid #1e1e1e;border-radius:10px">
                        <h6 class="fw-700 mb-3">Media Assets</h6>
                        <div class="mb-4">
                            <label class="form-label-dark">Movie Poster <span style="color:var(--danger)">*</span></label>
                            <input type="file" name="movie_poster" id="posterInput" class="form-control-dark" accept="image/*" required>
                            <div style="font-size:0.75rem;color:#888;margin-top:4px">JPG/PNG/WebP, max 5 MB</div>
                            <div id="posterPreview" class="mt-2" style="display:none">
                                <img id="posterImg" src="" alt="Poster preview"
                                     style="max-width:100%;max-height:200px;border-radius:8px;border:1px solid #222;object-fit:contain">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label-dark">E-Flyer (optional)</label>
                            <input type="file" name="movie_flyer" id="flyerInput" class="form-control-dark" accept="image/*">
                            <div style="font-size:0.75rem;color:#888;margin-top:4px">JPG/PNG/WebP, max 5 MB</div>
                            <div id="flyerPreview" class="mt-2" style="display:none">
                                <img id="flyerImg" src="" alt="Flyer preview"
                                     style="max-width:100%;max-height:200px;border-radius:8px;border:1px solid #222;object-fit:contain">
                            </div>
                        </div>
                    </div>

                    <div class="card-dark p-4 mb-4" style="border:1px solid #1e1e1e;border-radius:10px">
                        <h6 class="fw-700 mb-3">Payment Method</h6>
                        <div class="mb-2">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="payment_method" id="pmOnline"
                                       value="online" checked>
                                <label class="form-check-label text-theme" for="pmOnline">
                                    💳 Pay Online (Paystack)
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="payment_method" id="pmManual"
                                       value="manual">
                                <label class="form-check-label text-theme" for="pmManual">
                                    🏦 Manual Bank Transfer
                                </label>
                            </div>
                        </div>
                        <!-- Manual bank info -->
                        <div id="manualBankInfo" style="display:none;margin-top:12px">
                            <div style="background:#0d0d0d;border-radius:8px;padding:12px;border:1px solid #222;font-size:0.85rem">
                                <?php if ($bankName || $bankNumber || $bankAccount): ?>
                                <div class="fw-700 mb-2" style="font-size:0.8rem;color:#aaa;text-transform:uppercase">Bank Details</div>
                                <?php if ($bankName): ?>
                                <div>🏦 Bank: <strong><?= e($bankName) ?></strong></div>
                                <?php endif; ?>
                                <?php if ($bankAccount): ?>
                                <div>👤 Account Name: <strong><?= e($bankAccount) ?></strong></div>
                                <?php endif; ?>
                                <?php if ($bankNumber): ?>
                                <div>🔢 Account No: <strong style="color:var(--accent)"><?= e($bankNumber) ?></strong></div>
                                <?php endif; ?>
                                <?php else: ?>
                                <div class="text-muted">Bank details will be provided by the admin. Please contact support.</div>
                                <?php endif; ?>
                                <div class="mt-2" style="font-size:0.78rem;color:#888">
                                    After submitting, you'll be prompted to upload your payment proof.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="formFeedback" class="mb-3"></div>
                    <button type="submit" class="btn btn-accent w-100" id="submitAdBtn" style="font-size:1rem;padding:12px">
                        🚀 Submit Ad
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Post-submit: Manual deposit proof upload -->
    <div id="depositProofSection" style="display:none">
        <div class="card-dark p-4" style="max-width:520px;margin:0 auto;border:1px solid #222;border-radius:12px">
            <div style="font-size:2rem;text-align:center;margin-bottom:12px">✅</div>
            <h5 class="fw-700 text-center mb-2">Ad Submitted!</h5>
            <p class="text-muted text-center" style="font-size:0.9rem;margin-bottom:20px">
                Your movie ad has been received. Please upload your bank transfer proof to complete your submission.
            </p>
            <form id="proofUploadForm" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="upload_deposit_proof">
                <input type="hidden" name="ad_id" id="proofAdId">
                <div class="mb-3">
                    <label class="form-label-dark">Transfer Amount (₦) <span style="color:var(--danger)">*</span></label>
                    <input type="number" name="deposit_amount" class="form-control-dark" min="1" step="0.01" required>
                </div>
                <div class="mb-3">
                    <label class="form-label-dark">Deposit Proof (screenshot / receipt) <span style="color:var(--danger)">*</span></label>
                    <input type="file" name="deposit_proof" class="form-control-dark" accept="image/*" required>
                    <div style="font-size:0.75rem;color:#888;margin-top:4px">JPG/PNG/WebP, max 5 MB</div>
                </div>
                <div class="mb-3">
                    <label class="form-label-dark">Note (optional)</label>
                    <input type="text" name="deposit_note" class="form-control-dark" maxlength="500"
                           placeholder="Transaction ID or any relevant note">
                </div>
                <div id="proofFeedback" class="mb-3"></div>
                <button type="submit" class="btn btn-accent w-100" id="proofUploadBtn">Upload Proof</button>
            </form>
        </div>
    </div>

</div>
</div>
<?php renderFooter(); ?>
<script>
const csrf = '<?= e($csrf) ?>';

// Image preview helper
function attachPreview(inputId, imgId, previewId) {
    document.getElementById(inputId).addEventListener('change', function() {
        const file = this.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = e => {
            document.getElementById(imgId).src = e.target.result;
            document.getElementById(previewId).style.display = '';
        };
        reader.readAsDataURL(file);
    });
}
attachPreview('posterInput', 'posterImg', 'posterPreview');
attachPreview('flyerInput', 'flyerImg', 'flyerPreview');

// Payment method toggle
document.querySelectorAll('input[name="payment_method"]').forEach(r => {
    r.addEventListener('change', () => {
        document.getElementById('manualBankInfo').style.display =
            document.getElementById('pmManual').checked ? '' : 'none';
    });
});

// Package selection
document.querySelectorAll('.choose-pkg-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('selectedPackageId').value = this.dataset.id;
        document.getElementById('selectedPkgName').textContent = this.dataset.name;
        document.getElementById('selectedPkgPrice').textContent = '— ₦' + parseFloat(this.dataset.price).toLocaleString();
        document.getElementById('packagesSection').style.display = 'none';
        document.getElementById('adFormSection').style.display = '';
        window.scrollTo(0, 0);
    });
});

document.getElementById('backToPackages').addEventListener('click', () => {
    document.getElementById('adFormSection').style.display = 'none';
    document.getElementById('packagesSection').style.display = '';
    window.scrollTo(0, 0);
});

// Ad form submission
document.getElementById('adSubmitForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const fb  = document.getElementById('formFeedback');
    const btn = document.getElementById('submitAdBtn');
    btn.disabled = true; btn.textContent = 'Submitting…'; fb.innerHTML = '';

    try {
        const r = await fetch('/ajax/ad_actions.php', {
            method: 'POST',
            body: new FormData(this)
        });
        const d = await r.json();
        if (!d.success) {
            fb.innerHTML = '<div class="alert-dark-danger" style="font-size:0.85rem">' + (d.message || 'Error') + '</div>';
            btn.disabled = false; btn.textContent = '🚀 Submit Ad';
            return;
        }
        if (d.payment_method === 'online' && d.authorization_url) {
            window.location.href = d.authorization_url;
            return;
        }
        // Manual payment
        document.getElementById('proofAdId').value = d.ad_id;
        document.getElementById('adFormSection').style.display = 'none';
        document.getElementById('depositProofSection').style.display = '';
        window.scrollTo(0, 0);
    } catch {
        fb.innerHTML = '<div class="alert-dark-danger" style="font-size:0.85rem">Network error. Please try again.</div>';
        btn.disabled = false; btn.textContent = '🚀 Submit Ad';
    }
});

// Deposit proof upload
document.getElementById('proofUploadForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const fb  = document.getElementById('proofFeedback');
    const btn = document.getElementById('proofUploadBtn');
    btn.disabled = true; btn.textContent = 'Uploading…'; fb.innerHTML = '';

    try {
        const r = await fetch('/ajax/ad_actions.php', {
            method: 'POST',
            body: new FormData(this)
        });
        const d = await r.json();
        if (d.success) {
            fb.innerHTML = '<div class="alert-dark-success" style="font-size:0.85rem">✅ ' + d.message + '</div>';
            btn.textContent = 'Uploaded ✅';
            setTimeout(() => { window.location.href = '/my-ads'; }, 1500);
        } else {
            fb.innerHTML = '<div class="alert-dark-danger" style="font-size:0.85rem">' + (d.message || 'Error') + '</div>';
            btn.disabled = false; btn.textContent = 'Upload Proof';
        }
    } catch {
        fb.innerHTML = '<div class="alert-dark-danger" style="font-size:0.85rem">Network error.</div>';
        btn.disabled = false; btn.textContent = 'Upload Proof';
    }
});
</script>
