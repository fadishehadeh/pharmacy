<?php
require_once __DIR__ . '/config/app.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user'] = $user;
        $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
        try {
            $db->prepare("INSERT INTO login_log (user_id, login_time, ip_address, user_agent, status) VALUES (?,NOW(),?,?,?)")
                ->execute([$user['id'], $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', 'success']);
            $_SESSION['login_log_id'] = $db->lastInsertId();
        } catch (Exception $e) {}
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    } else {
        if ($user) {
            try {
                $db->prepare("INSERT INTO login_log (user_id, login_time, ip_address, user_agent, status) VALUES (?,NOW(),?,?,?)")
                    ->execute([$user['id'], $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', 'failed']);
            } catch (Exception $e) {}
        }
        $error = 'Invalid username or password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - PharmaSys</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #1E3A5F 0%, #2563EB 100%); min-height: 100vh; display: flex; align-items: center; }
        .login-card { max-width: 400px; width: 100%; border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
    </style>
</head>
<body>
<div class="container">
    <div class="card login-card mx-auto">
        <div class="card-body p-5">
            <div class="text-center mb-4">
                <i class="bi bi-capsule display-3 text-primary"></i>
                <h3 class="mt-2">PharmaSys</h3>
                <p class="text-muted">Pharmacy Management System</p>
            </div>
            <?php if ($error): ?>
            <div class="alert alert-danger"><?= sanitize($error) ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" class="form-control" name="username" required autofocus>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control" name="password" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 py-2">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                </button>
            </form>
            <div class="text-center mt-3">
                <small class="text-muted">Default: admin / password</small>
            </div>
        </div>
    </div>
</div>
</body>
</html>
