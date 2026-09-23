-- =====================================================================
--  ระบบบันทึกชั่วโมงการฝึกทักษะวิชาชีพ
--  คณะวิศวกรรมศาสตร์และเทคโนโลยี มทร.ศรีวิชัย วิทยาเขตตรัง
--  โครงสร้างฐานข้อมูล (MariaDB 11)
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+07:00';

-- ผู้ใช้ทุกประเภท: นักศึกษา / อาจารย์ / ฝ่ายทะเบียน
CREATE TABLE users (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username             VARCHAR(50)  NOT NULL UNIQUE COMMENT 'นักศึกษาใช้รหัสนักศึกษา',
    password_hash        VARCHAR(255) NULL COMMENT 'NULL = เข้าสู่ระบบผ่าน API มหาวิทยาลัยเท่านั้น',
    role                 ENUM('student','teacher','registrar') NOT NULL,
    prefix               VARCHAR(50)  NOT NULL DEFAULT '' COMMENT 'คำนำหน้า เช่น นาย, อ., ผศ.ดร.',
    first_name           VARCHAR(100) NOT NULL,
    last_name            VARCHAR(100) NOT NULL,
    email                VARCHAR(150) NULL,
    phone                VARCHAR(30)  NULL,
    signature_data       MEDIUMTEXT   NULL COMMENT 'ลายเซ็นที่บันทึกไว้ (PNG data URL) สำหรับอาจารย์',
    auth_source          ENUM('local','university') NOT NULL DEFAULT 'local',
    external_id          VARCHAR(100) NULL COMMENT 'รหัสอ้างอิงในระบบมหาวิทยาลัย',
    must_change_password TINYINT(1)   NOT NULL DEFAULT 0,
    is_active            TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at        DATETIME     NULL,
    created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_role (role),
    INDEX idx_users_name (first_name, last_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ข้อมูลเพิ่มเติมของนักศึกษา
CREATE TABLE students (
    user_id      INT UNSIGNED PRIMARY KEY,
    student_code VARCHAR(20)  NOT NULL UNIQUE,
    program_type ENUM('4year','transfer') NOT NULL DEFAULT '4year' COMMENT '4year = 100 ชม., transfer = เทียบโอน 50 ชม.',
    faculty      VARCHAR(150) NOT NULL DEFAULT 'คณะวิศวกรรมศาสตร์และเทคโนโลยี',
    major        VARCHAR(150) NOT NULL DEFAULT 'วิศวกรรมคอมพิวเตอร์และการสื่อสาร',
    entry_year   SMALLINT     NULL COMMENT 'ปีการศึกษาที่เข้า (พ.ศ.)',
    advisor_id   INT UNSIGNED NULL,
    status       ENUM('studying','graduated','inactive') NOT NULL DEFAULT 'studying',
    synced_at    DATETIME     NULL COMMENT 'ซิงก์กับ API มหาวิทยาลัยล่าสุด',
    CONSTRAINT fk_students_user    FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_students_advisor FOREIGN KEY (advisor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ภาคการศึกษา
CREATE TABLE semesters (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    term          TINYINT      NOT NULL COMMENT '1, 2, 3 (ฤดูร้อน)',
    academic_year SMALLINT     NOT NULL COMMENT 'พ.ศ.',
    start_date    DATE         NULL,
    end_date      DATE         NULL,
    is_current    TINYINT(1)   NOT NULL DEFAULT 0,
    UNIQUE KEY uq_semester (term, academic_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- รายชื่อทักษะวิชาชีพ
CREATE TABLE skills (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(20)   NOT NULL UNIQUE,
    name        VARCHAR(255)  NOT NULL,
    description TEXT          NULL,
    owner_id    INT UNSIGNED  NULL COMMENT 'อาจารย์ผู้รับผิดชอบ/ผู้ควบคุมทักษะ',
    max_hours   DECIMAL(5,1)  NOT NULL DEFAULT 25.0 COMMENT 'ชั่วโมงสูงสุดที่นับได้ต่อทักษะ',
    sort_order  INT           NOT NULL DEFAULT 0,
    is_active   TINYINT(1)    NOT NULL DEFAULT 1,
    CONSTRAINT fk_skills_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- กิจกรรมที่อาจารย์สร้าง (public = เปิดให้สมัคร, assigned = มอบหมายเฉพาะคน)
CREATE TABLE activities (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    skill_id          INT UNSIGNED  NOT NULL,
    teacher_id        INT UNSIGNED  NOT NULL,
    semester_id       INT UNSIGNED  NULL,
    title             VARCHAR(255)  NOT NULL,
    description       TEXT          NULL,
    location          VARCHAR(255)  NULL,
    hours             DECIMAL(5,1)  NOT NULL,
    capacity          INT           NULL COMMENT 'NULL = ไม่จำกัด',
    visibility        ENUM('public','assigned') NOT NULL DEFAULT 'public',
    status            ENUM('open','closed','completed','cancelled') NOT NULL DEFAULT 'open',
    register_deadline DATETIME      NULL,
    created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_act_status (status, visibility),
    CONSTRAINT fk_act_skill    FOREIGN KEY (skill_id)    REFERENCES skills(id),
    CONSTRAINT fk_act_teacher  FOREIGN KEY (teacher_id)  REFERENCES users(id),
    CONSTRAINT fk_act_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- วัน/เวลาที่ต้องมาทำกิจกรรม (1 กิจกรรมมีได้หลายวัน)
CREATE TABLE activity_sessions (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    activity_id  INT UNSIGNED NOT NULL,
    session_date DATE         NOT NULL,
    start_time   TIME         NULL,
    end_time     TIME         NULL,
    detail       VARCHAR(255) NULL,
    INDEX idx_sess_date (session_date),
    CONSTRAINT fk_sess_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- การเข้าร่วมกิจกรรม = 1 แถวในแบบบันทึกการฝึกทักษะวิชาชีพ
CREATE TABLE participations (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    activity_id    INT UNSIGNED NOT NULL,
    student_id     INT UNSIGNED NOT NULL,
    source         ENUM('request','assigned') NOT NULL DEFAULT 'request',
    status         ENUM('pending','approved','rejected','cancelled','completed') NOT NULL DEFAULT 'pending',
    request_note   VARCHAR(500) NULL,
    teacher_note   VARCHAR(500) NULL,
    hours_awarded  DECIMAL(5,1) NULL,
    result         ENUM('pass','fail') NULL,
    remark         VARCHAR(255) NULL COMMENT 'หมายเหตุ ในแบบฟอร์ม',
    sign_method    ENUM('online','name') NULL COMMENT 'online = ลายเซ็นออนไลน์, name = พิมพ์ชื่อ',
    signature_data MEDIUMTEXT   NULL,
    signer_name    VARCHAR(200) NULL,
    signed_by      INT UNSIGNED NULL,
    signed_at      DATETIME     NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_part (activity_id, student_id),
    INDEX idx_part_student (student_id, status),
    CONSTRAINT fk_part_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE,
    CONSTRAINT fk_part_student  FOREIGN KEY (student_id)  REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_part_signer   FOREIGN KEY (signed_by)   REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- การแจ้งเตือนในระบบ
CREATE TABLE notifications (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    title      VARCHAR(255) NOT NULL,
    message    TEXT         NULL,
    link       VARCHAR(255) NULL,
    is_read    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notif_user (user_id, is_read),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- บันทึกการใช้งาน (ใครทำอะไร เมื่อไร)
CREATE TABLE audit_logs (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NULL,
    action     VARCHAR(100) NOT NULL,
    detail     TEXT         NULL,
    ip_address VARCHAR(45)  NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_created (created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ค่าตั้งค่าระบบ (แก้ได้จากหน้าฝ่ายทะเบียน)
CREATE TABLE settings (
    setting_key   VARCHAR(100) PRIMARY KEY,
    setting_value TEXT         NULL,
    updated_at    DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API key สำหรับระบบภายนอก (เช่น ระบบทะเบียนของมหาวิทยาลัย) ดึงข้อมูลชั่วโมง
CREATE TABLE api_keys (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(100) NOT NULL,
    key_prefix   VARCHAR(12)  NOT NULL,
    key_hash     CHAR(64)     NOT NULL UNIQUE COMMENT 'SHA-256 ของ key',
    is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    last_used_at DATETIME     NULL,
    created_by   INT UNSIGNED NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_apikey_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
