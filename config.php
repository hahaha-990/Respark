<?php
// config.php – Central configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'campus_parking');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'ParkCampus');
define('SESSION_LIFETIME', 3600); // 1 hour
define('GRACE_MINUTES', 15);       // FR3
define('EXIT_WARN_MINUTES', 10);   // FR4
define('REMINDER_MINUTES', 15);    // FR4

// Supported languages
define('LANGS', ['ms' => 'Bahasa Malaysia', 'en' => 'English']);

// ─── Database Connection (PDO) ──────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }
    return $pdo;
}

// ─── Session Bootstrap ──────────────────────────────────────
function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_samesite', 'Strict');
        session_set_cookie_params(['lifetime' => SESSION_LIFETIME, 'httponly' => true, 'samesite' => 'Strict']);
        session_start();
    }
}

// ─── Auth Helpers ───────────────────────────────────────────
function currentUser(): ?array {
    startSecureSession();
    return $_SESSION['user'] ?? null;
}

function requireLogin(): void {
    if (!currentUser()) {
        header('Location: index.php');
        exit;
    }
}

function requireAdmin(): void {
    $u = currentUser();
    if (!$u || !in_array($u['role'], ['admin', 'security'])) {
        header('Location: student_dashboard.php');
        exit;
    }
}

// ─── Security Helpers ───────────────────────────────────────
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function studentIdHash(string $id): string {
    return hash('sha256', $id . 'campus_salt_2025'); // SR2
}

function logEligibility(string $studentId, string $action, bool $found, string $decision, string $reason = '', string $device = ''): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    db()->prepare("INSERT INTO eligibility_audit
        (student_id_hash, action, timetable_found, decision, deny_reason, requestor_ip, device_info)
        VALUES (?,?,?,?,?,?,?)")
        ->execute([studentIdHash($studentId), $action, $found ? 1 : 0, $decision, $reason, $ip, substr($device ?: ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250)]);
}

// ─── i18n strings ───────────────────────────────────────────
function t(string $key, string $lang = 'ms'): string {
    static $strings = [
        'ms' => [
            'app_name'         => 'ParkCampus',
            'tagline'          => 'Parkir Bijak, Belajar Fokus',
            'login'            => 'Log Masuk',
            'logout'           => 'Log Keluar',
            'student_id'       => 'ID Pelajar',
            'password'         => 'Kata Laluan',
            'search_slot'      => 'Cari Slot Parking',
            'available'        => 'Tersedia',
            'booked'           => 'Ditempah',
            'my_bookings'      => 'Tempahan Saya',
            'book_slot'        => 'Tempah Slot',
            'cancel'           => 'Batal',
            'extend'           => 'Lanjutkan',
            'confirm_book'     => 'Sahkan Tempahan',
            'zone'             => 'Zon',
            'class_start'      => 'Mula Kelas',
            'class_end'        => 'Tamat Kelas',
            'slot'             => 'Slot',
            'no_timetable'     => 'Tiada jadual kelas yang sah ditemui untuk masa ini.',
            'booking_ok'       => 'Tempahan berjaya! Sila tiba sebelum ',
            'grace_expired'    => 'Tempoh masa telah tamat. Tempahan dibatalkan.',
            'admin_dashboard'  => 'Papan Pemuka Pentadbir',
            'violations'       => 'Pelanggaran',
            'issue_warning'    => 'Keluarkan Amaran',
            'no_slots'         => 'Tiada slot tersedia dalam zon ini.',
            'restricted'       => '🔴 Terhad – Bukan waktu kelas',
            'notifications'    => 'Notifikasi',
        ],
        'en' => [
            'app_name'         => 'ParkCampus',
            'tagline'          => 'Park Smart, Study Focused',
            'login'            => 'Login',
            'logout'           => 'Logout',
            'student_id'       => 'Student ID',
            'password'         => 'Password',
            'search_slot'      => 'Search Parking Slot',
            'available'        => 'Available',
            'booked'           => 'Booked',
            'my_bookings'      => 'My Bookings',
            'book_slot'        => 'Book Slot',
            'cancel'           => 'Cancel',
            'extend'           => 'Extend',
            'confirm_book'     => 'Confirm Booking',
            'zone'             => 'Zone',
            'class_start'      => 'Class Start',
            'class_end'        => 'Class End',
            'slot'             => 'Slot',
            'no_timetable'     => 'No valid class schedule found for this time.',
            'booking_ok'       => 'Booking confirmed! Please arrive before ',
            'grace_expired'    => 'Grace period expired. Booking auto-cancelled.',
            'admin_dashboard'  => 'Admin Dashboard',
            'violations'       => 'Violations',
            'issue_warning'    => 'Issue Warning',
            'no_slots'         => 'No available slots in this zone.',
            'restricted'       => '🔴 Restricted – Non-class hours',
            'notifications'    => 'Notifications',
        ],
    ];
    return $strings[$lang][$key] ?? $key;
}
