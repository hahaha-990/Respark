<?php
require_once 'config.php';
startSecureSession();

$error = '';
$lang = $_COOKIE['lang'] ?? 'ms';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sid  = trim($_POST['student_id'] ?? '');
    $pass = $_POST['password'] ?? '';
    $lang = in_array($_POST['lang'] ?? 'ms', ['ms','en']) ? $_POST['lang'] : 'ms';

    if ($sid && $pass) {
        $stmt = db()->prepare("SELECT * FROM users WHERE student_id = ? AND is_active = 1");
        $stmt->execute([$sid]);
        $user = $stmt->fetch();

        if ($user && $pass === $user['password_hash']) {
            $_SESSION['user'] = $user;
            setcookie('lang', $lang, time() + 86400*365, '/', '', false, true);
            header('Location: ' . ($user['role'] === 'student' ? 'student_dashboard.php' : 'admin_dashboard.php'));
            exit;
        } else {
            $error = ($lang === 'ms') ? 'ID atau kata laluan tidak sah.' : 'Invalid ID or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= h($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ParkCampus – <?= t('login', $lang) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body class="login-body">

<div class="login-split">
    <!-- Left Panel -->
    <div class="login-brand">
        <div class="brand-inner">
            <div class="brand-icon">
                <svg viewBox="0 0 60 60" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="5" y="5" width="50" height="50" rx="12" fill="var(--accent)"/>
                    <text x="30" y="38" text-anchor="middle" fill="white" font-size="28" font-family="Syne" font-weight="800">P</text>
                </svg>
            </div>
            <h1 class="brand-name">ParkCampus</h1>
            <p class="brand-tagline"><?= t('tagline', $lang) ?></p>

            <div class="brand-features">
                <div class="feat-item"><span class="feat-dot"></span>Pengesahan jadual kelas secara langsung</div>
                <div class="feat-item"><span class="feat-dot"></span>Ketersediaan slot masa nyata</div>
                <div class="feat-item"><span class="feat-dot"></span>Notifikasi dalam Bahasa Malaysia</div>
            </div>

            <div class="brand-visual">
                <div class="parking-grid-demo">
                    <?php for($i=0;$i<12;$i++): ?>
                    <div class="demo-slot <?= ($i < 3) ? 'demo-slot--free' : (($i < 9) ? 'demo-slot--taken' : 'demo-slot--free') ?>"></div>
                    <?php endfor; ?>
                </div>
                <p class="demo-label">3 slot tersedia · Zon Kejuruteraan</p>
            </div>
        </div>
    </div>

    <!-- Right Panel: Login Form -->
    <div class="login-form-panel">
        <div class="login-form-inner">
            <div class="lang-switcher">
                <a href="?lang=ms" class="<?= $lang==='ms'?'active':'' ?>">BM</a>
                <span>|</span>
                <a href="?lang=en" class="<?= $lang==='en'?'active':'' ?>">EN</a>
            </div>

            <h2 class="form-title"><?= t('login', $lang) ?></h2>
            <p class="form-sub"><?= $lang==='ms' ? 'Masukkan maklumat anda untuk meneruskan' : 'Enter your credentials to continue' ?></p>

            <?php if ($error): ?>
            <div class="alert alert--error"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="lang" value="<?= h($lang) ?>">

                <div class="field-group">
                    <label class="field-label"><?= t('student_id', $lang) ?></label>
                    <input class="field-input" type="text" name="student_id"
                           placeholder="A22EC0001" required autocomplete="username"
                           value="<?= h($_POST['student_id'] ?? '') ?>">
                </div>

                <div class="field-group">
                    <label class="field-label"><?= t('password', $lang) ?></label>
                    <input class="field-input" type="password" name="password"
                           placeholder="••••••••" required autocomplete="current-password">
                </div>

                <button type="submit" class="btn btn--primary btn--full"><?= t('login', $lang) ?></button>
            </form>

            <div class="demo-creds">
                <p><strong><?= $lang==='ms'?'Akaun demo:':'Demo accounts:' ?></strong></p>
                <p>Pelajar: <code>A22EC0001</code> / <code>password123</code></p>
                <p>Admin: <code>ADMIN001</code> / <code>password123</code></p>
            </div>
        </div>
    </div>
</div>

<script>
// Preserve lang in link clicks
const langParam = new URLSearchParams(window.location.search).get('lang');
if (langParam) document.cookie = `lang=${langParam};path=/;max-age=${365*86400}`;
</script>
</body>
</html>
