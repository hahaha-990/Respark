<?php
// cron_autocancel.php
// Run via cron every 5 minutes:
// */5 * * * * php /path/to/parking/cron_autocancel.php
//
// FR3: Auto-cancel grace expired bookings
// FR4: Send pre-class and expiry reminders

require_once __DIR__ . '/config.php';

$now = date('Y-m-d H:i:s');

// ── 1. Auto-cancel if grace_deadline passed and no check-in ──
$expired = db()->prepare("
    SELECT b.id, b.student_id, b.slot_id, ps.slot_code, b.class_start, b.class_end,
           u.lang_pref, cz.name_ms, cz.name
    FROM bookings b
    JOIN parking_slots ps ON b.slot_id = ps.id
    JOIN campus_zones cz ON ps.zone_id = cz.id
    LEFT JOIN users u ON b.student_id = u.student_id
    WHERE b.status IN ('pending','confirmed')
      AND b.grace_deadline < ?
");
$expired->execute([$now]);
foreach ($expired->fetchAll() as $bk) {
    db()->prepare("UPDATE bookings SET status='auto_cancelled', cancelled_at=NOW(), cancel_reason='grace_expired' WHERE id=?")
       ->execute([$bk['id']]);

    $lang = $bk['lang_pref'] ?? 'ms';
    $zone = $lang === 'ms' ? $bk['name_ms'] : $bk['name'];
    $msgMs = "Tempahan slot {$bk['slot_code']} dibatalkan secara automatik (anda tidak hadir dalam masa 15 minit).";
    $msgEn = "Booking for slot {$bk['slot_code']} auto-cancelled (no check-in within 15 minutes).";
    db()->prepare("INSERT INTO notifications (booking_id, student_id, type, lang, message_body) VALUES (?,?,?,?,?)")
       ->execute([$bk['id'], $bk['student_id'], 'auto_cancel', $lang, $lang==='ms'?$msgMs:$msgEn]);
    echo "[CRON] Auto-cancelled booking #{$bk['id']} for {$bk['student_id']}\n";
}

// ── 2. 15-min pre-class reminder ─────────────────────────────
$reminderTime = date('Y-m-d H:i:s', strtotime("+15 minutes", strtotime($now)));
$upcoming = db()->prepare("
    SELECT b.id, b.student_id, b.class_start, ps.slot_code, cz.name_ms, cz.name, u.lang_pref
    FROM bookings b
    JOIN parking_slots ps ON b.slot_id = ps.id
    JOIN campus_zones cz ON ps.zone_id = cz.id
    LEFT JOIN users u ON b.student_id = u.student_id
    WHERE b.status IN ('confirmed','pending')
      AND b.class_start BETWEEN ? AND ?
      AND NOT EXISTS (
          SELECT 1 FROM notifications n WHERE n.booking_id = b.id AND n.type = 'reminder_15min'
      )
");
$upcoming->execute([$now, $reminderTime]);
foreach ($upcoming->fetchAll() as $bk) {
    $lang = $bk['lang_pref'] ?? 'ms';
    $zone = $lang === 'ms' ? $bk['name_ms'] : $bk['name'];
    $time = date('h:i A', strtotime($bk['class_start']));
    $msgMs = "15 minit lagi kelas bermula di {$zone} ({$time}) – jangan lambat atau tempahan anda akan dibatalkan!";
    $msgEn = "15 minutes until class at {$zone} ({$time}) – please arrive on time or booking will be cancelled!";
    db()->prepare("INSERT INTO notifications (booking_id, student_id, type, lang, message_body) VALUES (?,?,?,?,?)")
       ->execute([$bk['id'], $bk['student_id'], 'reminder_15min', $lang, $lang==='ms'?$msgMs:$msgEn]);
    echo "[CRON] Sent 15-min reminder for booking #{$bk['id']}\n";
}

// ── 3. 10-min exit warning at class end ──────────────────────
$endingSoon = db()->prepare("
    SELECT b.id, b.student_id, b.class_end, ps.slot_code, u.lang_pref,
           COALESCE(b.extended_end, b.class_end) as effective_end
    FROM bookings b
    JOIN parking_slots ps ON b.slot_id = ps.id
    LEFT JOIN users u ON b.student_id = u.student_id
    WHERE b.status IN ('confirmed','active')
      AND COALESCE(b.extended_end, b.class_end) BETWEEN ? AND ?
      AND NOT EXISTS (
          SELECT 1 FROM notifications n WHERE n.booking_id = b.id AND n.type = 'expiry_10min'
      )
");
$warnTime = date('Y-m-d H:i:s', strtotime("+10 minutes", strtotime($now)));
$endingSoon->execute([$now, $warnTime]);
foreach ($endingSoon->fetchAll() as $bk) {
    $lang = $bk['lang_pref'] ?? 'ms';
    $msgMs = "Sila keluarkan kenderaan dari slot {$bk['slot_code']} dalam masa 10 minit untuk beri ruang kepada pelajar yang akan menghadiri kelas seterusnya.";
    $msgEn = "Please vacate slot {$bk['slot_code']} within 10 minutes to free space for the next class group.";
    db()->prepare("INSERT INTO notifications (booking_id, student_id, type, lang, message_body) VALUES (?,?,?,?,?)")
       ->execute([$bk['id'], $bk['student_id'], 'expiry_10min', $lang, $lang==='ms'?$msgMs:$msgEn]);
    echo "[CRON] Sent exit warning for booking #{$bk['id']}\n";
}

echo "[CRON] Done at $now\n";
