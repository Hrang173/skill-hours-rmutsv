<?php
/**
 * ทดสอบระบบแบบผู้ใช้จริง (end-to-end) — เล่นทุกบทบาทผ่าน HTTP ตามลำดับการใช้งาน 1 ภาคการศึกษา
 * + ทดสอบ "พยายามทำผิด" (สิทธิ์, ข้ามสาขา, ข้อมูลไม่ถูกต้อง, CSRF, XSS, SQL injection)
 *
 * รัน (ในเครื่อง):  docker compose exec -T web php < tests/e2e.php
 * บนเซิร์ฟเวอร์:     bash deploy/e2e.sh   (สรุปผลเป็นภาษาอังกฤษ เพราะหน้าจอ console แสดงภาษาไทยไม่ได้)
 *
 * ข้อมูลทดสอบทั้งหมดขึ้นต้นด้วย E2E / e2e. และอยู่ใน 2 สาขาทดสอบ ("E2E สาขา A", "E2E สาขา B")
 * ลบทิ้งทั้งก่อนเริ่มและหลังจบ — ไม่แตะข้อมูลจริง
 * ** ห้ามรันบนเซิร์ฟเวอร์ที่มีผู้ใช้จริงระหว่างใช้งาน ** (สร้างบัญชีชั่วคราวและการแจ้งเตือน)
 */
declare(strict_types=1);

require '/var/www/html/app/bootstrap.php';

use App\Services\HoursService;
use App\Services\ProgressService;

const BASE = 'http://localhost';
const PW = 'e2e-Pass-123';
const MAJOR_A = 'E2E สาขา A';
const MAJOR_B = 'E2E สาขา B';
const CODES = ['s1' => '668499990001', 's2' => '668299990002', 's3' => '668499990003', 's4' => '668499990004'];

/* ------------------------------------------------------------ เครื่องมือ */

final class Browser
{
    public string $body = '';
    public int $status = 0;
    public string $url = '';
    private string $jar;

    public function __construct(public string $who)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'e2e');
    }

    public function get(string $path): self
    {
        return $this->send('GET', $path);
    }

    /** POST พร้อม CSRF ของหน้า $from (ไม่ระบุ = หน้าเดียวกับที่ส่ง) */
    public function post(string $path, array $data, ?string $from = null, bool $csrf = true): self
    {
        if ($csrf) {
            $data['_csrf'] = $this->get($from ?? $path)->csrf();
        }
        return $this->send('POST', $path, $data);
    }

    public function csrf(): string
    {
        preg_match('/name="csrf-token" content="([^"]+)"|name="_csrf" value="([^"]+)"/', $this->body, $m);
        return ($m[1] ?? '') ?: ($m[2] ?? '');
    }

    public function has(string $text): bool
    {
        return str_contains($this->body, $text);
    }

    public function path(): string
    {
        return (string) parse_url($this->url, PHP_URL_PATH);
    }

    private function send(string $method, string $path, array $data = []): self
    {
        $ch = curl_init(BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_TIMEOUT        => 20,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        $this->body = (string) curl_exec($ch);
        $this->status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $this->url = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        return $this;
    }
}

$results = [];
$section = '';
function section(string $name): void
{
    global $section;
    $section = $name;
    echo "\n## $name\n";
}
function check(string $name, bool $ok, string $detail = ''): void
{
    global $results, $section;
    $results[] = [$section, $name, $ok, $detail];
    // [ลำดับ] เป็นตัวเลข ASCII — ใช้อ้างอิงข้อที่ไม่ผ่านจากหน้าจอที่แสดงภาษาไทยไม่ได้
    echo ($ok ? '  ✅ ' : '  ❌ ') . '[' . count($results) . '] ' . $name . ($ok || $detail === '' ? '' : "  → $detail") . "\n";
}
function login(string $username, string $password = PW): Browser
{
    $b = new Browser($username);
    $b->post('/login', ['username' => $username, 'password' => $password], '/login');
    return $b;
}
function uid(string $username): int
{
    return (int) q_val('SELECT id FROM users WHERE username = ?', [$username]);
}
function flashText(Browser $b): string
{
    preg_match_all('/<div class="alert alert-(?:success|danger|warning|info)[^"]*"[^>]*>(.*?)<\/div>/su', $b->body, $m);
    return trim(preg_replace('/\s+/u', ' ', strip_tags(implode(' | ', $m[1]))));
}

function cleanup(): void
{
    $users = array_column(q_all("SELECT id FROM users WHERE username LIKE 'e2e.%' OR username IN ('" . implode("','", CODES) . "')"), 'id');
    $in = $users ? implode(',', array_map('intval', $users)) : '0';
    q("DELETE p FROM participations p JOIN activities a ON a.id = p.activity_id WHERE a.title LIKE 'E2E%' OR a.teacher_id IN ($in)");
    q("DELETE s FROM activity_sessions s JOIN activities a ON a.id = s.activity_id WHERE a.title LIKE 'E2E%' OR a.teacher_id IN ($in)");
    q("DELETE FROM activities WHERE title LIKE 'E2E%' OR teacher_id IN ($in)");
    q("DELETE FROM participations WHERE student_id IN ($in)");
    q("DELETE FROM student_certifications WHERE student_id IN ($in)");
    q("DELETE FROM notifications WHERE user_id IN ($in)");
    q("DELETE FROM audit_logs WHERE user_id IN ($in) OR detail LIKE '%e2e.%' OR detail LIKE '%E2E%'");
    q("DELETE FROM skills WHERE major_id IN (SELECT id FROM majors WHERE name LIKE 'E2E %') OR code LIKE 'E2E%'");
    q("DELETE FROM users WHERE id IN ($in)");
    q("DELETE FROM majors WHERE name LIKE 'E2E %'");
}

/* ------------------------------------------------------------ เตรียม */

cleanup();
$sem = current_semester();
if (!$sem) {
    exit("ไม่มีภาคการศึกษาปัจจุบัน — ตั้งที่เมนู ภาคการศึกษา ก่อน\n");
}
$year = (int) $sem['academic_year'];
$future = date('Y-m-d', strtotime('+30 days'));
q("INSERT INTO users (username, password_hash, role, first_name, last_name, must_change_password) VALUES ('e2e.reg', ?, 'registrar', 'E2E', 'ฝ่ายทะเบียน', 0)",
    [password_hash(PW, PASSWORD_DEFAULT)]);
echo "# ผลทดสอบ end-to-end (" . date('Y-m-d H:i') . ") · ภาคปัจจุบัน {$sem['term']}/$year\n";

try {
    /* ======================================================== 1. ฝ่ายทะเบียนเตรียมสาขา */
    section('1. ฝ่ายทะเบียนเตรียมสาขา/บุคลากร/ทักษะ');
    $reg = login('e2e.reg');
    $reg->get('/registrar');
    check('เข้าหน้างานทะเบียนครั้งแรก → พาไปหน้าเลือกสาขา', $reg->path() === '/registrar/majors', $reg->url);

    $reg->post('/registrar/majors', ['action' => 'save', 'id' => 0, 'name' => MAJOR_A, 'api_names' => "E2E-A-API",
        'required_4year' => 30, 'required_transfer' => 20, 'teacher_cap' => 10]);
    $reg->post('/registrar/majors', ['action' => 'save', 'id' => 0, 'name' => MAJOR_B, 'api_names' => '']);
    $mA = (int) q_val('SELECT id FROM majors WHERE name = ?', [MAJOR_A]);
    $mB = (int) q_val('SELECT id FROM majors WHERE name = ?', [MAJOR_B]);
    check('เพิ่มสาขาใหม่ 2 สาขา', $mA > 0 && $mB > 0);
    $reg->post('/registrar/majors', ['action' => 'save', 'id' => 0, 'name' => MAJOR_A]);
    check('เพิ่มสาขาชื่อซ้ำ → ถูกปฏิเสธ', $reg->has('มีสาขานี้อยู่แล้ว'), flashText($reg));

    $reg->post('/registrar/majors', ['action' => 'select', 'id' => $mA]);
    check('เลือกสาขา A → แถบด้านบนแสดงสาขา A', $reg->has(MAJOR_A));

    foreach (['e2e.t1' => 'หนึ่ง', 'e2e.t2' => 'สอง', 'e2e.head' => 'หัวหน้า'] as $u => $n) {
        $reg->post('/registrar/user/edit?role=teacher', ['role' => 'teacher', 'username' => $u, 'prefix' => 'อ.', 'first_name' => 'E2E' . $n,
            'last_name' => 'ทดสอบ', 'major_id' => $mA, 'password' => PW], '/registrar/user/edit?role=teacher');
    }
    $reg->post('/registrar/user/edit?role=teacher', ['role' => 'teacher', 'username' => 'e2e.tb', 'prefix' => 'อ.', 'first_name' => 'E2Eบี',
        'last_name' => 'ทดสอบ', 'major_id' => $mB, 'password' => PW], '/registrar/user/edit?role=teacher');
    check('สร้างอาจารย์ 4 ท่าน (A 3, B 1) ผ่านฟอร์ม', (int) q_val("SELECT COUNT(*) FROM users WHERE username LIKE 'e2e.t%' OR username = 'e2e.head'") === 4);
    check('อาจารย์ได้สาขาตามที่เลือก', (int) q_val("SELECT major_id FROM users WHERE username = 'e2e.tb'") === $mB);

    foreach (['s1' => [$mA, 'หนึ่ง'], 's2' => [$mA, 'สอง'], 's3' => [$mB, 'สาม']] as $k => [$mj, $n]) {
        $reg->post('/registrar/user/edit?role=student', ['role' => 'student', 'student_code' => CODES[$k], 'program_type' => $k === 's2' ? 'transfer' : '4year',
            'prefix' => 'นาย', 'first_name' => 'E2Eนศ' . $n, 'last_name' => 'ทดสอบ', 'major_id' => $mj, 'password' => PW], '/registrar/user/edit?role=student');
    }
    $s = array_map(fn($c) => q_one('SELECT * FROM students WHERE student_code = ?', [$c]), CODES);
    check('สร้างนักศึกษา 3 คนผ่านฟอร์ม', $s['s1'] && $s['s2'] && $s['s3']);
    check('ปีที่เข้าคำนวณจากรหัสหลักที่ 2-3 (668… → 2568)', (int) ($s['s1']['entry_year'] ?? 0) === 2568, (string) ($s['s1']['entry_year'] ?? 'null'));
    check('สาขาของนักศึกษา + ชื่อสาขาบนแบบบันทึก', (int) $s['s3']['major_id'] === $mB && $s['s3']['major'] === MAJOR_B);

    [$t1, $t2, $head, $tb] = array_map('uid', ['e2e.t1', 'e2e.t2', 'e2e.head', 'e2e.tb']);
    $skillUrl = "/registrar/skills?year=$year";
    foreach ([['E2EA1', $t1], ['E2EA2', $t2], ['E2EA3', $head]] as $i => [$code, $owner]) {
        $reg->post($skillUrl, ['id' => 0, 'code' => $code, 'name' => "E2E ทักษะ $code", 'owner_id' => $owner, 'sort_order' => $i + 1, 'is_active' => 1]);
    }
    check('เพิ่มทักษะ 3 ทักษะในสาขา A', (int) q_val('SELECT COUNT(*) FROM skills WHERE major_id = ? AND academic_year = ?', [$mA, $year]) === 3);
    $reg->post($skillUrl, ['id' => 0, 'code' => 'E2EA4', 'name' => 'E2E ซ้ำ', 'owner_id' => $t1, 'is_active' => 1]);
    check('อาจารย์ 1 ท่านรับผิดชอบ 2 ทักษะในปีเดียว → ถูกปฏิเสธ', $reg->has('รับผิดชอบทักษะ') && $reg->has('อยู่แล้ว'), flashText($reg));
    $reg->post($skillUrl, ['id' => 0, 'code' => 'E2EA5', 'name' => 'E2E ข้ามสาขา', 'owner_id' => $tb, 'is_active' => 1]);
    check('ตั้งอาจารย์สาขา B เป็นผู้รับผิดชอบทักษะสาขา A → ถูกปฏิเสธ', $reg->has('ต้องเป็นอาจารย์ของสาขา'), flashText($reg));

    $reg->post('/registrar/staff-positions', ['id' => $head, 'position' => 'head']);
    check('ตั้งหัวหน้าหลักสูตรสาขา A', q_val('SELECT head_of_major FROM users WHERE id = ?', [$head]) === MAJOR_A, flashText($reg));
    $reg->post('/registrar/staff-positions', ['id' => $tb, 'position' => 'head']);
    check('ตั้งอาจารย์สาขา B เป็นหัวหน้าสาขา A → ถูกปฏิเสธ', $reg->has('ต้องเป็นอาจารย์ของสาขานี้') || q_val('SELECT head_of_major FROM users WHERE id = ?', [$tb]) === null);

    /* ======================================================== 2. อาจารย์โพสต์กิจกรรม */
    section('2. อาจารย์สร้างกิจกรรม');
    $T1 = login('e2e.t1');
    $form = fn(array $x) => $x + ['semester_id' => $sem['id'], 'visibility' => 'public', 'hours' => 6,
        'sessions' => ['date' => [$future], 'start' => ['09:00'], 'end' => ['15:00'], 'detail' => ['']]];
    $T1->post('/teacher/activity/new', $form(['title' => 'E2E กิจกรรม A1', 'skill_id' => q_val("SELECT id FROM skills WHERE code = 'E2EA2'"),
        'register_deadline' => date('Y-m-d\T17:00', strtotime($future . ' -1 day'))]));
    parse_str((string) parse_url($T1->url, PHP_URL_QUERY), $qs);
    $a1 = (int) ($qs['id'] ?? 0);
    check('สร้างกิจกรรมแบบเปิดรับสมัคร', $a1 > 0, $T1->url . ' ' . flashText($T1));
    check('ส่ง skill_id ของคนอื่นมา → ระบบใช้ทักษะของตัวเองแทน', q_val('SELECT s.code FROM activities a JOIN skills s ON s.id = a.skill_id WHERE a.id = ?', [$a1]) === 'E2EA1');
    $T1->post('/teacher/activity/new', $form(['title' => 'E2E ปิดรับหลังเริ่ม', 'register_deadline' => date('Y-m-d\T10:00', strtotime($future))]));
    check('ปิดรับสมัครหลังเวลาเริ่มฝึก → ถูกปฏิเสธ', $T1->has('วันปิดรับสมัครต้องอยู่ก่อน'), flashText($T1));
    $T1->post('/teacher/activity/new', $form(['title' => 'E2E ชั่วโมงติดลบ', 'hours' => -5]));
    check('จำนวนชั่วโมงติดลบ → ถูกปฏิเสธ', $T1->has('จำนวนชั่วโมงต้องอยู่ระหว่าง'), flashText($T1));
    $T1->post('/teacher/activity/new', $form(['title' => 'E2E <script>alert(1)</script>', 'hours' => 1]));
    check('ชื่อกิจกรรมมี <script> → แสดงแบบ escape (กัน XSS)', $T1->has('&lt;script&gt;') && !$T1->has('<script>alert(1)'));

    /* ======================================================== 3. นักศึกษาขอเข้าร่วม */
    section('3. นักศึกษาขอเข้าร่วม / แยกสาขา');
    $S1 = login(CODES['s1']);
    $S1->get('/student/activities');
    check('นักศึกษาสาขา A เห็นกิจกรรม A1', $S1->has('E2E กิจกรรม A1'));
    $S1->post('/student/activities', ['action' => 'request', 'activity_id' => $a1]);
    check('ส่งคำขอเข้าร่วม', $S1->has('ส่งคำขอเข้าร่วม'), flashText($S1));
    $S3 = login(CODES['s3']);
    $S3->get('/student/activities');
    check('นักศึกษาสาขา B ไม่เห็นกิจกรรมสาขา A', !$S3->has('E2E กิจกรรม A1'));
    $S3->get("/student/activity?id=$a1");
    check('นักศึกษาสาขา B เปิดลิงก์กิจกรรมสาขา A ตรง ๆ → 404', $S3->status === 404, (string) $S3->status);
    $S3->post('/student/activities', ['action' => 'request', 'activity_id' => $a1]);
    check('นักศึกษาสาขา B ส่งคำขอเข้ากิจกรรมสาขา A → ถูกปฏิเสธ', $S3->has('ไม่พบกิจกรรม') && !q_val('SELECT 1 FROM participations WHERE activity_id = ? AND student_id = ?', [$a1, uid(CODES['s3'])]));
    $S2 = login(CODES['s2']);
    $S2->post('/student/activities', ['action' => 'request', 'activity_id' => $a1]);

    /* ======================================================== 4. อาจารย์อนุมัติ + บันทึกผล */
    section('4. อาจารย์อนุมัติ บันทึกผล มอบหมาย (เพดาน 10 ชม./อาจารย์ ของสาขา A)');
    $p1 = (int) q_val('SELECT id FROM participations WHERE activity_id = ? AND student_id = ?', [$a1, uid(CODES['s1'])]);
    $T2 = login('e2e.t2');
    $T2->post("/teacher/activity?id=$a1", ['action' => "approve:$p1"], "/teacher/activities");
    check('อาจารย์อื่นอนุมัติกิจกรรมที่ไม่ใช่ของตัวเอง → ไม่ได้', q_val('SELECT status FROM participations WHERE id = ?', [$p1]) === 'pending');
    $T1->post("/teacher/activity?id=$a1", ['action' => "approve:$p1"]);
    check('เจ้าของกิจกรรมอนุมัติ', q_val('SELECT status FROM participations WHERE id = ?', [$p1]) === 'approved', flashText($T1));
    $stage = fn(string $k) => ProgressService::forStudent(uid(CODES[$k]))['stage'] ?? '?';
    check('ขั้นตอน s1 = รออาจารย์บันทึกผล', $stage('s1') === 'wait_teacher', $stage('s1'));
    check('ขั้นตอน s2 (มีคำขอรออนุมัติ) = รออาจารย์', $stage('s2') === 'wait_teacher', $stage('s2'));
    $T1->post("/teacher/activity?id=$a1", ['action' => 'record', 'ids' => [$p1], 'result' => 'pass', 'sign_method' => 'name', 'signer_name' => 'E2E อาจารย์หนึ่ง']);
    check('บันทึกผล "ผ่าน" พร้อมพิมพ์ชื่อ', q_val('SELECT CONCAT(status, result, hours_awarded) FROM participations WHERE id = ?', [$p1]) === 'completedpass6.0', flashText($T1));

    $assign = function (Browser $t, string $title, float $hours, array $students) use ($sem, $future): int {
        $t->post('/teacher/assign', ['title' => $title, 'hours' => $hours, 'semester_id' => $sem['id'], 'student_ids' => $students,
            'sessions' => ['date' => [$future], 'start' => ['09:00'], 'end' => ['17:00'], 'detail' => ['']]]);
        $aid = (int) q_val('SELECT id FROM activities WHERE title = ?', [$title]);
        $pids = array_column(q_all("SELECT id FROM participations WHERE activity_id = ? AND status = 'approved'", [$aid]), 'id');
        $t->post("/teacher/activity?id=$aid", ['action' => 'record', 'ids' => $pids, 'result' => 'pass', 'sign_method' => 'name', 'signer_name' => 'E2E']);
        return $aid;
    };
    $sid1 = uid(CODES['s1']);
    $assign($T1, 'E2E มอบหมาย t1', 8, [$sid1, uid(CODES['s3'])]);
    check('มอบหมายนักศึกษาต่างสาขา → ไม่ถูกเพิ่ม', !q_val("SELECT 1 FROM participations p JOIN activities a ON a.id = p.activity_id WHERE a.title = 'E2E มอบหมาย t1' AND p.student_id = ?", [uid(CODES['s3'])]));
    $sum = HoursService::summary($sid1, '4year');
    check('เพดานต่ออาจารย์: ได้จริง 14 ชม. นับได้ 10 ชม. (ตั้งไว้ในสาขา A)', $sum['raw'] == 14 && $sum['earned'] == 10, "raw={$sum['raw']} earned={$sum['earned']}");
    check('เกณฑ์ของสาขา A = 30 ชม. (4 ปี)', $sum['required'] == 30, (string) $sum['required']);
    $assign($T2, 'E2E มอบหมาย t2', 12, [$sid1]);
    check('ยังไม่ครบ (20/30) → กำลังสะสมชั่วโมง', $stage('s1') === 'collecting', $stage('s1'));
    $H = login('e2e.head');
    $assign($H, 'E2E มอบหมาย head', 12, [$sid1]);
    check('ครบ 30/30 → รอหัวหน้าหลักสูตรเซ็นยืนยัน', $stage('s1') === 'wait_head', $stage('s1'));

    /* ======================================================== 5. ฝ่ายทะเบียนติดตาม + หัวหน้าเซ็น */
    section('5. ฝ่ายทะเบียนติดตามขั้นตอน + หัวหน้าหลักสูตรเซ็นยืนยัน');
    $reg->get('/registrar/progress?year=2568&stage=wait_head');
    check('หน้าติดตามขั้นตอน (ปี 68) แสดง s1 ในกลุ่มรอหัวหน้าเซ็น', $reg->has(CODES['s1']));
    $reg->get('/registrar/progress?q=' . CODES['s1']);
    check('ค้นรหัสเต็มบนหน้าแรก → พาไปหน้าปีที่เข้า (2568)', str_contains($reg->url, 'year=2568'), $reg->url);
    $T1->get('/teacher/certify');
    check('อาจารย์ที่ไม่ใช่หัวหน้าหลักสูตรเข้าหน้าเซ็นยืนยัน → ไม่ได้', $T1->path() !== '/teacher/certify', $T1->url);
    $slots = json_encode(['sign' => ['mode' => 'name', 'value' => 'E2E ลายมือหัวหน้า'], 'paren' => ['mode' => 'name', 'value' => 'E2E หัวหน้า'], 'pos' => ['mode' => 'none', 'value' => '']], JSON_UNESCAPED_UNICODE);
    $H->post('/teacher/certify', ['action' => 'certify', 'student_ids' => [uid(CODES['s2'])], 'slots' => $slots]);
    check('เซ็นยืนยันนักศึกษาที่ชั่วโมงยังไม่ครบ → ข้าม', !ProgressService::certification(uid(CODES['s2'])), flashText($H));
    $H->post('/teacher/certify', ['action' => 'certify', 'student_ids' => [$sid1], 'slots' => '{"sign":{"mode":"none","value":""}}']);
    check('เซ็นยืนยันโดยไม่ลงชื่อบรรทัด "ลงชื่อ" → ถูกปฏิเสธ', !ProgressService::certification($sid1), flashText($H));
    $H->post('/teacher/certify', ['action' => 'certify', 'student_ids' => [$sid1], 'slots' => $slots]);
    check('หัวหน้าหลักสูตรเซ็นยืนยัน s1', $stage('s1') === 'certified', flashText($H));
    $S1->get('/student');
    check('นักศึกษาเห็นว่าหัวหน้าหลักสูตรเซ็นยืนยันแล้ว', $S1->has('หัวหน้าหลักสูตรเซ็นยืนยันแล้ว'));
    $reg->get("/registrar/progress?year=2568&stage=certified");
    check('ฝ่ายทะเบียนเห็นสถานะเซ็นแล้ว + มีปุ่มพิมพ์', $reg->has(CODES['s1']) && $reg->has("print/form?student_id=$sid1"));
    $reg->get("/print/form?student_id=$sid1");
    check('แบบบันทึก (ฝ่ายทะเบียนพิมพ์) มีชื่อที่หัวหน้าเซ็น + ชื่อสาขา A', $reg->has('E2E ลายมือหัวหน้า') && $reg->has(MAJOR_A));
    check('แบบบันทึกมีรายการครบ 4 รายการ และผู้รับผิดชอบทักษะ', substr_count($reg->body, 'E2E ทักษะ') >= 4);

    /* ======================================================== 6. สิทธิ์ + ข้ามสาขา */
    section('6. สิทธิ์การเข้าถึง / ข้ามสาขา (พยายามทำผิด)');
    $S1->get('/registrar');
    check('นักศึกษาเปิดหน้าฝ่ายทะเบียน → 403', $S1->status === 403, (string) $S1->status);
    $S1->get('/teacher/activity/new');
    check('นักศึกษาเปิดหน้าสร้างกิจกรรม → 403', $S1->status === 403, (string) $S1->status);
    $S1->get('/students/view?id=' . uid(CODES['s2']));
    check('นักศึกษาเปิดข้อมูลนักศึกษาคนอื่น → 403', $S1->status === 403, (string) $S1->status);
    $S1->get('/print/form?student_id=' . uid(CODES['s2']));
    check('นักศึกษาพิมพ์แบบบันทึกของคนอื่น (แก้ student_id) → ได้ของตัวเอง', $S1->has(CODES['s1']) && !$S1->has(CODES['s2']));
    $T1->get('/students/view?id=' . uid(CODES['s3']));
    check('อาจารย์สาขา A เปิดนักศึกษาสาขา B → ไม่ได้', $T1->path() !== '/students/view');
    $T1->get('/ajax/students?q=66849999');
    check('ช่องค้นหานักศึกษาของอาจารย์ A ไม่มีนักศึกษา B', str_contains($T1->body, CODES['s1']) && !str_contains($T1->body, CODES['s3']), $T1->body);
    $T1->get('/teacher/students?q=66849999');
    check('หน้าค้นหานักศึกษาของอาจารย์ A ไม่มีนักศึกษา B', !$T1->has('<code>' . CODES['s3']));
    $T1->get('/admin/settings');
    check('อาจารย์เปิดหน้าตั้งค่าระบบ → 403', $T1->status === 403, (string) $T1->status);
    $reg->get('/admin/settings');
    check('ฝ่ายทะเบียนเปิดหน้าตั้งค่าระบบ (admin) → 403', $reg->status === 403, (string) $reg->status);
    $reg->post('/registrar/majors', ['action' => 'select', 'id' => $mB]);
    $reg->get('/registrar/users');
    check('ฝ่ายทะเบียนเลือกสาขา B → รายชื่อผู้ใช้ไม่มีคนสาขา A', $reg->has(CODES['s3']) && !$reg->has(CODES['s1']));
    $reg->get('/registrar/progress?year=2568');
    check('ฝ่ายทะเบียนเลือกสาขา B → หน้าติดตามขั้นตอนไม่มีนักศึกษาสาขา A', !$reg->has(CODES['s1']));

    /* ======================================================== 7. ความปลอดภัยอื่น ๆ */
    section('7. CSRF / SQL injection / เข้าสู่ระบบ');
    $T1->post('/teacher/activity/new', ['title' => 'E2E ไม่มี CSRF', 'hours' => 1], null, false);
    check('POST โดยไม่มี CSRF token → 403', $T1->status === 403 && !q_val("SELECT 1 FROM activities WHERE title = 'E2E ไม่มี CSRF'"), (string) $T1->status);
    $x = login("e2e.reg' OR '1'='1' -- ", 'x');
    $x->get('/registrar');
    check('SQL injection ที่ช่องชื่อผู้ใช้ → เข้าไม่ได้', $x->path() === '/login', $x->url);
    $x = login('e2e.t1', 'wrong-password');
    check('รหัสผ่านผิด → เข้าไม่ได้', $x->has('ไม่ถูกต้อง') || $x->path() === '/login');
    q("UPDATE users SET is_active = 0 WHERE username = 'e2e.tb'");
    $x = login('e2e.tb');
    $x->get('/teacher');
    check('บัญชีที่ถูกระงับ → เข้าไม่ได้', $x->path() === '/login', $x->url);

    /* ======================================================== 8. ยังไม่ระบุสาขา */
    section('8. ยังไม่ระบุสาขา → จับคู่จากชื่อที่ API ส่งมา');
    q("INSERT INTO users (username, password_hash, role, first_name, last_name, auth_source, must_change_password) VALUES (?, ?, 'student', 'E2Eไม่มีสาขา', 'ทดสอบ', 'local', 0)",
        [CODES['s4'], password_hash(PW, PASSWORD_DEFAULT)]);
    q("INSERT INTO students (user_id, student_code, program_type, major, major_id) VALUES (?, ?, '4year', 'E2E-A-API (แบบใหม่)', NULL)", [uid(CODES['s4']), CODES['s4']]);
    $S4 = login(CODES['s4']);
    $S4->get('/student/activities');
    check('นักศึกษาที่ยังไม่ระบุสาขา เห็นแถบเตือน + ไม่เห็นกิจกรรม', $S4->has('ยังไม่ระบุสาขาวิชา') && !$S4->has('E2E กิจกรรม A1'));
    $reg->get('/registrar/unassigned');
    check('ฝ่ายทะเบียนเห็นในหน้า "ยังไม่ระบุสาขา"', $reg->has(CODES['s4']) && $reg->has('E2E-A-API (แบบใหม่)'));
    $reg->post('/registrar/majors', ['action' => 'save', 'id' => $mA, 'name' => MAJOR_A, 'api_names' => "E2E-A-API\nE2E-A-API (แบบใหม่)",
        'required_4year' => 30, 'required_transfer' => 20, 'teacher_cap' => 10, 'is_active' => 1]);
    check('เพิ่มชื่อที่ API ส่งมาให้สาขา A → จับคู่ให้ทันที', (int) q_val('SELECT major_id FROM students WHERE student_code = ?', [CODES['s4']]) === $mA, flashText($reg));
    $S4->get('/student/activities');
    check('หลังได้สาขา → เห็นกิจกรรมสาขา A', $S4->has('E2E กิจกรรม A1'));
} catch (Throwable $e) {
    check('ไม่มี error ระหว่างทดสอบ', false, $e->getMessage() . ' @' . $e->getLine());
} finally {
    cleanup();
}

/* ------------------------------------------------------------ สรุป */
$pass = count(array_filter($results, fn($r) => $r[2]));
$fail = count($results) - $pass;
echo "\n---\nผ่าน $pass / " . count($results) . ($fail ? " · ไม่ผ่าน $fail" : ' ✅ ทั้งหมด') . "\n";
$failedNo = array_keys(array_filter(array_combine(range(1, count($results)), $results), fn($r) => !$r[2]));
echo 'E2E RESULT: PASS ' . $pass . ' / ' . count($results) . ($fail ? ' FAIL ' . implode(',', $failedNo) : ' ALL OK') . "\n";
echo "ข้อมูลทดสอบถูกลบแล้ว (เหลือ " . q_val("SELECT COUNT(*) FROM users WHERE username LIKE 'e2e.%'") . " บัญชี e2e)\n";
exit($fail ? 1 : 0);
