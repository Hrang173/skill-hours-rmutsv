<?php
use App\Services\StudentQuery;

/**
 * ส่งออกรายชื่อนักศึกษา + ชั่วโมงสะสม เป็น CSV (เปิดด้วย Excel ได้ ภาษาไทยไม่เพี้ยน)
 * ใช้ตัวกรองเดียวกับหน้าค้นหา
 */
['from' => $from, 'params' => $params, 'required' => $required] = StudentQuery::build(StudentQuery::filtersFromRequest());
$rows = q_all('SELECT ' . StudentQuery::columns($required) . " $from ORDER BY st.student_code", $params);

audit('export.students', ['count' => count($rows)]);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="skill-hours-' . date('Ymd-His') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM ให้ Excel อ่าน UTF-8
fputcsv($out, ['รหัสนักศึกษา', 'คำนำหน้า', 'ชื่อ', 'สกุล', 'แผนการเรียน', 'สาขาวิชา', 'ปีที่เข้า', 'ชั่วโมงสะสม', 'เกณฑ์', 'คงเหลือ', 'สถานะ']);
foreach ($rows as $r) {
    fputcsv($out, [
        $r['student_code'],
        $r['prefix'],
        $r['first_name'],
        $r['last_name'],
        program_label($r['program_type']),
        $r['major'],
        $r['entry_year'],
        fmt_hours($r['earned']),
        fmt_hours($r['required']),
        fmt_hours(max(0, $r['required'] - $r['earned'])),
        $r['earned'] >= $r['required'] ? 'ครบแล้ว' : 'ยังไม่ครบ',
    ]);
}
fclose($out);
exit;
