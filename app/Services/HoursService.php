<?php
declare(strict_types=1);

namespace App\Services;

/**
 * คำนวณชั่วโมงการฝึกทักษะวิชาชีพ
 *
 * กติกา (ตามเอกสาร "รายชื่อทักษะวิชาชีพ"):
 *  - แผน 4 ปี ต้องฝึกอย่างน้อย 100 ชม. / แผนเทียบโอน อย่างน้อย 50 ชม.
 *  - นับเฉพาะรายการที่อาจารย์บันทึกผล "ผ่าน" แล้ว
 *  - นักศึกษา 1 คน เก็บชั่วโมงจากอาจารย์ 1 ท่าน (ผู้ควบคุมกิจกรรม) นับได้สูงสุด 25 ชม.
 *    (ตั้งค่า max_hours_per_teacher / enforce_teacher_cap) ส่วนเกินยังแสดงในประวัติแต่ไม่นับรวม
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
        return setting('enforce_teacher_cap', '1') === '1';
    }

    /** ชั่วโมงสูงสุดที่นับได้จากอาจารย์ 1 ท่าน (null = ไม่จำกัด) */
    public static function teacherCap(): ?float
    {
        return self::capEnabled() ? (float) setting('max_hours_per_teacher', 25) : null;
    }

    /**
     * SQL ย่อยที่คืน (student_id, earned) ของนักศึกษาทุกคน — ใช้ JOIN ในหน้าค้นหา/รายงาน
     */
    public static function earnedSql(): string
    {
        $cap = self::teacherCap();
        $expr = $cap !== null ? "LEAST(x.h, $cap)" : 'x.h';
        return "SELECT x.student_id, SUM($expr) AS earned
                  FROM (SELECT p.student_id, a.teacher_id, SUM(p.hours_awarded) AS h
                          FROM participations p
                          JOIN activities a ON a.id = p.activity_id
                         WHERE p.status = 'completed' AND p.result = 'pass'
                         GROUP BY p.student_id, a.teacher_id) x
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
     * ชั่วโมงที่ "ผ่าน" แล้วของนักศึกษาหลายคน กับอาจารย์ 1 ท่าน → [student_id => hours]
     */
    public static function hoursWithTeacher(int $teacherId, array $studentIds): array
    {
        $studentIds = array_values(array_unique(array_map('intval', $studentIds)));
        if (!$studentIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($studentIds), '?'));
        $rows = q_all(
            "SELECT p.student_id, SUM(p.hours_awarded) AS h
               FROM participations p JOIN activities a ON a.id = p.activity_id
              WHERE a.teacher_id = ? AND p.status = 'completed' AND p.result = 'pass' AND p.student_id IN ($in)
              GROUP BY p.student_id",
            array_merge([$teacherId], $studentIds)
        );
        return array_column(array_map(fn($r) => [$r['student_id'], (float) $r['h']], $rows), 1, 0);
    }

    /**
     * สรุปชั่วโมงของนักศึกษา 1 คน
     */
    public static function summary(int $studentId, ?string $programType): array
    {
        $required = self::requiredHours($programType);
        $cap = self::teacherCap();

        // แยกตามทักษะ (แสดงข้อมูล ไม่มีเพดาน)
        $skills = q_all(
            "SELECT sk.id, sk.code, sk.name,
                    CONCAT(o.prefix, o.first_name, ' ', o.last_name) AS owner_name,
                    COALESCE(SUM(CASE WHEN p.status = 'completed' AND p.result = 'pass' THEN p.hours_awarded END), 0) AS hours,
                    COUNT(CASE WHEN p.status = 'completed' AND p.result = 'pass' THEN 1 END) AS activity_count
               FROM skills sk
               LEFT JOIN users o ON o.id = sk.owner_id
               LEFT JOIN activities a ON a.skill_id = sk.id
               LEFT JOIN participations p ON p.activity_id = a.id AND p.student_id = ?
              WHERE sk.is_active = 1 OR p.id IS NOT NULL
              GROUP BY sk.id, sk.code, sk.name, owner_name
              ORDER BY sk.sort_order, sk.id",
            [$studentId]
        );

        // แยกตามอาจารย์ผู้ควบคุมกิจกรรม — ตัวนี้ใช้คิดชั่วโมงที่นับได้
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

        $raw = 0.0;
        $earned = 0.0;
        foreach ($teachers as &$t) {
            $h = (float) $t['hours'];
            $t['counted'] = $cap !== null ? min($h, $cap) : $h;
            $t['over'] = $cap !== null && $h > $cap;
            $raw += $h;
            $earned += $t['counted'];
        }
        unset($t);

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
