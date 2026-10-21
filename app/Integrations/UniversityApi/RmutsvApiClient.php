<?php
declare(strict_types=1);

namespace App\Integrations\UniversityApi;

use RuntimeException;

/**
 * ตัวเชื่อมต่อ API ของมหาวิทยาลัยเทคโนโลยีราชมงคลศรีวิชัย (RMUTSV)
 *
 * รองรับ 2 รูปแบบ (ตั้งที่ UNI_API_FORMAT):
 *
 * 1) elogin (ค่าเริ่มต้น)
 *    POST https://api.rmutsv.ac.th/elogin   body JSON {"username": "...", "password": "..."}
 *    ตอบ: {"status": "ok", "username", "name", "firstname", "lastname", "type",
 *          "faccode", "facname", "depcode", "depname", "seccode", "secname",
 *          "campuscode", "campusname", "email", "cid", "token", ...}
 *    status อื่น: "password" (รหัสผิด), "weakpassword" (รหัสไม่ผ่านนโยบาย), "json" (ส่งคำขอผิดรูปแบบ)
 *
 * 2) epassport (ระบบ e-Passport ตรวจทั้ง LDAP และ elogin)
 *    POST https://epassport.rmutsv.ac.th/assets/api/logintest_user.php   body JSON {"userUid": "...", "userPass": "..."}
 *    ตอบ: {"data": [{"action": "true", "mesg": "", "ldap": "true", "elogin": "true",
 *                    "datadecode": { ...ข้อมูลเดียวกับแบบ elogin... }, "username": "..."}]}
 *    ไม่ผ่าน: action = "false", datadecode = null, mesg = ข้อความแจ้ง
 *
 * ** ไม่เก็บ cid (เลขประจำตัวประชาชน) และ token ** — ตัดทิ้งทันทีหลังได้คำตอบ
 */
final class RmutsvApiClient implements UniversityApiInterface
{
    /** ฟิลด์อ่อนไหวที่ห้ามเก็บ/แสดง */
    public const SENSITIVE_FIELDS = ['cid', 'token'];

    /** คำตอบล่าสุดจาก API เข้าสู่ระบบ (ตัดฟิลด์อ่อนไหวแล้ว) — ใช้ในหน้าทดสอบของ admin */
    private ?array $lastResponse = null;

    public function __construct(private readonly array $cfg)
    {
    }

    public function name(): string
    {
        return 'RMUTSV ' . $this->format() . ' (' . ($this->cfg['base_url'] ?: 'ยังไม่ตั้งค่า URL') . ')';
    }

    public function supportsLookup(): bool
    {
        return $this->cfg['endpoints']['student'] !== '';
    }

    public function ping(): array
    {
        if (!$this->cfg['base_url']) {
            return ['ok' => false, 'message' => 'ยังไม่ได้ตั้งค่า UNI_API_BASE_URL'];
        }
        try {
            // ส่งชื่อผู้ใช้/รหัสผ่านว่าง — ถ้า API ทำงาน จะตอบ JSON ว่า "ไม่ผ่าน" กลับมาตามรูปแบบ
            [$ok] = $this->loginRequest('', '');
            return ['ok' => $ok !== null, 'message' => $ok !== null ? 'เชื่อมต่อ API ได้ (รูปแบบ ' . $this->format() . ')' : 'API ตอบกลับผิดรูปแบบ'];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function authenticate(string $username, string $password): ?array
    {
        [$ok, $data] = $this->loginRequest($username, $password);
        if ($ok === null) {
            throw new RuntimeException('API มหาวิทยาลัยตอบกลับผิดรูปแบบ');
        }
        if (!$ok || trim((string) ($data['username'] ?? '')) === '') {
            return null;
        }
        return $this->mapElogin($data);
    }

    private function format(): string
    {
        return ($this->cfg['format'] ?? 'elogin') === 'epassport' ? 'epassport' : 'elogin';
    }

    /**
     * ส่งคำขอเข้าสู่ระบบตามรูปแบบที่ตั้งไว้
     * @return array{0: ?bool, 1: ?array} [ผ่านหรือไม่ (null = คำตอบผิดรูปแบบ), ข้อมูลผู้ใช้แบบ elogin]
     */
    private function loginRequest(string $username, string $password): array
    {
        $epassport = $this->format() === 'epassport';
        [$status, $body] = $this->request('POST', $this->cfg['endpoints']['auth'], $epassport
            ? ['userUid' => $username, 'userPass' => $password]
            : ['username' => $username, 'password' => $password]);
        if ($status !== 200 || !is_array($body)) {
            return [null, null];
        }
        $strip = fn(?array $a) => $a === null ? [] : array_diff_key($a, array_flip(self::SENSITIVE_FIELDS));

        if (!$epassport) {
            if (!isset($body['status'])) {
                return [null, null];
            }
            $this->lastResponse = $strip($body);
            return [$body['status'] === 'ok', $body];
        }

        $row = $body['data'][0] ?? null;
        if (!is_array($row) || !array_key_exists('action', $row)) {
            return [null, null];
        }
        $data = is_array($row['datadecode'] ?? null) ? $row['datadecode'] : null;
        $passed = in_array($row['action'], ['true', true, 1, '1'], true); // ตอนนี้ส่งมาเป็นข้อความ "true"
        // เก็บไว้แสดงในหน้าทดสอบ: ผลรวม + ข้อมูลผู้ใช้ (ตัด cid/token)
        $this->lastResponse = [
            'action' => $row['action'],
            'ldap'   => $row['ldap'] ?? null,
            'elogin' => $row['elogin'] ?? null,
            'mesg'   => $row['mesg'] ?? null,
        ] + $strip($data) + ['status' => $passed ? 'ok' : 'fail'];
        $ok = $passed && $data !== null && ($data['status'] ?? '') === 'ok';
        return [$ok, $data];
    }

    public function lastResponse(): ?array
    {
        return $this->lastResponse;
    }

    public function getStudent(string $studentCode): ?array
    {
        if (!$this->supportsLookup()) {
            return null;
        }
        [$status, $body] = $this->request('GET', str_replace('{code}', rawurlencode($studentCode), $this->cfg['endpoints']['student']));
        return $status === 200 && is_array($body) && ($body['status'] ?? 'ok') === 'ok' ? $this->mapElogin($body) : null;
    }

    public function getStaff(string $username): ?array
    {
        if ($this->cfg['endpoints']['staff'] === '') {
            return null;
        }
        [$status, $body] = $this->request('GET', str_replace('{code}', rawurlencode($username), $this->cfg['endpoints']['staff']));
        return $status === 200 && is_array($body) && ($body['status'] ?? 'ok') === 'ok' ? $this->mapElogin($body) : null;
    }

    /* ------------------------------------------------------------ internal */

    /** แปลงคำตอบของ RMUTSV → profile กลางของระบบ (ดู UniversityApiInterface) */
    private function mapElogin(array $b): array
    {
        // API ใช้ "-" แทนค่าว่าง
        $str = fn(string $k) => ($v = trim((string) ($b[$k] ?? ''))) === '-' ? '' : $v;
        $username = $str('username');
        $first = $str('firstname');
        $last = $str('lastname');
        $name = $str('name');

        // คำนำหน้า = ส่วนของ name ที่อยู่หน้าชื่อจริง เช่น "นายสมชาย ใจดี" → "นาย"
        $prefix = '';
        if ($first !== '' && ($pos = mb_strpos($name, $first)) !== false) {
            $prefix = trim(mb_substr($name, 0, $pos));
        }
        if ($first === '' && $name !== '') {
            [$first, $last] = array_pad(preg_split('/\s+/u', $name, 2), 2, '');
        }

        $type = mb_strtolower($str('type'));
        $isStudent = in_array($type, $this->cfg['student_types'], true) || preg_match('/^s?\d{10,13}$/i', $username) === 1;
        // ชื่อผู้ใช้นักศึกษาของ RMUTSV = "s" + รหัสนักศึกษา เช่น s666415850004 → 666415850004
        $studentCode = preg_match('/^s?(\d{10,13})$/i', $username, $m) ? $m[1] : $username;
        $major = $str($this->cfg['major_field'] ?? 'secname') ?: $str('secname') ?: $str('depname');

        return [
            'role'         => $isStudent ? 'student' : 'teacher',
            'username'     => $username,
            'student_code' => $isStudent ? $studentCode : null,
            'prefix'       => $prefix,
            'first_name'   => $first,
            'last_name'    => $last,
            'faculty'      => $str('facname') ?: null,
            'faculty_code' => $str('faccode') ?: null,
            'major'        => $major ?: null,
            // API ไม่บอกแผนการเรียน — ดูจากรหัสนักศึกษา (หลักที่ 4: 4 = 4 ปี, 2 = เทียบโอน)
            'program_type' => $isStudent ? program_type_from_code($studentCode) : null,
            'entry_year'   => null,
            'email'        => $str('email') ?: null,
            'external_id'  => $username,
            'raw_type'     => $str('type'),
        ];
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
