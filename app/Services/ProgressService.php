<?php
declare(strict_types=1);

namespace App\Services;

/**
 * ขั้นตอนของนักศึกษาแต่ละคน (ฝ่ายทะเบียนติดตาม / หัวหน้าหลักสูตรเซ็นยืนยัน)
 *
 *  collecting   กำลังสะสมชั่วโมง (ยังไม่ครบ และไม่มีรายการค้างที่อาจารย์)
 *  wait_teacher รออาจารย์ — มีคำขอเข้าร่วมรออนุมัติ หรือเข้าร่วมแล้วรออาจารย์บันทึกผล
 *  wait_head    ชั่วโมงครบตามเกณฑ์ รอหัวหน้าหลักสูตรเซ็นยืนยัน
 *  certified    หัวหน้าหลักสูตรเซ็นยืนยันแล้ว (เสร็จสิ้น)
 */
final class ProgressService
{
    public const STAGES = [
        'collecting'   => ['กำลังสะสมชั่วโมง', 'secondary', 'hourglass-split'],
        'wait_teacher' => ['รออาจารย์อนุมัติ/บันทึกผล', 'warning', 'person-check'],
        'wait_head'    => ['รอหัวหน้าหลักสูตรเซ็นยืนยัน', 'info', 'pen'],
        'certified'    => ['หัวหน้าหลักสูตรเซ็นยืนยันแล้ว', 'success', 'patch-check'],
    ];

    /** ปีที่เข้า: จากรหัสนักศึกษาหลักที่ 2-3 ก่อน แล้วจึงใช้ค่าที่บันทึกไว้ */
    public static function entryYearSql(string $alias = 'st'): string
    {
        return "IF($alias.student_code REGEXP '^[0-9]{12,13}$', 2500 + CAST(SUBSTRING($alias.student_code, 2, 2) AS UNSIGNED), $alias.entry_year)";
    }

    /**
     * FROM ... ของรายชื่อนักศึกษาพร้อมขั้นตอน (alias: st, u, h = ชั่วโมง, pc = รายการค้าง, c = การเซ็นยืนยัน)
     * คอลัมน์ที่ใช้ได้: ดู columns()
     */
    public static function fromSql(): string
    {
        return "FROM students st
                JOIN users u ON u.id = st.user_id
                LEFT JOIN (" . HoursService::earnedSql() . ") h ON h.student_id = st.user_id
                LEFT JOIN (SELECT student_id, SUM(status = 'pending') AS pending, SUM(status = 'approved') AS unrecorded
                             FROM participations GROUP BY student_id) pc ON pc.student_id = st.user_id
                LEFT JOIN student_certifications c ON c.student_id = st.user_id";
    }

    public static function stageSql(): string
    {
        $required = HoursService::requiredSqlExpr('st');
        return "CASE WHEN c.id IS NOT NULL THEN 'certified'
                     WHEN COALESCE(h.earned, 0) >= $required THEN 'wait_head'
                     WHEN COALESCE(pc.pending, 0) + COALESCE(pc.unrecorded, 0) > 0 THEN 'wait_teacher'
                     ELSE 'collecting' END";
    }

    public static function columns(): string
    {
        return "u.id, u.prefix, u.first_name, u.last_name, u.is_active, st.student_code, st.program_type, st.major, st.status AS student_status,
                " . self::entryYearSql() . " AS entry_year_calc,
                COALESCE(h.earned, 0) AS earned, " . HoursService::requiredSqlExpr('st') . " AS required,
                COALESCE(pc.pending, 0) AS pending, COALESCE(pc.unrecorded, 0) AS unrecorded,
                c.certified_at, c.signer_name AS certified_by, " . self::stageSql() . " AS stage";
    }

    /** ขั้นตอนของนักศึกษา 1 คน */
    public static function forStudent(int $studentId): ?array
    {
        return q_one('SELECT ' . self::columns() . ' ' . self::fromSql() . ' WHERE st.user_id = ?', [$studentId]);
    }

    public static function certification(int $studentId): ?array
    {
        return q_one('SELECT * FROM student_certifications WHERE student_id = ?', [$studentId]);
    }

    /** บรรทัดท้ายแบบบันทึก (ลงชื่อ / ในวงเล็บ / หัวหน้าหลักสูตร) */
    public const SLOTS = ['sign' => 'ลงชื่อ', 'paren' => 'ในวงเล็บ ( )', 'pos' => 'หัวหน้าหลักสูตร'];

    /**
     * ลายเซ็น/ชื่อ ทั้ง 3 บรรทัดของการเซ็นยืนยัน [slot => ['mode' => signature|name|none, 'value' => ...]]
     * (การเซ็นแบบเก่าที่ยังไม่มี slots: บรรทัดลงชื่อจากลายเซ็น, ในวงเล็บ = ชื่อหัวหน้าหลักสูตร)
     */
    public static function certSlots(array $cert, string $headName): array
    {
        $slots = json_decode((string) ($cert['slots'] ?? ''), true);
        if (is_array($slots)) {
            return $slots;
        }
        return [
            'sign'  => $cert['sign_method'] === 'online'
                ? ['mode' => 'signature', 'value' => $cert['signature_data']]
                : ['mode' => 'name', 'value' => $cert['signer_name']],
            'paren' => ['mode' => 'name', 'value' => $headName],
            'pos'   => ['mode' => 'none', 'value' => ''],
        ];
    }

    /**
     * อ่าน + ตรวจ slots จากฟอร์มเซ็นยืนยัน (JSON ใน $_POST['slots'])
     * @return array{0: ?array, 1: ?string} [slots, error]
     */
    public static function slotsFromRequest(): array
    {
        $in = json_decode((string) ($_POST['slots'] ?? ''), true);
        if (!is_array($in)) {
            return [null, 'กรุณาเซ็นชื่อก่อนยืนยัน'];
        }
        $out = [];
        foreach (array_keys(self::SLOTS) as $slot) {
            $mode = $in[$slot]['mode'] ?? 'none';
            $value = (string) ($in[$slot]['value'] ?? '');
            if ($mode === 'signature') {
                if (!valid_signature($value)) {
                    return [null, 'ลายเซ็นบรรทัด "' . self::SLOTS[$slot] . '" ไม่ถูกต้อง กรุณาเซ็นใหม่'];
                }
            } elseif ($mode === 'name') {
                $value = mb_substr(trim($value), 0, 200);
                if ($value === '') {
                    return [null, 'กรุณาพิมพ์ชื่อในบรรทัด "' . self::SLOTS[$slot] . '"'];
                }
            } else {
                $mode = 'none';
                $value = '';
            }
            $out[$slot] = ['mode' => $mode, 'value' => $value];
        }
        if ($out['sign']['mode'] === 'none') {
            return [null, 'กรุณาเซ็นหรือพิมพ์ชื่อในบรรทัด "ลงชื่อ"'];
        }
        return [$out, null];
    }
}
