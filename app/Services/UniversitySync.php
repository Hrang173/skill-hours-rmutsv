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
     * ลำดับ: ชื่อผู้ใช้ → ชื่อผู้ใช้มหาวิทยาลัยที่เคยผูกไว้ (external_id) → รหัสนักศึกษา → อีเมล
     */
    public static function findLocalUser(array $p): ?array
    {
        return q_one('SELECT * FROM users WHERE username = ?', [$p['username']])
            ?? q_one('SELECT * FROM users WHERE external_id = ?', [$p['username']])
            ?? (!empty($p['student_code']) ? q_one('SELECT u.* FROM users u JOIN students s ON s.user_id = u.id WHERE s.student_code = ?', [$p['student_code']]) : null)
            ?? (!empty($p['email']) ? q_one('SELECT * FROM users WHERE email = ? ORDER BY id LIMIT 1', [$p['email']]) : null);
    }

    /**
     * เข้าสู่ระบบด้วย profile ที่ API มหาวิทยาลัยยืนยันแล้ว
     *  - นักศึกษา: ถ้ายังไม่มีบัญชี สร้างให้อัตโนมัติ (รหัสนักศึกษา = ชื่อผู้ใช้)
     *  - บุคลากร/อาจารย์: ใช้บัญชีที่มีอยู่ ถ้ายังไม่มีและ type = staff สร้างบัญชีอาจารย์ให้ (ดู loginNewStaff)
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
                // แผนการเรียนจากรหัสนักศึกษา: อัปเดตเฉพาะบัญชีที่สร้างจาก API (บัญชีที่ฝ่ายทะเบียนสร้าง/นำเข้าเอง ไม่ทับ)
                if (!empty($p['program_type']) && $user['auth_source'] === 'university') {
                    q('UPDATE students SET program_type = ? WHERE user_id = ?', [$p['program_type'], $user['id']]);
                }
                // ไม่ทับสาขาที่ฝ่ายทะเบียนตั้งไว้ (เติมสาขาให้เฉพาะตอนที่ยังว่าง)
                q(
                    "UPDATE students SET faculty = COALESCE(?, faculty),
                            major = CASE WHEN major IN ('', '-') THEN COALESCE(?, major) ELSE major END,
                            synced_at = NOW()
                      WHERE user_id = ?",
                    [$p['faculty'], $p['major'], $user['id']]
                );
            }
            return [q_one('SELECT * FROM users WHERE id = ?', [$user['id']]), null];
        }

        if (!$isStudent) {
            return self::loginNewStaff($p);
        }

        $allowed = config('university_api.allowed_faccodes') ?: [];
        if ($allowed && !in_array((string) ($p['faculty_code'] ?? ''), $allowed, true)) {
            audit('university.login_faculty_denied', ['username' => $p['username'], 'faccode' => $p['faculty_code'] ?? null]);
            return [null, 'ระบบนี้เปิดให้เฉพาะนักศึกษาของคณะที่กำหนด'];
        }

        $user = self::upsertFromProfile($p);
        return $user ? [$user, null] : [null, 'ข้อมูลจาก API มหาวิทยาลัยไม่ครบ ไม่สามารถสร้างบัญชีได้'];
    }

    /**
     * บุคลากรที่ยังไม่มีบัญชีในระบบ (เข้าสู่ระบบครั้งแรก)
     *  - type ตรงกับ UNI_API_STAFF_TYPES (ค่าเริ่มต้น staff) และเปิดตั้งค่า auto_create_staff → สร้างบัญชีอาจารย์ให้ ใช้ชื่อจาก API
     *  - ถ้ามีบัญชีอาจารย์ที่สร้างไว้ก่อนด้วยชื่อผู้ใช้อื่น แต่ชื่อ-นามสกุลตรงกันคนเดียว → ผูกกับบัญชีเดิม (ไม่สร้างซ้ำ)
     *
     * @return array{0: ?array, 1: ?string} [user, error]
     */
    private static function loginNewStaff(array $p): array
    {
        $type = mb_strtolower((string) ($p['raw_type'] ?? ''));
        if (setting('auto_create_staff', '1') !== '1' || !in_array($type, config('university_api.staff_types') ?: [], true)) {
            audit('university.login_staff_not_found', ['username' => $p['username'], 'type' => $p['raw_type'] ?? null]);
            return [null, 'บัญชีบุคลากร "' . $p['username'] . '" ยังไม่มีในระบบนี้ กรุณาให้ฝ่ายทะเบียนเพิ่มบัญชีอาจารย์ด้วยชื่อผู้ใช้เดียวกับระบบมหาวิทยาลัยก่อน'];
        }
        if (trim((string) $p['first_name']) === '' || trim((string) ($p['last_name'] ?? '')) === '') {
            return [null, 'ข้อมูลจาก API มหาวิทยาลัยไม่มีชื่อ-นามสกุล ไม่สามารถสร้างบัญชีได้ กรุณาติดต่อฝ่ายทะเบียน'];
        }

        $same = q_all(
            "SELECT * FROM users WHERE role = 'teacher' AND external_id IS NULL AND first_name = ? AND last_name = ?",
            [trim($p['first_name']), trim($p['last_name'])]
        );
        if (count($same) === 1) {
            $user = $same[0];
            if (!$user['is_active']) {
                return [null, 'บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อฝ่ายทะเบียน'];
            }
            q('UPDATE users SET prefix = ?, email = COALESCE(email, ?), external_id = ? WHERE id = ?',
                [$p['prefix'] ?: $user['prefix'], $p['email'], $p['username'], $user['id']]);
            audit('university.link_staff', ['username' => $p['username'], 'local_username' => $user['username']]);
            return [q_one('SELECT * FROM users WHERE id = ?', [$user['id']]), null];
        }

        $user = self::upsertFromProfile(['role' => 'teacher'] + $p);
        if (!$user) {
            return [null, 'ข้อมูลจาก API มหาวิทยาลัยไม่ครบ ไม่สามารถสร้างบัญชีได้'];
        }
        // แจ้งผู้ดูแลระบบ/ฝ่ายทะเบียน ให้ตรวจว่าเป็นอาจารย์จริง (ระงับบัญชีได้ที่หน้าจัดการผู้ใช้)
        foreach (q_all("SELECT id FROM users WHERE role IN ('admin', 'registrar') AND is_active = 1") as $r) {
            notify((int) $r['id'], 'มีบัญชีอาจารย์ใหม่จากระบบมหาวิทยาลัย',
                full_name($user) . ' (' . $user['username'] . ') เข้าสู่ระบบครั้งแรก ระบบสร้างบัญชีอาจารย์ให้อัตโนมัติ',
                '/registrar/users?role=teacher');
        }
        return [$user, null];
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
