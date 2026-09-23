<?php
declare(strict_types=1);

namespace App\Integrations\UniversityApi;

use RuntimeException;

/**
 * ตัวเชื่อมต่อ REST API ของมหาวิทยาลัยเทคโนโลยีราชมงคลศรีวิชัย (RMUTSV)
 *
 * ** ยังไม่มีเอกสาร API จริง ** จึงออกแบบให้ปรับได้จาก config โดยไม่ต้องแก้โค้ด:
 *   - UNI_API_BASE_URL          URL หลัก
 *   - UNI_API_KEY               ส่งไปใน header "Authorization: Bearer <key>" และ "X-API-Key"
 *   - UNI_API_*_ENDPOINT        path ของแต่ละ endpoint ({code} จะถูกแทนด้วยรหัส)
 *   - config.php → field_map    จับคู่ชื่อฟิลด์ JSON ของมหาวิทยาลัย → ฟิลด์ในระบบเรา
 *
 * รูปแบบที่คาดไว้ (ปรับได้):
 *   POST {base}/auth/login          body: {"username": "...", "password": "..."}  → 200 + JSON โปรไฟล์
 *   GET  {base}/students/{code}                                                   → 200 + JSON นักศึกษา
 *   GET  {base}/staff/{code}                                                      → 200 + JSON บุคลากร
 * JSON ที่ตอบกลับอาจห่อด้วย {"data": {...}} ก็ได้ ระบบจะแกะให้อัตโนมัติ
 */
final class RmutsvApiClient implements UniversityApiInterface
{
    public function __construct(private readonly array $cfg)
    {
    }

    public function name(): string
    {
        return 'RMUTSV API (' . ($this->cfg['base_url'] ?: 'ยังไม่ตั้งค่า URL') . ')';
    }

    public function ping(): array
    {
        if (!$this->cfg['base_url']) {
            return ['ok' => false, 'message' => 'ยังไม่ได้ตั้งค่า UNI_API_BASE_URL'];
        }
        try {
            [$status] = $this->request('GET', '/');
            return ['ok' => $status < 500, 'message' => 'เชื่อมต่อได้ (HTTP ' . $status . ')'];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function authenticate(string $username, string $password): ?array
    {
        [$status, $body] = $this->request('POST', $this->cfg['endpoints']['auth'], [
            'username' => $username,
            'password' => $password,
        ]);
        if ($status !== 200 || !is_array($body)) {
            return null;
        }
        $data = $body['data'] ?? $body;
        // ถ้ามีรหัสนักศึกษา → นักศึกษา, ไม่มีก็เป็นบุคลากร
        $asStudent = $this->mapStudent($data);
        if ($asStudent['student_code']) {
            return $asStudent;
        }
        return $this->mapStaff($data);
    }

    public function getStudent(string $studentCode): ?array
    {
        [$status, $body] = $this->request('GET', str_replace('{code}', rawurlencode($studentCode), $this->cfg['endpoints']['student']));
        if ($status !== 200 || !is_array($body)) {
            return null;
        }
        return $this->mapStudent($body['data'] ?? $body);
    }

    public function getStaff(string $username): ?array
    {
        [$status, $body] = $this->request('GET', str_replace('{code}', rawurlencode($username), $this->cfg['endpoints']['staff']));
        if ($status !== 200 || !is_array($body)) {
            return null;
        }
        return $this->mapStaff($body['data'] ?? $body);
    }

    /* ------------------------------------------------------------ internal */

    private function mapStudent(array $data): array
    {
        $map = $this->cfg['field_map']['student'];
        $get = fn(string $k) => self::dig($data, $map[$k] ?? $k);
        $code = (string) ($get('student_code') ?? '');
        $rawType = (string) ($get('program_type') ?? '');
        return [
            'role'         => 'student',
            'username'     => $code,
            'student_code' => $code ?: null,
            'prefix'       => (string) ($get('prefix') ?? ''),
            'first_name'   => (string) ($get('first_name') ?? ''),
            'last_name'    => (string) ($get('last_name') ?? ''),
            'faculty'      => $get('faculty'),
            'major'        => $get('major'),
            'program_type' => $this->cfg['program_type_map'][$rawType] ?? null,
            'entry_year'   => $get('entry_year') ? (int) $get('entry_year') : null,
            'email'        => $get('email'),
            'external_id'  => $code ?: null,
        ];
    }

    private function mapStaff(array $data): array
    {
        $map = $this->cfg['field_map']['staff'];
        $get = fn(string $k) => self::dig($data, $map[$k] ?? $k);
        return [
            'role'         => 'teacher',
            'username'     => (string) ($get('username') ?? ''),
            'student_code' => null,
            'prefix'       => (string) ($get('prefix') ?? ''),
            'first_name'   => (string) ($get('first_name') ?? ''),
            'last_name'    => (string) ($get('last_name') ?? ''),
            'email'        => $get('email'),
            'external_id'  => (string) ($get('username') ?? ''),
        ];
    }

    /** อ่านค่าจาก array ด้วย path แบบ a.b.c */
    private static function dig(array $data, string $path): mixed
    {
        foreach (explode('.', $path) as $k) {
            if (!is_array($data) || !array_key_exists($k, $data)) {
                return null;
            }
            $data = $data[$k];
        }
        return $data;
    }

    /**
     * @return array{0:int, 1:mixed} [HTTP status, decoded JSON]
     */
    private function request(string $method, string $path, ?array $json = null): array
    {
        if (!$this->cfg['base_url']) {
            throw new RuntimeException('ยังไม่ได้ตั้งค่า UNI_API_BASE_URL');
        }
        $ch = curl_init($this->cfg['base_url'] . '/' . ltrim($path, '/'));
        $headers = ['Accept: application/json'];
        if ($this->cfg['api_key']) {
            $headers[] = 'Authorization: Bearer ' . $this->cfg['api_key'];
            $headers[] = 'X-API-Key: ' . $this->cfg['api_key'];
        }
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->cfg['timeout'] ?: 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => $this->cfg['verify_ssl'],
            CURLOPT_SSL_VERIFYHOST => $this->cfg['verify_ssl'] ? 2 : 0,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('เชื่อมต่อ API มหาวิทยาลัยไม่ได้: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, json_decode($raw, true)];
    }
}
