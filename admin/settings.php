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
  <script>
    (function() {
      var t = localStorage.getItem('clipaza_theme') || 'dark';
      document.documentElement.dataset.theme = t;
    })();
  </script>
</head>
<body>

<!-- Sidebar -->
<nav class="admin-sidebar">
    <?php $sn = getSetting("site_name", "Clipaza"); $sl = getSetting("site_logo", ""); if ($sl): ?><div class="sidebar-brand"><img src="<?= e($sl) ?>" alt="<?= e($sn) ?>" style="height:28px"></div><?php else: ?><div class="sidebar-brand"><?= formatSiteName($sn) ?></div><?php endif; ?>
    <div class="sidebar-nav">
        <ul class="nav flex-column">
            <li class="nav-item"><a href="index.php" class="nav-link"><span class="nav-icon">⊞</span> Dashboard</a></li>
            <li class="nav-item"><a href="security.php" class="nav-link"><span class="nav-icon">🛡</span> Security</a></li>
            <li class="nav-item"><a href="users.php" class="nav-link"><span class="nav-icon">👥</span> Users</a></li>
            <li class="nav-item"><a href="contests.php" class="nav-link"><span class="nav-icon">🏆</span> Contests</a></li>
            <li class="nav-item"><a href="featured-contests.php" class="nav-link"><span class="nav-icon">⭐</span> Featured</a></li>
            <li class="nav-item"><a href="payouts.php" class="nav-link"><span class="nav-icon">💸</span> Payouts</a></li>
            <li class="nav-item"><a href="kyc.php" class="nav-link"><span class="nav-icon">🪪</span> KYC</a></li>
            <li class="nav-item"><a href="ad-packages.php" class="nav-link"><span class="nav-icon">📦</span> Ad Packages</a></li>
            <li class="nav-item"><a href="movie-ads.php" class="nav-link"><span class="nav-icon">🎞</span> Movie Ads</a></li>
            <li class="nav-item"><a href="settings.php" class="nav-link active"><span class="nav-icon">⚙</span> Settings</a></li>
            <li class="nav-item"><a href="profile.php" class="nav-link"><span class="nav-icon">👤</span> Profile</a></li>
        </ul>
        <hr class="divider-dark mx-3">
        <ul class="nav flex-column">
            <li class="nav-item"><a href="logout.php" class="nav-link" style="color:var(--danger);"><span class="nav-icon">⇤</span> Logout</a></li>
        </ul>
    </div>
</nav>

<!-- Main -->
<main class="admin-main">
    <div class="admin-topbar">
        <div class="d-flex align-items-center gap-3">
            <button id="sidebarToggle" class="btn d-lg-none" style="color:var(--text-muted);background:var(--subtle-bg);border-radius:8px;padding:6px 10px;">☰</button>
            <button id="adminThemeToggle" class="btn-theme-toggle" title="Toggle light/dark mode" aria-label="Toggle theme" style="margin-left:4px">☀️</button>
            <h1>Site Settings</h1>
        </div>
        <a href="index.php" style="font-size:0.8rem;color:var(--text-muted);">← Dashboard</a>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs-dark mb-4">
        <?php
        $tabs = [
            'general' => '🌐 General',
            'seo'     => '🔍 SEO',
            'payment' => '💳 Payment',
            'landing' => '🏠 Landing Page',
            'code'    => '💻 Code Injection',
            'ads'     => '📢 Ads',
        ];
        foreach ($tabs as $key => $label):
        ?>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === $key ? 'active' : '' ?>" href="?tab=<?= $key ?>"><?= htmlspecialchars($label) ?></a>
        </li>
        <?php endforeach; ?>
    </ul>

    <div id="settingsAlert" style="display:none;" class="mb-3"></div>

    <form id="settingsForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

        <?php if ($activeTab === 'general'): ?>
            <input type="hidden" name="action" value="save_general">
            <div class="row g-4">
                <div class="col-md-8">
                    <div class="card-dark">
                        <div class="card-header">General Settings</div>
                        <div class="card-body">
                            <div class="mb-4">
                                <label class="form-label-dark">Site Name</label>
                                <input type="text" name="site_name" class="form-control form-control-dark" value="<?= ss($siteSettings, 'site_name', 'Clipaza') ?>" required maxlength="100" placeholder="e.g. ClipZaza">
                                <div class="form-text text-muted">Use a second Capital letter to start the Lemon color (e.g., <strong>ClipZaza</strong> will color "Zaza").</div>
                            </div>
                            <div class="mb-4">
                                <label class="form-label-dark">Default Landing Page Theme</label>
                                <select name="default_theme" class="form-control form-control-dark">
                                    <option value="dark"  <?= ($siteSettings['default_theme'] ?? 'dark') === 'dark'  ? 'selected' : '' ?>>🌙 Dark (default)</option>
                                    <option value="light" <?= ($siteSettings['default_theme'] ?? 'dark') === 'light' ? 'selected' : '' ?>>☀️ Light</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card-dark mb-4">
                        <div class="card-header">Site Logo</div>
                        <div class="card-body">
                            <?php if (!empty($siteSettings['site_logo'])): ?>
                            <div class="mb-3 text-center"><img src="<?= ss($siteSettings, 'site_logo') ?>" alt="Logo" style="max-height:60px;max-width:100%;border-radius:6px;background:var(--bg-secondary);padding:8px;"></div>
                            <?php endif; ?>
                            <input type="file" name="site_logo" class="form-control form-control-dark" accept=".png,.jpg,.jpeg">
                        </div>
                    </div>
                    <div class="card-dark">
                        <div class="card-header">Favicon</div>
                        <div class="card-body">
                            <?php if (!empty($siteSettings['site_favicon'])): ?>
                            <div class="mb-3 text-center"><img src="<?= ss($siteSettings, 'site_favicon') ?>" alt="Favicon" style="max-height:32px;max-width:32px;border-radius:4px;background:var(--bg-secondary);padding:4px;"></div>
                            <?php endif; ?>
                            <input type="file" name="site_favicon" class="form-control form-control-dark" accept=".png,.jpg,.jpeg,.ico">
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($activeTab === 'seo'): ?>
            <input type="hidden" name="action" value="save_seo">
            <div class="card-dark mb-4">
                <div class="card-header">SEO Settings</div>
                <div class="card-body">
                    <div class="mb-4"><label class="form-label-dark">Meta Title</label><input type="text" name="seo_title" class="form-control form-control-dark" value="<?= ss($siteSettings, 'seo_title') ?>" maxlength="200"></div>
                    <div class="mb-4"><label class="form-label-dark">Meta Description</label><textarea name="seo_description" class="form-control form-control-dark" rows="3" maxlength="500"><?= ss($siteSettings, 'seo_description') ?></textarea></div>
                    <div class="mb-4"><label class="form-label-dark">Meta Keywords</label><input type="text" name="seo_keywords" class="form-control form-control-dark" value="<?= ss($siteSettings, 'seo_keywords') ?>" maxlength="500"></div>
                    <div class="mb-4"><label class="form-label-dark">Open Graph Image URL</label><input type="url" name="og_image_url" class="form-control form-control-dark" value="<?= ss($siteSettings, 'og_image_url') ?>" maxlength="500"></div>
                </div>
            </div>

        <?php elseif ($activeTab === 'payment'): ?>
            <input type="hidden" name="action" value="save_payment">
            <div class="card-dark mb-4">
                <div class="card-header">Paystack API Keys</div>
                <div class="card-body">
                    <div class="mb-3"><label class="form-label-dark">Paystack Public Key</label><input type="text" name="paystack_public_key" class="form-control form-control-dark" value="<?= ss($siteSettings, 'paystack_public_key') ?>"></div>
                    <div class="mb-3"><label class="form-label-dark">Paystack Secret Key</label><input type="password" name="paystack_secret_key" class="form-control form-control-dark" value="<?= ss($siteSettings, 'paystack_secret_key') ?>"></div>
                </div>
            </div>
            <div class="card-dark mb-4">
                <div class="card-header">Fees &amp; Limits</div>
                <div class="card-body">
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label-dark">Platform Fee (%)</label>
                            <input type="number" step="0.1" name="platform_fee_percent" class="form-control form-control-dark" value="<?= ss($siteSettings, 'platform_fee_percent', '10') ?>">
                            <div class="form-text text-muted">Percentage taken from every contest prize pool.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-dark">Min Contest Prize (₦)</label>
                            <input type="number" name="min_contest_prize" class="form-control form-control-dark" value="<?= ss($siteSettings, 'min_contest_prize', '5000') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-dark">Max Contest Duration (Days)</label>
                            <input type="number" name="max_contest_days" class="form-control form-control-dark" value="<?= ss($siteSettings, 'max_contest_days', '30') ?>">
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-dark">Withdrawal Fee (%)</label>
                            <input type="number" step="0.1" name="withdrawal_fee_percent" class="form-control form-control-dark" value="<?= ss($siteSettings, 'withdrawal_fee_percent', '0') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-dark">Withdrawal Fee Flat (₦)</label>
                            <input type="number" name="withdrawal_fee_flat" class="form-control form-control-dark" value="<?= ss($siteSettings, 'withdrawal_fee_flat', '0') ?>">
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-dark mb-4">
                <div class="card-header">Payout Settings</div>
                <div class="card-body">
                    <div class="mb-4">
                        <label class="form-label-dark">Preferred Payout Gateway</label>
                        <select name="preferred_payout_gateway" class="form-control form-control-dark">
                            <option value="paystack" <?= ss($siteSettings, 'preferred_payout_gateway', 'paystack') === 'paystack' ? 'selected' : '' ?>>Paystack (API Transfer)</option>
                            <option value="payhub"   <?= ss($siteSettings, 'preferred_payout_gateway', 'paystack') === 'payhub'   ? 'selected' : '' ?>>PayHub Transfer</option>
                            <option value="manual"   <?= ss($siteSettings, 'preferred_payout_gateway', 'paystack') === 'manual'   ? 'selected' : '' ?>>Manual Only</option>
                        </select>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label-dark">Min Withdrawal (₦)</label><input type="number" name="min_withdrawal_amount" class="form-control form-control-dark" value="<?= ss($siteSettings, 'min_withdrawal_amount', '1000') ?>"></div>
                        <div class="col-md-6"><label class="form-label-dark">Max Withdrawal (₦)</label><input type="number" name="max_withdrawal_amount" class="form-control form-control-dark" value="<?= ss($siteSettings, 'max_withdrawal_amount', '500000') ?>"></div>
                    </div>
                </div>
            </div>
            <div class="card-dark mb-4">
                <div class="card-header">Movie Ad Bank Account (Manual)</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label-dark">Bank Name</label><input type="text" name="ad_bank_name" class="form-control form-control-dark" value="<?= ss($siteSettings, 'ad_bank_name') ?>"></div>
                        <div class="col-md-4"><label class="form-label-dark">Account Name</label><input type="text" name="ad_bank_account" class="form-control form-control-dark" value="<?= ss($siteSettings, 'ad_bank_account') ?>"></div>
                        <div class="col-md-4"><label class="form-label-dark">Account Number</label><input type="text" name="ad_bank_number" class="form-control form-control-dark" value="<?= ss($siteSettings, 'ad_bank_number') ?>"></div>
                    </div>
                </div>
            </div>

        <?php elseif ($activeTab === 'landing'): ?>
            <input type="hidden" name="action" value="save_landing">
            <div class="card-dark mb-4">
                <div class="card-header">Hero Section</div>
                <div class="card-body">
                    <div class="mb-3"><label class="form-label-dark">Hero Title</label><input type="text" name="lp_hero_title" class="form-control form-control-dark" value="<?= ss($siteSettings, 'lp_hero_title') ?>"></div>
                    <div class="mb-3"><label class="form-label-dark">Hero Accent Title</label><input type="text" name="lp_hero_accent" class="form-control form-control-dark" value="<?= ss($siteSettings, 'lp_hero_accent') ?>"></div>
                    <div class="mb-3"><label class="form-label-dark">Hero Subtext</label><textarea name="lp_hero_sub" class="form-control form-control-dark" rows="3"><?= ss($siteSettings, 'lp_hero_sub') ?></textarea></div>
                </div>
            </div>
            <div class="card-dark mb-4">
                <div class="card-header">Brands & Creators Section</div>
                <div class="card-body">
                    <div class="mb-3"><label class="form-label-dark">Brands Section Title</label><input type="text" name="lp_brands_title" class="form-control form-control-dark" value="<?= ss($siteSettings, 'lp_brands_title') ?>"></div>
                    <div class="mb-3"><label class="form-label-dark">Brands Section Subtitle</label><textarea name="lp_brands_sub" class="form-control form-control-dark" rows="3"><?= ss($siteSettings, 'lp_brands_sub') ?></textarea></div>
                    <div class="mb-3"><label class="form-label-dark">Brands Section Content</label><textarea name="lp_brands_content" class="form-control form-control-dark" rows="4"><?= ss($siteSettings, 'lp_brands_content') ?></textarea></div>
                </div>
            </div>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label-dark">Start Contest Button</label><input type="text" name="lp_hero_btn_creator" class="form-control form-control-dark" value="<?= ss($siteSettings, "lp_hero_btn_creator") ?>"></div>
                        <div class="col-md-6"><label class="form-label-dark">Join as Fan Button</label><input type="text" name="lp_hero_btn_fan" class="form-control form-control-dark" value="<?= ss($siteSettings, "lp_hero_btn_fan") ?>"></div>
                    </div>
            <div class="card-dark mb-4">
                <div class="card-header">Features Section (Why Clipaza?)</div>
                <div class="card-body">
                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" name="lp_hide_features" id="hideFeatures" value="1" <?= ($siteSettings['lp_hide_features'] ?? '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label text-theme" for="hideFeatures">Hide Features Section</label>
                    </div>
                    <div class="mb-3"><label class="form-label-dark">Features Section Title</label><input type="text" name="lp_features_title" class="form-control form-control-dark" value="<?= ss($siteSettings, 'lp_features_title') ?>"></div>
                    <div class="mb-4"><label class="form-label-dark">Features Subtext</label><input type="text" name="lp_features_sub" class="form-control form-control-dark" value="<?= ss($siteSettings, 'lp_features_sub') ?>"></div>
                    <div class="row g-3">
                        <?php for($i=1;$i<=6;$i++): ?>
                        <div class="col-md-4 mb-3">
                            <label class="form-label-dark">Feature <?= $i ?> Title</label><input type="text" name="lp_f<?= $i ?>_title" class="form-control form-control-dark mb-2" value="<?= ss($siteSettings, "lp_f{$i}_title") ?>">
                            <label class="form-label-dark">Feature <?= $i ?> Desc</label><textarea name="lp_f<?= $i ?>_desc" class="form-control form-control-dark" rows="3"><?= ss($siteSettings, "lp_f{$i}_desc") ?></textarea>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
            <div class="card-dark mb-4">
                <div class="card-header">How It Works Section</div>
                <div class="card-body">
                    <div class="mb-3"><label class="form-label-dark">Section Title</label><input type="text" name="lp_hiw_title" class="form-control form-control-dark" value="<?= ss($siteSettings, "lp_hiw_title") ?>"></div>
                    <div class="row g-3">
                        <?php for($i=1;$i<=3;$i++): ?>
                        <div class="col-md-4">
                            <label class="form-label-dark">Step <?= $i ?> Title</label><input type="text" name="lp_step<?= $i ?>_title" class="form-control form-control-dark mb-2" value="<?= ss($siteSettings, "lp_step{$i}_title") ?>">
                            <label class="form-label-dark">Step <?= $i ?> Desc</label><textarea name="lp_step<?= $i ?>_desc" class="form-control form-control-dark" rows="3"><?= ss($siteSettings, "lp_step{$i}_desc") ?></textarea>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
            <div class="card-dark mb-4">
                <div class="card-header">For Creators & Fans</div>
                <div class="card-body">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <h6>Creators Side</h6>
                            <input type="text" name="lp_creators_title" class="form-control form-control-dark mb-2" value="<?= ss($siteSettings, 'lp_creators_title') ?>" placeholder="Title">
                            <textarea name="lp_creators_sub" class="form-control form-control-dark mb-2" rows="3" placeholder="Subtext"><?= ss($siteSettings, 'lp_creators_sub') ?></textarea>
                            <input type="text" name="lp_creators_extra" class="form-control form-control-dark mb-2" value="<?= ss($siteSettings, 'lp_creators_extra') ?>" placeholder="Extra note">
                            <?php for($i=1;$i<=4;$i++): ?><input type="text" name="lp_creators_p<?= $i ?>" class="form-control form-control-dark mb-1" value="<?= ss($siteSettings, "lp_creators_p{$i}") ?>" placeholder="Point <?= $i ?>"><?php endfor; ?>
                        </div>
                        <div class="col-md-6">
                            <h6>Fans Side</h6>
                            <input type="text" name="lp_fans_title" class="form-control form-control-dark mb-2" value="<?= ss($siteSettings, 'lp_fans_title') ?>" placeholder="Title">
                            <textarea name="lp_fans_sub" class="form-control form-control-dark mb-2" rows="3" placeholder="Subtext"><?= ss($siteSettings, 'lp_fans_sub') ?></textarea>
                            <input type="text" name="lp_fans_extra" class="form-control form-control-dark mb-2" value="<?= ss($siteSettings, 'lp_fans_extra') ?>" placeholder="Extra note">
                            <?php for($i=1;$i<=4;$i++): ?><input type="text" name="lp_fans_p<?= $i ?>" class="form-control form-control-dark mb-1" value="<?= ss($siteSettings, "lp_fans_p{$i}") ?>" placeholder="Point <?= $i ?>"><?php endfor; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-dark mb-4">
                <div class="card-header">Leaderboard & CTA</div>
                <div class="card-body">
                    <div class="mb-3"><label class="form-label-dark">Leaderboard Title (Accent)</label><input type="text" name="lp_lb_title_accent" class="form-control form-control-dark" value="<?= ss($siteSettings, "lp_lb_title_accent") ?>"></div>
                    <div class="mb-3"><label class="form-label-dark">Leaderboard Text</label><textarea name="lp_lb_text" class="form-control form-control-dark" rows="3"><?= ss($siteSettings, "lp_lb_text") ?></textarea></div>
                    <div class="mb-3"><label class="form-label-dark">Footer CTA Title</label><input type="text" name="lp_cta_title" class="form-control form-control-dark" value="<?= ss($siteSettings, "lp_cta_title") ?>"></div>
                    <div class="mb-3"><label class="form-label-dark">Footer CTA Subtext</label><input type="text" name="lp_cta_sub" class="form-control form-control-dark" value="<?= ss($siteSettings, "lp_cta_sub") ?>"></div>
                </div>
            </div>

        <?php elseif ($activeTab === 'code'): ?>
            <input type="hidden" name="action" value="save_code">
            <div class="card-dark mb-4">
                <div class="card-header">Code Injection</div>
                <div class="card-body">
                    <div class="mb-3"><label class="form-label-dark">Header Code (Inside &lt;head&gt;)</label><textarea name="custom_header_code" class="form-control form-control-dark" rows="8"><?= htmlspecialchars($siteSettings['custom_header_code'] ?? '') ?></textarea></div>
                    <div class="mb-3"><label class="form-label-dark">Body Code (Immediately after &lt;body&gt;)</label><textarea name="adsense_code" class="form-control form-control-dark" rows="8"><?= htmlspecialchars($siteSettings['adsense_code'] ?? '') ?></textarea></div>
                </div>
            </div>

        <?php elseif ($activeTab === 'ads'): ?>
            <input type="hidden" name="action" value="save_ads">
            <div class="card-dark mb-4">
                <div class="card-header">ads.txt Content</div>
                <div class="card-body">
                    <textarea name="ads_txt_content" class="form-control form-control-dark mb-3" rows="10"><?= htmlspecialchars($siteSettings['ads_txt_content'] ?? '') ?></textarea>
                    <label class="form-label-dark">Or Upload File</label><input type="file" name="ads_txt_file" class="form-control form-control-dark" accept=".txt">
                </div>
            </div>
        <?php endif; ?>

        <div class="mt-4"><button type="submit" class="btn btn-accent">Save Settings</button></div>
    </form>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
(function () {
    const form = document.getElementById('settingsForm');
    if (!form) return;
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = form.querySelector('[type="submit"]');
        btn.disabled = true;
        try {
            const data = new FormData(form);
            const resp = await fetch('ajax/settings_actions.php', { method: 'POST', body: data });
            const json = await resp.json();
            if (typeof showToast === 'function') showToast(json.message || 'Done.', json.success ? 'success' : 'danger');
        } catch (err) { }
        btn.disabled = false;
    });
})();
</script>
<script src="assets/js/theme_sync.js"></script>
</body>
</html>
