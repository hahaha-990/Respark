<?php
require_once 'config.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = currentUser();
    $db = db();

    $slotId      = $_POST['slot_id'] ?? null;
    $timetableId = $_POST['timetable_id'] ?? null;
    $date        = $_POST['date'] ?? date('Y-m-d'); 
    $startTime   = $_POST['start_time'] ?? null;
    $endTime     = $_POST['end_time'] ?? null;

    if (!$slotId || !$timetableId || !$startTime || !$endTime) {
        header("Location: student_dashboard.php?status=error&message=missing_data");
        exit;
    }

    $classStartTS = $date . ' ' . $startTime;
    $classEndTS   = $date . ' ' . $endTime;
    
    $graceDeadline = date('Y-m-d H:i:s', strtotime($classStartTS . ' +15 minutes'));

    try {
        $checkStmt = $db->prepare("
            SELECT COUNT(*) FROM bookings 
            WHERE slot_id = ? 
            AND status IN ('confirmed', 'active', 'pending')
            AND (
                (class_start <= ? AND class_end >= ?) OR
                (class_start <= ? AND class_end >= ?)
            )
        ");
        $checkStmt->execute([$slotId, $classStartTS, $classStartTS, $classEndTS, $classEndTS]);
        
        if ($checkStmt->fetchColumn() > 0) {
            header("Location: student_dashboard.php?status=error&message=slot_taken");
            exit;
        }

        $stmt = $db->prepare("
            INSERT INTO bookings (
                student_id, 
                slot_id, 
                timetable_id, 
                status, 
                booked_at, 
                class_start, 
                class_end, 
                grace_deadline
            ) VALUES (?, ?, ?, 'confirmed', NOW(), ?, ?, ?)
        ");

        $stmt->execute([
            $user['student_id'], 
            $slotId,             
            $timetableId,        
            $classStartTS,
            $classEndTS,
            $graceDeadline
        ]);

        header("Location: student_dashboard.php?status=success&message=booked");
        exit;

    } catch (PDOException $e) {
        error_log("Booking Error: " . $e->getMessage());
        header("Location: student_dashboard.php?status=error&message=db_error");
        exit;
    }
} else {
    header("Location: student_dashboard.php");
    exit;
}