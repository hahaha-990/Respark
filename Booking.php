<?php
require 'mail.php';
require 'config.php';

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if (!$conn) { die("Connection failed: " . mysqli_connect_error()); }

$message = "";

if (isset($_POST['submit_booking'])) {
    $student_id   = $_POST['student_id'];
    $slot_id      = $_POST['slot_id'];
    $timetable_id = $_POST['timetable_id'];
    $class_start  = $_POST['class_start'];
    $class_end    = $_POST['class_end'];

    $query = "INSERT INTO bookings (student_id, slot_id, timetable_id, status, class_start, class_end) 
              VALUES ('$student_id', '$slot_id', '$timetable_id', 'confirmed', '$class_start', '$class_end')";

    if (mysqli_query($conn, $query)) {
        
        $info_query = "SELECT u.email, u.full_name, s.slot_name 
                       FROM users u, parking_slots s 
                       WHERE u.student_id = '$student_id' AND s.id = '$slot_id'";
        
        $result = mysqli_query($conn, $info_query);
        $data = mysqli_fetch_assoc($result);

        if ($data) {
            $userEmail = $data['email'];
            $userName  = $data['full_name'];
            $slotName  = $data['slot_name'];

            if (sendBookingNotification($userEmail, $userName, $slotName, $class_start)) {
                $message = "<p style='color:green;'>Booking saved! Email sent to $userEmail.</p>";
            } else {
                $message = "<p style='color:orange;'>Booking saved, but email failed.</p>";
            }
        }
    } else {
        $message = "<p style='color:red;'>Error: " . mysqli_error($conn) . "</p>";
    }
}
?>

<form method="POST">
    <input type="text" name="student_id" placeholder="Student ID (e.g. S123)" required><br>
    
    <label>Slot ID:</label>
    <select name="slot_id">
        <option value="1">Slot 1</option>
        <option value="2">Slot 2</option>
    </select><br>

    <input type="hidden" name="timetable_id" value="101"> <label>Class Start:</label>
    <input type="datetime-local" name="class_start" required><br>
    
    <label>Class End:</label>
    <input type="datetime-local" name="class_end" required><br>
    
    <button type="submit" name="submit_booking">Confirm Reservation</button>
</form>

<?php echo $message; ?>