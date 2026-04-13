<?php
require_once 'config.php';
requireLogin();
$user = currentUser();
if ($user['role'] !== 'student') { header('Location: admin_dashboard.php'); exit; }

$lang = $user['lang_pref'] ?? 'ms';
$sid  = $user['user_id'];

// ── Flash message ─────────────────────────────────────────
startSecureSession();
$flash = $_SESSION['tt_flash'] ?? null;
unset($_SESSION['tt_flash']);

// ── Load all zones for dropdown ───────────────────────────
$zones = db()->query("SELECT id, code, name, name_ms FROM campus_zones WHERE is_active=1 ORDER BY name")->fetchAll();

// ── Load student's timetable ──────────────────────────────
$stmtTT = db()->prepare("
    SELECT ct.*, cz.name as zone_name, cz.name_ms as zone_name_ms, cz.code as zone_code
    FROM class_timetable ct
    JOIN campus_zones cz ON ct.zone_id = cz.id
    WHERE ct.student_id = ?
    ORDER BY ct.day_of_week, ct.start_time
");
$stmtTT->execute([$sid]);
$entries = $stmtTT->fetchAll();

// ── Handle form submissions ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    // ── ADD or EDIT ───────────────────────────────────────
    if (in_array($act, ['add', 'edit'])) {
        $id            = (int)($_POST['id'] ?? 0);
        $courseCode    = trim($_POST['course_code']    ?? '');
        $courseName    = trim($_POST['course_name']    ?? '');
        $zoneId        = (int)($_POST['zone_id']       ?? 0);
        $venue         = trim($_POST['venue']          ?? '');
        $dayOfWeek     = (int)($_POST['day_of_week']   ?? 0);
        $startTime     = trim($_POST['start_time']     ?? '');
        $endTime       = trim($_POST['end_time']       ?? '');
        $seatCount     = max(1, (int)($_POST['seat_count'] ?? 30));
        $effectiveFrom = trim($_POST['effective_from'] ?? '');
        $effectiveTo   = trim($_POST['effective_to']   ?? '');

        $errors = [];
        if (!$courseCode)  $errors[] = $lang==='ms' ? 'Kod kursus diperlukan.'     : 'Course code is required.';
        if (!$courseName)  $errors[] = $lang==='ms' ? 'Nama kursus diperlukan.'    : 'Course name is required.';
        if (!$zoneId)      $errors[] = $lang==='ms' ? 'Zon mesti dipilih.'         : 'Zone must be selected.';
        if (!$startTime)   $errors[] = $lang==='ms' ? 'Masa mula diperlukan.'      : 'Start time is required.';
        if (!$endTime)     $errors[] = $lang==='ms' ? 'Masa tamat diperlukan.'     : 'End time is required.';
        if (!$effectiveFrom || !$effectiveTo)
                           $errors[] = $lang==='ms' ? 'Tarikh efektif diperlukan.' : 'Effective dates are required.';
        if ($startTime && $endTime && $startTime >= $endTime)
                           $errors[] = $lang==='ms' ? 'Masa tamat mesti selepas masa mula.' : 'End time must be after start time.';
        if ($effectiveFrom && $effectiveTo && $effectiveFrom > $effectiveTo)
                           $errors[] = $lang==='ms' ? 'Tarikh akhir mesti selepas tarikh mula.' : 'Effective-to must be after effective-from.';
        if ($dayOfWeek < 0 || $dayOfWeek > 6)
                           $errors[] = $lang==='ms' ? 'Hari tidak sah.' : 'Invalid day.';

        // Conflict check: same student, same day, overlapping time, different record
        if (!$errors) {
            $cf = db()->prepare("
                SELECT id FROM class_timetable
                WHERE student_id  = ?
                  AND day_of_week = ?
                  AND id != ?
                  AND start_time  < ?
                  AND end_time    > ?
            ");
            $cf->execute([$sid, $dayOfWeek, $id, $endTime, $startTime]);
            if ($cf->fetch())
                $errors[] = $lang==='ms'
                    ? 'Konflik jadual: slot masa ini bertindih dengan kelas lain pada hari yang sama.'
                    : 'Schedule conflict: this time slot overlaps with another class on the same day.';
        }

        if ($errors) {
            $_SESSION['tt_flash'] = ['type'=>'error', 'msgs'=>$errors, 'form'=>$_POST];
        } else {
            if ($act === 'add') {
                db()->prepare("
                    INSERT INTO class_timetable
                        (student_id,course_code,course_name,zone_id,venue,day_of_week,start_time,end_time,seat_count,effective_from,effective_to)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)
                ")->execute([$sid,$courseCode,$courseName,$zoneId,$venue,$dayOfWeek,$startTime,$endTime,$seatCount,$effectiveFrom,$effectiveTo]);
                $_SESSION['tt_flash'] = ['type'=>'success', 'msg'=> $lang==='ms'
                    ? "Kelas '$courseCode' berjaya ditambah." : "Class '$courseCode' added successfully."];
            } else {
                // Verify ownership before edit
                $own = db()->prepare("SELECT id FROM class_timetable WHERE id=? AND student_id=?");
                $own->execute([$id, $sid]);
                if (!$own->fetch()) {
                    $_SESSION['tt_flash'] = ['type'=>'error','msgs'=>[$lang==='ms'?'Rekod tidak dijumpai.':'Record not found.']];
                } else {
                    db()->prepare("
                        UPDATE class_timetable
                        SET course_code=?,course_name=?,zone_id=?,venue=?,day_of_week=?,
                            start_time=?,end_time=?,seat_count=?,effective_from=?,effective_to=?
                        WHERE id=? AND student_id=?
                    ")->execute([$courseCode,$courseName,$zoneId,$venue,$dayOfWeek,$startTime,$endTime,$seatCount,$effectiveFrom,$effectiveTo,$id,$sid]);
                    $_SESSION['tt_flash'] = ['type'=>'success','msg'=> $lang==='ms'
                        ? "Kelas '$courseCode' berjaya dikemaskini." : "Class '$courseCode' updated successfully."];
                }
            }
        }
        header('Location: timetable.php'); exit;
    }

    // ── DELETE ────────────────────────────────────────────
    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        // Block delete if active/future bookings depend on this timetable entry
        $linked = db()->prepare("
            SELECT COUNT(*) as cnt FROM bookings
            WHERE timetable_id=? AND status IN ('pending','confirmed','active')
        ");
        $linked->execute([$id]);
        $cnt = (int)$linked->fetch()['cnt'];

        if ($cnt > 0) {
            $_SESSION['tt_flash'] = ['type'=>'error','msgs'=>[
                $lang==='ms'
                    ? "Tidak boleh padam: terdapat $cnt tempahan aktif yang dikaitkan dengan kelas ini. Batalkan tempahan dahulu."
                    : "Cannot delete: $cnt active booking(s) are linked to this class. Cancel them first."
            ]];
        } else {
            $own = db()->prepare("SELECT id FROM class_timetable WHERE id=? AND student_id=?");
            $own->execute([$id,$sid]);
            if ($own->fetch()) {
                db()->prepare("DELETE FROM class_timetable WHERE id=? AND student_id=?")->execute([$id,$sid]);
                $_SESSION['tt_flash'] = ['type'=>'success','msg'=>$lang==='ms'?'Kelas berjaya dipadam.':'Class deleted successfully.'];
            }
        }
        header('Location: timetable.php'); exit;
    }
}

// Re-fetch after POST redirect
$stmtTT->execute([$sid]);
$entries = $stmtTT->fetchAll();

// ── Organise entries by day for calendar view ─────────────
$byDay = array_fill(0, 7, []);
foreach ($entries as $e) {
    $byDay[(int)$e['day_of_week']][] = $e;
}

$dayNames = [
    'ms' => ['Ahad','Isnin','Selasa','Rabu','Khamis','Jumaat','Sabtu'],
    'en' => ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'],
];
$dayShort = [
    'ms' => ['Ahd','Isn','Sel','Rab','Kha','Jum','Sab'],
    'en' => ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'],
];

// Prefill form from flash (validation errors)
$pre = $_SESSION['tt_flash']['form'] ?? [];

// ── Colour palette per day ────────────────────────────────
$dayColours = ['#f59e0b','#3b82f6','#22c55e','#a855f7','#ef4444','#ec4899','#14b8a6'];
?>
<!DOCTYPE html>
<html lang="<?= h($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ParkCampus – <?= $lang==='ms'?'Jadual Kelas':'Class Timetable' ?></title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
<style>
/* ── Timetable page ────────────────────────────────── */
.tt-layout  { display:flex; margin-top:var(--topnav-h); min-height:calc(100vh - var(--topnav-h)); }
.tt-main    { flex:1; padding:1.75rem 2rem; max-width:1100px; }

/* ── Week calendar grid ────────────────────────────── */
.week-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: .5rem;
    margin-bottom: 2rem;
}
.day-col {
    background: var(--bg-card);
    border: 1px solid var(--border-soft);
    border-radius: var(--radius-lg);
    overflow: hidden;
    min-height: 180px;
}
.day-col__header {
    padding: .55rem .75rem;
    font-family: var(--font-display);
    font-size: .8rem;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
    border-bottom: 1px solid var(--border-soft);
}
.day-col__body { padding: .5rem; display:flex; flex-direction:column; gap:.4rem; }
.day-col--today .day-col__header {
    background: var(--accent-glow);
    color: var(--accent);
    border-bottom-color: rgba(245,158,11,.25);
}

/* ── Class chips in calendar ───────────────────────── */
.class-chip {
    border-radius: 7px;
    padding: .45rem .6rem;
    font-size: .75rem;
    border-left: 3px solid;
    cursor: pointer;
    transition: opacity .15s, transform .15s;
    background: var(--bg-surface);
}
.class-chip:hover { opacity: .85; transform: translateX(2px); }
.class-chip__code  { font-weight: 700; font-size: .8rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.class-chip__time  { color: var(--text-muted); font-size: .72rem; margin-top:.1rem; font-variant-numeric: tabular-nums; }
.class-chip__zone  { color: var(--text-muted); font-size: .7rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.day-add-btn {
    display: flex; align-items: center; justify-content: center;
    width: 100%; padding: .35rem; border: 1.5px dashed var(--border);
    border-radius: 6px; background: none; color: var(--text-muted);
    font-size: .8rem; cursor: pointer; transition: border-color .2s, color .2s;
    margin-top: auto;
}
.day-add-btn:hover { border-color: var(--accent); color: var(--accent); }

/* ── List table (below calendar) ───────────────────── */
.tt-table-section { margin-top: 1.5rem; }

/* ── Modal form ────────────────────────────────────── */
.modal--wide { max-width: 560px; }
.form-grid   { display:grid; grid-template-columns:1fr 1fr; gap:.85rem 1rem; }
.form-grid .span-2 { grid-column: 1 / -1; }
.field-hint  { font-size: .75rem; color: var(--text-muted); margin-top: .25rem; }

/* ── SR1 notice ────────────────────────────────────── */
.sr1-notice {
    display:flex; align-items:flex-start; gap:.6rem;
    background: rgba(99,102,241,.08); border: 1px solid rgba(99,102,241,.25);
    border-radius: var(--radius); padding: .75rem 1rem;
    font-size: .8rem; color: #a5b4fc; line-height:1.6; margin-bottom:1.5rem;
}
.sr1-notice strong { color: #c7d2fe; }

/* ── Empty day placeholder ─────────────────────────── */
.day-empty { font-size: .72rem; color: var(--text-muted); text-align:center; padding:.5rem 0; font-style:italic; }

/* ── Conflict badge ────────────────────────────────── */
.conflict-tag {
    display:inline-flex; align-items:center; gap:.3rem;
    background: rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.3);
    color: #fca5a5; border-radius:5px; font-size:.72rem; padding:1px 6px;
}

/* ── Responsive ────────────────────────────────────── */
@media(max-width:900px) {
    .week-grid { grid-template-columns: repeat(4,1fr); }
}
@media(max-width:600px) {
    .week-grid { grid-template-columns: repeat(2,1fr); }
    .form-grid { grid-template-columns: 1fr; }
    .form-grid .span-2 { grid-column: 1; }
    .tt-main { padding:1rem; }
}
</style>
</head>
<body>

<!-- NAV -->
<nav class="topnav">
    <div class="nav-brand">
        <svg viewBox="0 0 40 40" width="32" height="32"><rect width="40" height="40" rx="8" fill="var(--accent)"/><text x="20" y="27" text-anchor="middle" fill="white" font-size="20" font-family="Syne" font-weight="800">P</text></svg>
        ParkCampus
    </div>
    <div class="nav-right">
        <span class="nav-user">👤 <?= h($user['full_name']) ?></span>
        <a href="student_dashboard.php" class="btn btn--outline btn--sm">← <?= $lang==='ms'?'Dashboard':'Dashboard' ?></a>
        <a href="logout.php" class="btn btn--outline btn--sm"><?= t('logout',$lang) ?></a>
    </div>
</nav>

<?php if ($flash && isset($flash['type'])): ?>
<div class="toast toast--<?= $flash['type']==='success'?'success':'error' ?>" data-auto-hide="6000" style="top:80px;bottom:auto;">
    <?php if ($flash['type']==='success'): ?>
        ✅ <?= h($flash['msg']) ?>
    <?php else: ?>
        ❌ <?= implode(' · ', array_map('htmlspecialchars', $flash['msgs'])) ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="tt-layout">
    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="sidebar-section">
            <p class="sidebar-label"><?= $lang==='ms'?'Navigasi':'Navigation' ?></p>
            <a href="student_dashboard.php" class="sidebar-link">🏠 Dashboard</a>
            <a href="timetable.php" class="sidebar-link" style="color:var(--accent);">📅 <?= $lang==='ms'?'Jadual Kelas':'Timetable' ?></a>
            <a href="preferences.php" class="sidebar-link">🌐 <?= $lang==='ms'?'Tetapan':'Settings' ?></a>
        </div>
        <div class="sidebar-section">
            <p class="sidebar-label"><?= $lang==='ms'?'Ringkasan':'Summary' ?></p>
            <div style="font-size:.83rem;line-height:2;color:var(--text-sub);">
                <div><?= $lang==='ms'?'Jumlah kelas:':'Total classes:' ?> <strong style="color:var(--text)"><?= count($entries) ?></strong></div>
                <?php
                $activeDays = array_filter($byDay, function($d) { return count($d) > 0; });
                ?>
                <div><?= $lang==='ms'?'Hari aktif:':'Active days:' ?> <strong style="color:var(--text)"><?= count($activeDays) ?>/7</strong></div>
            </div>
        </div>
    </aside>

    <main class="tt-main">
        <div class="section-header" style="margin-bottom:1.25rem;">
            <h2 class="section-title">📅 <?= $lang==='ms'?'Jadual Kelas Saya':'My Class Timetable' ?></h2>
            <button class="btn btn--primary btn--sm" onclick="openAddModal()">
                + <?= $lang==='ms'?'Tambah Kelas':'Add Class' ?>
            </button>
        </div>

        <!-- ── WEEKLY CALENDAR ──────────────────────────────── -->
        <div class="week-grid" id="weekGrid">
            <?php
            $todayDow = (int)(new DateTime())->format('w');
            for ($d = 0; $d < 7; $d++):
                $isToday = ($d === $todayDow);
            ?>
            <div class="day-col <?= $isToday ? 'day-col--today' : '' ?>">
                <div class="day-col__header" style="<?= $isToday ? '' : "border-top: 3px solid {$dayColours[$d]};" ?>">
                    <?= $isToday ? '⭐ ' : '' ?><?= h($dayShort[$lang][$d]) ?>
                </div>
                <div class="day-col__body">
                    <?php if (empty($byDay[$d])): ?>
                        <p class="day-empty"><?= $lang==='ms'?'Tiada kelas':'No classes' ?></p>
                    <?php else: ?>
                        <?php foreach ($byDay[$d] as $e): ?>
                        <div class="class-chip"
                             style="border-left-color:<?= $dayColours[$d] ?>"
                             onclick="openEditModal(<?= htmlspecialchars(json_encode($e), ENT_QUOTES) ?>)"
                             title="<?= h($e['course_name']) ?>">
                            <div class="class-chip__code"><?= h($e['course_code']) ?></div>
                            <div class="class-chip__time"><?= substr($e['start_time'],0,5) ?>–<?= substr($e['end_time'],0,5) ?></div>
                            <div class="class-chip__zone"><?= h($lang==='ms'?$e['zone_name_ms']:$e['zone_name']) ?></div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <button class="day-add-btn" onclick="openAddModal(<?= $d ?>)">+ <?= $lang==='ms'?'Tambah':'Add' ?></button>
                </div>
            </div>
            <?php endfor; ?>
        </div>

        <!-- ── LIST TABLE ────────────────────────────────────── -->
        <?php if ($entries): ?>
        <div class="tt-table-section">
            <h3 class="section-title" style="font-size:1rem;"><?= $lang==='ms'?'Semua Kelas':'All Classes' ?></h3>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr>
                        <th><?= $lang==='ms'?'Kod':'Code' ?></th>
                        <th><?= $lang==='ms'?'Nama Kursus':'Course Name' ?></th>
                        <th><?= $lang==='ms'?'Hari':'Day' ?></th>
                        <th><?= $lang==='ms'?'Masa':'Time' ?></th>
                        <th>Zon / Venue</th>
                        <th><?= $lang==='ms'?'Tarikh Efektif':'Effective' ?></th>
                        <th><?= $lang==='ms'?'Tindakan':'Actions' ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($entries as $e):
                        // Detect overlap with any other entry on same day
                        $hasConflict = false;
                        foreach ($entries as $other) {
                            if ($other['id'] === $e['id']) continue;
                            if ($other['day_of_week'] != $e['day_of_week']) continue;
                            if ($other['start_time'] < $e['end_time'] && $other['end_time'] > $e['start_time']) {
                                $hasConflict = true; break;
                            }
                        }
                    ?>
                    <tr>
                        <td>
                            <code><?= h($e['course_code']) ?></code>
                            <?php if ($hasConflict): ?>
                            <span class="conflict-tag">⚠ <?= $lang==='ms'?'konflik':'conflict' ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= h($e['course_name']) ?></td>
                        <td>
                            <span style="display:inline-flex;align-items:center;gap:.35rem;">
                                <span style="width:8px;height:8px;border-radius:50%;background:<?= $dayColours[$e['day_of_week']] ?>;flex-shrink:0;"></span>
                                <?= h($dayNames[$lang][$e['day_of_week']]) ?>
                            </span>
                        </td>
                        <td style="font-variant-numeric:tabular-nums"><?= substr($e['start_time'],0,5) ?> – <?= substr($e['end_time'],0,5) ?></td>
                        <td>
                            <div><?= h($lang==='ms'?$e['zone_name_ms']:$e['zone_name']) ?></div>
                            <?php if ($e['venue']): ?><div style="font-size:.75rem;color:var(--text-muted)"><?= h($e['venue']) ?></div><?php endif; ?>
                        </td>
                        <td style="font-size:.78rem;color:var(--text-muted);">
                            <?= date('d M Y', strtotime($e['effective_from'])) ?> →<br>
                            <?= date('d M Y', strtotime($e['effective_to'])) ?>
                        </td>
                        <td class="action-cell">
                            <button class="btn btn--xs btn--accent"
                                    onclick="openEditModal(<?= htmlspecialchars(json_encode($e), ENT_QUOTES) ?>)">
                                ✏️ <?= $lang==='ms'?'Edit':'Edit' ?>
                            </button>
                            <button class="btn btn--xs btn--danger"
                                    onclick="confirmDelete(<?= $e['id'] ?>, '<?= h($e['course_code']) ?>')">
                                🗑 <?= $lang==='ms'?'Padam':'Delete' ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
        <div class="empty-state">
            📅 <?= $lang==='ms'
                ? 'Tiada kelas dalam jadual anda. Tambah kelas pertama anda di atas.'
                : 'No classes in your timetable. Add your first class above.' ?>
        </div>
        <?php endif; ?>

    </main>
</div>

<!-- ── ADD / EDIT MODAL ───────────────────────────────────── -->
<div class="modal-overlay" id="ttModal">
    <div class="modal modal--wide">
        <h3 class="modal-title" id="ttModalTitle">+ <?= $lang==='ms'?'Tambah Kelas':'Add Class' ?></h3>

        <form method="POST" action="timetable.php" id="ttForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id"     id="formId"     value="0">

            <div class="form-grid">
                <!-- Course Code -->
                <div class="field-group">
                    <label class="field-label"><?= $lang==='ms'?'Kod Kursus *':'Course Code *' ?></label>
                    <input class="field-input" type="text" name="course_code" id="fCourseCode"
                           placeholder="BEE3243" maxlength="20" required
                           value="<?= h($pre['course_code'] ?? '') ?>">
                </div>

                <!-- Seat count -->
                <div class="field-group">
                    <label class="field-label"><?= $lang==='ms'?'Kapasiti Tempat Duduk':'Seat Capacity' ?></label>
                    <input class="field-input" type="number" name="seat_count" id="fSeatCount"
                           min="1" max="500" placeholder="30"
                           value="<?= h($pre['seat_count'] ?? '30') ?>">
                </div>

                <!-- Course Name -->
                <div class="field-group span-2">
                    <label class="field-label"><?= $lang==='ms'?'Nama Kursus *':'Course Name *' ?></label>
                    <input class="field-input" type="text" name="course_name" id="fCourseName"
                           placeholder="<?= $lang==='ms'?'Elektronik Digital':'Digital Electronics' ?>"
                           maxlength="160" required
                           value="<?= h($pre['course_name'] ?? '') ?>">
                </div>

                <!-- Zone -->
                <div class="field-group">
                    <label class="field-label"><?= $lang==='ms'?'Zon / Fakulti *':'Zone / Faculty *' ?></label>
                    <select class="field-input" name="zone_id" id="fZoneId" required>
                        <option value=""><?= $lang==='ms'?'— Pilih zon —':'— Select zone —' ?></option>
                        <?php foreach ($zones as $z): ?>
                        <option value="<?= $z['id'] ?>" <?= ($pre['zone_id']??'')==$z['id']?'selected':'' ?>>
                            <?= h($z['code']) ?> — <?= h($lang==='ms'?$z['name_ms']:$z['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Venue -->
                <div class="field-group">
                    <label class="field-label"><?= $lang==='ms'?'Dewan / Bilik':'Venue / Room' ?></label>
                    <input class="field-input" type="text" name="venue" id="fVenue"
                           placeholder="ENG Lab 2" maxlength="120"
                           value="<?= h($pre['venue'] ?? '') ?>">
                </div>

                <!-- Day -->
                <div class="field-group">
                    <label class="field-label"><?= $lang==='ms'?'Hari *':'Day *' ?></label>
                    <select class="field-input" name="day_of_week" id="fDay" required>
                        <?php for ($d=0; $d<7; $d++): ?>
                        <option value="<?= $d ?>" <?= ($pre['day_of_week']??'1')==$d?'selected':'' ?>>
                            <?= h($dayNames[$lang][$d]) ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <!-- Start time -->
                <div class="field-group">
                    <label class="field-label"><?= $lang==='ms'?'Masa Mula *':'Start Time *' ?></label>
                    <input class="field-input" type="time" name="start_time" id="fStart" required
                           value="<?= h($pre['start_time'] ?? '08:00') ?>">
                </div>

                <!-- End time -->
                <div class="field-group">
                    <label class="field-label"><?= $lang==='ms'?'Masa Tamat *':'End Time *' ?></label>
                    <input class="field-input" type="time" name="end_time" id="fEnd" required
                           value="<?= h($pre['end_time'] ?? '10:00') ?>">
                    <p class="field-hint" id="durationHint"></p>
                </div>

                <!-- Effective from -->
                <div class="field-group">
                    <label class="field-label"><?= $lang==='ms'?'Tarikh Mula Efektif *':'Effective From *' ?></label>
                    <input class="field-input" type="date" name="effective_from" id="fFrom" required
                           value="<?= h($pre['effective_from'] ?? date('Y-01-01')) ?>">
                </div>

                <!-- Effective to -->
                <div class="field-group">
                    <label class="field-label"><?= $lang==='ms'?'Tarikh Akhir Efektif *':'Effective To *' ?></label>
                    <input class="field-input" type="date" name="effective_to" id="fTo" required
                           value="<?= h($pre['effective_to'] ?? date('Y-12-31')) ?>">
                </div>
            </div><!-- /form-grid -->

            <div id="conflictWarning" style="display:none;margin-top:.75rem;"
                 class="alert alert--error"></div>

            <div class="modal-actions" style="margin-top:1.25rem;">
                <button type="submit" class="btn btn--primary" id="ttSubmitBtn">
                    <?= $lang==='ms'?'Simpan':'Save' ?>
                </button>
                <button type="button" class="btn btn--outline" onclick="closeModal('ttModal')">
                    <?= $lang==='ms'?'Batal':'Cancel' ?>
                </button>
                <button type="button" class="btn btn--danger btn--sm" id="ttDeleteBtn"
                        style="margin-left:auto;display:none;">
                    🗑 <?= $lang==='ms'?'Padam':'Delete' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── DELETE CONFIRM FORM (hidden) ──────────────────────── -->
<form method="POST" action="timetable.php" id="deleteForm" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id"     id="deleteId">
</form>

<script src="app.js"></script>
<script>
const LANG     = '<?= $lang ?>';
const TODAY_DOW = <?= (int)(new DateTime())->format('w') ?>;

// ── Existing entries for client-side conflict detection ───
const existingEntries = <?= json_encode(array_map(function($e) {
    return ['id'=>(int)$e['id'], 'day'=>(int)$e['day_of_week'], 'start'=>$e['start_time'], 'end'=>$e['end_time']];
}, $entries)) ?>;

const zones = <?= json_encode(array_map(function($z) use ($lang) {
    return ['id'=>(int)$z['id'], 'label'=> $z['code'].' — '.($lang==='ms'?$z['name_ms']:$z['name'])];
}, $zones)) ?>;

// ── Duration hint ─────────────────────────────────────────
function updateDurationHint() {
    const s = document.getElementById('fStart').value;
    const e = document.getElementById('fEnd').value;
    const hint = document.getElementById('durationHint');
    if (!s || !e) { hint.textContent = ''; return; }
    const [sh,sm] = s.split(':').map(Number);
    const [eh,em] = e.split(':').map(Number);
    const mins = (eh*60+em) - (sh*60+sm);
    if (mins <= 0) {
        hint.textContent = LANG==='ms' ? '⚠ Masa tamat mesti selepas masa mula.' : '⚠ End must be after start.';
        hint.style.color = 'var(--red)';
    } else {
        const h = Math.floor(mins/60), m = mins%60;
        hint.textContent = LANG==='ms'
            ? `⏱ Tempoh: ${h?h+'j ':''} ${m?m+'min':''}`
            : `⏱ Duration: ${h?h+'h ':''} ${m?m+'min':''}`;
        hint.style.color = 'var(--text-muted)';
    }
}
document.getElementById('fStart').addEventListener('change', updateDurationHint);
document.getElementById('fEnd').addEventListener('change',   updateDurationHint);

// ── Client-side conflict detection ───────────────────────
function checkConflict() {
    const day   = parseInt(document.getElementById('fDay').value);
    const start = document.getElementById('fStart').value;
    const end   = document.getElementById('fEnd').value;
    const editId = parseInt(document.getElementById('formId').value) || 0;
    if (!start || !end || start >= end) return;

    const warn = document.getElementById('conflictWarning');
    const clash = existingEntries.filter(e =>
        e.id !== editId && e.day === day && e.start < end && e.end > start
    );
    if (clash.length) {
        warn.style.display = 'block';
        warn.textContent = LANG==='ms'
            ? `⚠ Konflik terkesan dengan ${clash.length} kelas lain pada hari ini. Simpan akan ditolak oleh pelayan.`
            : `⚠ Conflict detected with ${clash.length} other class(es) on this day. Save will be rejected by server.`;
    } else {
        warn.style.display = 'none';
    }
}
['fDay','fStart','fEnd'].forEach(id =>
    document.getElementById(id).addEventListener('change', checkConflict)
);

// ── Open ADD modal ────────────────────────────────────────
function openAddModal(prefillDay) {
    document.getElementById('ttModalTitle').textContent =
        LANG==='ms' ? '+ Tambah Kelas' : '+ Add Class';
    document.getElementById('formAction').value = 'add';
    document.getElementById('formId').value     = '0';
    document.getElementById('ttForm').reset();
    document.getElementById('fFrom').value = new Date().getFullYear() + '-01-01';
    document.getElementById('fTo').value   = new Date().getFullYear() + '-12-31';
    document.getElementById('fStart').value = '08:00';
    document.getElementById('fEnd').value   = '10:00';
    if (prefillDay !== undefined) document.getElementById('fDay').value = prefillDay;
    document.getElementById('ttDeleteBtn').style.display = 'none';
    document.getElementById('conflictWarning').style.display = 'none';
    updateDurationHint();
    openModal('ttModal');
}

// ── Open EDIT modal ───────────────────────────────────────
function openEditModal(entry) {
    document.getElementById('ttModalTitle').textContent =
        LANG==='ms' ? '✏️ Edit Kelas' : '✏️ Edit Class';
    document.getElementById('formAction').value      = 'edit';
    document.getElementById('formId').value          = entry.id;
    document.getElementById('fCourseCode').value     = entry.course_code;
    document.getElementById('fCourseName').value     = entry.course_name;
    document.getElementById('fZoneId').value         = entry.zone_id;
    document.getElementById('fVenue').value          = entry.venue || '';
    document.getElementById('fDay').value            = entry.day_of_week;
    document.getElementById('fStart').value          = entry.start_time ? entry.start_time.slice(0,5) : '';
    document.getElementById('fEnd').value            = entry.end_time   ? entry.end_time.slice(0,5)   : '';
    document.getElementById('fSeatCount').value      = entry.seat_count;
    document.getElementById('fFrom').value           = entry.effective_from;
    document.getElementById('fTo').value             = entry.effective_to;

    // Show delete button inside modal for convenience
    const delBtn = document.getElementById('ttDeleteBtn');
    delBtn.style.display = 'inline-flex';
    delBtn.onclick = () => confirmDelete(entry.id, entry.course_code);

    document.getElementById('conflictWarning').style.display = 'none';
    updateDurationHint();
    checkConflict();
    openModal('ttModal');
}

// ── Delete confirm ────────────────────────────────────────
function confirmDelete(id, code) {
    const msg = LANG==='ms'
        ? `Padam kelas '${code}'? Tindakan ini tidak boleh dibuat asal.`
        : `Delete class '${code}'? This cannot be undone.`;
    if (!confirm(msg)) return;
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteForm').submit();
}

// Auto-open modal if there were validation errors from server
<?php if ($flash && $flash['type']==='error' && !empty($flash['form'])): ?>
openEditModal(<?= json_encode((object)array_merge(['id'=>0,'course_code'=>'','course_name'=>'','zone_id'=>0,'venue'=>'','day_of_week'=>1,'start_time'=>'08:00','end_time'=>'10:00','seat_count'=>30,'effective_from'=>date('Y-01-01'),'effective_to'=>date('Y-12-31')], array_intersect_key($flash['form'], array_flip(['id','course_code','course_name','zone_id','venue','day_of_week','start_time','end_time','seat_count','effective_from','effective_to'])))) ?>);
<?php endif; ?>
</script>
</body>
</html>