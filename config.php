<?php
// config.php – Central configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'campus_parking');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'ParkCampus');
define('SESSION_LIFETIME', 3600);
define('GRACE_MINUTES', 15);
define('EXIT_WARN_MINUTES', 10);
define('REMINDER_MINUTES', 15);

// MFA (SR7) – email OTP only, no phone
define('MFA_OTP_EXPIRY_MINUTES', 10);
define('MFA_OTP_LENGTH', 6);

// University API (SR1) – called only at booking time, not during browsing
define('UNI_API_URL',   'http://localhost/university_api.php'); // change in production
define('UNI_API_TOKEN', 'CAMPUS_API_TOKEN_2025');
define('UNI_API_TIMEOUT', 5);  // seconds

define('LANGS', ['ms' => 'Bahasa Malaysia', 'en' => 'English']);

// DB
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// Session
function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_samesite', 'Strict');
        session_set_cookie_params(['lifetime' => SESSION_LIFETIME, 'httponly' => true, 'samesite' => 'Strict']);
        session_start();
    }
}

function currentUser(): ?array {
    startSecureSession();
    return $_SESSION['user'] ?? null;
}

function requireLogin(): void {
    if (!currentUser()) { header('Location: index.php'); exit; }
}

function requireAdmin(): void {
    $u = currentUser();
    if (!$u || !in_array($u['role'], ['admin', 'security'])) {
        header('Location: student_dashboard.php'); exit;
    }
    // SR7: email MFA gate for admins
    if ($u['mfa_enabled'] && empty($_SESSION['mfa_verified'])) {
        header('Location: mfa_verify.php'); exit;
    }
}

// Security
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function studentIdHash(string $id): string {
    return hash('sha256', $id . 'campus_salt_2025');
}
function logEligibility(string $studentId, string $action, bool $found, string $decision, string $reason = '', string $device = ''): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    db()->prepare("INSERT INTO eligibility_audit (student_id_hash,action,timetable_found,decision,deny_reason,requestor_ip,device_info) VALUES (?,?,?,?,?,?,?)")
       ->execute([studentIdHash($studentId), $action, $found?1:0, $decision, $reason, $ip, substr($device ?: ($_SERVER['HTTP_USER_AGENT']??''),0,250)]);
}

// ── MFA Email OTP (SR7) ────────────────────────────────────

function generateMfaOtp(int $userId): string {
    $otp    = str_pad((string)random_int(0, (int)pow(10, MFA_OTP_LENGTH)-1), MFA_OTP_LENGTH, '0', STR_PAD_LEFT);
    $hashed = password_hash($otp, PASSWORD_BCRYPT);
    $expiry = date('Y-m-d H:i:s', time() + MFA_OTP_EXPIRY_MINUTES * 60);
    db()->prepare("UPDATE mfa_otps SET used=1 WHERE user_id=? AND used=0")->execute([$userId]);
    db()->prepare("INSERT INTO mfa_otps (user_id,otp_hash,expires_at) VALUES (?,?,?)")->execute([$userId,$hashed,$expiry]);
    return $otp;
}

function verifyMfaOtp(int $userId, string $submitted): bool {
    $stmt = db()->prepare("SELECT id,otp_hash FROM mfa_otps WHERE user_id=? AND used=0 AND expires_at>NOW() ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($submitted, $row['otp_hash'])) return false;
    db()->prepare("UPDATE mfa_otps SET used=1 WHERE id=?")->execute([$row['id']]);
    return true;
}

function sendMfaEmail(string $toEmail, string $toName, string $otp, string $lang = 'ms'): bool {
    $subject = $lang === 'ms'
        ? '[ParkCampus] Kod Pengesahan Log Masuk Pentadbir'
        : '[ParkCampus] Admin Login Verification Code';
    $expMin = MFA_OTP_EXPIRY_MINUTES;

    $body = $lang === 'ms'
        ? "Salam $toName,\n\nKod OTP anda ialah:\n\n    $otp\n\nSah selama $expMin minit sahaja. Jangan kongsikan kod ini.\n\nJika anda tidak meminta ini, hubungi pentadbir sistem segera.\n\n– Sistem ParkCampus"
        : "Dear $toName,\n\nYour OTP code is:\n\n    $otp\n\nValid for $expMin minutes only. Do not share this code.\n\nIf you did not request this, contact your system administrator immediately.\n\n– ParkCampus System";

    $headers = "From: noreply@parkcampus.edu.my\r\nReply-To: noreply@parkcampus.edu.my\r\nX-Mailer: ParkCampus/1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    return mail($toEmail, $subject, $body, $headers);
}

// ── University API helper (SR1) ───────────────────────────
// Called ONLY during booking to cross-check the student's
// timetable entry against the authoritative university record.
// Returns the matching API timetable entry or null.
function verifyWithUniversityApi(string $studentId, string $courseCode, int $dayOfWeek, string $startTime, string $endTime): ?array {
    $url = UNI_API_URL . '?' . http_build_query(['student_id' => $studentId, 'token' => UNI_API_TOKEN]);

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => UNI_API_TIMEOUT,
            'header'  => 'X-Api-Token: ' . UNI_API_TOKEN . "\r\n",
            'ignore_errors' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;   // API unreachable — fail open (log + allow)

    $data = json_decode($raw, true);
    if (!$data || ($data['status'] ?? '') !== 'ok') return null;

    // Find an entry that matches course + day + overlapping or equal time window
    foreach ($data['timetable'] as $entry) {
        if (
            $entry['course_code']  === $courseCode &&
            (int)$entry['day_of_week'] === $dayOfWeek &&
            $entry['start_time']   === substr($startTime, 0, 5) &&
            $entry['end_time']     === substr($endTime, 0, 5)
        ) {
            return $entry;
        }
    }
    return null;  // course/time not found in university record
}

// i18n
function t(string $key, string $lang = 'ms'): string {
    static $s = [
        'ms' => [
            'app_name'=>'ParkCampus','tagline'=>'Parkir Bijak, Belajar Fokus',
            'login'=>'Log Masuk','logout'=>'Log Keluar',
            'student_id'=>'ID Pelajar','password'=>'Kata Laluan',
            'search_slot'=>'Cari Slot Parking','available'=>'Tersedia','booked'=>'Ditempah',
            'my_bookings'=>'Tempahan Saya','book_slot'=>'Tempah Slot',
            'cancel'=>'Batal','extend'=>'Lanjutkan','confirm_book'=>'Sahkan Tempahan',
            'zone'=>'Zon','class_start'=>'Mula Kelas','class_end'=>'Tamat Kelas','slot'=>'Slot',
            'no_timetable'=>'Tiada jadual kelas yang sah ditemui untuk masa ini.',
            'booking_ok'=>'Tempahan berjaya! Sila tiba sebelum ',
            'grace_expired'=>'Tempoh masa telah tamat. Tempahan dibatalkan.',
            'admin_dashboard'=>'Papan Pemuka Pentadbir','violations'=>'Pelanggaran',
            'issue_warning'=>'Keluarkan Amaran','no_slots'=>'Tiada slot tersedia dalam zon ini.',
            'restricted'=>'🔴 Terhad – Bukan waktu kelas','notifications'=>'Notifikasi',
            'mfa_title'=>'Pengesahan Dua Faktor',
            'mfa_sent'=>'Kod OTP telah dihantar ke e-mel anda.',
            'mfa_enter'=>'Masukkan kod 6 digit yang dihantar ke e-mel anda:',
            'mfa_verify'=>'Sahkan Kod','mfa_resend'=>'Hantar semula kod',
            'mfa_invalid'=>'Kod OTP tidak sah atau telah tamat tempoh.',
            'mfa_email_label'=>'E-mel anda',
            'mfa_masked'=>'Kod dihantar ke',
        ],
        'en' => [
            'app_name'=>'ParkCampus','tagline'=>'Park Smart, Study Focused',
            'login'=>'Login','logout'=>'Logout',
            'student_id'=>'Student ID','password'=>'Password',
            'search_slot'=>'Search Parking Slot','available'=>'Available','booked'=>'Booked',
            'my_bookings'=>'My Bookings','book_slot'=>'Book Slot',
            'cancel'=>'Cancel','extend'=>'Extend','confirm_book'=>'Confirm Booking',
            'zone'=>'Zone','class_start'=>'Class Start','class_end'=>'Class End','slot'=>'Slot',
            'no_timetable'=>'No valid class schedule found for this time.',
            'booking_ok'=>'Booking confirmed! Please arrive before ',
            'grace_expired'=>'Grace period expired. Booking auto-cancelled.',
            'admin_dashboard'=>'Admin Dashboard','violations'=>'Violations',
            'issue_warning'=>'Issue Warning','no_slots'=>'No available slots in this zone.',
            'restricted'=>'🔴 Restricted – Non-class hours','notifications'=>'Notifications',
            'mfa_title'=>'Two-Factor Authentication',
            'mfa_sent'=>'An OTP code has been sent to your email address.',
            'mfa_enter'=>'Enter the 6-digit code sent to your email:',
            'mfa_verify'=>'Verify Code','mfa_resend'=>'Resend code',
            'mfa_invalid'=>'Invalid or expired OTP code.',
            'mfa_email_label'=>'Your email',
            'mfa_masked'=>'Code sent to',
        ],
    ];
    return $s[$lang][$key] ?? $key;
}