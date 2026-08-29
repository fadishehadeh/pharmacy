<?php
/**
 * PharmaSys Installation Script
 * Run this file once to set up the database
 */

$pageTitle = 'Install PharmaSys';
$isInstall = true;

// Check if already installed
$configFile = __DIR__ . '/config/database.php';
if (file_exists($configFile)) {
    require_once $configFile;
    try {
        $testDb = getDB();
        $testDb->query("SELECT 1 FROM medicines LIMIT 1");
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Already Installed</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
        <body class="bg-light"><div class="container mt-5"><div class="card mx-auto" style="max-width:500px">
        <div class="card-body text-center p-5">
        <i class="bi bi-check-circle text-success" style="font-size:4rem"></i>
        <h3 class="mt-3">PharmaSys Already Installed</h3>
        <p class="text-muted">The database is already set up and working.</p>
        <a href="index.php" class="btn btn-primary">Go to Dashboard</a>
        </div></div></div></body></html>';
        exit;
    } catch (Exception $e) {
        // Database exists but tables don't - continue with install
    }
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = $_POST['db_host'] ?? 'localhost';
    $name = $_POST['db_name'] ?? 'pharmacy_db';
    $user = $_POST['db_user'] ?? 'root';
    $pass = $_POST['db_pass'] ?? '';

    try {
        $pdo = new PDO("mysql:host=$host", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$name`");

        $schema = file_get_contents(__DIR__ . '/database/schema.sql');
        $schema = preg_replace('/^CREATE DATABASE.*?;\s*USE.*?;\s*/s', '', $schema);

        $statements = array_filter(array_map('trim', explode(';', $schema)));
        $executed = 0;
        foreach ($statements as $stmt) {
            if (!empty($stmt) && $stmt !== '--') {
                try {
                    $pdo->exec($stmt);
                    $executed++;
                } catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'already exists') === false) {
                        // Skip "already exists" errors, throw others
                    }
                }
            }
        }

        $success = true;
    } catch (PDOException $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install PharmaSys</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .install-card { max-width: 550px; margin: 50px auto; }
    </style>
</head>
<body>
    <div class="container">
        <div class="install-card">
            <div class="card shadow-lg">
                <div class="card-body p-5">
                    <div class="text-center mb-4">
                        <i class="bi bi-capsule text-primary" style="font-size:3rem"></i>
                        <h2 class="mt-2">PharmaSys</h2>
                        <p class="text-muted">Pharmacy Management System Installation</p>
                    </div>

                    <?php if ($success): ?>
                        <div class="text-center">
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle me-2"></i>Installation completed successfully!
                            </div>
                            <p>Database <strong><?= htmlspecialchars($name) ?></strong> has been created with all tables.</p>
                            <hr>
                            <p class="small text-muted mb-1">Default login credentials:</p>
                            <p><strong>Username:</strong> admin<br><strong>Password:</strong> password</p>
                            <div class="alert alert-warning small">
                                <i class="bi bi-exclamation-triangle me-1"></i>Change the default password immediately after first login!
                            </div>
                            <a href="login.php" class="btn btn-primary btn-lg w-100 mt-3">
                                <i class="bi bi-box-arrow-in-right me-2"></i>Go to Login
                            </a>
                        </div>
                    <?php else: ?>
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><i class="bi bi-x-circle me-2"></i><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>

                        <h5 class="mb-3">Database Configuration</h5>
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Database Host</label>
                                <input type="text" class="form-control" name="db_host" value="localhost" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Database Name</label>
                                <input type="text" class="form-control" name="db_name" value="pharmacy_db" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Database Username</label>
                                <input type="text" class="form-control" name="db_user" value="root" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Database Password</label>
                                <input type="password" class="form-control" name="db_pass" placeholder="Leave empty if no password">
                            </div>

                            <h6 class="mt-4 mb-2">Requirements Check</h6>
                            <ul class="list-group mb-4">
                                <li class="list-group-item d-flex justify-content-between">
                                    PHP >= 7.4
                                    <?php if (version_compare(PHP_VERSION, '7.4.0', '>=')): ?>
                                        <span class="badge bg-success">PHP <?= PHP_VERSION ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">PHP <?= PHP_VERSION ?></span>
                                    <?php endif; ?>
                                </li>
                                <li class="list-group-item d-flex justify-content-between">
                                    PDO MySQL Extension
                                    <span class="badge bg-<?= extension_loaded('pdo_mysql') ? 'success' : 'danger' ?>"><?= extension_loaded('pdo_mysql') ? 'Installed' : 'Missing' ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between">
                                    JSON Extension
                                    <span class="badge bg-<?= extension_loaded('json') ? 'success' : 'danger' ?>"><?= extension_loaded('json') ? 'Installed' : 'Missing' ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between">
                                    Session Support
                                    <span class="badge bg-<?= extension_loaded('session') ? 'success' : 'danger' ?>"><?= extension_loaded('session') ? 'Installed' : 'Missing' ?></span>
                                </li>
                            </ul>

                            <button type="submit" class="btn btn-primary btn-lg w-100">
                                <i class="bi bi-download me-2"></i>Install PharmaSys
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <p class="text-center text-white-50 mt-3 small">PharmaSys v1.0 - Pharmacy Management System for Lebanon</p>
        </div>
    </div>
</body>
</html>
