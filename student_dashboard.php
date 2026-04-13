<?php
require_once 'config.php';
requireLogin();
$user = currentUser();
if ($user['role'] !== 'student') { header('Location: admin_dashboard.php'); exit; }

$lang = $user['lang_pref'] ?? 'ms';
$now  = new DateTime();

// ── Timetable lookup (FR1 / SR1) ─────────────────────────
$dow   = (int)$now->format('w'); // 0=Sun
$time  = $now->format('H:i:s');
$date  = $now->format('Y-m-d');

$stmtTT = db()->prepare("
    SELECT ct.*, cz.name as zone_name, cz.name_ms as zone_name_ms, cz.code as zone_code
    FROM class_timetable ct
    JOIN campus_zones cz ON ct.zone_id = cz.id
    WHERE ct.student_id = ?
      AND ct.day_of_week = ?
      AND ? BETWEEN ct.effective_from AND ct.effective_to
    ORDER BY ct.start_time
");
$stmtTT->execute([$user['user_id'], $dow, $date]);
$todayClasses = $stmtTT->fetchAll();

// Active or upcoming class within 30 min window
$eligibleClass = null;
foreach ($todayClasses as $cls) {
    $start = new DateTime($date . ' ' . $cls['start_time']);
    $end   = new DateTime($date . ' ' . $cls['end_time']);
    $openAt = clone $start; $openAt->modify('-30 minutes');
    if ($now >= $openAt && $now <= $end) {
        $eligibleClass = $cls;
        break;
    }
}

// Log eligibility (SR2)
logEligibility($user['user_id'], 'dashboard_load', !empty($todayClasses),
    $eligibleClass ? 'allowed' : 'denied',
    $eligibleClass ? '' : 'No active/upcoming class in 30min window');

// ── Available slots ────────────────────────────────────────
$availableSlots = [];
if ($eligibleClass) {
    $classStart = $date . ' ' . $eligibleClass['start_time'];
    $classEnd   = $date . ' ' . $eligibleClass['end_time'];

    $stmtSlots = db()->prepare("
        SELECT ps.id, ps.slot_code, ps.floor_level, cz.name as zone_name, cz.name_ms as zone_name_ms, cz.code as zone_code
        FROM parking_slots ps
        JOIN campus_zones cz ON ps.zone_id = cz.id
        WHERE ps.zone_id = ?
          AND ps.is_active = 1
          AND ps.id NOT IN (
              SELECT b.slot_id FROM bookings b
              WHERE b.status IN ('confirmed','active','pending')
                AND b.class_start < ? AND b.class_end > ?
          )
        LIMIT 20
    ");
    $stmtSlots->execute([$eligibleClass['zone_id'], $classEnd, $classStart]);
    $availableSlots = $stmtSlots->fetchAll();
}

// ── My active bookings ────────────────────────────────────
$stmtBk = db()->prepare("
    SELECT b.*, ps.slot_code, cz.name as zone_name, cz.name_ms as zone_name_ms, cz.code as zone_code,
           ct.course_name, ct.course_code
    FROM bookings b
    JOIN parking_slots ps ON b.slot_id = ps.id
    JOIN campus_zones cz ON ps.zone_id = cz.id
    JOIN class_timetable ct ON b.timetable_id = ct.id
    WHERE b.student_id = ?
      AND b.status IN ('pending','confirmed','active')
    ORDER BY b.class_start DESC
");
$stmtBk->execute([$user['user_id']]);
$activeBookings = $stmtBk->fetchAll();

// ── Notifications ─────────────────────────────────────────
$stmtNotif = db()->prepare("
    SELECT * FROM notifications
    WHERE student_id = ? AND delivered = 0
    ORDER BY id DESC LIMIT 10
");
$stmtNotif->execute([$user['user_id']]);
$notifications = $stmtNotif->fetchAll();
// Mark as delivered
if ($notifications) {
    $ids = implode(',', array_column($notifications, 'id'));
    db()->exec("UPDATE notifications SET delivered = 1, sent_at = NOW() WHERE id IN ($ids)");
}

// ── Booking history ───────────────────────────────────────
$stmtHist = db()->prepare("
    SELECT b.*, ps.slot_code, cz.name as zone_name, cz.name_ms as zone_name_ms,
           ct.course_name, ct.course_code
    FROM bookings b
    JOIN parking_slots ps ON b.slot_id = ps.id
    JOIN campus_zones cz ON ps.zone_id = cz.id
    JOIN class_timetable ct ON b.timetable_id = ct.id
    WHERE b.student_id = ?
      AND b.status IN ('completed','cancelled','auto_cancelled')
    ORDER BY b.booked_at DESC LIMIT 10
");
$stmtHist->execute([$user['user_id']]);
$history = $stmtHist->fetchAll();

$zoneName = fn($row) => $lang === 'ms' ? ($row['zone_name_ms'] ?? $row['zone_name']) : $row['zone_name'];
?>
<!DOCTYPE html>
<html lang="<?= h($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ParkCampus – Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body>

<!-- TOP NAV -->
<nav class="topnav">
    <div class="nav-brand">
        <svg viewBox="0 0 40 40" width="32" height="32"><rect width="40" height="40" rx="8" fill="var(--accent)"/><text x="20" y="27" text-anchor="middle" fill="white" font-size="20" font-family="Syne" font-weight="800">P</text></svg>
        ParkCampus
    </div>
    <div class="nav-right">
        <?php if ($notifications): ?>
        <button class="notif-bell" id="notifBell" title="Notifikasi">
            🔔 <span class="notif-badge"><?= count($notifications) ?></span>
        </button>
        <?php endif; ?>
        <span class="nav-user">👤 <?= h($user['full_name']) ?></span>
        <a href="logout.php" class="btn btn--outline btn--sm"><?= t('logout', $lang) ?></a>
    </div>
</nav>

<!-- NOTIFICATION TOAST -->
<?php foreach ($notifications as $notif): ?>
<div class="toast toast--<?= h($notif['type']) ?>" data-auto-hide="5000">
    <span class="toast-icon"><?= $notif['type']==='confirmation'?'✅':($notif['type']==='reminder_15min'?'⏰':'🚗') ?></span>
    <span><?= h($notif['message_body']) ?></span>
</div>
<?php endforeach; ?>

<div class="dashboard-layout">

    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="sidebar-section">
            <p class="sidebar-label"><?= $lang==='ms'?'Jadual Hari Ini':'Today\'s Schedule' ?></p>
            <?php if ($todayClasses): ?>
                <?php foreach ($todayClasses as $cls): ?>
                <div class="schedule-card <?= ($eligibleClass && $eligibleClass['id']==$cls['id']) ? 'schedule-card--active' : '' ?>">
                    <div class="schedule-time"><?= substr($cls['start_time'],0,5) ?> – <?= substr($cls['end_time'],0,5) ?></div>
                    <div class="schedule-course"><?= h($cls['course_code']) ?></div>
                    <div class="schedule-venue"><?= h($lang==='ms'?$cls['zone_name_ms']:$cls['zone_name']) ?></div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="muted-text"><?= $lang==='ms'?'Tiada kelas hari ini.':'No classes today.' ?></p>
            <?php endif; ?>
        </div>

        <div class="sidebar-section">
            <p class="sidebar-label"><?= $lang==='ms'?'Tetapan':'Settings' ?></p>
            <a href="preferences.php" class="sidebar-link">🌐 <?= $lang==='ms'?'Bahasa & Notifikasi':'Language & Notifications' ?></a>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main-content">

        <!-- ELIGIBILITY BANNER -->
        <?php if ($eligibleClass): ?>
        <div class="banner banner--green">
            ✅ <?= $lang==='ms'
                ? 'Anda layak: <strong>' . h($eligibleClass['course_name']) . '</strong> · ' . substr($eligibleClass['start_time'],0,5) . '–' . substr($eligibleClass['end_time'],0,5) . ' · ' . h($zoneName($eligibleClass))
                : 'Eligible class: <strong>' . h($eligibleClass['course_name']) . '</strong> · ' . substr($eligibleClass['start_time'],0,5) . '–' . substr($eligibleClass['end_time'],0,5) . ' · ' . h($zoneName($eligibleClass)) ?>
        </div>
        <?php else: ?>
        <div class="banner banner--red">
            <?= t('restricted', $lang) ?> — <?= $lang==='ms'?'Anda hanya boleh menempah slot ketika kelas aktif atau dalam 30 minit sebelum kelas.':'You may only book slots during or within 30 min before an active class.' ?>
        </div>
        <?php endif; ?>

        <!-- ACTIVE BOOKINGS -->
        <?php if ($activeBookings): ?>
        <section class="section">
            <h2 class="section-title"><?= t('my_bookings', $lang) ?></h2>
            <div class="booking-cards">
                <?php foreach ($activeBookings as $bk): ?>
                <div class="booking-card booking-card--<?= h($bk['status']) ?>">
                    <div class="booking-card__header">
                        <span class="booking-slot"><?= h($bk['slot_code']) ?></span>
                        <span class="booking-status status--<?= h($bk['status']) ?>"><?= strtoupper($bk['status']) ?></span>
                    </div>
                    <div class="booking-card__body">
                        <p><strong><?= h($bk['course_code']) ?></strong> — <?= h($lang==='ms'?$bk['zone_name_ms']:$bk['zone_name']) ?></p>
                        <p>🕐 <?= date('h:i A', strtotime($bk['class_start'])) ?> → <?= date('h:i A', strtotime($bk['extended_end'] ?? $bk['class_end'])) ?></p>
                        <p class="grace-timer" data-deadline="<?= h($bk['grace_deadline']) ?>" data-class-end="<?= h($bk['extended_end'] ?? $bk['class_end']) ?>">
                            ⏳ <?= $lang==='ms'?'Menghitung masa...':'Calculating...' ?>
                        </p>
                    </div>
                    <div class="booking-card__actions">
                        <button class="btn btn--sm btn--danger" onclick="cancelBooking(<?= $bk['id'] ?>)"><?= t('cancel', $lang) ?></button>
                        <?php if ($bk['status'] === 'confirmed'): ?>
                        <button class="btn btn--sm btn--accent" onclick="openExtend(<?= $bk['id'] ?>, '<?= $bk['class_end'] ?>', '<?= $bk['timetable_id'] ?>')"><?= t('extend', $lang) ?></button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- SLOT SEARCH / AVAILABLE SLOTS (FR1, FR2) -->
        <section class="section">
            <h2 class="section-title"><?= t('search_slot', $lang) ?></h2>

            <?php if ($eligibleClass && $availableSlots): ?>
            <p class="slot-count-label">
                <?= count($availableSlots) ?> <?= $lang==='ms'?'slot tersedia dalam':'slots available in' ?> <strong><?= h($zoneName($eligibleClass)) ?></strong>
            </p>
            <div class="slot-grid">
                <?php foreach ($availableSlots as $slot): ?>
                <div class="slot-card slot-card--free">
                    <div class="slot-icon">🅿</div>
                    <div class="slot-code"><?= h($slot['slot_code']) ?></div>
                    <div class="slot-floor"><?= $lang==='ms'?'Aras':'Floor' ?> <?= h($slot['floor_level']) ?></div>
                    <button class="btn btn--sm btn--primary" onclick="bookSlot(<?= $slot['id'] ?>, '<?= h($slot['slot_code']) ?>', <?= $eligibleClass['id'] ?>, '<?= h($eligibleClass['start_time']) ?>', '<?= h($eligibleClass['end_time']) ?>', '<?= $date ?>')">
                        <?= t('book_slot', $lang) ?>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
            <?php elseif ($eligibleClass): ?>
                <div class="empty-state">🚗 <?= t('no_slots', $lang) ?></div>
            <?php else: ?>
                <div class="empty-state locked">🔒 <?= t('no_timetable', $lang) ?></div>
            <?php endif; ?>
        </section>

        <!-- HISTORY -->
        <?php if ($history): ?>
        <section class="section">
            <h2 class="section-title"><?= $lang==='ms'?'Sejarah Tempahan':'Booking History' ?></h2>
            <div class="history-table-wrap">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th><?= t('slot', $lang) ?></th>
                            <th><?= $lang==='ms'?'Kursus':'Course' ?></th>
                            <th><?= $lang==='ms'?'Tarikh':'Date' ?></th>
                            <th><?= $lang==='ms'?'Status':'Status' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $h_row): ?>
                        <tr>
                            <td><?= h($h_row['slot_code']) ?></td>
                            <td><?= h($h_row['course_code']) ?></td>
                            <td><?= date('d M Y', strtotime($h_row['class_start'])) ?></td>
                            <td><span class="status--<?= h($h_row['status']) ?>"><?= ucfirst($h_row['status']) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

    </main>
</div>

<!-- BOOKING CONFIRM MODAL -->
<div class="modal-overlay" id="bookModal">
    <div class="modal">
        <h3 class="modal-title"><?= t('confirm_book', $lang) ?></h3>
        <div id="bookModalBody"></div>
        <div class="modal-actions">
            <button class="btn btn--primary" id="bookConfirmBtn"><?= $lang==='ms'?'Ya, Tempah':'Yes, Book' ?></button>
            <button class="btn btn--outline" onclick="closeModal('bookModal')"><?= $lang==='ms'?'Batal':'Cancel' ?></button>
        </div>
    </div>
</div>

<!-- EXTEND MODAL -->
<div class="modal-overlay" id="extendModal">
    <div class="modal">
        <h3 class="modal-title"><?= t('extend', $lang) ?></h3>
        <div id="extendModalBody">
            <label class="field-label"><?= $lang==='ms'?'Masa tamat baharu:':'New end time:' ?></label>
            <input type="time" id="newEndTime" class="field-input">
        </div>
        <div class="modal-actions">
            <button class="btn btn--accent" id="extendConfirmBtn"><?= $lang==='ms'?'Lanjutkan':'Extend' ?></button>
            <button class="btn btn--outline" onclick="closeModal('extendModal')"><?= $lang==='ms'?'Batal':'Cancel' ?></button>
        </div>
    </div>
</div>

<script src="app.js"></script>
<script>
const LANG = '<?= $lang ?>';
const user_id = '<?= h($user['user_id']) ?>';

// Grace period countdown timers
document.querySelectorAll('.grace-timer').forEach(el => {
    const deadline = new Date(el.dataset.deadline.replace(' ','T'));
    const classEnd = new Date(el.dataset.classEnd.replace(' ','T'));
    const update = () => {
        const now = new Date();
        if (now < deadline) {
            const diff = Math.floor((deadline - now) / 1000);
            el.textContent = LANG === 'ms'
                ? `⏳ Masa masuk: ${diff}s lagi`
                : `⏳ Grace window: ${diff}s left`;
            el.style.color = diff < 60 ? '#ef4444' : '#f59e0b';
        } else if (now < classEnd) {
            el.textContent = LANG === 'ms' ? '✅ Dalam kawasan parking' : '✅ Active – in parking area';
            el.style.color = '#22c55e';
        } else {
            el.textContent = LANG === 'ms' ? '⚠️ Kelas telah tamat' : '⚠️ Class ended';
            el.style.color = '#ef4444';
        }
    };
    update();
    setInterval(update, 1000);
});
</script>
</body>
</html>
