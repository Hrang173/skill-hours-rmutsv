<?php
declare(strict_types=1);

namespace App\Services;

/**
 * คำนวณชั่วโมงการฝึกทักษะวิชาชีพ
 *
 * กติกา (ตามเอกสาร "รายชื่อทักษะวิชาชีพ"):
 *  - แผน 4 ปี ต้องฝึกอย่างน้อย 100 ชม. / แผนเทียบโอน อย่างน้อย 50 ชม.
 *  - นับเฉพาะรายการที่อาจารย์บันทึกผล "ผ่าน" แล้ว
 *  - แต่ละทักษะนับได้สูงสุดตาม max_hours (ค่าเริ่มต้น 25 ชม.) ถ้าเปิด enforce_skill_cap
 */
final class HoursService
{
    public static function requiredHours(?string $programType): float
    {
        return $programType === 'transfer'
            ? (float) setting('required_hours_transfer', 50)
            : (float) setting('required_hours_4year', 100);
    }

    public static function capEnabled(): bool
    {
        return setting('enforce_skill_cap', '1') === '1';
    }

    /**
     * SQL ย่อยที่คืน (student_id, earned) ของนักศึกษาทุกคน — ใช้ JOIN ในหน้าค้นหา/รายงาน
     */
    public static function earnedSql(): string
    {
        $expr = self::capEnabled() ? 'LEAST(x.h, x.max_hours)' : 'x.h';
        return "SELECT x.student_id, SUM($expr) AS earned
                  FROM (SELECT p.student_id, a.skill_id, SUM(p.hours_awarded) AS h, sk.max_hours
                          FROM participations p
                          JOIN activities a ON a.id = p.activity_id
                          JOIN skills sk    ON sk.id = a.skill_id
                         WHERE p.status = 'completed' AND p.result = 'pass'
                         GROUP BY p.student_id, a.skill_id, sk.max_hours) x
                 GROUP BY x.student_id";
    }

    /** SQL expression ของชั่วโมงที่ต้องเก็บ ตามประเภทนักศึกษา (ใช้ร่วมกับตาราง alias st) */
    public static function requiredSqlExpr(string $alias = 'st'): string
    {
        $t = (float) setting('required_hours_transfer', 50);
        $f = (float) setting('required_hours_4year', 100);
        return "(CASE WHEN $alias.program_type = 'transfer' THEN $t ELSE $f END)";
    }

    /**
     * สรุปชั่วโมงของนักศึกษา 1 คน
     */
    public static function summary(int $studentId, ?string $programType): array
    {
        $required = self::requiredHours($programType);
        $cap = self::capEnabled();

        $skills = q_all(
            "SELECT sk.id, sk.code, sk.name, sk.max_hours,
                    CONCAT(o.prefix, o.first_name, ' ', o.last_name) AS owner_name,
                    COALESCE(SUM(CASE WHEN p.status = 'completed' AND p.result = 'pass' THEN p.hours_awarded END), 0) AS hours,
                    COUNT(CASE WHEN p.status = 'completed' AND p.result = 'pass' THEN 1 END) AS activity_count
               FROM skills sk
               LEFT JOIN users o ON o.id = sk.owner_id
               LEFT JOIN activities a ON a.skill_id = sk.id
               LEFT JOIN participations p ON p.activity_id = a.id AND p.student_id = ?
              WHERE sk.is_active = 1 OR p.id IS NOT NULL
              GROUP BY sk.id, sk.code, sk.name, sk.max_hours, owner_name
              ORDER BY sk.sort_order, sk.id",
            [$studentId]
        );

        $raw = 0.0;
        $earned = 0.0;
        foreach ($skills as &$s) {
            $h = (float) $s['hours'];
            $s['counted'] = $cap ? min($h, (float) $s['max_hours']) : $h;
            $s['over'] = $cap && $h > (float) $s['max_hours'];
            $raw += $h;
            $earned += $s['counted'];
        }
        unset($s);

        // แยกตามอาจารย์ผู้ควบคุมกิจกรรม
        $teachers = q_all(
            "SELECT t.id, CONCAT(t.prefix, t.first_name, ' ', t.last_name) AS name,
                    SUM(p.hours_awarded) AS hours, COUNT(*) AS activity_count
               FROM participations p
               JOIN activities a ON a.id = p.activity_id
               JOIN users t ON t.id = a.teacher_id
              WHERE p.student_id = ? AND p.status = 'completed' AND p.result = 'pass'
              GROUP BY t.id, name
              ORDER BY hours DESC",
            [$studentId]
        );

        // ชั่วโมงที่รอบันทึกผล (อนุมัติแล้วแต่ยังไม่เสร็จ)
        $pending = (float) q_val(
            "SELECT COALESCE(SUM(a.hours), 0) FROM participations p JOIN activities a ON a.id = p.activity_id
              WHERE p.student_id = ? AND p.status = 'approved'",
            [$studentId]
        );

        return [
            'required'  => $required,
            'earned'    => $earned,
            'raw'       => $raw,
            'remaining' => max(0.0, $required - $earned),
            'percent'   => $required > 0 ? min(100, round($earned / $required * 100, 1)) : 0,
            'complete'  => $earned >= $required,
            'pending'   => $pending,
            'skills'    => $skills,
            'teachers'  => $teachers,
            'cap'       => $cap,
        ];
    }

    /**
     * รายการที่บันทึกผลแล้ว (ใช้พิมพ์แบบบันทึกการฝึกทักษะวิชาชีพ)
     */
    public static function records(int $studentId, ?int $semesterId = null): array
    {
        $sql = "SELECT p.*, a.title AS activity_title, a.hours AS activity_hours, a.semester_id,
                       sk.name AS skill_name, sk.code AS skill_code,
                       CONCAT(o.prefix, o.first_name, ' ', o.last_name) AS owner_name,
                       CONCAT(t.prefix, t.first_name, ' ', t.last_name) AS teacher_name,
                       (SELECT MIN(session_date) FROM activity_sessions s WHERE s.activity_id = a.id) AS first_date,
                       (SELECT MAX(session_date) FROM activity_sessions s WHERE s.activity_id = a.id) AS last_date
                  FROM participations p
                  JOIN activities a ON a.id = p.activity_id
                  JOIN skills sk    ON sk.id = a.skill_id
                  LEFT JOIN users o ON o.id = sk.owner_id
                  JOIN users t      ON t.id = a.teacher_id
                 WHERE p.student_id = ? AND p.status = 'completed'";
        $params = [$studentId];
        if ($semesterId) {
            $sql .= ' AND a.semester_id = ?';
            $params[] = $semesterId;
        }
        $sql .= ' ORDER BY COALESCE(first_date, p.signed_at), p.id';
        return q_all($sql, $params);
    }
}
