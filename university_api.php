<?php
// university_api.php – Simulated University Timetable API
// Called ONLY during booking (SR1 verification), not for browsing.
// In production, replace the $registry data with a real HTTP call
// to the university's actual endpoint.
//
// Internal use only — not exposed to students directly.
// Endpoint: GET university_api.php?student_id=X&token=CAMPUS_API_TOKEN_2025

define('API_TOKEN', 'CAMPUS_API_TOKEN_2025');

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$token = $_SERVER['HTTP_X_API_TOKEN'] ?? $_GET['token'] ?? '';
if ($token !== API_TOKEN) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$studentId = trim($_GET['student_id'] ?? '');
if (!$studentId) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'student_id required']);
    exit;
}

// ── Simulated university timetable registry ───────────────
// day_of_week: 0=Sun 1=Mon 2=Tue 3=Wed 4=Thu 5=Fri 6=Sat
// These represent the AUTHORITATIVE schedule the university holds.
$registry = [

    'A22EC0001' => [
        'student_id' => 'A22EC0001',
        'full_name'  => 'Ahmad bin Hassan',
        'programme'  => 'Bachelor of Electrical Engineering',
        'semester'   => '2025/2026-1',
        'timetable'  => [
            ['course_code'=>'BEE3243','course_name'=>'Digital Electronics',
             'zone_code'=>'ENG','venue'=>'ENG Lab 2',
             'day_of_week'=>1,'start_time'=>'08:00','end_time'=>'10:00','seat_count'=>30],
            ['course_code'=>'BEE3243','course_name'=>'Digital Electronics',
             'zone_code'=>'ENG','venue'=>'ENG Lab 2',
             'day_of_week'=>3,'start_time'=>'08:00','end_time'=>'10:00','seat_count'=>30],
            ['course_code'=>'BCS3413','course_name'=>'Operating Systems',
             'zone_code'=>'CCI','venue'=>'CCI Lab 3',
             'day_of_week'=>2,'start_time'=>'14:00','end_time'=>'16:00','seat_count'=>40],
            ['course_code'=>'BCS3413','course_name'=>'Operating Systems',
             'zone_code'=>'CCI','venue'=>'CCI Lab 3',
             'day_of_week'=>4,'start_time'=>'14:00','end_time'=>'16:00','seat_count'=>40],
        ],
    ],

    'A22EC0002' => [
        'student_id' => 'A22EC0002',
        'full_name'  => 'Siti Aminah',
        'programme'  => 'Bachelor of Computer Science',
        'semester'   => '2025/2026-1',
        'timetable'  => [
            ['course_code'=>'BBI1114','course_name'=>'English Communication',
             'zone_code'=>'DKB','venue'=>'DKB Hall 1',
             'day_of_week'=>1,'start_time'=>'10:00','end_time'=>'12:00','seat_count'=>120],
            ['course_code'=>'BMM1013','course_name'=>'Bahasa Malaysia',
             'zone_code'=>'DKB','venue'=>'DKB Hall 2',
             'day_of_week'=>3,'start_time'=>'10:00','end_time'=>'12:00','seat_count'=>80],
        ],
    ],

    'A22EC0003' => [
        'student_id' => 'A22EC0003',
        'full_name'  => 'Aisyah Razak',
        'programme'  => 'Bachelor of Software Engineering',
        'semester'   => '2025/2026-1',
        'timetable'  => [
            ['course_code'=>'BSW3133','course_name'=>'Software Engineering',
             'zone_code'=>'CCI','venue'=>'CCI Tutorial Rm',
             'day_of_week'=>2,'start_time'=>'14:00','end_time'=>'16:30','seat_count'=>25],
            ['course_code'=>'BSW3133','course_name'=>'Software Engineering',
             'zone_code'=>'CCI','venue'=>'CCI Tutorial Rm',
             'day_of_week'=>4,'start_time'=>'14:00','end_time'=>'16:30','seat_count'=>25],
        ],
    ],

    'A22EC0004' => [
        'student_id' => 'A22EC0004',
        'full_name'  => 'Zulfikar Idris',
        'programme'  => 'Bachelor of Electrical Engineering',
        'semester'   => '2025/2026-1',
        'timetable'  => [
            ['course_code'=>'BEE3243','course_name'=>'Digital Electronics',
             'zone_code'=>'ENG','venue'=>'ENG Lab 2',
             'day_of_week'=>1,'start_time'=>'08:00','end_time'=>'10:00','seat_count'=>30],
            ['course_code'=>'BBI1114','course_name'=>'English Communication',
             'zone_code'=>'DKB','venue'=>'DKB Hall 1',
             'day_of_week'=>5,'start_time'=>'10:00','end_time'=>'12:00','seat_count'=>120],
        ],
    ],

    // Student from the image: Muhammad Jeff Aiman Bin Harudin
    'A22EC9012' => [
        'student_id' => 'A22EC9012',
        'full_name'  => 'Muhammad Jeff Aiman Bin Harudin',
        'programme'  => 'Bachelor of Computer Science (Data Engineering)',
        'semester'   => '2025/2026-1',
        'email'      => 'jeffauman04@gmail.com', // Note: In production, don't expose email
        'timetable'  => [
            ['course_code'=>'BEE3243','course_name'=>'Digital Electronics',
             'zone_code'=>'ENG','venue'=>'ENG Lab 2',
             'day_of_week'=>1,'start_time'=>'08:00','end_time'=>'10:00','seat_count'=>30],
            ['course_code'=>'BEE3243','course_name'=>'Digital Electronics',
             'zone_code'=>'ENG','venue'=>'ENG Lab 2',
             'day_of_week'=>3,'start_time'=>'08:00','end_time'=>'10:00','seat_count'=>30],
            ['course_code'=>'BCS3413','course_name'=>'Operating Systems',
             'zone_code'=>'CCI','venue'=>'CCI Lab 3',
             'day_of_week'=>2,'start_time'=>'14:00','end_time'=>'16:00','seat_count'=>40],
            ['course_code'=>'BCS3413','course_name'=>'Operating Systems',
             'zone_code'=>'CCI','venue'=>'CCI Lab 3',
             'day_of_week'=>4,'start_time'=>'14:00','end_time'=>'16:00','seat_count'=>40],
            ['course_code'=>'BSW3133','course_name'=>'Software Engineering',
             'zone_code'=>'CCI','venue'=>'CCI Tutorial Rm',
             'day_of_week'=>3,'start_time'=>'09:00','end_time'=>'11:00','seat_count'=>25],
            ['course_code'=>'BMM1013','course_name'=>'Bahasa Malaysia',
             'zone_code'=>'DKB','venue'=>'DKB Hall 2',
             'day_of_week'=>2,'start_time'=>'10:00','end_time'=>'12:00','seat_count'=>80],
        ],
    ],

    // Alternative student ID format (from image could be different)
    'BSW01086490' => [
        'student_id' => 'BSW01086490',
        'full_name'  => 'Muhammad Jeff Aiman Bin Harudin',
        'programme'  => 'Bachelor of Software Engineering (Hons.)',
        'semester'   => '2025/2026-1',
        'email'      => 'jeffaiman04@gmail.com',
        'timetable'  => [
            ['course_code'=>'CS1013','course_name'=>'Programming Fundamentals',
             'zone_code'=>'CCI','venue'=>'CCI Lab 1',
             'day_of_week'=>1,'start_time'=>'09:00','end_time'=>'11:00','seat_count'=>35],
            ['course_code'=>'CS1023','course_name'=>'Database Systems',
             'zone_code'=>'CCI','venue'=>'CCI Lab 2',
             'day_of_week'=>2,'start_time'=>'11:00','end_time'=>'13:00','seat_count'=>35],
            ['course_code'=>'CS1033','course_name'=>'Web Development',
             'zone_code'=>'CCI','venue'=>'CCI Lab 3',
             'day_of_week'=>3,'start_time'=>'14:00','end_time'=>'16:00','seat_count'=>35],
            ['course_code'=>'MATH1013','course_name'=>'Discrete Mathematics',
             'zone_code'=>'DKB','venue'=>'DKB Lecture Hall',
             'day_of_week'=>4,'start_time'=>'08:00','end_time'=>'10:00','seat_count'=>100],
        ],
    ],
];

// Try multiple possible student ID formats
$studentData = null;
if (isset($registry[$studentId])) {
    $studentData = $registry[$studentId];
} else {
    // Try case-insensitive matching or partial matching
    foreach ($registry as $id => $data) {
        if (strtolower($id) === strtolower($studentId)) {
            $studentData = $data;
            break;
        }
    }
}

if (!$studentData) {
    http_response_code(404);
    echo json_encode([
        'status' => 'error', 
        'message' => 'Student not found',
        'requested_id' => $studentId
    ]);
    exit;
}

// Remove email from response for privacy (only include if absolutely needed)
$responseData = [
    'status'    => 'ok',
    'student'   => [
        'student_id' => $studentData['student_id'],
        'full_name'  => $studentData['full_name'],
        'programme'  => $studentData['programme'],
        'semester'   => $studentData['semester'],
    ],
    'timetable' => $studentData['timetable'],
    'generated' => date('c'),
];

// Optional: Include email only if explicitly requested via parameter
if (isset($_GET['include_email']) && $_GET['include_email'] === 'true') {
    $responseData['student']['email'] = $studentData['email'] ?? 'Not available';
}

echo json_encode($responseData, JSON_PRETTY_PRINT);
?>