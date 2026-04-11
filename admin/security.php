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

$csrf    = generateCsrfToken();
$activeTab = $_GET['tab'] ?? 'settings';

// Load data for all tabs
try {
    $db = db();

    // Security settings
    $settingsStmt = $db->query('SELECT setting_key, setting_value FROM security_settings');
    $rawSettings  = $settingsStmt->fetchAll();
    $settings = [];
    foreach ($rawSettings as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    // Blocked IPs
    $blockedIps = BruteForceProtection::getBlockedIps(200);

    // Whitelisted IPs (for king icon)
    $whitelistStmt = $db->query('SELECT ip_address FROM ip_whitelist');
    $whitelistedIps = array_column($whitelistStmt->fetchAll(), 'ip_address');
    $whitelistedIps = array_flip($whitelistedIps);

    // Locked Accounts
    $lockedAccounts = BruteForceProtection::getLockedAccounts();

    // Countries
    $countryStmt = $db->query('SELECT * FROM country_rules ORDER BY country_name ASC');
    $countries   = $countryStmt->fetchAll();

    // Login History (paginated)
    $histPage    = max(1, (int)($_GET['hpage'] ?? 1));
    $histPerPage = 25;
    $histTotal   = (int)$db->query('SELECT COUNT(*) FROM login_history')->fetchColumn();
    $histPager   = paginate($histTotal, $histPerPage, $histPage);
    // Allowlist for the action filter to prevent any unexpected values being bound
    $validActions = ['login_success', 'login_failed', 'logout', 'account_locked', 'ip_blocked', ''];
    $histFilterRaw = $_GET['haction'] ?? '';
    $histFilter    = in_array($histFilterRaw, $validActions, true) ? $histFilterRaw : '';
    $histIp        = substr(trim($_GET['hip']    ?? ''), 0, 45);
    $histUser      = substr(trim($_GET['huser']  ?? ''), 0, 100);

    // $histWhere is built from literal SQL strings only; user input is always bound as parameters.
    $histWhere  = '1=1';
    $histParams = [];
    if ($histFilter !== '') { $histWhere .= ' AND action = ?';          $histParams[] = $histFilter; }
    if ($histIp !== '')     { $histWhere .= ' AND ip_address LIKE ?';   $histParams[] = '%' . $histIp . '%'; }
    if ($histUser !== '')   { $histWhere .= ' AND username LIKE ?';     $histParams[] = '%' . $histUser . '%'; }

    // WHERE clause uses only hardcoded column names; all user values are in $histParams as bound params.
    $histCountStmt = $db->prepare("SELECT COUNT(*) FROM login_history WHERE {$histWhere}");
    $histCountStmt->execute($histParams);
    $histTotal = (int)$histCountStmt->fetchColumn();
    $histPager = paginate($histTotal, $histPerPage, $histPage);

    $histStmt = $db->prepare(
        "SELECT * FROM login_history WHERE {$histWhere} ORDER BY created_at DESC LIMIT ? OFFSET ?"
    );
    // Bind string params first, then integers with explicit PARAM_INT for LIMIT/OFFSET
    foreach ($histParams as $i => $val) {
        $histStmt->bindValue($i + 1, $val);
    }
    $nextIdx = count($histParams) + 1;
    $histStmt->bindValue($nextIdx,     $histPerPage,         PDO::PARAM_INT);
    $histStmt->bindValue($nextIdx + 1, $histPager['offset'], PDO::PARAM_INT);
    $histStmt->execute();
    $loginHistory = $histStmt->fetchAll();
} catch (Throwable $e) {
    $settings = $blockedIps = $lockedAccounts = $countries = $loginHistory = [];
    $whitelistedIps = [];
    $histPager = ['pages' => 1, 'current' => 1, 'hasPrev' => false, 'hasNext' => false];
}

function s(array $settings, string $key, string $default = ''): string {
    return htmlspecialchars($settings[$key] ?? $default, ENT_QUOTES, 'UTF-8');
}
function checked(array $settings, string $key, string $trueVal = '1'): string {
    return ($settings[$key] ?? '') === $trueVal ? 'checked' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security — Clipaza Admin</title>
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
            <li class="nav-item"><a href="index.php" class="nav-link"><span class="nav-icon">⊞</span> Dashboard</a></li>
            <li class="nav-item"><a href="security.php" class="nav-link active"><span class="nav-icon">🛡</span> Security</a></li>
            <li class="nav-item"><a href="users.php" class="nav-link"><span class="nav-icon">👥</span> Users</a></li>
            <li class="nav-item"><a href="/contests.php" class="nav-link"><span class="nav-icon">🏆</span> Contests</a></li>
            <li class="nav-item"><a href="settings.php" class="nav-link"><span class="nav-icon">⚙</span> Settings</a></li>
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
            <button id="sidebarToggle" class="btn d-lg-none" style="color:#888;background:rgba(255,255,255,0.05);border-radius:8px;padding:6px 10px;">☰</button>
            <h1>Security Management</h1>
        </div>
        <a href="/" style="font-size:0.8rem;color:#888;">← Dashboard</a>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs-dark mb-4" id="secTabs">
        <?php
        $tabs = [
            'settings'  => '⚙ Settings',
            'blocked'   => '🚫 Blocked IPs (' . count($blockedIps) . ')',
            'locks'     => '🔒 Account Locks (' . count($lockedAccounts) . ')',
            'countries' => '🌍 Country Rules',
            'history'   => '📋 Login History',
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

    <!-- TAB: SETTINGS -->
    <?php if ($activeTab === 'settings'): ?>
    <form id="securitySettingsForm" method="POST" action="ajax/security_actions.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="save_security_settings">

        <div class="row g-4">
            <!-- IP Protection -->
            <div class="col-md-6">
                <div class="card-dark h-100">
                    <div class="card-header">IP Protection</div>
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div>
                                <div style="font-weight:600;font-size:0.875rem;">Enable IP Protection</div>
                                <div style="font-size:0.8rem;color:#888;">Block IPs with too many failures</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="ip_protection_enabled"
                                       value="1" id="ipProtSwitch" <?= checked($settings, 'ip_protection_enabled') ?>>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label-dark">Time Window (minutes)</label>
                            <input type="number" name="ip_period_minutes" class="form-control form-control-dark"
                                   value="<?= s($settings, 'ip_period_minutes', '15') ?>" min="1" max="1440">
                        </div>
                        <div class="mb-3">
                            <label class="form-label-dark">Max Failures Before Block</label>
                            <input type="number" name="ip_max_failures" class="form-control form-control-dark"
                                   value="<?= s($settings, 'ip_max_failures', '10') ?>" min="1" max="100">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Username Protection -->
            <div class="col-md-6">
                <div class="card-dark h-100">
                    <div class="card-header">Username Protection</div>
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div>
                                <div style="font-weight:600;font-size:0.875rem;">Enable Username Protection</div>
                                <div style="font-size:0.8rem;color:#888;">Lock accounts with too many failures</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="username_protection_enabled"
                                       value="1" id="userProtSwitch" <?= checked($settings, 'username_protection_enabled') ?>>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label-dark">Time Window (minutes)</label>
                            <input type="number" name="username_period_minutes" class="form-control form-control-dark"
                                   value="<?= s($settings, 'username_period_minutes', '30') ?>" min="1" max="1440">
                        </div>
                        <div class="mb-3">
                            <label class="form-label-dark">Max Failures Before Lock</label>
                            <input type="number" name="username_max_failures" class="form-control form-control-dark"
                                   value="<?= s($settings, 'username_max_failures', '5') ?>" min="1" max="100">
                        </div>
                        <hr style="border-color:var(--border);">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div>
                                <div style="font-weight:600;font-size:0.875rem;">Apply protection to local addresses only</div>
                                <div style="font-size:0.8rem;color:#888;">Only enforce username protection for local/private IPs</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="protect_local_only"
                                       value="1" <?= checked($settings, 'protect_local_only') ?>>
                            </div>
                        </div>
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <div style="font-weight:600;font-size:0.875rem;">Allow username protection to lock the <code style="font-size:0.8rem;color:#ccc;">admin</code> / <code style="font-size:0.8rem;color:#ccc;">administrator</code> user</div>
                                <div style="font-size:0.8rem;color:#888;">Enable locking of privileged accounts on brute force</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="allow_lock_admin"
                                       value="1" <?= checked($settings, 'allow_lock_admin') ?>>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Block Duration -->
            <div class="col-md-6">
                <div class="card-dark">
                    <div class="card-header">Block Duration</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label-dark">Block Duration</label>
                            <select name="block_duration_minutes" class="form-select form-select-dark">
                                <?php
                                $durations = [15 => '15 minutes', 30 => '30 minutes', 60 => '1 hour',
                                              120 => '2 hours', 360 => '6 hours', 720 => '12 hours',
                                              1440 => '24 hours', 4320 => '3 days', 10080 => '7 days'];
                                $current = (int)($settings['block_duration_minutes'] ?? 60);
                                foreach ($durations as $val => $label):
                                ?>
                                <option value="<?= $val ?>" <?= $current === $val ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- IP Address-based Protection -->
            <div class="col-md-6">
                <div class="card-dark">
                    <div class="card-header">IP Address-based Protection</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label-dark">What should happen when an IP triggers Brute Force Protection?</label>
                            <select name="ip_block_duration_option" class="form-select form-select-dark">
                                <?php
                                $ipBlockOptions = [
                                    '1day'   => 'One-day Blocks',
                                    '1week'  => 'One-week Blocks',
                                    '1month' => 'One-month Blocks',
                                    '1year'  => 'One-year Blocks',
                                ];
                                $currentOpt = $settings['ip_block_duration_option'] ?? '1day';
                                foreach ($ipBlockOptions as $val => $label):
                                ?>
                                <option value="<?= $val ?>" <?= $currentOpt === $val ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Notifications & Logs -->
            <div class="col-md-6">
                <div class="card-dark">
                    <div class="card-header">Notifications &amp; Logs</div>
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div>
                                <div style="font-weight:600;font-size:0.875rem;">Notify on IP Block</div>
                                <div style="font-size:0.8rem;color:#888;">Send admin email when IP is blocked</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="notify_on_block"
                                       value="1" <?= checked($settings, 'notify_on_block') ?>>
                            </div>
                        </div>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div>
                                <div style="font-weight:600;font-size:0.875rem;">Notify on Account Lock</div>
                                <div style="font-size:0.8rem;color:#888;">Send admin email when account is locked</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="notify_on_lock"
                                       value="1" <?= checked($settings, 'notify_on_lock') ?>>
                            </div>
                        </div>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div>
                                <div style="font-weight:600;font-size:0.875rem;">Notify on admin login from unknown IP</div>
                                <div style="font-size:0.8rem;color:#888;">Send notification when admin logs in from a non-whitelisted IP</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="notify_admin_login_unknown_ip"
                                       value="1" <?= checked($settings, 'notify_admin_login_unknown_ip') ?>>
                            </div>
                        </div>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div>
                                <div style="font-weight:600;font-size:0.875rem;">Include username in brute force notifications</div>
                                <div style="font-size:0.8rem;color:#888;">Include the targeted username in brute force alert emails</div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="notify_brute_force_with_username"
                                       value="1" <?= checked($settings, 'notify_brute_force_with_username') ?>>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label-dark">Log Retention (days)</label>
                            <input type="number" name="log_retention_days" class="form-control form-control-dark"
                                   value="<?= s($settings, 'log_retention_days', '90') ?>" min="7" max="3650">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4">
            <button type="submit" class="btn btn-accent">Save Settings</button>
        </div>
    </form>

    <!-- TAB: BLOCKED IPs -->
    <?php elseif ($activeTab === 'blocked'): ?>
    <div class="row g-4">
        <!-- Current Blocks -->
        <div class="col-12">
            <div class="card-dark">
                <div class="card-header">Currently Blocked IPs</div>
                <div class="card-body p-0">
                    <div style="overflow-x:auto;">
                        <table class="table-dark-custom">
                            <thead>
                                <tr>
                                    <th>IP Address</th>
                                    <th>Type</th>
                                    <th>Reason</th>
                                    <th>Blocked At</th>
                                    <th>Blocked Until</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($blockedIps)): ?>
                                <tr><td colspan="6" class="text-center py-4" style="color:#888;">No blocked IPs.</td></tr>
                                <?php else: ?>
                                <?php foreach ($blockedIps as $block): ?>
                                <tr>
                                    <td><code style="color:#CCFF00;"><?= htmlspecialchars($block['ip_address']) ?></code><?php if (isset($whitelistedIps[$block['ip_address']])): ?> <span title="Whitelisted IP" style="color:#00cc66;">👑</span><?php endif; ?></td>
                                    <td>
                                        <span class="<?= $block['block_type'] === 'permanent' ? 'badge-danger' : 'badge-warning' ?>">
                                            <?= htmlspecialchars($block['block_type']) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($block['reason'] ?? '') ?></td>
                                    <td><?= htmlspecialchars(timeAgo($block['blocked_at'])) ?></td>
                                    <td><?= $block['blocked_until'] ? htmlspecialchars(formatDate($block['blocked_until'], 'M j, Y H:i')) : '<span class="badge-danger">Permanent</span>' ?></td>
                                    <td>
                                        <button class="btn btn-success-custom btn-sm"
                                                data-ip-action="unblock_ip"
                                                data-ip="<?= htmlspecialchars($block['ip_address']) ?>">
                                            Unblock
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Manual Block Form -->
        <div class="col-md-6">
            <div class="card-dark">
                <div class="card-header">Manually Block IP</div>
                <div class="card-body">
                    <form id="manualBlockForm" onsubmit="handleManualBlock(event)">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="block_ip">
                        <div class="mb-3">
                            <label class="form-label-dark">IP Address</label>
                            <input type="text" name="ip" class="form-control form-control-dark"
                                   placeholder="192.168.1.1" required pattern="^[\d.:a-fA-F]+$">
                        </div>
                        <div class="mb-3">
                            <label class="form-label-dark">Block Type</label>
                            <select name="block_type" class="form-select form-select-dark">
                                <option value="temporary">Temporary</option>
                                <option value="permanent">Permanent</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label-dark">Duration (minutes, temporary only)</label>
                            <input type="number" name="duration" class="form-control form-control-dark"
                                   value="60" min="1">
                        </div>
                        <div class="mb-3">
                            <label class="form-label-dark">Reason</label>
                            <input type="text" name="reason" class="form-control form-control-dark"
                                   placeholder="Manual block by admin">
                        </div>
                        <button type="submit" class="btn btn-danger-custom">Block IP</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- TAB: ACCOUNT LOCKS -->
    <?php elseif ($activeTab === 'locks'): ?>
    <div class="card-dark">
        <div class="card-header">Locked Accounts</div>
        <div class="card-body p-0">
            <div style="overflow-x:auto;">
                <table class="table-dark-custom">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Reason</th>
                            <th>Locked At</th>
                            <th>Locked Until</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($lockedAccounts)): ?>
                        <tr><td colspan="5" class="text-center py-4" style="color:#888;">No locked accounts.</td></tr>
                        <?php else: ?>
                        <?php foreach ($lockedAccounts as $lock): ?>
                        <tr>
                            <td><strong style="color:#fff;"><?= htmlspecialchars($lock['username']) ?></strong></td>
                            <td><?= htmlspecialchars($lock['lock_reason'] ?? '') ?></td>
                            <td><?= htmlspecialchars(timeAgo($lock['locked_at'])) ?></td>
                            <td><?= $lock['locked_until'] ? htmlspecialchars(formatDate($lock['locked_until'], 'M j, Y H:i')) : '<span class="badge-danger">Permanent</span>' ?></td>
                            <td>
                                <button class="btn btn-success-custom btn-sm"
                                        data-unlock-user="<?= htmlspecialchars($lock['username']) ?>">
                                    Unlock
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB: COUNTRY RULES -->
    <?php elseif ($activeTab === 'countries'): ?>
    <div class="card-dark">
        <div class="card-header d-flex align-items-center justify-content-between gap-3">
            <span>Country Access Rules</span>
            <input type="text" id="countrySearch" class="form-control form-control-dark"
                   style="max-width:240px;" placeholder="Search countries...">
        </div>
        <div class="card-body p-0">
            <div style="overflow-x:auto;max-height:70vh;">
                <table class="table-dark-custom" id="countryTable">
                    <thead style="position:sticky;top:0;z-index:1;">
                        <tr>
                            <th>Code</th>
                            <th>Country</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($countries as $country): ?>
                        <tr class="country-row"
                            data-country="<?= htmlspecialchars(strtolower($country['country_name'])) ?>"
                            data-code="<?= htmlspecialchars(strtolower($country['country_code'])) ?>">
                            <td>
                                <code style="color:#ccc;"><?= htmlspecialchars($country['country_code']) ?></code>
                            </td>
                            <td><?= htmlspecialchars($country['country_name']) ?></td>
                            <td>
                                <select class="form-select form-select-dark country-status-select"
                                        style="width:auto;padding:4px 8px;font-size:0.8rem;"
                                        data-code="<?= htmlspecialchars($country['country_code']) ?>">
                                    <option value="not_specified" <?= $country['status'] === 'not_specified' ? 'selected' : '' ?>>
                                        Not Specified
                                    </option>
                                    <option value="whitelist" <?= $country['status'] === 'whitelist' ? 'selected' : '' ?>>
                                        ✅ Whitelist
                                    </option>
                                    <option value="blacklist" <?= $country['status'] === 'blacklist' ? 'selected' : '' ?>>
                                        🚫 Blacklist
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB: LOGIN HISTORY -->
    <?php elseif ($activeTab === 'history'): ?>
    <div class="card-dark mb-3">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="tab" value="history">
                <div class="col-md-3">
                    <label class="form-label-dark">Username</label>
                    <input type="text" name="huser" class="form-control form-control-dark"
                           value="<?= htmlspecialchars($histUser) ?>" placeholder="Filter by user">
                </div>
                <div class="col-md-3">
                    <label class="form-label-dark">IP Address</label>
                    <input type="text" name="hip" class="form-control form-control-dark"
                           value="<?= htmlspecialchars($histIp) ?>" placeholder="Filter by IP">
                </div>
                <div class="col-md-3">
                    <label class="form-label-dark">Action</label>
                    <select name="haction" class="form-select form-select-dark">
                        <option value="">All Actions</option>
                        <option value="login_success" <?= $histFilter === 'login_success' ? 'selected' : '' ?>>Login Success</option>
                        <option value="failed_login"  <?= $histFilter === 'failed_login'  ? 'selected' : '' ?>>Failed Login</option>
                        <option value="logout"        <?= $histFilter === 'logout'        ? 'selected' : '' ?>>Logout</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-outline-accent w-100">Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card-dark">
        <div class="card-header">
            Login History
            <span class="badge-muted ms-2" style="font-size:0.75rem;"><?= number_format($histTotal) ?> records</span>
        </div>
        <div class="card-body p-0">
            <div style="overflow-x:auto;">
                <table class="table-dark-custom">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>IP Address</th>
                            <th>Action</th>
                            <th>Details</th>
                            <th>User Agent</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($loginHistory)): ?>
                        <tr><td colspan="6" class="text-center py-4" style="color:#888;">No records found.</td></tr>
                        <?php else: ?>
                        <?php foreach ($loginHistory as $entry): ?>
                        <tr>
                            <td><strong style="color:#fff;"><?= htmlspecialchars($entry['username']) ?></strong></td>
                            <td><code style="color:#ccc;font-size:0.8rem;"><?= htmlspecialchars($entry['ip_address']) ?></code></td>
                            <td>
                                <?php
                                $ac = match($entry['action']) {
                                    'login_success' => 'badge-success',
                                    'failed_login'  => 'badge-danger',
                                    'logout'        => 'badge-muted',
                                    default         => 'badge-info',
                                };
                                ?>
                                <span class="<?= $ac ?>"><?= htmlspecialchars($entry['action']) ?></span>
                            </td>
                            <td><?= htmlspecialchars($entry['details'] ?? '') ?></td>
                            <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:0.75rem;color:#888;">
                                <?= htmlspecialchars(substr($entry['user_agent'] ?? '', 0, 80)) ?>
                            </td>
                            <td style="white-space:nowrap;font-size:0.8rem;"><?= htmlspecialchars(formatDate($entry['created_at'], 'M j H:i')) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($histPager['pages'] > 1): ?>
        <div class="card-body" style="border-top:1px solid var(--border);">
            <nav>
                <ul class="pagination pagination-dark mb-0 justify-content-center">
                    <li class="page-item <?= !$histPager['hasPrev'] ? 'disabled' : '' ?>">
                        <a class="page-link" href="?tab=history&hpage=<?= $histPager['current'] - 1 ?>&haction=<?= urlencode($histFilter) ?>&hip=<?= urlencode($histIp) ?>&huser=<?= urlencode($histUser) ?>">‹</a>
                    </li>
                    <?php for ($p = max(1, $histPager['current'] - 2); $p <= min($histPager['pages'], $histPager['current'] + 2); $p++): ?>
                    <li class="page-item <?= $p === $histPager['current'] ? 'active' : '' ?>">
                        <a class="page-link" href="?tab=history&hpage=<?= $p ?>&haction=<?= urlencode($histFilter) ?>&hip=<?= urlencode($histIp) ?>&huser=<?= urlencode($histUser) ?>"><?= $p ?></a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?= !$histPager['hasNext'] ? 'disabled' : '' ?>">
                        <a class="page-link" href="?tab=history&hpage=<?= $histPager['current'] + 1 ?>&haction=<?= urlencode($histFilter) ?>&hip=<?= urlencode($histIp) ?>&huser=<?= urlencode($histUser) ?>">›</a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script>
async function handleManualBlock(e) {
    e.preventDefault();
    const form = e.target;
    const btn  = form.querySelector('[type="submit"]');
    btn.disabled = true; btn.textContent = 'Blocking...';
    try {
        const data = new FormData(form);
        const resp = await fetch('ajax/security_actions.php', { method: 'POST', body: data });
        const json = await resp.json();
        if (typeof showToast === 'function') showToast(json.message || 'Done.', json.success ? 'success' : 'danger');
        if (json.success) setTimeout(() => location.reload(), 1000);
    } catch(e) {
        if (typeof showToast === 'function') showToast('Request failed.', 'danger');
    }
    btn.disabled = false; btn.textContent = 'Block IP';
}
</script>
</body>
</html>
