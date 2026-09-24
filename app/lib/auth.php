<?php
declare(strict_types=1);

use App\Integrations\UniversityApi\UniversityApiFactory;
use App\Services\UniversitySync;

/* ============================================================
 *  การยืนยันตัวตนและสิทธิ์การใช้งาน
 * ============================================================ */

function current_user(): ?array
{
    static $cache = [];
    $id = (int) ($_SESSION['user_id'] ?? 0);
    if (!$id) {
        return null;
    }
    if (array_key_exists($id, $cache)) {
        return $cache[$id];
    }
    $user = $cache[$id] = q_one(
        'SELECT u.*, s.student_code, s.program_type, s.major, s.faculty, s.entry_year
           FROM users u LEFT JOIN students s ON s.user_id = u.id
          WHERE u.id = ? AND u.is_active = 1',
        [$id]
    );
    if (!$user) {
        unset($_SESSION['user_id']);
    }
    return $user;
}

function user_id(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}

function has_role(string ...$roles): bool
{
    $u = current_user();
    return $u !== null && in_array($u['role'], $roles, true);
}

function home_path(?string $role = null): string
{
    $role ??= current_user()['role'] ?? null;
    return match ($role) {
        'student'   => '/student',
        'teacher'   => '/teacher',
        'registrar' => '/registrar',
        'admin'     => '/admin',
        default     => '/login',
    };
}

function require_login(?array $roles = null): array
{
    $u = current_user();
    if (!$u) {
        $_SESSION['_intended'] = $_SERVER['REQUEST_URI'] ?? '/';
        flash('warning', 'กรุณาเข้าสู่ระบบก่อน');
        redirect('/login');
    }
    if ($roles && !in_array($u['role'], $roles, true)) {
        http_response_code(403);
        require APP_PATH . '/pages/errors/403.php';
        exit;
    }
    // บังคับเปลี่ยนรหัสผ่านครั้งแรก
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if ($u['must_change_password'] && !in_array($path, ['/profile', '/logout'], true)) {
        flash('warning', 'กรุณาเปลี่ยนรหัสผ่านก่อนใช้งานระบบ');
        redirect('/profile');
    }
    return $u;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    q('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
    audit('login', ['username' => $user['username']]);
}

function logout_user(): void
{
    audit('logout');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * ตรวจสอบชื่อผู้ใช้/รหัสผ่าน
 *  1) บัญชีในระบบ (password_hash)
 *  2) ถ้า AUTH_MODE=hybrid และไม่ผ่านข้อ 1 → ถาม API มหาวิทยาลัย แล้วสร้าง/อัปเดตบัญชีให้อัตโนมัติ
 *
 * @return array{0: ?array, 1: ?string} [user, error]
 */
function attempt_login(string $username, string $password): array
{
    $user = q_one('SELECT * FROM users WHERE username = ?', [$username]);

    if ($user && !$user['is_active']) {
        return [null, 'บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อฝ่ายทะเบียน'];
    }

    if ($user && $user['password_hash'] && password_verify($password, $user['password_hash'])) {
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        return [$user, null];
    }

    if (config('auth.mode') === 'hybrid') {
        try {
            $api = UniversityApiFactory::make();
            $profile = $api->authenticate($username, $password);
            if ($profile) {
                $user = UniversitySync::upsertFromProfile($profile);
                if ($user) {
                    return [$user, null];
                }
            }
        } catch (Throwable $ex) {
            error_log('University API login failed: ' . $ex->getMessage());
            return [null, 'ไม่สามารถเชื่อมต่อระบบของมหาวิทยาลัยได้ในขณะนี้'];
        }
    }

    return [null, 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'];
}

const ALL_ROLES = ['student', 'teacher', 'registrar', 'admin'];
const STAFF_ROLES = ['teacher', 'registrar', 'admin'];  // ดูข้อมูลนักศึกษาได้ทุกคน
const OFFICE_ROLES = ['registrar', 'admin'];            // งานทะเบียน
const ADMIN_ROLES = ['admin'];                          // ตั้งค่าระบบ / API

function is_office(): bool
{
    return has_role(...OFFICE_ROLES);
}

/** บทบาทของบัญชีที่ผู้ใช้ปัจจุบันจัดการได้ (ฝ่ายทะเบียน: นักศึกษา/อาจารย์, admin: ทุกบทบาท) */
function manageable_roles(): array
{
    return match (current_user()['role'] ?? null) {
        'admin'     => ALL_ROLES,
        'registrar' => ['student', 'teacher'],
        default     => [],
    };
}

/** ตรวจว่าผู้ใช้ปัจจุบันดูข้อมูลของนักศึกษาคนนี้ได้หรือไม่ */
function can_view_student(int $studentUserId): bool
{
    $u = current_user();
    if (!$u) {
        return false;
    }
    return in_array($u['role'], STAFF_ROLES, true) || (int) $u['id'] === $studentUserId;
}
