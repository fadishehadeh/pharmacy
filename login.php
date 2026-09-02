<?php
require_once __DIR__ . '/config/app.php';
// initLang() already called by app.php

// Prevent browser from caching the login page (avoids stale CSRF tokens)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');

if (isLoggedIn()) {
    header('Location: /welcome.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $providedToken = $_POST['_csrf'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $providedToken)) {
        // Token mismatch = stale page. Regenerate token and redirect to fresh login.
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header('Location: /login.php');
        exit;
    } else {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user']    = $user;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
            try {
                $db->prepare("INSERT INTO login_log (user_id, login_time, ip_address, user_agent, status) VALUES (?,NOW(),?,?,?)")
                    ->execute([$user['id'], $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', 'success']);
                $_SESSION['login_log_id'] = $db->lastInsertId();
            } catch (Exception $e) {}
            header('Location: ' . BASE_URL . '/welcome.php');
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
}

$pharmacyName = 'PharmaSys';
$quickUsers = [];
try {
    $db = getDB();
    $pn = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'pharmacy_name'")->fetchColumn();
    if ($pn) $pharmacyName = $pn;
    $quickUsers = $db->query("SELECT username, full_name, role FROM users WHERE is_active = 1 ORDER BY role DESC, full_name ASC LIMIT 6")->fetchAll();
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="<?= langCode() ?>" dir="<?= isRtl() ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('sign_in') ?> — <?= htmlspecialchars($pharmacyName, ENT_QUOTES, 'UTF-8') ?></title>
    <?php if (isRtl()): ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <?php else: ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php endif; ?>
    <meta name="theme-color" content="#2563EB">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/assets/icons/icon-192.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; font-family: 'Inter', sans-serif; }

        .login-wrap {
            display: flex;
            min-height: 100vh;
        }

        /* LEFT PANEL */
        .login-brand {
            flex: 1;
            background: linear-gradient(145deg, #0F172A 0%, #1E3A5F 50%, #2563EB 100%);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 3rem;
            color: #fff;
            position: relative;
            overflow: hidden;
        }
        .login-brand::before {
            content: '';
            position: absolute;
            width: 500px; height: 500px;
            border-radius: 50%;
            background: rgba(255,255,255,0.03);
            top: -150px; right: -150px;
        }
        .login-brand::after {
            content: '';
            position: absolute;
            width: 300px; height: 300px;
            border-radius: 50%;
            background: rgba(255,255,255,0.04);
            bottom: -80px; left: -80px;
        }
        .brand-icon {
            width: 80px; height: 80px;
            background: rgba(255,255,255,0.12);
            border-radius: 20px;
            display: flex; align-items: center; justify-content: center;
            font-size: 2.5rem;
            margin-bottom: 1.5rem;
            backdrop-filter: blur(10px);
        }
        .brand-name {
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: -0.5px;
            text-align: center;
        }
        .brand-tagline {
            font-size: 0.95rem;
            opacity: 0.65;
            text-align: center;
            margin-top: 0.5rem;
            max-width: 260px;
            line-height: 1.6;
        }
        .brand-pills {
            display: flex;
            gap: 0.6rem;
            margin-top: 2.5rem;
            flex-wrap: wrap;
            justify-content: center;
        }
        .brand-pill {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 20px;
            padding: 5px 14px;
            font-size: 0.78rem;
            color: rgba(255,255,255,0.8);
            backdrop-filter: blur(6px);
        }

        /* RIGHT PANEL */
        .login-form-wrap {
            width: 460px;
            min-width: 360px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 3rem 2.5rem;
            background: #fff;
        }
        .login-form-wrap .form-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #0F172A;
            margin-bottom: 0.25rem;
        }
        .login-form-wrap .form-sub {
            font-size: 0.875rem;
            color: #94A3B8;
            margin-bottom: 2rem;
        }
        .form-label {
            font-size: 0.82rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 5px;
        }
        .input-group-text {
            background: #F8FAFC;
            border-color: #E2E8F0;
            color: #94A3B8;
        }
        .form-control {
            border-color: #E2E8F0;
            font-size: 0.9rem;
            padding: 0.6rem 0.85rem;
        }
        .form-control:focus {
            border-color: #2563EB;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
        }
        .btn-signin {
            background: linear-gradient(135deg, #1D4ED8, #2563EB);
            border: none;
            color: #fff;
            font-weight: 600;
            padding: 0.7rem;
            border-radius: 10px;
            font-size: 0.95rem;
            transition: opacity .2s;
        }
        .btn-signin:hover { opacity: 0.9; color: #fff; }
        .login-footer {
            text-align: center;
            font-size: 0.78rem;
            color: #CBD5E1;
            margin-top: 2rem;
        }

        @media (max-width: 768px) {
            .login-brand { display: none; }
            .login-form-wrap { width: 100%; padding: 2rem 1.5rem; }
        }

        /* Quick users */
        .quick-users-wrap { margin-top: 1.5rem; }
        .quick-users-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: #CBD5E1;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.75rem;
        }
        .quick-users-grid {
            display: flex;
            gap: 0.6rem;
            flex-wrap: wrap;
        }
        .quick-user-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            padding: 10px 14px;
            cursor: pointer;
            transition: all .15s;
            min-width: 70px;
        }
        .quick-user-btn:hover {
            background: #EFF6FF;
            border-color: #BFDBFE;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(37,99,235,0.08);
        }
        .qu-avatar {
            width: 36px; height: 36px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.8rem;
            font-weight: 700;
        }
        .qu-name {
            font-size: 0.78rem;
            font-weight: 600;
            color: #334155;
        }
        .qu-role {
            font-size: 0.68rem;
            color: #94A3B8;
        }
        .quick-user-btn.qu-active {
            background: #EFF6FF;
            border-color: #2563EB;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.15);
        }
    </style>
</head>
<body>
<div class="login-wrap">

    <!-- LEFT: Branding -->
    <div class="login-brand">
        <div class="brand-icon"><i class="bi bi-capsule"></i></div>
        <div class="brand-name"><?= htmlspecialchars($pharmacyName, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="brand-tagline">Complete pharmacy management — from sales to compliance</div>
        <div class="brand-pills">
            <span class="brand-pill"><i class="bi bi-cart3 me-1"></i>Point of Sale</span>
            <span class="brand-pill"><i class="bi bi-box-seam me-1"></i>Inventory</span>
            <span class="brand-pill"><i class="bi bi-building me-1"></i>MoPH</span>
            <span class="brand-pill"><i class="bi bi-shield-plus me-1"></i>Insurance</span>
            <span class="brand-pill"><i class="bi bi-bar-chart-line me-1"></i>Reports</span>
        </div>
    </div>

    <!-- RIGHT: Form -->
    <div class="login-form-wrap">
        <!-- Language toggle -->
        <div style="position:absolute;top:1.5rem;right:1.5rem">
            <a href="?setlang=<?= langCode() === 'ar' ? 'en' : 'ar' ?>" style="font-size:.8rem;font-weight:700;color:#94A3B8;text-decoration:none;border:1px solid #E2E8F0;border-radius:6px;padding:3px 9px">
                <?= langCode() === 'ar' ? 'EN' : 'ع' ?>
            </a>
        </div>

        <div class="form-title"><?= t('welcome_back') ?></div>
        <div class="form-sub"><?= t('sign_in_sub') ?></div>

        <?php if ($error): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 py-2" style="font-size:.85rem;border-radius:10px">
            <i class="bi bi-exclamation-circle-fill"></i>
            <?= t('invalid_login') ?>
        </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

            <div class="mb-3">
                <label class="form-label"><?= t('username') ?></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                    <input type="text" class="form-control" name="username" placeholder="<?= t('username') ?>" required autofocus>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label"><?= t('password') ?></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" class="form-control" name="password" id="passInput" placeholder="<?= t('password') ?>" required>
                    <button class="input-group-text" type="button" onclick="togglePass()" style="cursor:pointer">
                        <i class="bi bi-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-signin w-100">
                <i class="bi bi-box-arrow-in-right me-2"></i><?= t('sign_in') ?>
            </button>
        </form>

        <?php if (!empty($quickUsers)): ?>
        <div class="quick-users-wrap">
            <div class="quick-users-label"><?= t('quick_access') ?></div>
            <div class="quick-users-grid">
                <?php
                $roleColors = ['admin'=>'#7C3AED','pharmacist'=>'#0891B2','cashier'=>'#059669','viewer'=>'#64748B'];
                foreach ($quickUsers as $qu):
                    $initials = strtoupper(substr($qu['full_name'] ?? $qu['username'], 0, 1));
                    if (strpos($qu['full_name'] ?? '', ' ') !== false)
                        $initials .= strtoupper(substr(strrchr($qu['full_name'], ' '), 1, 1));
                    $color = $roleColors[$qu['role']] ?? '#64748B';
                ?>
                <button type="button" class="quick-user-btn"
                        onclick="fillUser(this, <?= htmlspecialchars(json_encode($qu['username']), ENT_QUOTES, 'UTF-8') ?>, '1234')">
                    <div class="qu-avatar" style="background:<?= $color ?>15;color:<?= $color ?>"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="qu-name"><?= htmlspecialchars(explode(' ', trim($qu['full_name'] ?? $qu['username']))[0], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="qu-role"><?= htmlspecialchars(ucfirst($qu['role']), ENT_QUOTES, 'UTF-8') ?></div>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="login-footer">
            &copy; <?= date('Y') ?> <?= htmlspecialchars($pharmacyName, ENT_QUOTES, 'UTF-8') ?> &mdash; Powered by PharmaSys
        </div>
    </div>
</div>

<script>
function togglePass() {
    var inp = document.getElementById('passInput');
    var ico = document.getElementById('eyeIcon');
    if (inp.type === 'password') {
        inp.type = 'text';
        ico.className = 'bi bi-eye-slash';
    } else {
        inp.type = 'password';
        ico.className = 'bi bi-eye';
    }
}

function fillUser(btn, username, password) {
    var u = document.querySelector('input[name="username"]');
    var p = document.getElementById('passInput');
    var form = document.querySelector('form');

    // Highlight selected card
    document.querySelectorAll('.quick-user-btn').forEach(function(b) {
        b.classList.remove('qu-active');
    });
    btn.classList.add('qu-active');

    u.value = username;
    p.value = password || '';

    // Submit after brief highlight flash
    setTimeout(function() {
        form.submit();
    }, 350);
}
</script>
</body>
</html>
