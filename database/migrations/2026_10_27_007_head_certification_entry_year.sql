-- =====================================================================
--  Migration 007
--  - หัวหน้าหลักสูตรเซ็นยืนยันนักศึกษาที่เก็บชั่วโมงครบ (ขั้นตอนสุดท้าย) → ตาราง student_certifications
--  - ปีที่เข้าศึกษาจากรหัสนักศึกษา หลักที่ 2-3 (เช่น 666415850004 → 2566, 667215850003 → 2567)
--    เติมให้นักศึกษาที่ยังไม่มี entry_year
-- =====================================================================

CREATE TABLE IF NOT EXISTS student_certifications (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id     INT UNSIGNED  NOT NULL UNIQUE,
    head_id        INT UNSIGNED  NULL COMMENT 'หัวหน้าหลักสูตรที่เซ็นยืนยัน',
    major          VARCHAR(150)  NOT NULL,
    hours          DECIMAL(6,1)  NOT NULL COMMENT 'ชั่วโมงที่นับได้ ณ วันที่เซ็น',
    sign_method    ENUM('online','name') NOT NULL,
    signature_data MEDIUMTEXT    NULL,
    signer_name    VARCHAR(200)  NOT NULL,
    certified_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cert_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_cert_head    FOREIGN KEY (head_id)    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE students
   SET entry_year = 2500 + CAST(SUBSTRING(student_code, 2, 2) AS UNSIGNED)
 WHERE entry_year IS NULL AND student_code REGEXP '^[0-9]{12,13}$';
