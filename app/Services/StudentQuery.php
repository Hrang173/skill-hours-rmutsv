<?php
declare(strict_types=1);

namespace App\Services;

/**
 * สร้าง SQL ค้นหานักศึกษาพร้อมชั่วโมงสะสม (ใช้ร่วมกันระหว่างหน้าค้นหา / ส่งออก CSV / API)
 */
final class StudentQuery
{
    /** อ่านตัวกรองจาก query string */
    public static function filtersFromRequest(): array
    {
        return [
            'q'       => (string) input('q', ''),
            'program' => (string) input('program', ''),
            'done'    => (string) input('done', ''),
            'year'    => input_int('year'),
        ];
    }

    /**
     * @return array{from: string, params: array, required: string}
     *   from     = "FROM ... WHERE ..." (มี alias st, u, h)
     *   required = SQL expression ของเกณฑ์ชั่วโมง
     */
    public static function build(array $f): array
    {
        $where = ['1=1'];
        $params = [];
        if (($f['q'] ?? '') !== '') {
            $where[] = "(st.student_code LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
            array_push($params, like($f['q']), like($f['q']), like($f['q']), like($f['q']));
        }
        if (in_array($f['program'] ?? '', ['4year', 'transfer'], true)) {
            $where[] = 'st.program_type = ?';
            $params[] = $f['program'];
        }
        if (!empty($f['year'])) {
            $where[] = 'st.entry_year = ?';
            $params[] = (int) $f['year'];
        }
        $required = HoursService::requiredSqlExpr('st');
        if (($f['done'] ?? '') === '1') {
            $where[] = "COALESCE(h.earned, 0) >= $required";
        } elseif (($f['done'] ?? '') === '0') {
            $where[] = "COALESCE(h.earned, 0) < $required";
        }

        $from = "FROM students st
                 JOIN users u ON u.id = st.user_id
                 LEFT JOIN (" . HoursService::earnedSql() . ") h ON h.student_id = st.user_id
                WHERE " . implode(' AND ', $where);

        return ['from' => $from, 'params' => $params, 'required' => $required];
    }

    public static function columns(string $required): string
    {
        return "u.id, u.prefix, u.first_name, u.last_name, u.is_active, st.student_code, st.program_type, st.major, st.entry_year,
                COALESCE(h.earned, 0) AS earned, $required AS required";
    }
}
