<?php
declare(strict_types=1);

namespace App\Integrations\UniversityApi;

/**
 * API จำลอง สำหรับทดสอบระหว่างยังไม่มี API จริงของมหาวิทยาลัย
 * - รหัสนักศึกษาที่ขึ้นต้นด้วย 66/67/68 และยาว 12 หลัก จะถือว่ามีอยู่ในระบบมหาวิทยาลัย
 * - เข้าสู่ระบบได้ด้วยรหัสผ่าน "rmutsv" (ใช้กับ AUTH_MODE=hybrid)
 */
final class MockUniversityApi implements UniversityApiInterface
{
    private const DEMO = [
        '166404140005' => ['นาย', 'ธนากร', 'ศรีตรัง', '4year', 2566],
        '166404140006' => ['นางสาว', 'พิมพ์ชนก', 'ทะเลงาม', '4year', 2566],
        '167404150003' => ['นาย', 'กิตติพงษ์', 'อันดามัน', 'transfer', 2567],
        '168404140001' => ['นางสาว', 'ณัฐธิดา', 'ปะการัง', '4year', 2568],
    ];

    public function name(): string
    {
        return 'ข้อมูลจำลอง (mock)';
    }

    public function ping(): array
    {
        return ['ok' => true, 'message' => 'Mock API พร้อมใช้งาน (ข้อมูลจำลอง ' . count(self::DEMO) . ' คน)'];
    }

    public function authenticate(string $username, string $password): ?array
    {
        if ($password !== 'rmutsv') {
            return null;
        }
        return $this->getStudent($username);
    }

    public function getStudent(string $studentCode): ?array
    {
        if (isset(self::DEMO[$studentCode])) {
            [$prefix, $first, $last, $type, $year] = self::DEMO[$studentCode];
        } elseif (preg_match('/^(6[6-9])\d{10}$/', $studentCode, $m)) {
            [$prefix, $first, $last, $type, $year] = ['นาย', 'นักศึกษา', 'ทดสอบ' . substr($studentCode, -4), '4year', 2500 + (int) $m[1]];
        } else {
            return null;
        }
        return [
            'role'         => 'student',
            'username'     => $studentCode,
            'student_code' => $studentCode,
            'prefix'       => $prefix,
            'first_name'   => $first,
            'last_name'    => $last,
            'faculty'      => 'คณะวิศวกรรมศาสตร์และเทคโนโลยี',
            'major'        => 'วิศวกรรมคอมพิวเตอร์และการสื่อสาร',
            'program_type' => $type,
            'entry_year'   => $year,
            'email'        => $studentCode . '@example.rmutsv.ac.th',
            'external_id'  => 'MOCK-' . $studentCode,
        ];
    }

    public function getStaff(string $username): ?array
    {
        return null;
    }
}
