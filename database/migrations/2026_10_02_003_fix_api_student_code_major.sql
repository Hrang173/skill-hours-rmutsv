-- แก้บัญชีนักศึกษาที่สร้างจาก API มหาวิทยาลัยรุ่นแรก
-- 1) รหัสนักศึกษาเคยเก็บเป็นชื่อผู้ใช้ RMUTSV (มีตัว s นำหน้า เช่น s666415850004) → เหลือแต่ตัวเลข
UPDATE students s
   SET s.student_code = SUBSTRING(s.student_code, 2)
 WHERE s.student_code REGEXP '^[sS][0-9]{10,13}$'
   AND NOT EXISTS (SELECT 1 FROM (SELECT student_code FROM students) x WHERE x.student_code = SUBSTRING(s.student_code, 2));

-- 2) สาขาเคยได้ค่า "-" (มาจาก depname) → ใช้สาขาเริ่มต้นของระบบ
UPDATE students
   SET major = COALESCE((SELECT setting_value FROM settings WHERE setting_key = 'default_major'), major)
 WHERE major IN ('', '-');
