<?php
/**
 * JSON สำหรับช่องค้นหานักศึกษา (student picker)
 * GET /ajax/students?q=...
 */
$kw = (string) input('q', '');
if (mb_strlen($kw) < 2) {
    json_response(['data' => []]);
}
$rows = q_all(
    "SELECT u.id, u.prefix, u.first_name, u.last_name, s.student_code, s.program_type
       FROM students s JOIN users u ON u.id = s.user_id
      WHERE u.is_active = 1
        AND (s.student_code LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)
      ORDER BY s.student_code LIMIT 15",
    [like($kw), like($kw), like($kw), like($kw)]
);
json_response(['data' => array_map(fn($r) => [
    'id'      => (int) $r['id'],
    'code'    => $r['student_code'],
    'name'    => full_name($r),
    'program' => program_label($r['program_type']),
], $rows)]);
