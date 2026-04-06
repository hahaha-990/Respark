-- ============================================================
-- CAMPUS SMART PARKING SYSTEM - DATABASE SCHEMA
-- Compatible with MySQL 8.0+
-- ============================================================

CREATE DATABASE IF NOT EXISTS campus_parking
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE campus_parking;

-- ============================================================
-- USERS TABLE (Students + Admins)
-- ============================================================
CREATE TABLE users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id    VARCHAR(20)  UNIQUE NOT NULL,          -- e.g. A22EC0001
    full_name     VARCHAR(120) NOT NULL,
    email         VARCHAR(180) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('student','admin','security') NOT NULL DEFAULT 'student',
    phone         VARCHAR(20),
    lang_pref     ENUM('ms','en') NOT NULL DEFAULT 'ms', -- SR5
    notify_push   TINYINT(1) NOT NULL DEFAULT 1,         -- SR5 consent
    notify_sms    TINYINT(1) NOT NULL DEFAULT 0,         -- SR5 consent
    mfa_secret    VARCHAR(64),                            -- SR7 MFA seed
    mfa_enabled   TINYINT(1) NOT NULL DEFAULT 0,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- CAMPUS ZONES / FACULTIES
-- ============================================================
CREATE TABLE campus_zones (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(10)  UNIQUE NOT NULL,   -- e.g. ENG, CCI, DKB
    name        VARCHAR(120) NOT NULL,
    name_ms     VARCHAR(120) NOT NULL,
    latitude    DECIMAL(10,7),
    longitude   DECIMAL(10,7),
    total_slots INT UNSIGNED NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1
);

-- ============================================================
-- PARKING SLOTS
-- ============================================================
CREATE TABLE parking_slots (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    zone_id     INT UNSIGNED NOT NULL,
    slot_code   VARCHAR(10)  NOT NULL,          -- e.g. A-12, B-05
    floor_level TINYINT NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_zone_slot (zone_id, slot_code),
    FOREIGN KEY (zone_id) REFERENCES campus_zones(id) ON DELETE CASCADE
);

-- ============================================================
-- CLASS TIMETABLE (mirror/cache from university API) — SR1
-- ============================================================
CREATE TABLE class_timetable (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id   VARCHAR(20)  NOT NULL,
    course_code  VARCHAR(20)  NOT NULL,
    course_name  VARCHAR(160) NOT NULL,
    zone_id      INT UNSIGNED NOT NULL,
    venue        VARCHAR(120),
    day_of_week  TINYINT UNSIGNED NOT NULL,    -- 0=Sun … 6=Sat
    start_time   TIME NOT NULL,
    end_time     TIME NOT NULL,
    seat_count   INT UNSIGNED NOT NULL DEFAULT 30,
    effective_from DATE NOT NULL,
    effective_to   DATE NOT NULL,
    synced_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (zone_id) REFERENCES campus_zones(id)
);

CREATE INDEX idx_tt_student  ON class_timetable(student_id);
CREATE INDEX idx_tt_day_time ON class_timetable(day_of_week, start_time, end_time);

-- ============================================================
-- BOOKINGS
-- ============================================================
CREATE TABLE bookings (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id      VARCHAR(20)  NOT NULL,
    slot_id         INT UNSIGNED NOT NULL,
    timetable_id    INT UNSIGNED NOT NULL,
    status          ENUM('pending','confirmed','active','completed','cancelled','auto_cancelled') NOT NULL DEFAULT 'pending',
    booked_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    class_start     DATETIME NOT NULL,
    class_end       DATETIME NOT NULL,
    extended_end    DATETIME,                  -- FR3 extension
    grace_deadline  DATETIME NOT NULL,         -- booking + 15 min
    cancelled_at    TIMESTAMP NULL,
    cancel_reason   VARCHAR(255),
    cancelled_by    INT UNSIGNED,              -- NULL = system
    FOREIGN KEY (slot_id)      REFERENCES parking_slots(id),
    FOREIGN KEY (timetable_id) REFERENCES class_timetable(id),
    FOREIGN KEY (cancelled_by) REFERENCES users(id)
);

CREATE INDEX idx_bk_student ON bookings(student_id);
CREATE INDEX idx_bk_slot    ON bookings(slot_id, status);
CREATE INDEX idx_bk_time    ON bookings(class_start, class_end);

-- ============================================================
-- ELIGIBILITY AUDIT TRAIL — SR2
-- ============================================================
CREATE TABLE eligibility_audit (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id_hash VARCHAR(64)  NOT NULL,      -- SHA-256 of student_id
    action          VARCHAR(60)  NOT NULL,       -- 'search','book','extend','cancel'
    timetable_found TINYINT(1)  NOT NULL,
    decision        ENUM('allowed','denied') NOT NULL,
    deny_reason     VARCHAR(255),
    requestor_ip    VARCHAR(45)  NOT NULL,
    device_info     VARCHAR(255),
    logged_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_ea_hash ON eligibility_audit(student_id_hash);
CREATE INDEX idx_ea_time ON eligibility_audit(logged_at);

-- ============================================================
-- NOTIFICATIONS — FR4, SR5, SR6
-- ============================================================
CREATE TABLE notifications (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id   INT UNSIGNED NOT NULL,
    student_id   VARCHAR(20)  NOT NULL,
    type         ENUM('confirmation','reminder_15min','expiry_10min','auto_cancel','admin_warning') NOT NULL,
    channel      ENUM('push','sms','in_app') NOT NULL DEFAULT 'in_app',
    lang         ENUM('ms','en') NOT NULL DEFAULT 'ms',
    message_body TEXT NOT NULL,
    sent_at      TIMESTAMP NULL,
    delivered    TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (booking_id) REFERENCES bookings(id)
);

-- ============================================================
-- VIOLATIONS / DISPUTES — FR5
-- ============================================================
CREATE TABLE violations (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id   INT UNSIGNED,
    student_id   VARCHAR(20) NOT NULL,
    issued_by    INT UNSIGNED NOT NULL,         -- admin/security user id
    type         ENUM('no_show','overstay','invalid_booking','other') NOT NULL,
    description  TEXT,
    status       ENUM('open','resolved','appealed') NOT NULL DEFAULT 'open',
    issued_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at  TIMESTAMP NULL,
    FOREIGN KEY (booking_id) REFERENCES bookings(id),
    FOREIGN KEY (issued_by)  REFERENCES users(id)
);

-- ============================================================
-- ADMIN SESSIONS / ACCESS LOG — SR7
-- ============================================================
CREATE TABLE admin_sessions (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    session_token VARCHAR(128) NOT NULL,
    mfa_verified TINYINT(1) NOT NULL DEFAULT 0,
    ip_address   VARCHAR(45) NOT NULL,
    device_info  VARCHAR(255),
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at   TIMESTAMP NOT NULL,
    invalidated  TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ============================================================
-- SEED DATA
-- ============================================================

-- Zones
INSERT INTO campus_zones (code, name, name_ms, latitude, longitude, total_slots) VALUES
('ENG',  'Engineering Faculty',           'Fakulti Kejuruteraan',              3.0000, 101.7000, 30),
('CCI',  'Computing & Creative Industry', 'Fakulti CCI',                       3.0010, 101.7010, 25),
('DKB',  'Dewan Kuliah Besar',            'Dewan Kuliah Besar',                3.0020, 101.7020, 40),
('SCI',  'Science Faculty',               'Fakulti Sains',                     3.0030, 101.7030, 20),
('ADMIN','Administration Block',          'Blok Pentadbiran',                  3.0040, 101.7040, 15);

-- Parking Slots for ENG (zone 1)
INSERT INTO parking_slots (zone_id, slot_code, floor_level) VALUES
(1,'A-01',0),(1,'A-02',0),(1,'A-03',0),(1,'A-04',0),(1,'A-05',0),
(1,'A-06',0),(1,'A-07',0),(1,'A-08',0),(1,'A-09',0),(1,'A-10',0),
(1,'B-01',1),(1,'B-02',1),(1,'B-03',1),(1,'B-04',1),(1,'B-05',1);

-- Parking Slots for CCI (zone 2)
INSERT INTO parking_slots (zone_id, slot_code, floor_level) VALUES
(2,'A-11',0),(2,'A-12',0),(2,'A-13',0),(2,'A-14',0),(2,'A-15',0),
(2,'B-11',1),(2,'B-12',1),(2,'B-13',1),(2,'B-14',1),(2,'B-15',1);

-- Parking Slots for DKB (zone 3)
INSERT INTO parking_slots (zone_id, slot_code, floor_level) VALUES
(3,'C-01',0),(3,'C-02',0),(3,'C-03',0),(3,'C-04',0),(3,'C-05',0),
(3,'C-06',0),(3,'C-07',0),(3,'C-08',0),(3,'C-09',0),(3,'C-10',0),
(3,'D-01',1),(3,'D-02',1),(3,'D-03',1),(3,'D-04',1),(3,'D-05',1);

-- Demo Users (passwords: "password123" bcrypt)
INSERT INTO users (student_id, full_name, email, password_hash, role, phone, lang_pref, notify_push) VALUES
('A22EC0001','Ahmad bin Hassan',  'ahmad@student.edu.my',  '$2y$12$LQv3c1yqBWVHxkd0LQ1Enc8f1c/HQvuGBkHVcuGOL8RyWnCQF5kCC','student','0123456789','ms',1),
('A22EC0002','Siti Aminah',       'siti@student.edu.my',   '$2y$12$LQv3c1yqBWVHxkd0LQ1Enc8f1c/HQvuGBkHVcuGOL8RyWnCQF5kCC','student','0129876543','ms',1),
('A22EC0003','Aisyah Razak',      'aisyah@student.edu.my', '$2y$12$LQv3c1yqBWVHxkd0LQ1Enc8f1c/HQvuGBkHVcuGOL8RyWnCQF5kCC','student','0112345678','ms',1),
('A22EC0004','Zulfikar Idris',    'zulfikar@student.edu.my','$2y$12$LQv3c1yqBWVHxkd0LQ1Enc8f1c/HQvuGBkHVcuGOL8RyWnCQF5kCC','student','0167654321','ms',1),
('ADMIN001', 'Pakcik Rahman',     'rahman@admin.edu.my',   '$2y$12$LQv3c1yqBWVHxkd0LQ1Enc8f1c/HQvuGBkHVcuGOL8RyWnCQF5kCC','security','0198765432','ms',1);

-- Sample Timetable for Ahmad (A22EC0001)
INSERT INTO class_timetable (student_id, course_code, course_name, zone_id, venue, day_of_week, start_time, end_time, seat_count, effective_from, effective_to) VALUES
('A22EC0001','BEE3243','Digital Electronics',   1,'ENG Lab 2',1,'08:00:00','10:00:00',30,'2025-01-01','2025-12-31'),
('A22EC0001','BEE3243','Digital Electronics',   1,'ENG Lab 2',3,'08:00:00','10:00:00',30,'2025-01-01','2025-12-31'),
('A22EC0001','BCS3413','Operating Systems',     2,'CCI Lab 3',2,'14:00:00','16:00:00',40,'2025-01-01','2025-12-31'),
('A22EC0001','BCS3413','Operating Systems',     2,'CCI Lab 3',4,'14:00:00','16:00:00',40,'2025-01-01','2025-12-31'),
-- Siti
('A22EC0002','BBI1114','English Communication', 3,'DKB Hall 1',1,'10:00:00','12:00:00',120,'2025-01-01','2025-12-31'),
('A22EC0002','BMM1013','Bahasa Malaysia',        3,'DKB Hall 2',3,'10:00:00','12:00:00',80,'2025-01-01','2025-12-31'),
-- Aisyah
('A22EC0003','BSW3133','Software Engineering',  2,'CCI Tutorial Rm',2,'14:00:00','16:00:00',25,'2025-01-01','2025-12-31'),
('A22EC0003','BSW3133','Software Engineering',  2,'CCI Tutorial Rm',4,'14:00:00','16:00:00',25,'2025-01-01','2025-12-31'),
-- Zulfikar
('A22EC0004','BEE3243','Digital Electronics',   1,'ENG Lab 2',1,'08:00:00','10:00:00',30,'2025-01-01','2025-12-31'),
('A22EC0004','BBI1114','English Communication', 3,'DKB Hall 1',5,'10:00:00','12:00:00',120,'2025-01-01','2025-12-31');
