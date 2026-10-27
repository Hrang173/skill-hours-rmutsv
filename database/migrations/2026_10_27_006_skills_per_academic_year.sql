-- =====================================================================
--  Migration 006
--  รายชื่อทักษะแยกตามปีการศึกษา: ฝ่ายทะเบียนเพิ่มทักษะ + ผู้รับผิดชอบใหม่ทุกปี
--  - skills.academic_year (พ.ศ.) · รหัสทักษะซ้ำได้ข้ามปี (ไม่ซ้ำภายในปีเดียวกัน)
--  - ทักษะเดิมทั้งหมด → ปีการศึกษาปัจจุบัน
--  - กิจกรรมของปีอื่นที่ใช้ทักษะเดิม → คัดลอกทักษะไปเป็นของปีนั้น แล้วผูกกิจกรรมกับทักษะของปีตัวเอง
-- =====================================================================

SET @year := COALESCE(
    (SELECT academic_year FROM semesters WHERE is_current = 1 LIMIT 1),
    (SELECT MAX(academic_year) FROM semesters),
    YEAR(CURDATE()) + 543
);

ALTER TABLE skills DROP INDEX IF EXISTS code;
ALTER TABLE skills ADD COLUMN IF NOT EXISTS academic_year SMALLINT NULL COMMENT 'ปีการศึกษา (พ.ศ.)' AFTER id;

UPDATE skills SET academic_year = @year WHERE academic_year IS NULL;

INSERT INTO skills (academic_year, code, name, description, owner_id, max_hours, sort_order, is_active)
SELECT DISTINCT se.academic_year, sk.code, sk.name, sk.description, sk.owner_id, sk.max_hours, sk.sort_order, sk.is_active
  FROM activities a
  JOIN semesters se ON se.id = a.semester_id
  JOIN skills sk    ON sk.id = a.skill_id
 WHERE se.academic_year <> sk.academic_year;

UPDATE activities a
  JOIN semesters se ON se.id = a.semester_id
  JOIN skills old   ON old.id = a.skill_id
  JOIN skills nw    ON nw.code = old.code AND nw.academic_year = se.academic_year
   SET a.skill_id = nw.id
 WHERE old.academic_year <> se.academic_year;

ALTER TABLE skills MODIFY academic_year SMALLINT NOT NULL COMMENT 'ปีการศึกษา (พ.ศ.)';
ALTER TABLE skills ADD UNIQUE KEY IF NOT EXISTS uq_skill_year_code (academic_year, code);
