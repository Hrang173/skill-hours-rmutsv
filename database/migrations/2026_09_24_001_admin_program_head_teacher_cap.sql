-- =====================================================================
--  Migration 001
--  - เพิ่มบทบาท admin (ผู้ดูแลระบบ) + บัญชี admin เริ่มต้น (รหัสผ่าน password123)
--  - เพิ่มการกำหนดหัวหน้าหลักสูตร (users.head_of_major)
--  - เปลี่ยนเพดานชั่วโมง: จาก "ต่อทักษะ" เป็น "ต่ออาจารย์ 1 ท่าน ไม่เกิน 25 ชม."
-- =====================================================================

ALTER TABLE users MODIFY role ENUM('student','teacher','registrar','admin') NOT NULL;

ALTER TABLE users ADD COLUMN IF NOT EXISTS head_of_major VARCHAR(150) NULL COMMENT 'เป็นหัวหน้าหลักสูตรของสาขานี้' AFTER signature_data;

INSERT IGNORE INTO users (username, password_hash, role, prefix, first_name, last_name, must_change_password)
VALUES ('admin', '$2y$10$fA34ftLcfyM3BvF4ZkeBKe3GxWePPoXCe/Z8kyaQ6V5Yh/POLQ6aa', 'admin', '', 'ผู้ดูแล', 'ระบบ', 0);

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('max_hours_per_teacher', '25'),
('enforce_teacher_cap',   '1');

DELETE FROM settings WHERE setting_key IN ('enforce_skill_cap', 'program_head_name');

-- ข้อมูลตัวอย่าง: ตั้ง ผศ.ดร.ชัยวัฒน์ เป็นหัวหน้าหลักสูตร (เฉพาะกรณียังไม่มีใครเป็น)
UPDATE users SET head_of_major = 'วิศวกรรมคอมพิวเตอร์และการสื่อสาร'
 WHERE username = 'chaiwat' AND role = 'teacher'
   AND (SELECT c FROM (SELECT COUNT(*) AS c FROM users WHERE head_of_major IS NOT NULL) t) = 0;
