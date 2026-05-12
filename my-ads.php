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

$csrf   = generateCsrfToken();
$user   = $_SESSION['user'] ?? [];
$userId = (int)($_SESSION['user_id'] ?? 0);

$ads = [];
try {
    $stmt = db()->prepare(
        "SELECT ma.*, ap.name AS package_name, ap.price AS package_price
         FROM movie_ads ma
         LEFT JOIN ad_packages ap ON ap.id = ma.package_id
         WHERE ma.user_id = ?
         ORDER BY ma.created_at DESC"
    );
    $stmt->execute([$userId]);
    $ads = $stmt->fetchAll();
} catch (Throwable) {}

$siteUrl = rtrim(getSetting('site_url', ''), '/');

renderHead('My Movie Ads');
renderNav(true, $user);
?>
<div style="min-height:80vh;padding:40px 0">
<div class="container">

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <h1 class="fw-900 mb-0" style="font-size:1.8rem">📋 My Movie Ads</h1>
        <a href="/advertise" class="btn btn-accent btn-sm">+ New Ad</a>
    </div>

    <div id="myAdsFeedback" class="mb-3"></div>

    <?php if (empty($ads)): ?>
    <div class="card-dark p-5 text-center">
        <div style="font-size:3rem;margin-bottom:12px">🎬</div>
        <h5 class="fw-700">No ads yet</h5>
        <p class="text-muted" style="font-size:0.9rem;margin-bottom:20px">You haven't submitted any movie ads. Promote your movie today!</p>
        <a href="/advertise" class="btn btn-accent">Advertise Your Movie</a>
    </div>
    <?php else: ?>
    <div class="table-responsive">
    <table class="table table-dark-custom">
        <thead>
            <tr>
                <th>Movie</th>
                <th>Package</th>
                <th>Payment</th>
                <th>Status</th>
                <th>Dates</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($ads as $ad): ?>
        <?php
            $statusClass = match($ad['status']) {
                'approved'       => 'badge-success',
                'rejected'       => 'badge-danger',
                'pending_review' => 'badge-warning',
                'expired'        => 'badge-muted',
                default          => 'badge-muted',
            };
            $payClass = match($ad['payment_status']) {
                'paid'                 => 'badge-success',
                'pending_verification' => 'badge-warning',
                default                => 'badge-muted',
            };
            $isExpired  = !empty($ad['expires_at']) && strtotime($ad['expires_at']) < time();
            $showPayNow = $ad['payment_status'] === 'unpaid' && in_array($ad['status'], ['draft'], true);
            $showProof  = $ad['payment_method'] === 'manual' && $ad['payment_status'] === 'pending_verification' && empty($ad['manual_deposit_proof']);
        ?>
        <tr>
            <td>
                <div class="fw-700" style="font-size:0.9rem"><?= e($ad['movie_title']) ?></div>
                <?php if ($ad['genre']): ?>
                <div style="font-size:0.78rem;color:#888"><?= e($ad['genre']) ?></div>
                <?php endif; ?>
            </td>
            <td>
                <div style="font-size:0.85rem"><?= e($ad['package_name'] ?? '—') ?></div>
                <?php if ($ad['package_price']): ?>
                <div style="font-size:0.78rem;color:var(--accent)">₦<?= number_format((float)$ad['package_price'], 2) ?></div>
                <?php endif; ?>
            </td>
            <td>
                <span class="<?= $payClass ?>" style="font-size:0.72rem">
                    <?= e(str_replace('_', ' ', ucfirst($ad['payment_status']))) ?>
                </span>
                <div style="font-size:0.72rem;color:#888"><?= e(ucfirst($ad['payment_method'])) ?></div>
            </td>
            <td>
                <span class="<?= $statusClass ?>" style="font-size:0.72rem">
                    <?= e(str_replace('_', ' ', ucfirst($ad['status']))) ?>
                </span>
                <?php if ($ad['review_note']): ?>
                <div style="font-size:0.72rem;color:var(--danger);margin-top:2px"><?= e(mb_strimwidth($ad['review_note'], 0, 40, '…')) ?></div>
                <?php endif; ?>
            </td>
            <td style="font-size:0.78rem;color:#888">
                <div>Submitted: <?= e(formatDate($ad['created_at'], 'M j, Y')) ?></div>
                <?php if ($ad['starts_at']): ?>
                <div>Started: <?= e(formatDate($ad['starts_at'], 'M j, Y')) ?></div>
                <?php endif; ?>
                <?php if ($ad['expires_at']): ?>
                <div style="color:<?= $isExpired ? 'var(--danger)' : '#888' ?>">
                    Expires: <?= e(formatDate($ad['expires_at'], 'M j, Y')) ?>
                    <?= $isExpired ? ' ⚠' : '' ?>
                </div>
                <?php endif; ?>
            </td>
            <td>
                <div class="d-flex flex-column gap-1">
                    <?php if ($showPayNow): ?>
                    <button class="btn btn-sm btn-accent pay-now-btn"
                            data-id="<?= (int)$ad['id'] ?>"
                            style="font-size:0.75rem">
                        💳 Pay Now
                    </button>
                    <?php endif; ?>
                    <?php if ($showProof): ?>
                    <button class="btn btn-sm btn-outline-accent upload-proof-btn"
                            data-id="<?= (int)$ad['id'] ?>"
                            style="font-size:0.75rem">
                        📄 Upload Proof
                    </button>
                    <?php endif; ?>
                    <?php if ($ad['poster_path']): ?>
                    <a href="<?= e($siteUrl . $ad['poster_path']) ?>" target="_blank"
                       class="btn btn-sm btn-outline-accent" style="font-size:0.75rem">
                        🖼 Poster
                    </a>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- Deposit Proof Modal -->
<div class="modal fade modal-dark" id="proofModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title fw-700">Upload Deposit Proof</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="proofForm" enctype="multipart/form-data">
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
          <div id="proofFeedback" class="mb-2"></div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-accent" id="proofSubmitBtn">Upload Proof</button>
            <button type="button" class="btn btn-outline-accent" data-bs-dismiss="modal">Cancel</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php renderFooter(); ?>
<script>
const csrf = '<?= e($csrf) ?>';
const proofModal = new bootstrap.Modal(document.getElementById('proofModal'));

document.querySelectorAll('.pay-now-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const id  = this.dataset.id;
        const btn = this;
        btn.disabled = true; btn.textContent = 'Initializing…';
        try {
            const r = await fetch('/ajax/ad_actions.php', {
                method: 'POST',
                body: new URLSearchParams({csrf_token: csrf, action: 'init_ad_payment', ad_id: id})
            });
            const d = await r.json();
            if (d.success && d.authorization_url) {
                window.location.href = d.authorization_url;
            } else {
                showFeedback(d.message || 'Payment initialization failed.', 'danger');
                btn.disabled = false; btn.textContent = '💳 Pay Now';
            }
        } catch {
            showFeedback('Network error.', 'danger');
            btn.disabled = false; btn.textContent = '💳 Pay Now';
        }
    });
});

document.querySelectorAll('.upload-proof-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('proofAdId').value = this.dataset.id;
        document.getElementById('proofFeedback').innerHTML = '';
        document.getElementById('proofForm').reset();
        document.getElementById('proofAdId').value = this.dataset.id;
        proofModal.show();
    });
});

document.getElementById('proofForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const fb  = document.getElementById('proofFeedback');
    const btn = document.getElementById('proofSubmitBtn');
    btn.disabled = true; btn.textContent = 'Uploading…'; fb.innerHTML = '';
    try {
        const r = await fetch('/ajax/ad_actions.php', {
            method: 'POST',
            body: new FormData(this)
        });
        const d = await r.json();
        if (d.success) {
            fb.innerHTML = '<div class="alert-dark-success" style="font-size:0.82rem">✅ ' + d.message + '</div>';
            btn.textContent = 'Done ✅';
            setTimeout(() => location.reload(), 1200);
        } else {
            fb.innerHTML = '<div class="alert-dark-danger" style="font-size:0.82rem">' + (d.message || 'Error') + '</div>';
            btn.disabled = false; btn.textContent = 'Upload Proof';
        }
    } catch {
        fb.innerHTML = '<div class="alert-dark-danger" style="font-size:0.82rem">Network error.</div>';
        btn.disabled = false; btn.textContent = 'Upload Proof';
    }
});

function showFeedback(msg, type) {
    const fb = document.getElementById('myAdsFeedback');
    fb.innerHTML = '<div class="alert-dark-' + type + '" style="font-size:0.85rem">' + msg + '</div>';
    setTimeout(() => { fb.innerHTML = ''; }, 4000);
}
</script>
