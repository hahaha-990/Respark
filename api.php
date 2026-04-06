<?php
// api.php – JSON API for all AJAX actions (booking, cancel, extend, violations)
require_once 'config.php';
startSecureSession();

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

$user = currentUser();
if (!$user) { echo json_encode(['ok' => false, 'message' => 'Unauthorized']); exit; }

$input  = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? '';
$lang   = $user['lang_pref'] ?? 'ms';

try {
    switch ($action) {

        // ── BOOK SLOT (FR1, FR2) ─────────────────────────────────
        case 'book_slot': {
            $slotId       = (int)($input['slot_id'] ?? 0);
            $timetableId  = (int)($input['timetable_id'] ?? 0);
            $classDate    = $input['class_date'] ?? '';

            if (!$slotId || !$timetableId || !$classDate) {
                throw new Exception($lang==='ms' ? 'Parameter tidak lengkap.' : 'Incomplete parameters.');
            }

            // SR1: Verify timetable server-side
            $tt = db()->prepare("SELECT * FROM class_timetable WHERE id = ? AND student_id = ?");
            $tt->execute([$timetableId, $user['student_id']]);
            $ttRow = $tt->fetch();
            if (!$ttRow) {
                logEligibility($user['student_id'], 'book', false, 'denied', 'Timetable not found');
                throw new Exception($lang==='ms' ? 'Jadual kelas tidak sah.' : 'Invalid class timetable.');
            }

            // Validate class is today
            $dow = (int)(new DateTime($classDate))->format('w');
            if ($ttRow['day_of_week'] != $dow) {
                logEligibility($user['student_id'], 'book', true, 'denied', 'Day mismatch');
                throw new Exception($lang==='ms' ? 'Hari kelas tidak padan.' : 'Class day mismatch.');
            }

            $classStart   = $classDate . ' ' . $ttRow['start_time'];
            $classEnd     = $classDate . ' ' . $ttRow['end_time'];
            $graceDeadline = date('Y-m-d H:i:s', strtotime($classStart) + (GRACE_MINUTES * 60));

            // Check slot still available
            $chk = db()->prepare("
                SELECT id FROM bookings
                WHERE slot_id = ? AND status IN ('confirmed','active','pending')
                  AND class_start < ? AND class_end > ?
            ");
            $chk->execute([$slotId, $classEnd, $classStart]);
            if ($chk->fetch()) {
                throw new Exception($lang==='ms' ? 'Slot sudah ditempah.' : 'Slot already taken.');
            }

            // Prevent double-booking same student
            $dbl = db()->prepare("
                SELECT id FROM bookings
                WHERE student_id = ? AND status IN ('confirmed','active','pending')
                  AND class_start < ? AND class_end > ?
            ");
            $dbl->execute([$user['student_id'], $classEnd, $classStart]);
            if ($dbl->fetch()) {
                throw new Exception($lang==='ms' ? 'Anda sudah mempunyai tempahan aktif.' : 'You already have an active booking.');
            }

            // Insert booking
            $ins = db()->prepare("
                INSERT INTO bookings (student_id, slot_id, timetable_id, status, class_start, class_end, grace_deadline)
                VALUES (?, ?, ?, 'confirmed', ?, ?, ?)
            ");
            $ins->execute([$user['student_id'], $slotId, $timetableId, $classStart, $classEnd, $graceDeadline]);
            $bookingId = db()->lastInsertId();

            // Get slot code for notification
            $slotRow = db()->prepare("SELECT ps.slot_code, cz.name_ms, cz.name FROM parking_slots ps JOIN campus_zones cz ON ps.zone_id=cz.id WHERE ps.id=?");
            $slotRow->execute([$slotId]);
            $slotData = $slotRow->fetch();
            $zoneName = $lang==='ms' ? $slotData['name_ms'] : $slotData['name'];
            $arriveBy = date('h:i A', strtotime($graceDeadline));

            // FR4: Confirmation notification
            $msgMs = "Slot {$slotData['slot_code']} berdekatan {$zoneName}, masuk sebelum {$arriveBy}.";
            $msgEn = "Slot {$slotData['slot_code']} near {$zoneName}. Please arrive before {$arriveBy}.";
            $msg = $lang==='ms' ? $msgMs : $msgEn;
            db()->prepare("INSERT INTO notifications (booking_id, student_id, type, lang, message_body) VALUES (?,?,?,?,?)")
               ->execute([$bookingId, $user['student_id'], 'confirmation', $lang, $msg]);

            logEligibility($user['student_id'], 'book', true, 'allowed');

            echo json_encode(['ok' => true, 'booking_id' => $bookingId, 'message' => $msg, 'arrive_by' => $arriveBy]);
            break;
        }

        // ── CANCEL BOOKING (FR3) ─────────────────────────────────
        case 'cancel_booking': {
            $bookingId = (int)($input['booking_id'] ?? 0);

            $bk = db()->prepare("SELECT * FROM bookings WHERE id = ? AND student_id = ?");
            $bk->execute([$bookingId, $user['student_id']]);
            $booking = $bk->fetch();

            if (!$booking || !in_array($booking['status'], ['pending','confirmed','active'])) {
                throw new Exception($lang==='ms' ? 'Tempahan tidak dijumpai atau tidak aktif.' : 'Booking not found or not active.');
            }

            db()->prepare("UPDATE bookings SET status='cancelled', cancelled_at=NOW(), cancel_reason='student_request' WHERE id=?")
               ->execute([$bookingId]);

            echo json_encode(['ok' => true, 'message' => $lang==='ms' ? 'Tempahan dibatalkan.' : 'Booking cancelled.']);
            break;
        }

        // ── ADMIN CANCEL (FR5) ───────────────────────────────────
        case 'admin_cancel': {
            if (!in_array($user['role'], ['admin','security'])) throw new Exception('Forbidden');
            $bookingId = (int)($input['booking_id'] ?? 0);
            $reason    = $input['reason'] ?? 'admin_action';

            db()->prepare("UPDATE bookings SET status='cancelled', cancelled_at=NOW(), cancelled_by=?, cancel_reason=? WHERE id=?")
               ->execute([$user['id'], $reason, $bookingId]);

            // Notify student
            $bk = db()->prepare("SELECT b.student_id, b.class_start, ps.slot_code FROM bookings b JOIN parking_slots ps ON b.slot_id=ps.id WHERE b.id=?");
            $bk->execute([$bookingId]);
            $bd = $bk->fetch();
            if ($bd) {
                $msgMs = "Tempahan slot {$bd['slot_code']} anda telah dibatalkan oleh pentadbir.";
                $msgEn = "Your booking for slot {$bd['slot_code']} was cancelled by admin.";
                db()->prepare("INSERT INTO notifications (booking_id, student_id, type, lang, message_body) VALUES (?,?,?,?,?)")
                   ->execute([$bookingId, $bd['student_id'], 'admin_warning', $user['lang_pref']??'ms', $lang==='ms'?$msgMs:$msgEn]);
            }

            echo json_encode(['ok' => true]);
            break;
        }

        // ── EXTEND BOOKING (FR3) ─────────────────────────────────
        case 'extend_booking': {
            $bookingId   = (int)($input['booking_id'] ?? 0);
            $newEndTime  = $input['new_end_time'] ?? ''; // HH:MM

            $bk = db()->prepare("SELECT b.*, ct.end_time as tt_end, ct.day_of_week FROM bookings b JOIN class_timetable ct ON b.timetable_id=ct.id WHERE b.id=? AND b.student_id=?");
            $bk->execute([$bookingId, $user['student_id']]);
            $booking = $bk->fetch();

            if (!$booking) throw new Exception($lang==='ms' ? 'Tempahan tidak dijumpai.' : 'Booking not found.');

            // Build proposed new end datetime
            $classDate   = date('Y-m-d', strtotime($booking['class_start']));
            $newEndDT    = new DateTime($classDate . ' ' . $newEndTime);
            $timetableEnd = new DateTime($classDate . ' ' . $booking['tt_end']);
            $currentEnd  = new DateTime($booking['class_end']);

            if ($newEndDT <= $currentEnd) {
                throw new Exception($lang==='ms' ? 'Masa baharu mesti lebih lewat dari masa tamat semasa.' : 'New end must be later than current end.');
            }
            if ($newEndDT > $timetableEnd) {
                throw new Exception($lang==='ms' ? 'Tidak boleh melebihi masa tamat jadual kelas.' : 'Cannot exceed timetable end time.');
            }

            // Check no conflict with another booking on same slot
            $newEndStr = $newEndDT->format('Y-m-d H:i:s');
            $conflict = db()->prepare("
                SELECT id FROM bookings
                WHERE slot_id = ? AND id != ? AND status IN ('confirmed','active','pending')
                  AND class_start < ? AND class_end > ?
            ");
            $conflict->execute([$booking['slot_id'], $bookingId, $newEndStr, $booking['class_end']]);
            if ($conflict->fetch()) {
                throw new Exception($lang==='ms' ? 'Slot sudah ditempah untuk tempoh itu.' : 'Slot already booked for that period.');
            }

            db()->prepare("UPDATE bookings SET extended_end=? WHERE id=?")
               ->execute([$newEndStr, $bookingId]);

            echo json_encode(['ok' => true, 'new_end' => $newEndStr]);
            break;
        }

        // ── ISSUE VIOLATION (FR5) ────────────────────────────────
        case 'issue_violation': {
            if (!in_array($user['role'], ['admin','security'])) throw new Exception('Forbidden');

            $bookingId = $input['booking_id'] ? (int)$input['booking_id'] : null;
            $studentId = $input['student_id'] ?? '';
            $type      = $input['type'] ?? 'other';
            $desc      = $input['description'] ?? '';

            if (!in_array($type, ['no_show','overstay','invalid_booking','other'])) $type = 'other';

            db()->prepare("INSERT INTO violations (booking_id, student_id, issued_by, type, description) VALUES (?,?,?,?,?)")
               ->execute([$bookingId, $studentId, $user['id'], $type, $desc]);

            // Notify student
            $msgMs = "Amaran pelanggaran telah dikeluarkan terhadap anda: $type. Sila hubungi pentadbir.";
            $msgEn = "A violation warning has been issued against you: $type. Please contact admin.";
            db()->prepare("INSERT INTO notifications (booking_id, student_id, type, lang, message_body) VALUES (?,?,?,?,?)")
               ->execute([$bookingId, $studentId, 'admin_warning', 'ms', $msgMs]);

            echo json_encode(['ok' => true]);
            break;
        }

        // ── RESOLVE VIOLATION ────────────────────────────────────
        case 'resolve_violation': {
            if (!in_array($user['role'], ['admin','security'])) throw new Exception('Forbidden');
            $id = (int)($input['id'] ?? 0);
            db()->prepare("UPDATE violations SET status='resolved', resolved_at=NOW() WHERE id=?")
               ->execute([$id]);
            echo json_encode(['ok' => true]);
            break;
        }

        default:
            echo json_encode(['ok' => false, 'message' => 'Unknown action']);
    }

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
