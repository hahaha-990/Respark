<?php
require_once 'config.php';
requireLogin();
$user = currentUser();
if ($user['role'] !== 'student') { header('Location: admin_dashboard.php'); exit; }

$lang = $user['lang_pref'] ?? 'ms';
$now  = new DateTime();

// ── Timetable lookup (FR1 / SR1) ─────────────────────────
$dow  = (int)$now->format('w');
$date = $now->format('Y-m-d');

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
    $start  = new DateTime($date . ' ' . $cls['start_time']);
    $end    = new DateTime($date . ' ' . $cls['end_time']);
    $openAt = clone $start; $openAt->modify('-30 minutes');
    if ($now >= $openAt && $now <= $end) { $eligibleClass = $cls; break; }
}

logEligibility($user['user_id'], 'dashboard_load', !empty($todayClasses),
    $eligibleClass ? 'allowed' : 'denied',
    $eligibleClass ? '' : 'No active/upcoming class in 30min window');

// ── Available slots ────────────────────────────────────────
$availableSlots = [];
if ($eligibleClass) {
    $classStart = $date . ' ' . $eligibleClass['start_time'];
    $classEnd   = $date . ' ' . $eligibleClass['end_time'];
    $stmtSlots  = db()->prepare("
        SELECT ps.id, ps.slot_code, ps.floor_level,
               cz.name as zone_name, cz.name_ms as zone_name_ms, cz.code as zone_code
        FROM parking_slots ps
        JOIN campus_zones cz ON ps.zone_id = cz.id
        WHERE ps.zone_id = ? AND ps.is_active = 1
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

// ── Active bookings with grace window metadata ─────────────
$stmtBk = db()->prepare("
    SELECT b.*,
           ps.slot_code, cz.name as zone_name, cz.name_ms as zone_name_ms, cz.code as zone_code,
           ct.course_name, ct.course_code, ct.end_time as tt_end_time
    FROM bookings b
    JOIN parking_slots ps ON b.slot_id = ps.id
    JOIN campus_zones cz ON ps.zone_id = cz.id
    JOIN class_timetable ct ON b.timetable_id = ct.id
    WHERE b.student_id = ?
      AND b.status IN ('pending','confirmed','active')
    ORDER BY b.class_start DESC
");
$stmtBk->execute([$user['student_id']]);
$activeBookings = $stmtBk->fetchAll();

// ── Compute per-booking grace window state (PHP-side) ──────
// States: 'grace_open' | 'grace_expired' | 'class_active' | 'class_ended'
// Modify/cancel allowed only during grace_open OR before class starts (within 15 min prior)
foreach ($activeBookings as &$bk) {
    $graceDeadline = new DateTime($bk['grace_deadline']);
    $classStart    = new DateTime($bk['class_start']);
    $classEnd      = new DateTime($bk['extended_end'] ?? $bk['class_end']);
    $timetableEnd  = new DateTime(date('Y-m-d', strtotime($bk['class_start'])) . ' ' . $bk['tt_end_time']);
    $preClassOpen  = clone $classStart; $preClassOpen->modify('-15 minutes');

    // Can modify/cancel during the grace window (after booking, before grace_deadline)
    // OR in the 15-min window before class starts
    $inGrace      = ($now <= $graceDeadline);
    $preClass     = ($now >= $preClassOpen && $now < $classStart);
    $canModify    = $inGrace || $preClass;

    // Can extend only while class hasn't ended and there's room in timetable
    $classRunning = ($now >= $classStart && $now <= $classEnd);
    $canExtend    = $canModify || $classRunning;
    $canExtend    = $canExtend && ($classEnd < $timetableEnd); // must have headroom

    // Grace seconds remaining (for JS countdown)
    $graceSecsLeft = max(0, $graceDeadline->getTimestamp() - $now->getTimestamp());
    // Pre-class seconds (how long until class starts, for a second countdown type)
    $preClassSecsLeft = max(0, $classStart->getTimestamp() - $now->getTimestamp());

    if ($inGrace) {
        $windowState = 'grace_open';
    } elseif ($preClass) {
        $windowState = 'pre_class';
    } elseif ($now < $classStart) {
        $windowState = 'grace_expired'; // grace ended before class — auto-cancel should have fired
    } elseif ($now <= $classEnd) {
        $windowState = 'class_active';
    } else {
        $windowState = 'class_ended';
    }

    $bk['_grace_secs_left']    = $graceSecsLeft;
    $bk['_pre_class_secs_left'] = $preClassSecsLeft;
    $bk['_window_state']       = $windowState;
    $bk['_can_modify']         = $canModify;
    $bk['_can_extend']         = $canExtend;
    $bk['_tt_end_dt']          = $timetableEnd->format('Y-m-d H:i:s');
    $bk['_effective_end']      = $classEnd->format('Y-m-d H:i:s');
}
unset($bk);

// ── Notifications ─────────────────────────────────────────
$stmtNotif = db()->prepare("SELECT * FROM notifications WHERE student_id=? AND delivered=0 ORDER BY id DESC LIMIT 10");
$stmtNotif->execute([$user['user_id']]);
$notifications = $stmtNotif->fetchAll();
if ($notifications) {
    $ids = implode(',', array_column($notifications, 'id'));
    db()->exec("UPDATE notifications SET delivered=1, sent_at=NOW() WHERE id IN ($ids)");
}

// ── Booking history ───────────────────────────────────────
$stmtHist = db()->prepare("
    SELECT b.*, ps.slot_code, cz.name as zone_name, cz.name_ms as zone_name_ms,
           ct.course_name, ct.course_code, b.cancel_reason
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

$zoneName = function($row) use ($lang) { return $lang === 'ms' ? ($row['zone_name_ms'] ?? $row['zone_name']) : $row['zone_name']; };

// Grace window label helper
function graceLabel(array $bk, string $lang) {
    $ws = $bk['_window_state'];
    if ($ws === 'grace_open')    return $lang==='ms' ? '🟡 Tempoh masa aktif'        : '🟡 Grace window open';
    if ($ws === 'pre_class')     return $lang==='ms' ? '🟠 Sebelum kelas bermula'     : '🟠 Before class starts';
    if ($ws === 'grace_expired') return $lang==='ms' ? '🔴 Tempoh masa tamat'         : '🔴 Grace expired';
    if ($ws === 'class_active')  return $lang==='ms' ? '🟢 Kelas sedang berlangsung'  : '🟢 Class in progress';
    if ($ws === 'class_ended')   return $lang==='ms' ? '⚫ Kelas telah tamat'          : '⚫ Class ended';
    return '';
}
?>
<!DOCTYPE html>
<html lang="<?= h($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ParkCampus – Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
<style>
/* ── Grace Window UI ──────────────────────────────── */
.grace-banner {
    display: flex; align-items: center; gap: .6rem;
    padding: .55rem .9rem;
    border-radius: 8px;
    font-size: .8rem;
    font-weight: 600;
    margin-bottom: .75rem;
}
.grace-banner--open     { background: rgba(245,158,11,.12); border: 1px solid rgba(245,158,11,.35); color: #fbbf24; }
.grace-banner--pre      { background: rgba(249,115,22,.12); border: 1px solid rgba(249,115,22,.35); color: #fb923c; }
.grace-banner--expired  { background: rgba(239,68,68,.1);  border: 1px solid rgba(239,68,68,.3);  color: #f87171; }
.grace-banner--active   { background: rgba(34,197,94,.1);  border: 1px solid rgba(34,197,94,.3);  color: #4ade80; }
.grace-banner--ended    { background: rgba(107,114,128,.1); border: 1px solid rgba(107,114,128,.3); color: #9ca3af; }

.grace-countdown {
    font-family: 'Courier New', monospace;
    font-size: .85rem;
    font-weight: 700;
    letter-spacing: .04em;
    padding: .2rem .5rem;
    border-radius: 5px;
    background: rgba(0,0,0,.2);
    margin-left: auto;
    min-width: 56px;
    text-align: center;
}
.grace-countdown.urgent { color: var(--red); animation: blink .8s step-end infinite; }
@keyframes blink { 50% { opacity: .4; } }

/* ── Action locked state ──────────────────────────── */
.btn--locked {
    opacity: .35;
    cursor: not-allowed;
    pointer-events: none;
    position: relative;
}
.action-locked-msg {
    font-size: .75rem;
    color: var(--text-muted);
    margin-top: .35rem;
    font-style: italic;
}

/* ── Extend modal enhancements ───────────────────── */
.extend-info {
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: .85rem 1rem;
    margin-bottom: 1.1rem;
    font-size: .85rem;
    line-height: 1.9;
}
.extend-info .row { display: flex; justify-content: space-between; }
.extend-info .lbl { color: var(--text-muted); }
.extend-info .val { font-weight: 600; font-variant-numeric: tabular-nums; }
.extend-rule {
    font-size: .78rem;
    color: var(--text-muted);
    margin-top: .6rem;
    padding: .55rem .75rem;
    border-left: 2px solid var(--accent);
    background: var(--accent-glow);
    border-radius: 0 6px 6px 0;
}

/* ── View booking detail ─────────────────────────── */
.booking-card__detail {
    padding: .6rem 1rem;
    background: var(--bg-surface);
    border-top: 1px solid var(--border-soft);
    font-size: .78rem;
    color: var(--text-muted);
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .25rem .5rem;
}
.booking-card__detail span { font-weight: 600; color: var(--text-sub); }
</style>
</head>
<body>

<nav class="topnav">
    <div class="nav-brand">
        <svg viewBox="0 0 40 40" width="32" height="32"><rect width="40" height="40" rx="8" fill="var(--accent)"/><text x="20" y="27" text-anchor="middle" fill="white" font-size="20" font-family="Syne" font-weight="800">P</text></svg>
        ParkCampus
    </div>
    <div class="nav-right">
        <?php if ($notifications): ?>
        <button class="notif-bell" id="notifBell">🔔 <span class="notif-badge"><?= count($notifications) ?></span></button>
        <?php endif; ?>
        <span class="nav-user">👤 <?= h($user['full_name']) ?></span>
        <a href="logout.php" class="btn btn--outline btn--sm"><?= t('logout', $lang) ?></a>
    </div>
</nav>

<?php foreach ($notifications as $notif): ?>
<div class="toast toast--<?= h($notif['type']) ?>" data-auto-hide="5000">
    <span class="toast-icon"><?= $notif['type']==='confirmation'?'✅':($notif['type']==='reminder_15min'?'⏰':'🚗') ?></span>
    <span><?= h($notif['message_body']) ?></span>
</div>
<?php endforeach; ?>

<div class="dashboard-layout">
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
            <a href="timetable.php"   class="sidebar-link">📅 <?= $lang==='ms'?'Jadual Kelas':'Timetable' ?></a>
            <a href="preferences.php" class="sidebar-link">🌐 <?= $lang==='ms'?'Bahasa & Notifikasi':'Language & Notifications' ?></a>
        </div>
    </aside>

    <main class="main-content">

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

        <!-- ── ACTIVE BOOKINGS (FR3) ──────────────────────────── -->
        <?php if ($activeBookings): ?>
        <section class="section">
            <h2 class="section-title"><?= t('my_bookings', $lang) ?></h2>
            <div class="booking-cards">
                <?php foreach ($activeBookings as $bk):
                    $ws         = $bk['_window_state'];
                    $canMod     = $bk['_can_modify'];
                    $canExt     = $bk['_can_extend'];
                    if      ($ws === 'grace_open')    $bannerCls = 'open';
                    elseif ($ws === 'pre_class')     $bannerCls = 'pre';
                    elseif ($ws === 'grace_expired') $bannerCls = 'expired';
                    elseif ($ws === 'class_active')  $bannerCls = 'active';
                    else                             $bannerCls = 'ended';
                    $lockedMsg = '';
                    if (!$canMod) {
                        if      ($ws === 'grace_expired') $lockedMsg = ($lang==='ms' ? 'Tempoh ubah suai tamat.'    : 'Modification window closed.');
                        elseif ($ws === 'class_active')  $lockedMsg = ($lang==='ms' ? 'Kelas sedang berlangsung.' : 'Class is in progress.');
                        elseif ($ws === 'class_ended')   $lockedMsg = ($lang==='ms' ? 'Kelas telah tamat.'        : 'Class has ended.');
                        else                             $lockedMsg = '';
                    }
                ?>
                <div class="booking-card booking-card--<?= h($bk['status']) ?>">
                    <div class="booking-card__header">
                        <span class="booking-slot"><?= h($bk['slot_code']) ?></span>
                        <span class="booking-status status--<?= h($bk['status']) ?>"><?= strtoupper($bk['status']) ?></span>
                    </div>

                    <div class="booking-card__body">
                        <p><strong><?= h($bk['course_code']) ?></strong> — <?= h($zoneName($bk)) ?></p>
                        <p>🕐 <?= date('h:i A', strtotime($bk['class_start'])) ?> → <?= date('h:i A', strtotime($bk['_effective_end'])) ?>
                            <?php if ($bk['extended_end']): ?>
                            <span style="font-size:.75rem;color:var(--accent);"> (<?= $lang==='ms'?'dilanjutkan':'extended' ?>)</span>
                            <?php endif; ?>
                        </p>

                        <!-- Grace window banner with live countdown -->
                        <div class="grace-banner grace-banner--<?= $bannerCls ?>"
                             data-state="<?= $ws ?>"
                             data-grace-deadline="<?= h($bk['grace_deadline']) ?>"
                             data-class-start="<?= h($bk['class_start']) ?>"
                             data-class-end="<?= h($bk['_effective_end']) ?>">
                            <span class="grace-label"><?= graceLabel($bk, $lang) ?></span>
                            <?php if (in_array($ws, ['grace_open','pre_class'])): ?>
                            <span class="grace-countdown" id="gcd-<?= $bk['id'] ?>">--:--</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Booking detail strip -->
                    <div class="booking-card__detail">
                        <div><?= $lang==='ms'?'Ditempah:':'Booked:' ?> <span><?= date('h:i A', strtotime($bk['booked_at'])) ?></span></div>
                        <div><?= $lang==='ms'?'Had jadual:':'Timetable bound:' ?> <span><?= date('h:i A', strtotime($bk['_tt_end_dt'])) ?></span></div>
                        <div><?= $lang==='ms'?'Tempoh masa:':'Grace deadline:' ?> <span><?= date('h:i A', strtotime($bk['grace_deadline'])) ?></span></div>
                        <div><?= $lang==='ms'?'Tamat efektif:':'Effective end:' ?> <span><?= date('h:i A', strtotime($bk['_effective_end'])) ?></span></div>
                    </div>

                    <div class="booking-card__actions">
                        <!-- CANCEL: allowed during grace_open or pre_class only -->
                        <button class="btn btn--sm btn--danger <?= !$canMod ? 'btn--locked' : '' ?>"
                                <?= !$canMod ? 'disabled title="'.h($lockedMsg).'"' : '' ?>
                                onclick="cancelBooking(<?= $bk['id'] ?>, this)">
                            <?= t('cancel', $lang) ?>
                        </button>

                        <!-- EXTEND: allowed during grace_open, pre_class, or class_active if timetable has headroom -->
                        <button class="btn btn--sm btn--accent <?= !$canExt ? 'btn--locked' : '' ?>"
                                <?= !$canExt ? 'disabled title="'.($lang==='ms'?'Tiada ruang untuk lanjutan.':'No extension headroom.').'"' : '' ?>
                                onclick="openExtend(<?= $bk['id'] ?>, '<?= h($bk['_effective_end']) ?>', '<?= h($bk['_tt_end_dt']) ?>', '<?= h($bk['class_start']) ?>')">
                            <?= t('extend', $lang) ?>
                        </button>

                        <?php if ($lockedMsg): ?>
                        <p class="action-locked-msg"><?= h($lockedMsg) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- ── SLOT SEARCH (FR1, FR2) ─────────────────────────── -->
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
                    <button class="btn btn--sm btn--primary"
                        onclick="bookSlot(<?= $slot['id'] ?>,'<?= h($slot['slot_code']) ?>',<?= $eligibleClass['id'] ?>,'<?= h($eligibleClass['start_time']) ?>','<?= h($eligibleClass['end_time']) ?>','<?= $date ?>')">
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

        <!-- ── HISTORY ────────────────────────────────────────── -->
        <?php if ($history): ?>
        <section class="section">
            <h2 class="section-title"><?= $lang==='ms'?'Sejarah Tempahan':'Booking History' ?></h2>
            <div class="history-table-wrap">
                <table class="history-table">
                    <thead><tr>
                        <th><?= t('slot', $lang) ?></th>
                        <th><?= $lang==='ms'?'Kursus':'Course' ?></th>
                        <th><?= $lang==='ms'?'Tarikh':'Date' ?></th>
                        <th>Status</th>
                        <th><?= $lang==='ms'?'Sebab':'Reason' ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($history as $h_row): ?>
                        <tr>
                            <td><?= h($h_row['slot_code']) ?></td>
                            <td><?= h($h_row['course_code']) ?></td>
                            <td><?= date('d M Y', strtotime($h_row['class_start'])) ?></td>
                            <td><span class="status--<?= h($h_row['status']) ?>"><?= ucfirst(str_replace('_',' ',$h_row['status'])) ?></span></td>
                            <td style="font-size:.78rem;color:var(--text-muted)"><?= h($h_row['cancel_reason'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

    </main>
</div>

<!-- ── BOOK CONFIRM MODAL ─────────────────────────────────── -->
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

<!-- ── EXTEND MODAL ───────────────────────────────────────── -->
<div class="modal-overlay" id="extendModal">
    <div class="modal">
        <h3 class="modal-title">⏱ <?= $lang==='ms'?'Lanjutkan Tempahan':'Extend Booking' ?></h3>

        <!-- Info strip populated by JS -->
        <div class="extend-info" id="extendInfo"></div>

        <div class="field-group">
            <label class="field-label"><?= $lang==='ms'?'Masa tamat baharu:':'New end time:' ?></label>
            <input type="time" id="newEndTime" class="field-input">
            <p id="extendError" style="color:var(--red);font-size:.8rem;margin-top:.4rem;display:none;"></p>
        </div>

        <div class="extend-rule">
            <?= $lang==='ms'
                ? '⚠️ Masa baharu <strong>mesti</strong> dalam had jadual kelas dan tiada konflik dengan tempahan lain.'
                : '⚠️ New time <strong>must</strong> be within timetable bounds with no slot conflicts.' ?>
        </div>

        <div class="modal-actions">
            <button class="btn btn--accent" id="extendConfirmBtn"><?= $lang==='ms'?'Sahkan Lanjutan':'Confirm Extension' ?></button>
            <button class="btn btn--outline" onclick="closeModal('extendModal')"><?= $lang==='ms'?'Batal':'Cancel' ?></button>
        </div>
    </div>
</div>

<script src="app.js"></script>
<script>
const LANG        = '<?= $lang ?>';
const STUDENT_ID  = '<?= h($user['student_id']) ?>';
const GRACE_MINS  = <?= GRACE_MINUTES ?>;
const PRE_CLASS_MINS = 15;

// ── Live grace countdown timers ───────────────────────────
document.querySelectorAll('.grace-banner').forEach(banner => {
    const state      = banner.dataset.state;
    const deadline   = new Date(banner.dataset.graceDeadline.replace(' ','T'));
    const classStart = new Date(banner.dataset.classStart.replace(' ','T'));
    const classEnd   = new Date(banner.dataset.classEnd.replace(' ','T'));
    const countdownEl = banner.querySelector('.grace-countdown');
    if (!countdownEl) return;

    const bookingCard = banner.closest('.booking-card');
    const cancelBtn   = bookingCard?.querySelector('.btn--danger');
    const extendBtn   = bookingCard?.querySelector('.btn--accent');

    const fmt = secs => `${String(Math.floor(secs/60)).padStart(2,'0')}:${String(secs%60).padStart(2,'0')}`;

    const tick = () => {
        const now       = new Date();
        const inGrace   = now <= deadline;
        const preClass  = now >= new Date(classStart - 15*60000) && now < classStart;
        const canAct    = inGrace || preClass;
        const secsLeft  = inGrace
            ? Math.floor((deadline - now) / 1000)
            : Math.max(0, Math.floor((classStart - now) / 1000));

        countdownEl.textContent = fmt(Math.max(0, secsLeft));
        countdownEl.classList.toggle('urgent', secsLeft <= 60 && secsLeft > 0);

        // Dynamically lock/unlock buttons based on real-time window
        if (cancelBtn) {
            cancelBtn.disabled = !canAct;
            cancelBtn.classList.toggle('btn--locked', !canAct);
        }
        if (extendBtn) {
            const classRunning = now >= classStart && now <= classEnd;
            const extOk = (canAct || classRunning) && classEnd < new Date(classStart.toDateString() + ' 23:59'); // rough check
            extendBtn.disabled = !extOk;
            extendBtn.classList.toggle('btn--locked', !extOk);
        }

        if (secsLeft <= 0 && inGrace && !preClass) {
            banner.className = 'grace-banner grace-banner--expired';
            banner.querySelector('.grace-label').textContent =
                LANG === 'ms' ? '🔴 Tempoh masa tamat' : '🔴 Grace expired';
            countdownEl.textContent = '00:00';
        }
    };
    tick();
    setInterval(tick, 1000);
});
</script>
</body>
</html>