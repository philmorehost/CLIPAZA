<?php
declare(strict_types=1);

$errors = [];
$formData = $_SESSION['db_config'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['db_next'])) {
    $host = trim($_POST['db_host'] ?? 'localhost');
    $port = trim($_POST['db_port'] ?? '3306');
    $name = trim($_POST['db_name'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $pass = $_POST['db_pass'] ?? '';

    if (empty($name)) $errors[] = 'Database name is required.';
    if (empty($user)) $errors[] = 'Database user is required.';

    if (empty($errors)) {
        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $_SESSION['db_config'] = compact('host', 'port', 'name', 'user', 'pass');
            $_SESSION['install_step_2_done'] = true;
            header('Location: ?step=3');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Connection failed: ' . $e->getMessage();
        }
    }

    $formData = compact('host', 'port', 'name', 'user', 'pass');
}
?>
<h3 style="font-size:1.1rem;font-weight:700;margin-bottom:4px;">Database Configuration</h3>
<p style="color:#666;font-size:0.875rem;margin-bottom:24px;">Enter your MySQL database connection details.</p>

<?php foreach ($errors as $err): ?>
<div class="alert-dark-danger mb-3"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<form method="POST" id="dbForm">
    <div class="mb-3">
        <label class="form-label-dark">Database Host</label>
        <input type="text" name="db_host" class="form-control form-control-dark"
               value="<?= htmlspecialchars($formData['host'] ?? 'localhost') ?>" placeholder="localhost">
    </div>
    <div class="mb-3">
        <label class="form-label-dark">Database Port</label>
        <input type="number" name="db_port" class="form-control form-control-dark"
               value="<?= htmlspecialchars($formData['port'] ?? '3306') ?>" placeholder="3306">
    </div>
    <div class="mb-3">
        <label class="form-label-dark">Database Name</label>
        <input type="text" name="db_name" class="form-control form-control-dark"
               value="<?= htmlspecialchars($formData['name'] ?? '') ?>" placeholder="clipaza_db" required>
    </div>
    <div class="mb-3">
        <label class="form-label-dark">Database User</label>
        <input type="text" name="db_user" class="form-control form-control-dark"
               value="<?= htmlspecialchars($formData['user'] ?? '') ?>" placeholder="db_username" required>
    </div>
    <div class="mb-3">
        <label class="form-label-dark">Database Password</label>
        <input type="password" name="db_pass" class="form-control form-control-dark"
               value="<?= htmlspecialchars($formData['pass'] ?? '') ?>" placeholder="database password">
    </div>

    <div id="dbTestResult" style="display:none;"></div>

    <div class="d-flex justify-content-between align-items-center mt-4">
        <button type="button" id="testDbBtn" class="btn btn-outline-accent">
            Test Connection
        </button>
        <div class="d-flex gap-2">
            <a href="?step=1" class="btn" style="color:#888;background:rgba(255,255,255,0.05);border-radius:8px;padding:10px 20px;">← Back</a>
            <button type="submit" name="db_next" value="1" class="btn btn-accent">Continue →</button>
        </div>
    </div>
</form>