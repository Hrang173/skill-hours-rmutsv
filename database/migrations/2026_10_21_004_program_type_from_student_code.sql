-- แผนการเรียนของนักศึกษาที่สร้างจาก API มหาวิทยาลัย: ดูจากรหัสนักศึกษาหลักที่ 4 (4 = 4 ปี, 2 = เทียบโอน)
-- (บัญชีที่ฝ่ายทะเบียนสร้าง/นำเข้าเอง ไม่แตะ)
UPDATE students s
  JOIN users u ON u.id = s.user_id
   SET s.program_type = IF(SUBSTRING(s.student_code, 4, 1) = '2', 'transfer', '4year')
 WHERE u.auth_source = 'university'
   AND s.student_code REGEXP '^[0-9]{3}[24][0-9]{6,9}$';
