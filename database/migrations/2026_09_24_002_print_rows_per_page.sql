-- แบบบันทึกพิมพ์ 4 แถวต่อหน้า เหมือนแบบฟอร์มต้นฉบับ (เดิม 5 แถว ทำให้ล้นหน้าบนกระดาษ Letter)
UPDATE settings SET setting_value = '4' WHERE setting_key = 'print_rows_per_page' AND setting_value = '5';
