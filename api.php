<?php
// api.php – JSON API (booking, cancel, extend, violations)
require_once 'config.php';
startSecureSession();

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

$user = currentUser();
if (!$user) { echo json_encode(['ok'=>false,'message'=>'Unauthorized']); exit; }

$input  = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? '';
$lang   = $user['lang_pref'] ?? 'ms';

// ── Grace window enforcement helper ───────────────────────
// Returns true if the student is within the modifiable window:
//   • within GRACE_MINUTES after booking was confirmed, OR
//   • within 15 min before class starts
function withinModifyWindow(array $booking): bool {
    $now           = new DateTime();
    $graceDeadline = new DateTime($booking['grace_deadline']);
    $classStart    = new DateTime($booking['class_start']);
    $preClassOpen  = clone $classStart; $preClassOpen->modify('-15 minutes');

    $inGrace  = $now <= $graceDeadline;
    $preClass = $now >= $preClassOpen && $now < $classStart;
    return $inGrace || $preClass;
}

try {
    switch ($action) {

        // ── BOOK SLOT (FR1, FR2) ─────────────────────────────────
        case 'book_slot': {
            $slotId      = (int)($input['slot_id']      ?? 0);
            $timetableId = (int)($input['timetable_id'] ?? 0);
            $classDate   = $input['class_date'] ?? '';

            if (!$slotId || !$timetableId || !$classDate)
                throw new Exception($lang==='ms' ? 'Parameter tidak lengkap.' : 'Incomplete parameters.');

            // SR1: Step 1 – load local timetable record (student-entered)
            $tt = db()->prepare("SELECT * FROM class_timetable WHERE id=? AND student_id=?");
            $tt->execute([$timetableId, $user['student_id']]);
            $ttRow = $tt->fetch();
            if (!$ttRow) {
                logEligibility($user['student_id'], 'book', false, 'denied', 'Timetable not found');
                throw new Exception($lang==='ms' ? 'Jadual kelas tidak dijumpai.' : 'Timetable entry not found.');
            }

            $dow = (int)(new DateTime($classDate))->format('w');
            if ((int)$ttRow['day_of_week'] !== $dow) {
                logEligibility($user['student_id'], 'book', true, 'denied', 'Day mismatch');
                throw new Exception($lang==='ms' ? 'Hari kelas tidak padan.' : 'Class day mismatch.');
            }

            // SR1: Step 2 – cross-check against university API (only at booking time)
            $apiEntry = verifyWithUniversityApi(
                $user['student_id'],
                $ttRow['course_code'],
                (int)$ttRow['day_of_week'],
                $ttRow['start_time'],
                $ttRow['end_time']
            );

            if ($apiEntry === null) {
                // API unreachable OR course not found in university record
                // Log the discrepancy but decide based on policy:
                //   - If API is unreachable (null from network error): fail-open, allow booking, log warning
                //   - If API returned a record set but course missing: deny
                // We distinguish by attempting a raw fetch to check reachability
                $apiReachable = @file_get_contents(
                    UNI_API_URL . '?student_id=' . urlencode($user['student_id']) . '&token=' . UNI_API_TOKEN,
                    false,
                    stream_context_create(['http'=>['timeout'=>UNI_API_TIMEOUT,'ignore_errors'=>true]])
                );
                $apiData = $apiReachable ? json_decode($apiReachable, true) : null;

                if ($apiData && ($apiData['status'] ?? '') === 'ok') {
                    // API is up but course not found — hard deny
                    logEligibility($user['student_id'], 'book', true, 'denied',
                        'Course not found in university API: ' . $ttRow['course_code']);
                    throw new Exception($lang==='ms'
                        ? 'Pengesahan gagal: kursus ' . h($ttRow['course_code']) . ' tidak ditemui dalam rekod universiti.'
                        : 'Verification failed: course ' . h($ttRow['course_code']) . ' not found in university records.');
                } else {
                    // API unreachable — fail-open, log warning, proceed
                    logEligibility($user['student_id'], 'book', true, 'allowed',
                        'API unreachable – fail-open for ' . $ttRow['course_code']);
                }
            } else {
                // API confirmed the entry — log success
                logEligibility($user['student_id'], 'book', true, 'allowed',
                    'API verified: ' . $ttRow['course_code']);
            }

            $classStart    = $classDate . ' ' . $ttRow['start_time'];
            $classEnd      = $classDate . ' ' . $ttRow['end_time'];
            $graceDeadline = date('Y-m-d H:i:s', strtotime($classStart) + GRACE_MINUTES * 60);

            // Slot conflict check
            $chk = db()->prepare("
                SELECT id FROM bookings
                WHERE slot_id=? AND status IN ('confirmed','active','pending')
                  AND class_start < ? AND class_end > ?
            ");
            $chk->execute([$slotId, $classEnd, $classStart]);
            if ($chk->fetch())
                throw new Exception($lang==='ms' ? 'Slot sudah ditempah.' : 'Slot already taken.');

            // Double-booking guard
            $dbl = db()->prepare("
                SELECT id FROM bookings
                WHERE student_id=? AND status IN ('confirmed','active','pending')
                  AND class_start < ? AND class_end > ?
            ");
            $dbl->execute([$user['student_id'], $classEnd, $classStart]);
            if ($dbl->fetch())
                throw new Exception($lang==='ms' ? 'Anda sudah mempunyai tempahan aktif.' : 'You already have an active booking.');

            $ins = db()->prepare("
                INSERT INTO bookings (student_id,slot_id,timetable_id,status,class_start,class_end,grace_deadline)
                VALUES (?,?,?,'confirmed',?,?,?)
            ");
            $ins->execute([$user['student_id'],$slotId,$timetableId,$classStart,$classEnd,$graceDeadline]);
            $bookingId = db()->lastInsertId();

            $slotRow = db()->prepare("SELECT ps.slot_code, cz.name_ms, cz.name FROM parking_slots ps JOIN campus_zones cz ON ps.zone_id=cz.id WHERE ps.id=?");
            $slotRow->execute([$slotId]);
            $sd = $slotRow->fetch();
            $zoneName = $lang==='ms' ? $sd['name_ms'] : $sd['name'];
            $arriveBy = date('h:i A', strtotime($graceDeadline));

            $msgMs = "Slot {$sd['slot_code']} berdekatan {$zoneName}, masuk sebelum {$arriveBy}.";
            $msgEn = "Slot {$sd['slot_code']} near {$zoneName}. Please arrive before {$arriveBy}.";
            $msg   = $lang==='ms' ? $msgMs : $msgEn;
            db()->prepare("INSERT INTO notifications (booking_id,student_id,type,lang,message_body) VALUES (?,?,?,?,?)")
               ->execute([$bookingId,$user['student_id'],'confirmation',$lang,$msg]);

            logEligibility($user['student_id'], 'book', true, 'allowed');
            echo json_encode(['ok'=>true,'booking_id'=>$bookingId,'message'=>$msg,'arrive_by'=>$arriveBy,
                              'grace_deadline'=>$graceDeadline]);
            break;
        }

        // ── CANCEL BOOKING (FR3) ─────────────────────────────────
        case 'cancel_booking': {
            $bookingId = (int)($input['booking_id'] ?? 0);

            $bk = db()->prepare("SELECT * FROM bookings WHERE id=? AND student_id=?");
            $bk->execute([$bookingId, $user['student_id']]);
            $booking = $bk->fetch();

            if (!$booking || !in_array($booking['status'], ['pending','confirmed','active']))
                throw new Exception($lang==='ms' ? 'Tempahan tidak dijumpai atau tidak aktif.' : 'Booking not found or not active.');

            // FR3: Enforce grace window — cancel only within modify window
            if (!withinModifyWindow($booking)) {
                logEligibility($user['student_id'], 'cancel', true, 'denied', 'Outside modify window');
                throw new Exception($lang==='ms'
                    ? 'Pembatalan tidak dibenarkan: tempoh masa ubah suai telah tamat. Hubungi pentadbir jika perlu.'
                    : 'Cancellation not allowed: modification window has closed. Contact admin if needed.');
            }

            db()->prepare("UPDATE bookings SET status='cancelled', cancelled_at=NOW(), cancel_reason='student_request' WHERE id=?")
               ->execute([$bookingId]);

            logEligibility($user['student_id'], 'cancel', true, 'allowed');
            echo json_encode(['ok'=>true, 'message'=>$lang==='ms'?'Tempahan dibatalkan.':'Booking cancelled.']);
            break;
        }

        // ── EXTEND BOOKING (FR3) ─────────────────────────────────
        case 'extend_booking': {
            $bookingId  = (int)($input['booking_id']  ?? 0);
            $newEndTime = trim($input['new_end_time'] ?? ''); // HH:MM

            $bk = db()->prepare("
                SELECT b.*, ct.end_time as tt_end, ct.start_time as tt_start, ct.day_of_week
                FROM bookings b
                JOIN class_timetable ct ON b.timetable_id = ct.id
                WHERE b.id=? AND b.student_id=?
            ");
            $bk->execute([$bookingId, $user['student_id']]);
            $booking = $bk->fetch();

            if (!$booking)
                throw new Exception($lang==='ms' ? 'Tempahan tidak dijumpai.' : 'Booking not found.');

            if (!in_array($booking['status'], ['pending','confirmed','active']))
                throw new Exception($lang==='ms' ? 'Hanya tempahan aktif boleh dilanjutkan.' : 'Only active bookings can be extended.');

            // FR3: Enforce grace window OR class-in-progress for extension
            $now         = new DateTime();
            $classStart  = new DateTime($booking['class_start']);
            $currentEnd  = new DateTime($booking['extended_end'] ?? $booking['class_end']);
            $classRunning = $now >= $classStart && $now <= $currentEnd;

            if (!withinModifyWindow($booking) && !$classRunning) {
                logEligibility($user['student_id'], 'extend', true, 'denied', 'Outside extend window');
                throw new Exception($lang==='ms'
                    ? 'Lanjutan tidak dibenarkan: tempoh masa ubah suai atau kelas aktif telah tamat.'
                    : 'Extension not allowed: modification window and active class period have both closed.');
            }

            // Validate new end time format
            if (!preg_match('/^\d{2}:\d{2}$/', $newEndTime))
                throw new Exception($lang==='ms' ? 'Format masa tidak sah.' : 'Invalid time format.');

            $classDate    = date('Y-m-d', strtotime($booking['class_start']));
            $newEndDT     = new DateTime($classDate . ' ' . $newEndTime . ':00');
            $timetableEnd = new DateTime($classDate . ' ' . $booking['tt_end']);

            // Must be later than current effective end
            if ($newEndDT <= $currentEnd)
                throw new Exception($lang==='ms'
                    ? 'Masa baharu mesti lebih lewat daripada masa tamat semasa (' . $currentEnd->format('h:i A') . ').'
                    : 'New time must be later than current end (' . $currentEnd->format('h:i A') . ').');

            // Must not exceed timetable end (SR1 bound)
            if ($newEndDT > $timetableEnd)
                throw new Exception($lang==='ms'
                    ? 'Tidak boleh melebihi had jadual kelas: ' . $timetableEnd->format('h:i A') . '.'
                    : 'Cannot exceed timetable bound: ' . $timetableEnd->format('h:i A') . '.');

            // No slot conflict in the extended period
            $newEndStr = $newEndDT->format('Y-m-d H:i:s');
            $conflict  = db()->prepare("
                SELECT id FROM bookings
                WHERE slot_id=? AND id!=? AND status IN ('confirmed','active','pending')
                  AND class_start < ? AND class_end > ?
            ");
            $conflict->execute([$booking['slot_id'], $bookingId, $newEndStr, $booking['class_end']]);
            if ($conflict->fetch())
                throw new Exception($lang==='ms'
                    ? 'Konflik: slot sudah ditempah oleh pelajar lain dalam tempoh lanjutan itu.'
                    : 'Conflict: slot is already booked by another student during that extended period.');

            db()->prepare("UPDATE bookings SET extended_end=? WHERE id=?")->execute([$newEndStr, $bookingId]);

            // FR4: Notify student of extension
            $msgMs = "Tempahan anda telah dilanjutkan hingga " . $newEndDT->format('h:i A') . ".";
            $msgEn = "Your booking has been extended to " . $newEndDT->format('h:i A') . ".";
            db()->prepare("INSERT INTO notifications (booking_id,student_id,type,lang,message_body) VALUES (?,?,?,?,?)")
               ->execute([$bookingId,$user['student_id'],'confirmation',$lang,$lang==='ms'?$msgMs:$msgEn]);

            logEligibility($user['student_id'], 'extend', true, 'allowed');
            echo json_encode(['ok'=>true,'new_end'=>$newEndStr,'new_end_fmt'=>$newEndDT->format('h:i A')]);
            break;
        }

        // ── ADMIN CANCEL (FR5) ───────────────────────────────────
        case 'admin_cancel': {
            if (!in_array($user['role'],['admin','security'])) throw new Exception('Forbidden');
            $bookingId = (int)($input['booking_id'] ?? 0);
            $reason    = $input['reason'] ?? 'admin_action';
            db()->prepare("UPDATE bookings SET status='cancelled',cancelled_at=NOW(),cancelled_by=?,cancel_reason=? WHERE id=?")
               ->execute([$user['id'],$reason,$bookingId]);
            $bk = db()->prepare("SELECT b.student_id, ps.slot_code FROM bookings b JOIN parking_slots ps ON b.slot_id=ps.id WHERE b.id=?");
            $bk->execute([$bookingId]);
            $bd = $bk->fetch();
            if ($bd) {
                $msgMs = "Tempahan slot {$bd['slot_code']} anda telah dibatalkan oleh pentadbir.";
                $msgEn = "Your booking for slot {$bd['slot_code']} was cancelled by admin.";
                db()->prepare("INSERT INTO notifications (booking_id,student_id,type,lang,message_body) VALUES (?,?,?,?,?)")
                   ->execute([$bookingId,$bd['student_id'],'admin_warning',$lang,$lang==='ms'?$msgMs:$msgEn]);
            }
            echo json_encode(['ok'=>true]);
            break;
        }

        // ── ISSUE VIOLATION (FR5) ────────────────────────────────
        case 'issue_violation': {
            if (!in_array($user['role'],['admin','security'])) throw new Exception('Forbidden');
            $bookingId = $input['booking_id'] ? (int)$input['booking_id'] : null;
            $studentId = $input['student_id'] ?? '';
            $type      = $input['type'] ?? 'other';
            $desc      = $input['description'] ?? '';
            if (!in_array($type,['no_show','overstay','invalid_booking','other'])) $type='other';
            db()->prepare("INSERT INTO violations (booking_id,student_id,issued_by,type,description) VALUES (?,?,?,?,?)")
               ->execute([$bookingId,$studentId,$user['id'],$type,$desc]);
            $msgMs = "Amaran pelanggaran dikeluarkan: $type. Sila hubungi pentadbir.";
            $msgEn = "Violation warning issued: $type. Please contact admin.";
            db()->prepare("INSERT INTO notifications (booking_id,student_id,type,lang,message_body) VALUES (?,?,?,?,?)")
               ->execute([$bookingId,$studentId,'admin_warning','ms',$msgMs]);
            echo json_encode(['ok'=>true]);
            break;
        }

        // ── RESOLVE VIOLATION ────────────────────────────────────
        case 'resolve_violation': {
            if (!in_array($user['role'],['admin','security'])) throw new Exception('Forbidden');
            $id = (int)($input['id'] ?? 0);
            db()->prepare("UPDATE violations SET status='resolved',resolved_at=NOW() WHERE id=?")->execute([$id]);
            echo json_encode(['ok'=>true]);
            break;
        }

        default:
            echo json_encode(['ok'=>false,'message'=>'Unknown action']);
    }

} catch (Exception $e) {
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
}