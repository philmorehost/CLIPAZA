<?php
declare(strict_types=1);
session_start();

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/security.php';
require_once $root . '/includes/auth.php';

requireAdmin();

$csrf      = generateCsrfToken();
$activeTab = $_GET['tab'] ?? 'general';

// Load current site settings
$siteSettings = [];
try {
    $db   = db();
    $rows = $db->query('SELECT setting_key, setting_value FROM site_settings')->fetchAll();
    foreach ($rows as $row) {
        $siteSettings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Throwable) {}

function ss(array $settings, string $key, string $default = ''): string {
    return htmlspecialchars($settings[$key] ?? $default, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — Clipaza Admin</title>
    <meta name="csrf" content="<?= htmlspecialchars($csrf) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>

<!-- Sidebar -->
<nav class="admin-sidebar">
    <div class="sidebar-brand">Clipa<span>za</span></div>
    <div class="sidebar-nav">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a href="index.php" class="nav-link">
                    <span class="nav-icon">⊞</span> Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a href="security.php" class="nav-link">
                    <span class="nav-icon">🛡</span> Security
                </a>
            </li>
            <li class="nav-item">
                <a href="users.php" class="nav-link">
                    <span class="nav-icon">👥</span> Users
                </a>
            </li>
            <li class="nav-item">
                <a href="contests.php" class="nav-link">
                    <span class="nav-icon">🏆</span> Contests
                </a>
            </li>
            <li class="nav-item">
                <a href="featured-contests.php" class="nav-link">
                    <span class="nav-icon">⭐</span> Featured
                </a>
            </li>
            <li class="nav-item">
                <a href="payouts.php" class="nav-link">
                    <span class="nav-icon">💸</span> Payouts
                </a>
            </li>
            <li class="nav-item">
                <a href="kyc.php" class="nav-link">
                    <span class="nav-icon">🪪</span> KYC
                </a>
            </li>
            <li class="nav-item"><a href="ad-packages.php" class="nav-link"><span class="nav-icon">📦</span> Ad Packages</a></li>
            <li class="nav-item"><a href="movie-ads.php" class="nav-link"><span class="nav-icon">🎞</span> Movie Ads</a></li>
            <li class="nav-item">
                <a href="settings.php" class="nav-link active">
                    <span class="nav-icon">⚙</span> Settings
                </a>
            </li>
            <li class="nav-item">
                <a href="profile.php" class="nav-link">
                    <span class="nav-icon">👤</span> Profile
                </a>
            </li>
        </ul>
        <hr class="divider-dark mx-3">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a href="logout.php" class="nav-link" style="color:var(--danger);">
                    <span class="nav-icon">⇤</span> Logout
                </a>
            </li>
        </ul>
    </div>
</nav>

<!-- Main -->
<main class="admin-main">
    <div class="admin-topbar">
        <div class="d-flex align-items-center gap-3">
            <button id="sidebarToggle" class="btn d-lg-none" style="color:#888;background:rgba(255,255,255,0.05);border-radius:8px;padding:6px 10px;">☰</button>
            <h1>Site Settings</h1>
        </div>
        <a href="index.php" style="font-size:0.8rem;color:#888;">← Dashboard</a>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs-dark mb-4">
        <?php
        $tabs = [
            'general' => '🌐 General',
            'seo'     => '🔍 SEO',
            'payment' => '💳 Payment',
            'code'    => '💻 Code Injection',
            'ads'     => '📢 Ads',
        ];
        foreach ($tabs as $key => $label):
        ?>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === $key ? 'active' : '' ?>"
               href="?tab=<?= $key ?>">
                <?= htmlspecialchars($label) ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>

    <div id="settingsAlert" style="display:none;" class="mb-3"></div>

    <!-- TAB: GENERAL -->
    <?php if ($activeTab === 'general'): ?>
    <form id="settingsForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="save_general">

        <div class="row g-4">
            <div class="col-md-8">
                <div class="card-dark">
                    <div class="card-header">General Settings</div>
                    <div class="card-body">
                        <div class="mb-4">
                            <label class="form-label-dark">Site Name</label>
                            <input type="text" name="site_name" class="form-control form-control-dark"
                                   value="<?= ss($siteSettings, 'site_name', 'Clipaza') ?>" required maxlength="100">
                            <div style="font-size:0.78rem;color:#888;margin-top:6px;">Displayed in the browser tab and navbar.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card-dark mb-4">
                    <div class="card-header">Site Logo</div>
                    <div class="card-body">
                        <?php if (!empty($siteSettings['site_logo'])): ?>
                        <div class="mb-3 text-center">
                            <img src="<?= ss($siteSettings, 'site_logo') ?>" alt="Logo"
                                 style="max-height:60px;max-width:100%;border-radius:6px;background:#1a1a1a;padding:8px;">
                        </div>
                        <?php endif; ?>
                        <label class="form-label-dark">Upload Logo (.png, .jpg, .jpeg)</label>
                        <input type="file" name="site_logo" class="form-control form-control-dark" accept=".png,.jpg,.jpeg">
                        <div style="font-size:0.78rem;color:#888;margin-top:6px;">Max 2MB. Stored at /uploads/logo/</div>
                    </div>
                </div>

                <div class="card-dark">
                    <div class="card-header">Favicon</div>
                    <div class="card-body">
                        <?php if (!empty($siteSettings['site_favicon'])): ?>
                        <div class="mb-3 text-center">
                            <img src="<?= ss($siteSettings, 'site_favicon') ?>" alt="Favicon"
                                 style="max-height:32px;max-width:32px;border-radius:4px;background:#1a1a1a;padding:4px;">
                        </div>
                        <?php endif; ?>
                        <label class="form-label-dark">Upload Favicon (.png, .jpg, .ico)</label>
                        <input type="file" name="site_favicon" class="form-control form-control-dark" accept=".png,.jpg,.jpeg,.ico">
                        <div style="font-size:0.78rem;color:#888;margin-top:6px;">Max 2MB. Stored at /uploads/favicon/</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4">
            <button type="submit" class="btn btn-accent">Save General Settings</button>
        </div>
    </form>

    <!-- TAB: SEO -->
    <?php elseif ($activeTab === 'seo'): ?>
    <form id="settingsForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="save_seo">

        <div class="card-dark mb-4">
            <div class="card-header">SEO Settings</div>
            <div class="card-body">
                <div class="mb-4">
                    <label class="form-label-dark">Meta Title</label>
                    <input type="text" name="seo_title" class="form-control form-control-dark"
                           value="<?= ss($siteSettings, 'seo_title') ?>" maxlength="200"
                           placeholder="Clipaza — Earn Money Clipping Videos">
                    <div style="font-size:0.78rem;color:#888;margin-top:6px;">Overrides the default page title in search engines.</div>
                </div>
                <div class="mb-4">
                    <label class="form-label-dark">Meta Description</label>
                    <textarea name="seo_description" class="form-control form-control-dark" rows="3"
                              maxlength="500" placeholder="Turn viral moments into real income..."><?= ss($siteSettings, 'seo_description') ?></textarea>
                    <div style="font-size:0.78rem;color:#888;margin-top:6px;">Shown as the snippet in search results. Recommended 150–160 characters.</div>
                </div>
                <div class="mb-4">
                    <label class="form-label-dark">Meta Keywords</label>
                    <input type="text" name="seo_keywords" class="form-control form-control-dark"
                           value="<?= ss($siteSettings, 'seo_keywords') ?>" maxlength="500"
                           placeholder="clipaza, earn money, clipping videos, creators">
                    <div style="font-size:0.78rem;color:#888;margin-top:6px;">Comma-separated keywords.</div>
                </div>
                <div class="mb-4">
                    <label class="form-label-dark">Open Graph Image URL</label>
                    <input type="url" name="og_image_url" class="form-control form-control-dark"
                           value="<?= ss($siteSettings, 'og_image_url') ?>" maxlength="500"
                           placeholder="https://example.com/og-image.png">
                    <div style="font-size:0.78rem;color:#888;margin-top:6px;">Image shown when sharing on social media. Recommended 1200×630px.</div>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-accent">Save SEO Settings</button>
    </form>

    <!-- TAB: PAYMENT -->
    <?php elseif ($activeTab === 'payment'): ?>
    <form id="settingsForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="save_payment">

        <div class="card-dark mb-4">
            <div class="card-header">Paystack API Keys</div>
            <div class="card-body">
                <div class="mb-4">
                    <label class="form-label-dark">Paystack Public Key</label>
                    <input type="text" name="paystack_public_key" class="form-control form-control-dark"
                           value="<?= ss($siteSettings, 'paystack_public_key') ?>"
                           placeholder="pk_live_... or pk_test_...">
                    <div style="font-size:0.78rem;color:#888;margin-top:6px;">Used on the frontend to initialize payment popups.</div>
                </div>
                <div class="mb-4">
                    <label class="form-label-dark">Paystack Secret Key</label>
                    <input type="password" name="paystack_secret_key" class="form-control form-control-dark"
                           value="<?= ss($siteSettings, 'paystack_secret_key') ?>"
                           placeholder="sk_live_... or sk_test_...">
                    <div style="font-size:0.78rem;color:#888;margin-top:6px;">Used server-side to verify payments and initiate transfers. Keep secret.</div>
                </div>
            </div>
        </div>

        <div class="card-dark mb-4">
            <div class="card-header">PayHub API Keys</div>
            <div class="card-body">
                <div class="mb-4">
                    <label class="form-label-dark">PayHub Base URL</label>
                    <input type="text" name="payhub_base_url" class="form-control form-control-dark"
                           value="<?= ss($siteSettings, 'payhub_base_url', 'https://payhub.datagifting.com.ng') ?>"
                           placeholder="https://payhub.datagifting.com.ng">
                    <div style="font-size:0.78rem;color:#888;margin-top:6px;">Base URL for the PayHub API.</div>
                </div>
                <div class="mb-4">
                    <label class="form-label-dark">PayHub API Key</label>
                    <input type="password" name="payhub_api_key" class="form-control form-control-dark"
                           value="<?= ss($siteSettings, 'payhub_api_key') ?>"
                           placeholder="Your PayHub API key">
                    <div style="font-size:0.78rem;color:#888;margin-top:6px;">Bearer token used for all PayHub API requests. Keep secret.</div>
                </div>
                <div class="mb-4">
                    <label class="form-label-dark">PayHub Merchant ID</label>
                    <input type="text" name="payhub_merchant_id" class="form-control form-control-dark"
                           value="<?= ss($siteSettings, 'payhub_merchant_id') ?>"
                           placeholder="Your PayHub Merchant ID">
                    <div style="font-size:0.78rem;color:#888;margin-top:6px;">Merchant identifier provided by PayHub.</div>
                </div>
            </div>
        </div>

        <div class="card-dark mb-4">
            <div class="card-header">Payout Gateway</div>
            <div class="card-body">
                <div class="mb-4">
                    <label class="form-label-dark">Preferred Payout Gateway</label>
                    <select name="preferred_payout_gateway" class="form-control form-control-dark">
                        <option value="paystack" <?= ss($siteSettings, 'preferred_payout_gateway', 'paystack') === 'paystack' ? 'selected' : '' ?>>Paystack (API Transfer)</option>
                        <option value="payhub"   <?= ss($siteSettings, 'preferred_payout_gateway', 'paystack') === 'payhub'   ? 'selected' : '' ?>>PayHub Transfer</option>
                        <option value="manual"   <?= ss($siteSettings, 'preferred_payout_gateway', 'paystack') === 'manual'   ? 'selected' : '' ?>>Manual Only</option>
                    </select>
                    <div style="font-size:0.78rem;color:#888;margin-top:6px;">When admin approves a payout request, which gateway should be used to initiate the bank transfer?</div>
                </div>
            </div>
        </div>

        <div class="card-dark mb-4">
            <div class="card-header">Platform Fees</div>
            <div class="card-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label-dark">Platform Fee (%)</label>
                        <input type="number" name="platform_fee_percent" class="form-control form-control-dark"
                               value="<?= ss($siteSettings, 'platform_fee_percent', '10') ?>"
                               min="0" max="100" step="0.1" placeholder="10">
                        <div style="font-size:0.78rem;color:#888;margin-top:6px;">% added to contest prize pool as platform fee.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-dark">Minimum Contest Prize (₦)</label>
                        <input type="number" name="min_contest_prize" class="form-control form-control-dark"
                               value="<?= ss($siteSettings, 'min_contest_prize', '5000') ?>"
                               min="0" placeholder="5000">
                    </div>
                </div>
            </div>
        </div>

        <div class="card-dark mb-4">
            <div class="card-header">Withdrawal Settings</div>
            <div class="card-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label-dark">Minimum Withdrawal (₦)</label>
                        <input type="number" name="min_withdrawal_amount" class="form-control form-control-dark"
                               value="<?= ss($siteSettings, 'min_withdrawal_amount', '1000') ?>"
                               min="0" placeholder="1000">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-dark">Maximum Withdrawal (₦)</label>
                        <input type="number" name="max_withdrawal_amount" class="form-control form-control-dark"
                               value="<?= ss($siteSettings, 'max_withdrawal_amount', '500000') ?>"
                               min="0" placeholder="500000">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-dark">Withdrawal Fee (%)</label>
                        <input type="number" name="withdrawal_fee_percent" class="form-control form-control-dark"
                               value="<?= ss($siteSettings, 'withdrawal_fee_percent', '0') ?>"
                               min="0" max="100" step="0.01" placeholder="0">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-dark">Withdrawal Fee Flat (₦)</label>
                        <input type="number" name="withdrawal_fee_flat" class="form-control form-control-dark"
                               value="<?= ss($siteSettings, 'withdrawal_fee_flat', '0') ?>"
                               min="0" placeholder="0">
                    </div>
                </div>
                <div style="font-size:0.78rem;color:#888">Fees are deducted from the withdrawal amount before transfer.</div>
            </div>
        </div>

        <div class="card-dark mb-4">
            <div class="card-header">Movie Ad Bank Account</div>
            <div class="card-body">
                <div class="mb-4">
                    <label class="form-label-dark">Bank Name</label>
                    <input type="text" name="ad_bank_name" class="form-control form-control-dark"
                           value="<?= ss($siteSettings, 'ad_bank_name') ?>" maxlength="200"
                           placeholder="e.g. First Bank">
                </div>
                <div class="mb-4">
                    <label class="form-label-dark">Account Name</label>
                    <input type="text" name="ad_bank_account" class="form-control form-control-dark"
                           value="<?= ss($siteSettings, 'ad_bank_account') ?>" maxlength="200"
                           placeholder="e.g. Clipaza Ltd">
                </div>
                <div class="mb-4">
                    <label class="form-label-dark">Account Number</label>
                    <input type="text" name="ad_bank_number" class="form-control form-control-dark"
                           value="<?= ss($siteSettings, 'ad_bank_number') ?>" maxlength="20"
                           placeholder="e.g. 0123456789">
                    <div style="font-size:0.78rem;color:#888;margin-top:6px;">Shown to advertisers who choose manual bank transfer.</div>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-accent">Save Payment Settings</button>
    </form>

    <!-- TAB: CODE INJECTION -->
    <?php elseif ($activeTab === 'code'): ?>
    <div class="alert-dark-warning mb-4" style="display:flex;gap:12px;align-items:flex-start;">
        <span style="font-size:1.2rem;">⚠️</span>
        <div>
            <strong>Security Warning:</strong> Code entered here is output as raw HTML on every public page load.
            Only enter code from sources you fully trust (e.g., your own Google Analytics tag). A compromised admin
            account could use these fields to inject malicious scripts.
        </div>
    </div>
    <form id="settingsForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="save_code">

        <div class="card-dark mb-4">
            <div class="card-header">Code Injection</div>
            <div class="card-body">
                <div class="mb-4">
                    <label class="form-label-dark">Custom Header Code</label>
                    <textarea name="custom_header_code" class="form-control form-control-dark"
                              rows="8" style="font-family:monospace;font-size:0.85rem;"
                              placeholder="<!-- Paste scripts, tracking pixels, or styles here -->"><?= htmlspecialchars($siteSettings['custom_header_code'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                    <div style="font-size:0.78rem;color:#888;margin-top:6px;">Raw HTML/JS injected inside <code style="color:#ccc;">&lt;head&gt;</code>. Use with caution.</div>
                </div>
                <div class="mb-4">
                    <label class="form-label-dark">Google AdSense Code</label>
                    <textarea name="adsense_code" class="form-control form-control-dark"
                              rows="6" style="font-family:monospace;font-size:0.85rem;"
                              placeholder="<!-- Paste your Google AdSense script here -->"><?= htmlspecialchars($siteSettings['adsense_code'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                    <div style="font-size:0.78rem;color:#888;margin-top:6px;">Injected immediately after <code style="color:#ccc;">&lt;body&gt;</code> on all public pages.</div>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-accent">Save Code Settings</button>
    </form>

    <!-- TAB: ADS -->
    <?php elseif ($activeTab === 'ads'): ?>
    <form id="settingsForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="save_ads">

        <div class="card-dark mb-4">
            <div class="card-header">ads.txt Management</div>
            <div class="card-body">
                <div class="mb-4">
                    <label class="form-label-dark">ads.txt Content</label>
                    <textarea name="ads_txt_content" class="form-control form-control-dark"
                              rows="10" style="font-family:monospace;font-size:0.85rem;"
                              placeholder="google.com, pub-XXXXXXXXXXXXXXXX, DIRECT, f08c47fec0942fa0"><?= htmlspecialchars($siteSettings['ads_txt_content'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                    <div style="font-size:0.78rem;color:#888;margin-top:6px;">
                        This content is served at <a href="/ads.txt" target="_blank" style="color:var(--accent);">/ads.txt</a>.
                        One entry per line.
                    </div>
                </div>

                <hr style="border-color:var(--border);margin:24px 0;">

                <div class="mb-4">
                    <label class="form-label-dark">Upload ads.txt File</label>
                    <input type="file" name="ads_txt_file" class="form-control form-control-dark" accept=".txt">
                    <div style="font-size:0.78rem;color:#888;margin-top:6px;">Upload a .txt file to replace the ads.txt content above. Max 2MB.</div>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-accent">Save Ads Settings</button>
    </form>
    <?php endif; ?>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
(function () {
    const form = document.getElementById('settingsForm');
    const alertBox = document.getElementById('settingsAlert');
    if (!form) return;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = form.querySelector('[type="submit"]');
        const origText = btn.textContent;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-accent" style="width:16px;height:16px;border-width:2px;vertical-align:middle;margin-right:8px;"></span> Saving...';
        alertBox.style.display = 'none';

        try {
            const data = new FormData(form);
            const resp = await fetch('ajax/settings_actions.php', { method: 'POST', body: data });
            const json = await resp.json();
            alertBox.className = json.success ? 'alert-dark-success mb-3' : 'alert-dark-danger mb-3';
            alertBox.textContent = json.message || (json.success ? 'Settings saved.' : 'An error occurred.');
            alertBox.style.display = 'block';
            if (typeof showToast === 'function') showToast(json.message || 'Done.', json.success ? 'success' : 'danger');
        } catch (err) {
            alertBox.className = 'alert-dark-danger mb-3';
            alertBox.textContent = 'Request failed. Please try again.';
            alertBox.style.display = 'block';
        }

        btn.disabled = false;
        btn.textContent = origText;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
})();
</script>
</body>
</html>
