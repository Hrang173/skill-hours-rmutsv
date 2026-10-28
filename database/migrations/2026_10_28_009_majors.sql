-- =====================================================================
--  Migration 009 — หลายสาขาวิชา (เริ่มจาก วิศวกรรมคอมพิวเตอร์และการสื่อสาร + วิศวกรรมไฟฟ้า)
--  - ตาราง majors: ชื่อสาขา, ชื่อที่ API ส่งมา (secname), ชั่วโมงที่ต้องเก็บ/เพดานต่ออาจารย์ แยกรายสาขา (ว่าง = ใช้ค่ากลาง)
--  - นักศึกษา / อาจารย์ / ทักษะ ผูกกับสาขา (major_id) — NULL = "ยังไม่ระบุสาขา" รอฝ่ายทะเบียนกำหนด
--  - ข้อมูลเดิมทั้งหมด → สาขาคอม
--  - ทักษะสาขาไฟฟ้า ปี 2568 + 2569 ตามเอกสารรายชื่อทักษะ + บัญชีอาจารย์ไฟฟ้า 6 ท่านรอไว้
--    (ชื่อผู้ใช้ wait.ee0x ไม่มีรหัสผ่าน — เข้าด้วย e-Passport ครั้งแรก ระบบจับคู่ชื่อแล้วผูก + เปลี่ยนชื่อผู้ใช้ให้)
-- =====================================================================

CREATE TABLE IF NOT EXISTS majors (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name              VARCHAR(150) NOT NULL UNIQUE,
    api_names         TEXT         NULL COMMENT 'ชื่อสาขาที่ API มหาวิทยาลัยส่งมา (secname) คั่นด้วยบรรทัดใหม่ — ใช้จับคู่ตอนเข้าสู่ระบบ',
    required_4year    DECIMAL(5,1) NULL COMMENT 'ว่าง = ใช้ค่ากลางในตั้งค่าระบบ',
    required_transfer DECIMAL(5,1) NULL,
    teacher_cap       DECIMAL(5,1) NULL COMMENT 'ชั่วโมงสูงสุดต่ออาจารย์ 1 ท่าน (ว่าง = ค่ากลาง)',
    sort_order        INT          NOT NULL DEFAULT 0,
    is_active         TINYINT(1)   NOT NULL DEFAULT 1,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO majors (id, name, api_names, sort_order) VALUES
(1, 'วิศวกรรมคอมพิวเตอร์และการสื่อสาร', 'วิศวกรรมคอมพิวเตอร์และการสื่อสาร', 1),
(2, 'วิศวกรรมไฟฟ้า', 'วิศวกรรมไฟฟ้า', 2);

ALTER TABLE students ADD COLUMN IF NOT EXISTS major_id INT UNSIGNED NULL AFTER major;
ALTER TABLE users    ADD COLUMN IF NOT EXISTS major_id INT UNSIGNED NULL COMMENT 'สาขาของอาจารย์' AFTER head_of_major;
ALTER TABLE skills   ADD COLUMN IF NOT EXISTS major_id INT UNSIGNED NULL AFTER academic_year;

-- นักศึกษา: จับคู่จากชื่อสาขาเดิม (ไม่ตรง = ยังไม่ระบุสาขา)
UPDATE students s JOIN majors m ON m.name = s.major SET s.major_id = m.id WHERE s.major_id IS NULL;
-- อาจารย์ที่มีทักษะหรือเป็นหัวหน้าหลักสูตรอยู่แล้ว → สาขาคอม
UPDATE users SET major_id = 1
 WHERE role = 'teacher' AND major_id IS NULL
   AND (head_of_major IS NOT NULL OR id IN (SELECT owner_id FROM skills WHERE owner_id IS NOT NULL));
-- ทักษะเดิมทั้งหมด → สาขาคอม
UPDATE skills SET major_id = 1 WHERE major_id IS NULL;
ALTER TABLE skills MODIFY major_id INT UNSIGNED NOT NULL;
ALTER TABLE skills DROP INDEX IF EXISTS uq_skill_year_code;
ALTER TABLE skills ADD UNIQUE KEY IF NOT EXISTS uq_skill_year_major_code (academic_year, major_id, code);

ALTER TABLE students ADD CONSTRAINT fk_students_major FOREIGN KEY IF NOT EXISTS (major_id) REFERENCES majors(id) ON DELETE SET NULL;
ALTER TABLE users    ADD CONSTRAINT fk_users_major    FOREIGN KEY IF NOT EXISTS (major_id) REFERENCES majors(id) ON DELETE SET NULL;
ALTER TABLE skills   ADD CONSTRAINT fk_skills_major   FOREIGN KEY IF NOT EXISTS (major_id) REFERENCES majors(id);

-- อาจารย์สาขาไฟฟ้า (รอเข้าระบบด้วย e-Passport)
INSERT IGNORE INTO users (username, password_hash, role, prefix, first_name, last_name, auth_source, major_id, must_change_password) VALUES
('wait.ee01', NULL, 'teacher', 'ผศ.', 'กิตติกร',     'ขันแกล้ว',    'university', 2, 0),
('wait.ee02', NULL, 'teacher', 'ผศ.', 'กฤติกร',      'แก้ววงศ์ศรี',  'university', 2, 0),
('wait.ee03', NULL, 'teacher', 'นาย', 'คณิศร',       'บุญรัตน์',     'university', 2, 0),
('wait.ee04', NULL, 'teacher', 'ผศ.', 'วีระศักดิ์',     'ไชยชาญ',      'university', 2, 0),
('wait.ee05', NULL, 'teacher', 'ผศ.', 'ปภัศร์ชกรณ์',   'อารีย์กุล',     'university', 2, 0),
('wait.ee06', NULL, 'teacher', 'นาย', 'วชิรวิทย์',     'บัวเกตุ',      'university', 2, 0);

-- ทักษะสาขาไฟฟ้า (ปี 2568 และ 2569)
INSERT IGNORE INTO skills (academic_year, major_id, code, name, owner_id, sort_order, is_active)
SELECT y.yr, 2, t.code, t.name, (SELECT id FROM users WHERE username = t.owner), t.ord, 1
  FROM (SELECT 2568 AS yr UNION ALL SELECT 2569) y
 CROSS JOIN (
    SELECT 'EE01' AS code, 'การออกแบบและติดตั้งแบบไฟฟ้า' AS name, 'wait.ee01' AS owner, 1 AS ord
    UNION ALL SELECT 'EE02', 'Image processing and computer Vision เบื้องต้น ด้วยภาษา python', 'wait.ee02', 2
    UNION ALL SELECT 'EE03', 'พื้นฐานการตรวจวัดและวิเคราะห์คุณภาพไฟฟ้าสำหรับวิศวกร (Fundamentals of Power Quality Measurement and Analysis for Engineers)', 'wait.ee03', 3
    UNION ALL SELECT 'EE04', 'การออกแบบและติดตั้งระบบผลิตไฟฟ้าพลังงานหมุนเวียน', 'wait.ee04', 4
    UNION ALL SELECT 'EE05', 'เทคนิคงานซ่อมบำรุงอุปกรณ์และเครื่องใช้ไฟฟ้า', 'wait.ee05', 5
    UNION ALL SELECT 'EE06', 'การใช้งาน PLC (Programmable Logic Controller) เบื้องต้น', 'wait.ee06', 6
 ) t;
