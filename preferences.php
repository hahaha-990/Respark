<?php
require_once 'config.php';
requireLogin();
$user = currentUser();
$lang = $user['lang_pref'] ?? 'ms';
$msg  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newLang  = in_array($_POST['lang_pref'] ?? '', ['ms','en']) ? $_POST['lang_pref'] : 'ms';
    $notifPush = isset($_POST['notify_push']) ? 1 : 0;
    $notifSms  = isset($_POST['notify_sms'])  ? 1 : 0;

    db()->prepare("UPDATE users SET lang_pref=?, notify_push=?, notify_sms=? WHERE id=?")
       ->execute([$newLang, $notifPush, $notifSms, $user['id']]);

    // Refresh session
    $stmt = db()->prepare("SELECT * FROM users WHERE id=?");
    $stmt->execute([$user['id']]);
    $_SESSION['user'] = $stmt->fetch();
    $user = $_SESSION['user'];
    $lang = $newLang;
    $msg  = $lang === 'ms' ? 'Tetapan disimpan.' : 'Settings saved.';
}
?>
<!DOCTYPE html>
<html lang="<?= h($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ParkCampus – <?= $lang==='ms'?'Keutamaan':'Preferences' ?></title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body>
<nav class="topnav">
    <div class="nav-brand">
        <svg viewBox="0 0 40 40" width="32" height="32"><rect width="40" height="40" rx="8" fill="var(--accent)"/><text x="20" y="27" text-anchor="middle" fill="white" font-size="20" font-family="Syne" font-weight="800">P</text></svg>
        ParkCampus
    </div>
    <div class="nav-right">
        <a href="student_dashboard.php" class="btn btn--outline btn--sm">← <?= $lang==='ms'?'Dashboard':'Dashboard' ?></a>
        <a href="logout.php" class="btn btn--outline btn--sm"><?= t('logout', $lang) ?></a>
    </div>
</nav>

<div style="max-width:480px; margin: calc(var(--topnav-h) + 3rem) auto 3rem; padding: 0 1.5rem;">
    <h2 class="section-title">🌐 <?= $lang==='ms'?'Bahasa & Notifikasi':'Language & Notifications' ?></h2>

    <?php if ($msg): ?>
    <div class="alert alert--success"><?= h($msg) ?></div>
    <?php endif; ?>

    <div style="background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius-lg); padding:1.75rem;">
        <form method="POST">
            <div class="field-group">
                <label class="field-label"><?= $lang==='ms'?'Bahasa Pilihan':'Preferred Language' ?></label>
                <select name="lang_pref" class="field-input">
                    <option value="ms" <?= $user['lang_pref']==='ms'?'selected':'' ?>>Bahasa Malaysia</option>
                    <option value="en" <?= $user['lang_pref']==='en'?'selected':'' ?>>English</option>
                </select>
            </div>

            <div class="field-group">
                <label class="field-label"><?= $lang==='ms'?'Pilihan Notifikasi':'Notification Preferences' ?> (SR5)</label>
                <label style="display:flex;align-items:center;gap:.6rem;margin-bottom:.6rem;cursor:pointer;">
                    <input type="checkbox" name="notify_push" <?= $user['notify_push']?'checked':'' ?> style="width:16px;height:16px;accent-color:var(--accent);">
                    <?= $lang==='ms'?'Notifikasi Push (dalam app)':'Push Notifications (in-app)' ?>
                </label>
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;">
                    <input type="checkbox" name="notify_sms" <?= $user['notify_sms']?'checked':'' ?> style="width:16px;height:16px;accent-color:var(--accent);">
                    <?= $lang==='ms'?'Notifikasi SMS':'SMS Notifications' ?>
                </label>
                <p class="muted-text" style="margin-top:.6rem;">
                    <?= $lang==='ms'?'Nyahtanda untuk opt-out. Tetapan disimpan dengan selamat (SR5).':'Uncheck to opt-out. Settings stored securely (SR5).' ?>
                </p>
            </div>

            <button type="submit" class="btn btn--primary"><?= $lang==='ms'?'Simpan':'Save' ?></button>
        </form>
    </div>
</div>
</body>
</html>
