<?php
require_once 'config.php';
requireAdmin();
$user = currentUser();
$lang = $user['lang_pref'] ?? 'ms';

// ── Stats ─────────────────────────────────────────────────
$stats = db()->query("
    SELECT
        (SELECT COUNT(*) FROM bookings WHERE status IN ('confirmed','active','pending')) AS active_bookings,
        (SELECT COUNT(*) FROM bookings WHERE status IN ('confirmed','active','pending','completed') AND DATE(class_start) = CURDATE()) AS today_bookings,
        (SELECT COUNT(*) FROM parking_slots WHERE is_active = 1) AS total_slots,
        (SELECT COUNT(*) FROM violations WHERE status = 'open') AS open_violations
")->fetch();

// Utilization %
$utilizationPct = $stats['total_slots'] > 0
    ? round(($stats['active_bookings'] / $stats['total_slots']) * 100)
    : 0;

// ── Live bookings feed ────────────────────────────────────
$liveBookings = db()->query("
    SELECT b.id, b.student_id, b.status, b.class_start, b.class_end, b.grace_deadline,
           b.booked_at, ps.slot_code, cz.name as zone_name, cz.name_ms as zone_name_ms,
           ct.course_code, ct.course_name, u.full_name
    FROM bookings b
    JOIN parking_slots ps ON b.slot_id = ps.id
    JOIN campus_zones cz ON ps.zone_id = cz.id
    JOIN class_timetable ct ON b.timetable_id = ct.id
    LEFT JOIN users u ON b.student_id = u.student_id
    WHERE b.status IN ('pending','confirmed','active')
    ORDER BY b.booked_at DESC
    LIMIT 50
")->fetchAll();

// ── Zone capacity overview ────────────────────────────────
$zones = db()->query("
    SELECT cz.id, cz.code, cz.name, cz.name_ms, cz.total_slots,
           COUNT(CASE WHEN b.status IN ('confirmed','active','pending') THEN 1 END) AS occupied
    FROM campus_zones cz
    LEFT JOIN parking_slots ps ON ps.zone_id = cz.id
    LEFT JOIN bookings b ON b.slot_id = ps.id AND b.status IN ('confirmed','active','pending')
    WHERE cz.is_active = 1
    GROUP BY cz.id
")->fetchAll();

// ── Open violations ───────────────────────────────────────
$violations = db()->query("
    SELECT v.*, u.full_name, ub.full_name as issued_by_name
    FROM violations v
    LEFT JOIN users u ON v.student_id = u.student_id
    LEFT JOIN users ub ON v.issued_by = ub.id
    WHERE v.status = 'open'
    ORDER BY v.issued_at DESC LIMIT 20
")->fetchAll();

// ── Audit trail ───────────────────────────────────────────
$auditLog = db()->query("
    SELECT * FROM eligibility_audit ORDER BY logged_at DESC LIMIT 30
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="<?= h($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ParkCampus – <?= t('admin_dashboard', $lang) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body class="admin-body">

<nav class="topnav topnav--admin">
    <div class="nav-brand">
        <svg viewBox="0 0 40 40" width="32" height="32"><rect width="40" height="40" rx="8" fill="var(--admin-accent)"/><text x="20" y="27" text-anchor="middle" fill="white" font-size="20" font-family="Syne" font-weight="800">P</text></svg>
        ParkCampus <span class="admin-badge">ADMIN</span>
    </div>
    <div class="nav-right">
        <span class="nav-user">🔐 <?= h($user['full_name']) ?> (<?= ucfirst($user['role']) ?>)</span>
        <a href="logout.php" class="btn btn--outline btn--sm"><?= t('logout', $lang) ?></a>
    </div>
</nav>

<div class="admin-layout">

    <!-- SIDEBAR -->
    <aside class="admin-sidebar">
        <nav class="admin-nav">
            <a href="#overview" class="admin-nav-link active">📊 <?= $lang==='ms'?'Gambaran Keseluruhan':'Overview' ?></a>
            <a href="#live-bookings" class="admin-nav-link">🚗 <?= $lang==='ms'?'Tempahan Aktif':'Live Bookings' ?></a>
            <a href="#zones" class="admin-nav-link">🗺 <?= $lang==='ms'?'Zon Parking':'Parking Zones' ?></a>
            <a href="#violations" class="admin-nav-link">⚠️ <?= t('violations', $lang) ?></a>
            <a href="#audit" class="admin-nav-link">📋 <?= $lang==='ms'?'Log Audit':'Audit Log' ?></a>
        </nav>
    </aside>

    <!-- MAIN -->
    <main class="admin-main">

        <!-- OVERVIEW STATS -->
        <section id="overview" class="section">
            <h2 class="section-title"><?= $lang==='ms'?'Gambaran Keseluruhan':'Overview' ?></h2>
            <div class="stats-grid">
                <div class="stat-card stat-card--blue">
                    <div class="stat-value"><?= $stats['active_bookings'] ?></div>
                    <div class="stat-label"><?= $lang==='ms'?'Tempahan Aktif':'Active Bookings' ?></div>
                </div>
                <div class="stat-card stat-card--green">
                    <div class="stat-value"><?= $utilizationPct ?>%</div>
                    <div class="stat-label"><?= $lang==='ms'?'Penggunaan Slot':'Slot Utilization' ?></div>
                    <div class="stat-bar"><div class="stat-bar-fill" style="width:<?= $utilizationPct ?>%"></div></div>
                </div>
                <div class="stat-card stat-card--yellow">
                    <div class="stat-value"><?= $stats['today_bookings'] ?></div>
                    <div class="stat-label"><?= $lang==='ms'?'Tempahan Hari Ini':'Today\'s Bookings' ?></div>
                </div>
                <div class="stat-card stat-card--red">
                    <div class="stat-value"><?= $stats['open_violations'] ?></div>
                    <div class="stat-label"><?= $lang==='ms'?'Pelanggaran Terbuka':'Open Violations' ?></div>
                </div>
            </div>
        </section>

        <!-- ZONE CAPACITY -->
        <section id="zones" class="section">
            <h2 class="section-title"><?= $lang==='ms'?'Kapasiti Zon':'Zone Capacity' ?></h2>
            <div class="zone-grid">
                <?php foreach ($zones as $z): ?>
                <?php
                    $pct = $z['total_slots'] > 0 ? round(($z['occupied']/$z['total_slots'])*100) : 0;
                    $color = $pct >= 90 ? 'red' : ($pct >= 60 ? 'yellow' : 'green');
                ?>
                <div class="zone-card zone-card--<?= $color ?>">
                    <div class="zone-code"><?= h($z['code']) ?></div>
                    <div class="zone-name"><?= h($lang==='ms'?$z['name_ms']:$z['name']) ?></div>
                    <div class="zone-stats">
                        <span class="zone-occ"><?= $z['occupied'] ?> / <?= $z['total_slots'] ?></span>
                        <span class="zone-pct"><?= $pct ?>%</span>
                    </div>
                    <div class="zone-bar"><div class="zone-bar-fill" style="width:<?= $pct ?>%"></div></div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- LIVE BOOKINGS TABLE -->
        <section id="live-bookings" class="section">
            <div class="section-header">
                <h2 class="section-title"><?= $lang==='ms'?'Tempahan Aktif':'Active Bookings' ?></h2>
                <button class="btn btn--sm btn--outline" onclick="location.reload()">🔄 <?= $lang==='ms'?'Muat Semula':'Refresh' ?></button>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th><?= $lang==='ms'?'Pelajar':'Student' ?></th>
                            <th>Slot</th>
                            <th>Zon</th>
                            <th>Kursus</th>
                            <th><?= $lang==='ms'?'Mula':'Start' ?></th>
                            <th><?= $lang==='ms'?'Tamat':'End' ?></th>
                            <th>Status</th>
                            <th><?= $lang==='ms'?'Tindakan':'Action' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($liveBookings as $bk): ?>
                        <tr data-id="<?= $bk['id'] ?>">
                            <td><?= $bk['id'] ?></td>
                            <td>
                                <strong><?= h($bk['student_id']) ?></strong><br>
                                <small><?= h($bk['full_name']) ?></small>
                            </td>
                            <td><code><?= h($bk['slot_code']) ?></code></td>
                            <td><?= h($lang==='ms'?$bk['zone_name_ms']:$bk['zone_name']) ?></td>
                            <td><?= h($bk['course_code']) ?></td>
                            <td><?= date('h:i A', strtotime($bk['class_start'])) ?></td>
                            <td><?= date('h:i A', strtotime($bk['class_end'])) ?></td>
                            <td><span class="status--<?= h($bk['status']) ?>"><?= ucfirst($bk['status']) ?></span></td>
                            <td class="action-cell">
                                <button class="btn btn--xs btn--danger" onclick="adminCancel(<?= $bk['id'] ?>, '<?= h($bk['student_id']) ?>')">
                                    <?= $lang==='ms'?'Batal':'Cancel' ?>
                                </button>
                                <button class="btn btn--xs btn--warn" onclick="openViolation(<?= $bk['id'] ?>, '<?= h($bk['student_id']) ?>')">
                                    ⚠️
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$liveBookings): ?>
                        <tr><td colspan="9" class="text-center muted-text"><?= $lang==='ms'?'Tiada tempahan aktif.':'No active bookings.' ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- VIOLATIONS -->
        <section id="violations" class="section">
            <h2 class="section-title">⚠️ <?= t('violations', $lang) ?></h2>
            <?php if ($violations): ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><?= $lang==='ms'?'Pelajar':'Student' ?></th>
                            <th><?= $lang==='ms'?'Jenis':'Type' ?></th>
                            <th><?= $lang==='ms'?'Penerangan':'Description' ?></th>
                            <th><?= $lang==='ms'?'Dikeluarkan Oleh':'Issued By' ?></th>
                            <th><?= $lang==='ms'?'Masa':'Time' ?></th>
                            <th><?= $lang==='ms'?'Tindakan':'Action' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($violations as $v): ?>
                        <tr>
                            <td><?= h($v['student_id']) ?> — <?= h($v['full_name']) ?></td>
                            <td><span class="badge badge--red"><?= h($v['type']) ?></span></td>
                            <td><?= h($v['description']) ?></td>
                            <td><?= h($v['issued_by_name']) ?></td>
                            <td><?= date('d M H:i', strtotime($v['issued_at'])) ?></td>
                            <td>
                                <button class="btn btn--xs btn--primary" onclick="resolveViolation(<?= $v['id'] ?>)">
                                    <?= $lang==='ms'?'Selesai':'Resolve' ?>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">✅ <?= $lang==='ms'?'Tiada pelanggaran terbuka.':'No open violations.' ?></div>
            <?php endif; ?>
        </section>

        <!-- AUDIT LOG (SR2) -->
        <section id="audit" class="section">
            <h2 class="section-title">📋 <?= $lang==='ms'?'Log Audit Kelayakan':'Eligibility Audit Log' ?></h2>
            <div class="table-wrap">
                <table class="data-table data-table--compact">
                    <thead>
                        <tr>
                            <th>Hash ID</th>
                            <th>Action</th>
                            <th>Timetable</th>
                            <th>Decision</th>
                            <th>IP</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($auditLog as $log): ?>
                        <tr>
                            <td><code><?= substr(h($log['student_id_hash']),0,12) ?>…</code></td>
                            <td><?= h($log['action']) ?></td>
                            <td><?= $log['timetable_found'] ? '✅' : '❌' ?></td>
                            <td><span class="status--<?= $log['decision']==='allowed'?'confirmed':'auto_cancelled' ?>"><?= $log['decision'] ?></span></td>
                            <td><?= h($log['requestor_ip']) ?></td>
                            <td><?= date('H:i:s d/m', strtotime($log['logged_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </main>
</div>

<!-- VIOLATION MODAL -->
<div class="modal-overlay" id="violationModal">
    <div class="modal">
        <h3 class="modal-title">⚠️ <?= t('issue_warning', $lang) ?></h3>
        <input type="hidden" id="vBookingId">
        <input type="hidden" id="vStudentId">
        <div class="field-group">
            <label class="field-label"><?= $lang==='ms'?'Jenis Pelanggaran':'Violation Type' ?></label>
            <select id="vType" class="field-input">
                <option value="no_show">No Show</option>
                <option value="overstay">Overstay</option>
                <option value="invalid_booking">Invalid Booking</option>
                <option value="other">Other</option>
            </select>
        </div>
        <div class="field-group">
            <label class="field-label"><?= $lang==='ms'?'Penerangan':'Description' ?></label>
            <textarea id="vDesc" class="field-input" rows="3"></textarea>
        </div>
        <div class="modal-actions">
            <button class="btn btn--danger" id="vSubmitBtn"><?= $lang==='ms'?'Keluarkan Amaran':'Issue Warning' ?></button>
            <button class="btn btn--outline" onclick="closeModal('violationModal')"><?= $lang==='ms'?'Batal':'Cancel' ?></button>
        </div>
    </div>
</div>

<script src="app.js"></script>
<script>
const LANG = '<?= $lang ?>';
// Admin nav highlight
document.querySelectorAll('.admin-nav-link').forEach(link => {
    link.addEventListener('click', e => {
        document.querySelectorAll('.admin-nav-link').forEach(l => l.classList.remove('active'));
        e.currentTarget.classList.add('active');
    });
});

function openViolation(bookingId, studentId) {
    document.getElementById('vBookingId').value = bookingId;
    document.getElementById('vStudentId').value = studentId;
    document.getElementById('violationModal').classList.add('active');
}
document.getElementById('vSubmitBtn').addEventListener('click', async () => {
    const res = await apiPost('api.php', {
        action: 'issue_violation',
        booking_id: document.getElementById('vBookingId').value,
        student_id: document.getElementById('vStudentId').value,
        type: document.getElementById('vType').value,
        description: document.getElementById('vDesc').value
    });
    if (res.ok) { closeModal('violationModal'); location.reload(); }
    else alert(res.message || 'Error');
});

function resolveViolation(id) {
    if (!confirm(LANG==='ms'?'Selesaikan pelanggaran ini?':'Resolve this violation?')) return;
    apiPost('api.php', { action: 'resolve_violation', id }).then(() => location.reload());
}
</script>
</body>
</html>
