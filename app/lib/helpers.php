<?php
declare(strict_types=1);

/* ============================================================
 *  ฟังก์ชันช่วยทั่วไป
 * ============================================================ */

function env(string $key, ?string $default = null): ?string
{
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

/** อ่านค่า config แบบ dot notation เช่น config('db.host') */
function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['config'] ?? [];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            config('db.host'),
            config('db.port'),
            config('db.name')
        );
        $pdo = new PDO($dsn, config('db.user'), config('db.pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '+07:00'");
    }
    return $pdo;
}

/** รัน query แบบ prepared แล้วคืน PDOStatement */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function q_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function q_val(string $sql, array $params = []): mixed
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/** escape HTML */
function e(mixed $v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function url(string $path = '', array $query = []): string
{
    $u = '/' . ltrim($path, '/');
    $query = array_filter($query, fn($v) => $v !== null && $v !== '');
    if ($query) {
        $u .= '?' . http_build_query($query);
    }
    return $u;
}

function asset(string $path): string
{
    $file = ROOT_PATH . '/public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? filemtime($file) : 0;
    return '/assets/' . ltrim($path, '/') . '?v=' . $v;
}

function redirect(string $path, array $query = []): never
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path, $query)));
    exit;
}

/** redirect กลับหน้าเดิม (ใช้ referer ภายในเว็บเท่านั้น) */
function redirect_back(string $fallback = '/'): never
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($ref && parse_url($ref, PHP_URL_HOST) === explode(':', $host)[0]) {
        $path = parse_url($ref, PHP_URL_PATH) ?? '/';
        $qs = parse_url($ref, PHP_URL_QUERY);
        header('Location: ' . $path . ($qs ? '?' . $qs : ''));
        exit;
    }
    redirect($fallback);
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** อ่านค่าจาก POST ก่อน แล้วค่อย GET (ตัดช่องว่าง) */
function input(string $key, mixed $default = null): mixed
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function input_int(string $key, int $default = 0): int
{
    $v = input($key);
    return is_numeric($v) ? (int) $v : $default;
}

/* ------------------------------------------------------------ flash message */

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function flashes(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

/** เก็บค่า input เดิมไว้แสดงในฟอร์มหลัง validate ไม่ผ่าน */
function old(string $key, mixed $default = ''): mixed
{
    return $_SESSION['_old'][$key] ?? $default;
}

function keep_old(): void
{
    $_SESSION['_old'] = $_POST;
}

function clear_old(): void
{
    unset($_SESSION['_old']);
}

/* ------------------------------------------------------------ CSRF */

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        exit('CSRF token ไม่ถูกต้อง กรุณารีเฟรชหน้าแล้วลองใหม่');
    }
}

/* ------------------------------------------------------------ วันที่ภาษาไทย */

const THAI_MONTHS_SHORT = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
const THAI_MONTHS_FULL  = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
const THAI_DAYS         = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];

function thai_date(?string $dt, bool $withTime = false, bool $full = false): string
{
    if (!$dt) {
        return '-';
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return '-';
    }
    $m = (int) date('n', $ts);
    $s = date('j', $ts) . ' ' . ($full ? THAI_MONTHS_FULL[$m] : THAI_MONTHS_SHORT[$m]) . ' ' . ((int) date('Y', $ts) + 543);
    if ($withTime) {
        $s .= ' ' . date('H:i', $ts) . ' น.';
    }
    return $s;
}

function thai_weekday(string $date): string
{
    return THAI_DAYS[(int) date('w', strtotime($date))];
}

function time_short(?string $t): string
{
    return $t ? substr($t, 0, 5) : '';
}

/** แสดงวันเวลาของ session เช่น "ส. 10 ต.ค. 2569 08:30–16:30 น." */
function session_label(array $s): string
{
    $label = thai_weekday($s['session_date']) . ' ' . thai_date($s['session_date']);
    if ($s['start_time']) {
        $label .= ' ' . time_short($s['start_time']);
        if ($s['end_time']) {
            $label .= '–' . time_short($s['end_time']);
        }
        $label .= ' น.';
    }
    return $label;
}

/** แปลงตัวเลขอารบิกเป็นเลขไทย */
function thai_digits(string|int|float $s): string
{
    return strtr((string) $s, ['0' => '๐', '1' => '๑', '2' => '๒', '3' => '๓', '4' => '๔', '5' => '๕', '6' => '๖', '7' => '๗', '8' => '๘', '9' => '๙']);
}

/* ------------------------------------------------------------ การแสดงผล */

function fmt_hours(mixed $h): string
{
    $h = (float) ($h ?? 0);
    return fmod($h, 1.0) == 0.0 ? number_format($h, 0) : number_format($h, 1);
}

function full_name(array $u, string $prefixKey = 'prefix'): string
{
    return trim(($u[$prefixKey] ?? '') . ($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
}

function program_label(?string $type): string
{
    return $type === 'transfer' ? 'เทียบโอน' : '4 ปี';
}

function role_label(string $role): string
{
    return ['student' => 'นักศึกษา', 'teacher' => 'อาจารย์', 'registrar' => 'ฝ่ายทะเบียน', 'admin' => 'ผู้ดูแลระบบ'][$role] ?? $role;
}

function participation_badge(array $p): string
{
    $map = [
        'pending'   => ['warning', 'รออนุมัติ'],
        'approved'  => ['info', 'อนุมัติแล้ว / รอบันทึกผล'],
        'rejected'  => ['secondary', 'ไม่อนุมัติ'],
        'cancelled' => ['secondary', 'ยกเลิก'],
        'completed' => ['success', 'บันทึกผลแล้ว'],
    ];
    [$cls, $text] = $map[$p['status']] ?? ['light', $p['status']];
    if ($p['status'] === 'completed') {
        [$cls, $text] = ($p['result'] ?? '') === 'pass' ? ['success', 'ผ่าน'] : ['danger', 'ไม่ผ่าน'];
    }
    return '<span class="badge text-bg-' . $cls . '">' . e($text) . '</span>';
}

function activity_status_badge(string $status): string
{
    $map = [
        'open'      => ['success', 'เปิดรับ'],
        'closed'    => ['warning', 'ปิดรับสมัคร'],
        'completed' => ['primary', 'เสร็จสิ้น'],
        'cancelled' => ['secondary', 'ยกเลิก'],
    ];
    [$cls, $text] = $map[$status] ?? ['light', $status];
    return '<span class="badge text-bg-' . $cls . '">' . $text . '</span>';
}

function semester_label(?array $s): string
{
    return $s ? $s['term'] . '/' . $s['academic_year'] : '-';
}

/* ------------------------------------------------------------ settings */

function setting(string $key, mixed $default = null): mixed
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (q_all('SELECT setting_key, setting_value FROM settings') as $r) {
                $cache[$r['setting_key']] = $r['setting_value'];
            }
        } catch (Throwable) {
            // ตาราง settings ยังไม่ถูกสร้าง
        }
    }
    return $cache[$key] ?? $default;
}

function setting_set(string $key, ?string $value): void
{
    q('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$key, $value]);
}

function current_semester(): ?array
{
    return q_one('SELECT * FROM semesters WHERE is_current = 1 LIMIT 1');
}

function all_semesters(): array
{
    return q_all('SELECT * FROM semesters ORDER BY academic_year DESC, term DESC');
}

/* ------------------------------------------------------------ แจ้งเตือน & log */

function notify(int $userId, string $title, ?string $message = null, ?string $link = null): void
{
    q('INSERT INTO notifications (user_id, title, message, link) VALUES (?, ?, ?, ?)', [$userId, $title, $message, $link]);
}

function audit(string $action, mixed $detail = null): void
{
    try {
        q('INSERT INTO audit_logs (user_id, action, detail, ip_address) VALUES (?, ?, ?, ?)', [
            $_SESSION['user_id'] ?? null,
            $action,
            is_array($detail) ? json_encode($detail, JSON_UNESCAPED_UNICODE) : $detail,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable) {
        // log ไม่ควรทำให้ระบบหลักล้ม
    }
}

/* ------------------------------------------------------------ อื่นๆ */

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

/** ตรวจว่าเป็น data URL ของรูป PNG ที่ถูกต้อง (ใช้กับลายเซ็น) */
function valid_signature(?string $data): bool
{
    if (!$data || !str_starts_with($data, 'data:image/png;base64,') || strlen($data) > 1_500_000) {
        return false;
    }
    $bin = base64_decode(substr($data, 22), true);
    return $bin !== false && str_starts_with($bin, "\x89PNG");
}

/** คำนวณการแบ่งหน้า */
function paginate(int $total, int $perPage = 20): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, input_int('page', 1)), $pages);
    return ['page' => $page, 'pages' => $pages, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage, 'total' => $total];
}

/** ค้นหาแบบ LIKE ปลอดภัย */
function like(string $s): string
{
    return '%' . addcslashes($s, '%_\\') . '%';
}
