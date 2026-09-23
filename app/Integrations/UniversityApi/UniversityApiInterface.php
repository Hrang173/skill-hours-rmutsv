<?php
declare(strict_types=1);

namespace App\Integrations\UniversityApi;

/**
 * สัญญา (interface) สำหรับเชื่อมต่อระบบของมหาวิทยาลัย
 *
 * ทุก driver ต้องคืนข้อมูลในรูปแบบกลางเดียวกัน (normalized profile):
 *   [
 *     'role'         => 'student' | 'teacher',
 *     'username'     => string,          // นักศึกษา = รหัสนักศึกษา
 *     'student_code' => ?string,
 *     'prefix'       => string,
 *     'first_name'   => string,
 *     'last_name'    => string,
 *     'faculty'      => ?string,
 *     'major'        => ?string,
 *     'program_type' => '4year' | 'transfer' | null,
 *     'entry_year'   => ?int,
 *     'email'        => ?string,
 *     'external_id'  => ?string,
 *   ]
 *
 * ถ้าในอนาคตมหาวิทยาลัยใช้ LDAP / OAuth2 / SSO ก็สร้างคลาสใหม่ที่ implement interface นี้
 * แล้วเพิ่มใน UniversityApiFactory ได้เลย โดยไม่ต้องแก้หน้าเว็บส่วนอื่น
 */
interface UniversityApiInterface
{
    /** ชื่อ driver สำหรับแสดงผล */
    public function name(): string;

    /** ตรวจสอบว่าเชื่อมต่อได้ (คืน ['ok' => bool, 'message' => string]) */
    public function ping(): array;

    /** ตรวจสอบชื่อผู้ใช้/รหัสผ่านกับระบบมหาวิทยาลัย คืน profile ถ้าสำเร็จ หรือ null */
    public function authenticate(string $username, string $password): ?array;

    /** ดึงข้อมูลนักศึกษาจากรหัสนักศึกษา */
    public function getStudent(string $studentCode): ?array;

    /** ดึงข้อมูลบุคลากร/อาจารย์ */
    public function getStaff(string $username): ?array;
}
