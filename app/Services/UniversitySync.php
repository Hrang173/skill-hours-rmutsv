<?php
declare(strict_types=1);

namespace App\Services;

use App\Integrations\UniversityApi\UniversityApiFactory;

/**
 * นำข้อมูลจาก API มหาวิทยาลัยมาสร้าง/อัปเดตบัญชีในระบบ
 */
final class UniversitySync
{
    /**
     * สร้างหรืออัปเดตผู้ใช้จาก profile กลาง (ดู UniversityApiInterface)
     * @return array|null แถว users ที่ได้
     */
    public static function upsertFromProfile(array $p): ?array
    {
        if (empty($p['username']) || empty($p['first_name'])) {
            return null;
        }
        $role = $p['role'] === 'student' ? 'student' : 'teacher';
        $user = q_one('SELECT * FROM users WHERE username = ?', [$p['username']]);

        if ($user) {
            q(
                'UPDATE users SET prefix = ?, first_name = ?, last_name = ?, email = COALESCE(?, email), external_id = COALESCE(?, external_id) WHERE id = ?',
                [$p['prefix'] ?? '', $p['first_name'], $p['last_name'] ?? '', $p['email'] ?? null, $p['external_id'] ?? null, $user['id']]
            );
            $id = (int) $user['id'];
        } else {
            q(
                "INSERT INTO users (username, password_hash, role, prefix, first_name, last_name, email, auth_source, external_id)
                 VALUES (?, NULL, ?, ?, ?, ?, ?, 'university', ?)",
                [$p['username'], $role, $p['prefix'] ?? '', $p['first_name'], $p['last_name'] ?? '', $p['email'] ?? null, $p['external_id'] ?? null]
            );
            $id = (int) db()->lastInsertId();
            audit('university.provision', ['username' => $p['username'], 'role' => $role]);
        }

        if ($role === 'student' && !empty($p['student_code'])) {
            q(
                "INSERT INTO students (user_id, student_code, program_type, faculty, major, entry_year, synced_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    program_type = COALESCE(VALUES(program_type), program_type),
                    faculty      = COALESCE(VALUES(faculty), faculty),
                    major        = COALESCE(VALUES(major), major),
                    entry_year   = COALESCE(VALUES(entry_year), entry_year),
                    synced_at    = NOW()",
                [
                    $id,
                    $p['student_code'],
                    $p['program_type'] ?? q_val('SELECT program_type FROM students WHERE user_id = ?', [$id]) ?? '4year',
                    $p['faculty'] ?: setting('faculty_name', 'คณะวิศวกรรมศาสตร์และเทคโนโลยี'),
                    $p['major'] ?: setting('default_major', 'วิศวกรรมคอมพิวเตอร์และการสื่อสาร'),
                    $p['entry_year'] ?? null,
                ]
            );
        }

        return q_one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /**
     * หาบัญชีในระบบที่ตรงกับ profile จาก API มหาวิทยาลัย
     * ลำดับ: ชื่อผู้ใช้ → ชื่อผู้ใช้มหาวิทยาลัยที่เคยผูกไว้ (external_id) → อีเมล
     */
    public static function findLocalUser(array $p): ?array
    {
        return q_one('SELECT * FROM users WHERE username = ?', [$p['username']])
            ?? q_one('SELECT * FROM users WHERE external_id = ?', [$p['username']])
            ?? (!empty($p['email']) ? q_one('SELECT * FROM users WHERE email = ? ORDER BY id LIMIT 1', [$p['email']]) : null);
    }

    /**
     * เข้าสู่ระบบด้วย profile ที่ API มหาวิทยาลัยยืนยันแล้ว
     *  - นักศึกษา: ถ้ายังไม่มีบัญชี สร้างให้อัตโนมัติ (รหัสนักศึกษา = ชื่อผู้ใช้)
     *  - บุคลากร/อาจารย์: ต้องมีบัญชีที่ฝ่ายทะเบียนสร้างไว้ก่อน (ไม่สร้างอัตโนมัติ เพื่อไม่ให้ใครก็ได้ได้สิทธิ์อาจารย์)
     *
     * @return array{0: ?array, 1: ?string} [user, error]
     */
    public static function loginFromProfile(array $p): array
    {
        $isStudent = $p['role'] === 'student';
        $user = self::findLocalUser($p);

        if ($user) {
            if (!$user['is_active']) {
                return [null, 'บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อฝ่ายทะเบียน'];
            }
            // กันบัญชีผิดประเภท เช่น นักศึกษาไปชนกับบัญชีเจ้าหน้าที่ที่ชื่อผู้ใช้เหมือนกัน
            if ($isStudent !== ($user['role'] === 'student')) {
                audit('university.login_role_mismatch', ['username' => $p['username'], 'local_role' => $user['role']]);
                return [null, 'บัญชีมหาวิทยาลัยนี้ไม่ตรงกับประเภทบัญชีในระบบ กรุณาติดต่อฝ่ายทะเบียน'];
            }
            q(
                'UPDATE users SET prefix = ?, first_name = ?, last_name = ?, email = COALESCE(?, email), external_id = ? WHERE id = ?',
                [$p['prefix'] ?: $user['prefix'], $p['first_name'] ?: $user['first_name'], $p['last_name'] ?: $user['last_name'], $p['email'], $p['username'], $user['id']]
            );
            if ($isStudent) {
                // ไม่ทับสาขา/แผนการเรียนที่ฝ่ายทะเบียนตั้งไว้ อัปเดตเฉพาะคณะและเวลาซิงก์
                q('UPDATE students SET faculty = COALESCE(?, faculty), synced_at = NOW() WHERE user_id = ?', [$p['faculty'], $user['id']]);
            }
            return [q_one('SELECT * FROM users WHERE id = ?', [$user['id']]), null];
        }

        if (!$isStudent) {
            audit('university.login_staff_not_found', ['username' => $p['username'], 'type' => $p['raw_type'] ?? null]);
            return [null, 'บัญชีบุคลากร "' . $p['username'] . '" ยังไม่มีในระบบนี้ กรุณาให้ฝ่ายทะเบียนเพิ่มบัญชีอาจารย์ด้วยชื่อผู้ใช้เดียวกับระบบมหาวิทยาลัยก่อน'];
        }

        $allowed = config('university_api.allowed_faccodes') ?: [];
        if ($allowed && !in_array((string) ($p['faculty_code'] ?? ''), $allowed, true)) {
            audit('university.login_faculty_denied', ['username' => $p['username'], 'faccode' => $p['faculty_code'] ?? null]);
            return [null, 'ระบบนี้เปิดให้เฉพาะนักศึกษาของคณะที่กำหนด'];
        }

        $user = self::upsertFromProfile($p);
        return $user ? [$user, null] : [null, 'ข้อมูลจาก API มหาวิทยาลัยไม่ครบ ไม่สามารถสร้างบัญชีได้'];
    }

    /** ดึงนักศึกษาจาก API ด้วยรหัส แล้วบันทึกลงระบบ */
    public static function syncStudent(string $code): ?array
    {
        $profile = UniversityApiFactory::make()->getStudent($code);
        if (!$profile) {
            return null;
        }
        $user = self::upsertFromProfile($profile);
        if ($user) {
            audit('university.sync_student', ['student_code' => $code]);
        }
        return $user;
    }
}
