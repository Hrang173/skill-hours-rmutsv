<?php
declare(strict_types=1);

use App\Services\HoursService;
use App\Services\StudentQuery;
use App\Services\UniversitySync;

/**
 * REST API v1 — ให้ระบบภายนอก (เช่น ระบบทะเบียนของมหาวิทยาลัย) เชื่อมต่อ
 * ยืนยันตัวตนด้วย API key (สร้างได้ที่เมนู ผู้ดูแลระบบ → API Keys)
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$route = trim(substr($path, strlen('api')), '/'); // $path มาจาก public/index.php

function api_error(string $message, int $status): never
{
    json_response(['error' => ['status' => $status, 'message' => $message]], $status);
}

function api_auth(): array
{
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? '') : '');
    if (!$key && preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $m)) {
        $key = $m[1];
    }
    if (!$key) {
        api_error('Missing API key', 401);
    }
    $row = q_one('SELECT * FROM api_keys WHERE key_hash = ? AND is_active = 1', [hash('sha256', $key)]);
    if (!$row) {
        api_error('Invalid API key', 401);
    }
    q('UPDATE api_keys SET last_used_at = NOW() WHERE id = ?', [$row['id']]);
    return $row;
}

function api_student(string $code): array
{
    $st = q_one('SELECT u.id, u.prefix, u.first_name, u.last_name, u.email, s.* FROM students s JOIN users u ON u.id = s.user_id WHERE s.student_code = ?', [$code]);
    if (!$st) {
        api_error('Student not found', 404);
    }
    return $st;
}

function api_student_out(array $st): array
{
    return [
        'student_code' => $st['student_code'],
        'prefix'       => $st['prefix'],
        'first_name'   => $st['first_name'],
        'last_name'    => $st['last_name'],
        'full_name'    => full_name($st),
        'program_type' => $st['program_type'],
        'faculty'      => $st['faculty'] ?? null,
        'major'        => $st['major'],
        'entry_year'   => $st['entry_year'] ? (int) $st['entry_year'] : null,
    ];
}

// ---------------------------------------------------------------- routes
if ($route === 'v1/health' || $route === 'v1' || $route === '') {
    json_response(['status' => 'ok', 'app' => config('app.name'), 'version' => 'v1', 'time' => date(DATE_ATOM)]);
}

api_auth();

if ($method === 'GET' && $route === 'v1/skills') {
    $rows = q_all("SELECT sk.code, sk.name, CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS owner
                     FROM skills sk LEFT JOIN users u ON u.id = sk.owner_id WHERE sk.is_active = 1 ORDER BY sk.sort_order");
    json_response(['data' => array_map(fn($r) => ['code' => $r['code'], 'name' => $r['name'], 'owner' => $r['owner']], $rows)]);
}

if ($method === 'GET' && $route === 'v1/students') {
    ['from' => $from, 'params' => $params, 'required' => $required] = StudentQuery::build(StudentQuery::filtersFromRequest());
    $pg = paginate((int) q_val("SELECT COUNT(*) $from", $params), min(200, max(1, input_int('per_page', 50))));
    $rows = q_all('SELECT ' . StudentQuery::columns($required) . ", st.faculty $from ORDER BY st.student_code LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $params);
    json_response([
        'data' => array_map(fn($r) => api_student_out($r) + [
            'earned_hours'   => (float) $r['earned'],
            'required_hours' => (float) $r['required'],
            'completed'      => (float) $r['earned'] >= (float) $r['required'],
        ], $rows),
        'meta' => ['page' => $pg['page'], 'pages' => $pg['pages'], 'total' => $pg['total'], 'per_page' => $pg['per_page']],
    ]);
}

if ($method === 'GET' && preg_match('#^v1/students/([^/]+)/summary$#', $route, $m)) {
    $st = api_student(urldecode($m[1]));
    $sum = HoursService::summary((int) $st['id'], $st['program_type']);
    json_response(['data' => api_student_out($st) + [
        'required_hours'  => $sum['required'],
        'earned_hours'    => $sum['earned'],
        'raw_hours'       => $sum['raw'],
        'remaining_hours' => $sum['remaining'],
        'pending_hours'   => $sum['pending'],
        'completed'       => $sum['complete'],
        'max_hours_per_teacher' => $sum['cap'],
        'teachers'        => array_map(fn($t) => [
            'name' => $t['name'], 'hours' => (float) $t['hours'], 'counted_hours' => (float) $t['counted'],
        ], $sum['teachers']),
        'skills'          => array_map(fn($s) => [
            'code' => $s['code'], 'name' => $s['name'], 'hours' => (float) $s['hours'],
        ], $sum['skills']),
    ]]);
}

if ($method === 'GET' && preg_match('#^v1/students/([^/]+)/records$#', $route, $m)) {
    $st = api_student(urldecode($m[1]));
    $rows = HoursService::records((int) $st['id'], input_int('semester') ?: null);
    json_response(['data' => array_map(fn($r) => [
        'skill_code'   => $r['skill_code'],
        'skill_name'   => $r['skill_name'],
        'activity'     => $r['activity_title'],
        'owner'        => $r['owner_name'],
        'supervisor'   => $r['teacher_name'],
        'hours'        => (float) $r['hours_awarded'],
        'result'       => $r['result'],
        'sign_method'  => $r['sign_method'],
        'signer_name'  => $r['signer_name'],
        'signed_at'    => $r['signed_at'],
        'start_date'   => $r['first_date'],
        'end_date'     => $r['last_date'],
        'remark'       => $r['remark'],
    ], $rows)]);
}

// ระบบมหาวิทยาลัยส่งข้อมูลนักศึกษาเข้ามา (push) — รับได้ทั้ง object เดียวหรือ array
if ($method === 'POST' && $route === 'v1/students/sync') {
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body)) {
        api_error('Invalid JSON body', 422);
    }
    $items = array_is_list($body) ? $body : [$body];
    $ok = 0;
    $failed = [];
    foreach (array_slice($items, 0, 1000) as $i => $p) {
        $profile = [
            'role'         => 'student',
            'username'     => (string) ($p['student_code'] ?? ''),
            'student_code' => (string) ($p['student_code'] ?? ''),
            'prefix'       => (string) ($p['prefix'] ?? ''),
            'first_name'   => (string) ($p['first_name'] ?? ''),
            'last_name'    => (string) ($p['last_name'] ?? ''),
            'faculty'      => $p['faculty'] ?? null,
            'major'        => $p['major'] ?? null,
            'program_type' => config('university_api.program_type_map')[(string) ($p['program_type'] ?? '')] ?? null,
            'entry_year'   => isset($p['entry_year']) ? (int) $p['entry_year'] : null,
            'email'        => $p['email'] ?? null,
            'external_id'  => $p['external_id'] ?? ($p['student_code'] ?? null),
        ];
        if (UniversitySync::upsertFromProfile($profile)) {
            $ok++;
        } else {
            $failed[] = $i;
        }
    }
    audit('api.students_sync', ['ok' => $ok, 'failed' => count($failed)]);
    json_response(['data' => ['synced' => $ok, 'failed_indexes' => $failed]]);
}

api_error('Not found', 404);
