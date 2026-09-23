<?php
declare(strict_types=1);

namespace App\Integrations\UniversityApi;

/** ใช้เมื่อปิดการเชื่อมต่อ API มหาวิทยาลัย (UNI_API_DRIVER=none) */
final class NullUniversityApi implements UniversityApiInterface
{
    public function name(): string
    {
        return 'ปิดการเชื่อมต่อ (none)';
    }

    public function ping(): array
    {
        return ['ok' => false, 'message' => 'ยังไม่ได้เปิดใช้งาน API มหาวิทยาลัย (ตั้งค่า UNI_API_DRIVER)'];
    }

    public function authenticate(string $username, string $password): ?array
    {
        return null;
    }

    public function getStudent(string $studentCode): ?array
    {
        return null;
    }

    public function getStaff(string $username): ?array
    {
        return null;
    }
}
