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
