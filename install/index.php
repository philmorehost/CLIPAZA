<?php
/**
 * CLIPAZA Installer
 *
 * Like index.php, this file is intentionally written using only PHP 5.4-
 * compatible syntax so that the version check runs correctly on any PHP
 * version. No PHP 8.0+ syntax (union types, match, named arguments, etc.)
 * is used until after the version gate below.
 */

define('CLIPAZA_MIN_PHP', '8.0.0');
define('INSTALL_ROOT', dirname(__DIR__));

// ------------------------------------------------------------------
// PHP version gate
// ------------------------------------------------------------------
if (version_compare(PHP_VERSION, CLIPAZA_MIN_PHP, '<')) {
    header('Content-Type: text/html; charset=utf-8');
    header('HTTP/1.1 503 Service Unavailable');
    $required = htmlspecialchars(CLIPAZA_MIN_PHP, ENT_QUOTES, 'UTF-8');
    $current  = htmlspecialchars(PHP_VERSION,    ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PHP Version Requirement Not Met &mdash; CLIPAZA Installer</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f4f4f4;display:flex;
             align-items:center;justify-content:center;min-height:100vh;margin:0}
        .box{background:#fff;border-left:4px solid #e74c3c;border-radius:4px;
             padding:2rem 2.5rem;max-width:480px;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        h2{margin-top:0;color:#e74c3c}
        p{line-height:1.6;color:#555}
        code{background:#f0f0f0;padding:2px 6px;border-radius:3px;font-size:.9em}
    </style>
</head>
<body>
    <div class="box">
        <h2>&#9888; PHP Version Requirement Not Met</h2>
        <p>CLIPAZA requires <strong>PHP ' . $required . '</strong> or higher.</p>
        <p>Your server is running <strong>PHP <code>' . $current . '</code></strong>.</p>
        <p>Please upgrade PHP or contact your hosting provider for assistance.</p>
    </div>
</body>
</html>';
    exit;
}

// ------------------------------------------------------------------
// Past this point PHP 8.0+ syntax is safe to use.
// ------------------------------------------------------------------

// Prevent re-running the installer once it is complete.
if (file_exists(INSTALL_ROOT . '/installer.lock')) {
    header('Location: /');
    exit;
}

// ---- helpers -------------------------------------------------------

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Execute every SQL statement from the schema file against the supplied PDO
 * connection. Statements are split on semicolons that appear outside of
 * single-quoted strings so that escaped apostrophes (e.g. Côte d''Ivoire)
 * are handled correctly.
 */
function runSchema(PDO $pdo, string $schemaFile): void
{
    $sql    = file_get_contents($schemaFile);
    $stmts  = splitSqlStatements($sql);

    foreach ($stmts as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '' || str_starts_with($stmt, '--')) {
            continue;
        }
        $pdo->exec($stmt);
    }
}

/**
 * Split a SQL dump into individual statements.  Single-quoted string
 * literals (including escaped quotes written as '') are treated as
 * opaque tokens so that semicolons inside them do not cause a split.
 * SQL line comments (--) are stripped before splitting.
 */
function splitSqlStatements(string $sql): array
{
    $statements = [];
    $current    = '';
    $len        = strlen($sql);
    $i          = 0;

    while ($i < $len) {
        $ch = $sql[$i];

        // Skip line comments outside of a string literal.
        if ($ch === '-' && isset($sql[$i + 1]) && $sql[$i + 1] === '-') {
            while ($i < $len && $sql[$i] !== "\n") {
                $i++;
            }
            continue;
        }

        // Enter a single-quoted string literal.
        if ($ch === "'") {
            $current .= $ch;
            $i++;
            while ($i < $len) {
                $c = $sql[$i];
                $current .= $c;
                $i++;
                if ($c === "'") {
                    // Check for escaped quote ('').
                    if (isset($sql[$i]) && $sql[$i] === "'") {
                        $current .= $sql[$i];
                        $i++;
                        continue;
                    }
                    // End of string literal.
                    break;
                }
            }
            continue;
        }

        // Statement terminator outside a string literal.
        if ($ch === ';') {
            $stmt = trim($current);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $current = '';
            $i++;
            continue;
        }

        $current .= $ch;
        $i++;
    }

    $stmt = trim($current);
    if ($stmt !== '') {
        $statements[] = $stmt;
    }

    return $statements;
}

// ---- request handling ----------------------------------------------

$step   = isset($_GET['step']) ? (int) $_GET['step'] : 1;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    // Validate database credentials and run schema.
    $dbHost = trim($_POST['db_host'] ?? '');
    $dbPort = trim($_POST['db_port'] ?? '3306');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';

    if ($dbHost === '') {
        $errors[] = 'Database host is required.';
    }
    if ($dbName === '') {
        $errors[] = 'Database name is required.';
    }
    if ($dbUser === '') {
        $errors[] = 'Database username is required.';
    }

    if (empty($errors)) {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $dbHost,
                $dbPort,
                $dbName
            );
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ]);

            $schemaFile = __DIR__ . '/schema.sql';
            runSchema($pdo, $schemaFile);

            // Write config file.
            $configDir = INSTALL_ROOT . '/config';
            if (!is_dir($configDir)) {
                mkdir($configDir, 0755, true);
            }

            $configContent = '<?php' . PHP_EOL
                . '// Auto-generated by CLIPAZA installer – do not edit manually.' . PHP_EOL
                . "define('DB_HOST', " . var_export($dbHost, true) . ");" . PHP_EOL
                . "define('DB_PORT', " . var_export($dbPort, true) . ");" . PHP_EOL
                . "define('DB_NAME', " . var_export($dbName, true) . ");" . PHP_EOL
                . "define('DB_USER', " . var_export($dbUser, true) . ");" . PHP_EOL
                . "define('DB_PASS', " . var_export($dbPass, true) . ");" . PHP_EOL;

            file_put_contents($configDir . '/config.php', $configContent);

            // Write lock file to prevent re-running the installer.
            file_put_contents(INSTALL_ROOT . '/installer.lock', date('c'));

            header('Location: /install/?step=3');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Schema error: ' . h($e->getMessage());
        }
    }
}

// ---- layout --------------------------------------------------------

$title = match ($step) {
    2       => 'Database Configuration',
    3       => 'Installation Complete',
    default => 'System Requirements',
};

// Requirements check (step 1).
$reqs = [];
if ($step === 1) {
    $reqs = [
        ['label' => 'PHP >= 8.0', 'pass' => version_compare(PHP_VERSION, '8.0.0', '>=')],
        ['label' => 'PDO extension', 'pass' => extension_loaded('pdo')],
        ['label' => 'PDO MySQL driver', 'pass' => extension_loaded('pdo_mysql')],
        ['label' => 'config/ writable', 'pass' => is_writable(INSTALL_ROOT) || is_writable(INSTALL_ROOT . '/config')],
    ];
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($title) ?> &mdash; CLIPAZA Installer</title>
    <style>
        *{box-sizing:border-box}
        body{font-family:Arial,sans-serif;background:#f0f2f5;margin:0;padding:2rem}
        .container{max-width:600px;margin:0 auto}
        h1{color:#2c3e50;margin-bottom:.25rem}
        .subtitle{color:#888;margin-bottom:2rem}
        .card{background:#fff;border-radius:6px;padding:2rem;box-shadow:0 2px 8px rgba(0,0,0,.08);margin-bottom:1.5rem}
        .req{display:flex;align-items:center;gap:.75rem;padding:.5rem 0;border-bottom:1px solid #f0f0f0}
        .req:last-child{border-bottom:none}
        .badge{display:inline-block;padding:2px 10px;border-radius:12px;font-size:.8em;font-weight:bold}
        .pass{background:#d4edda;color:#155724}
        .fail{background:#f8d7da;color:#721c24}
        label{display:block;margin-bottom:.25rem;font-weight:bold;color:#444}
        input[type=text],input[type=password],input[type=number]{
            width:100%;padding:.55rem .75rem;border:1px solid #ccc;border-radius:4px;
            font-size:1rem;margin-bottom:1rem}
        input:focus{outline:none;border-color:#3498db;box-shadow:0 0 0 2px rgba(52,152,219,.2)}
        .btn{display:inline-block;padding:.6rem 1.4rem;background:#3498db;color:#fff;
             border:none;border-radius:4px;font-size:1rem;cursor:pointer;text-decoration:none}
        .btn:hover{background:#2980b9}
        .btn-success{background:#27ae60}
        .btn-success:hover{background:#219a52}
        .errors{background:#f8d7da;border-left:4px solid #e74c3c;padding:.75rem 1rem;
                margin-bottom:1rem;border-radius:4px}
        .errors li{color:#721c24;margin:.25rem 0}
        .step-bar{display:flex;gap:.5rem;margin-bottom:2rem}
        .step-bar span{flex:1;height:6px;border-radius:3px;background:#dee2e6}
        .step-bar span.active{background:#3498db}
        .step-bar span.done{background:#27ae60}
        .success-icon{font-size:3rem;text-align:center;margin-bottom:1rem}
    </style>
</head>
<body>
<div class="container">
    <h1>CLIPAZA Installer</h1>
    <p class="subtitle">Step <?= $step ?> of 3 &mdash; <?= h($title) ?></p>

    <div class="step-bar">
        <span class="<?= $step > 1 ? 'done' : ($step === 1 ? 'active' : '') ?>"></span>
        <span class="<?= $step > 2 ? 'done' : ($step === 2 ? 'active' : '') ?>"></span>
        <span class="<?= $step === 3 ? 'done active' : '' ?>"></span>
    </div>

    <?php if ($step === 1): ?>
    <div class="card">
        <h2 style="margin-top:0">System Requirements</h2>
        <?php foreach ($reqs as $req): ?>
        <div class="req">
            <span class="badge <?= $req['pass'] ? 'pass' : 'fail' ?>"><?= $req['pass'] ? '&#10003; OK' : '&#10007; FAIL' ?></span>
            <?= h($req['label']) ?>
        </div>
        <?php endforeach ?>
    </div>
    <?php
    $allPass = array_reduce($reqs, fn($carry, $r) => $carry && $r['pass'], true);
    if ($allPass): ?>
    <a href="?step=2" class="btn">Continue &rarr;</a>
    <?php else: ?>
    <p style="color:#e74c3c">Please fix the failing requirements before continuing.</p>
    <?php endif ?>

    <?php elseif ($step === 2): ?>
    <div class="card">
        <h2 style="margin-top:0">Database Configuration</h2>
        <?php if (!empty($errors)): ?>
        <ul class="errors">
            <?php foreach ($errors as $err): ?>
            <li><?= $err ?></li>
            <?php endforeach ?>
        </ul>
        <?php endif ?>
        <form method="post" action="?step=2">
            <label for="db_host">Database Host</label>
            <input type="text" id="db_host" name="db_host"
                   value="<?= h($_POST['db_host'] ?? 'localhost') ?>" required>

            <label for="db_port">Database Port</label>
            <input type="number" id="db_port" name="db_port"
                   value="<?= h($_POST['db_port'] ?? '3306') ?>" required>

            <label for="db_name">Database Name</label>
            <input type="text" id="db_name" name="db_name"
                   value="<?= h($_POST['db_name'] ?? '') ?>" required>

            <label for="db_user">Database Username</label>
            <input type="text" id="db_user" name="db_user"
                   value="<?= h($_POST['db_user'] ?? '') ?>" required>

            <label for="db_pass">Database Password</label>
            <input type="password" id="db_pass" name="db_pass">

            <button type="submit" class="btn">Install &rarr;</button>
        </form>
    </div>

    <?php elseif ($step === 3): ?>
    <div class="card" style="text-align:center">
        <div class="success-icon">&#9989;</div>
        <h2 style="color:#27ae60">Installation Complete!</h2>
        <p>CLIPAZA has been installed successfully.</p>
        <p style="font-size:.9em;color:#888">The installer is now locked. Delete the
           <code>installer.lock</code> file only if you need to re-install.</p>
        <a href="/" class="btn btn-success">Go to CLIPAZA &rarr;</a>
    </div>
    <?php endif ?>
</div>
</body>
</html>
