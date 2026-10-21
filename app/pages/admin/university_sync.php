<?php
use App\Integrations\UniversityApi\RmutsvApiClient;
use App\Integrations\UniversityApi\UniversityApiFactory;
use App\Services\UniversitySync;

$api = UniversityApiFactory::make();
$ping = null;
$result = null;
$test = null;

if (is_post()) {
    $action = input('action');
    try {
        if ($action === 'ping') {
            $ping = $api->ping();
        } elseif ($action === 'test_login') {
            // ทดสอบเข้าสู่ระบบกับ API มหาวิทยาลัย — แสดงผลเท่านั้น ไม่สร้าง/แก้บัญชี และไม่เก็บรหัสผ่าน
            $username = (string) input('test_username', '');
            $profile = $api->authenticate($username, (string) ($_POST['test_password'] ?? ''));
            $test = [
                'username' => $username,
                'profile'  => $profile,
                'raw'      => method_exists($api, 'lastResponse') ? $api->lastResponse() : null,
                'local'    => $profile ? UniversitySync::findLocalUser($profile) : null,
            ];
            audit('university.test_login', ['username' => $username, 'ok' => (bool) $profile]);
        } elseif (($action === 'lookup' || $action === 'import') && $api->supportsLookup()) {
            $codes = array_filter(array_map('trim', preg_split('/[\s,]+/', (string) input('codes', ''))));
            $result = [];
            foreach (array_slice($codes, 0, 200) as $code) {
                if ($action === 'import') {
                    $u = UniversitySync::syncStudent($code);
                    $result[] = ['code' => $code, 'ok' => (bool) $u, 'name' => $u ? full_name($u) : null];
                } else {
                    $p = $api->getStudent($code);
                    $result[] = ['code' => $code, 'ok' => (bool) $p, 'name' => $p ? full_name($p) : null, 'program' => $p['program_type'] ?? null];
                }
            }
        }
    } catch (Throwable $ex) {
        flash('danger', 'เกิดข้อผิดพลาด: ' . $ex->getMessage());
    }
}

$cfg = config('university_api');
$statusText = ['ok' => 'สำเร็จ', 'fail' => 'ไม่สำเร็จ', 'password' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง', 'weakpassword' => 'รหัสผ่านไม่ผ่านนโยบายของมหาวิทยาลัย', 'json' => 'รูปแบบคำขอไม่ถูกต้อง'];

layout_start('API มหาวิทยาลัย');
page_header('เชื่อมต่อ API มหาวิทยาลัย', 'มหาวิทยาลัยเทคโนโลยีราชมงคลศรีวิชัย — เข้าสู่ระบบด้วยบัญชี RMUTSV');
?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold">สถานะการตั้งค่า</div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-5">Driver</dt><dd class="col-7"><?= e($api->name()) ?></dd>
                    <dt class="col-5">เข้าสู่ระบบ</dt><dd class="col-7"><?= config('auth.mode') === 'hybrid'
                        ? '<span class="text-success">hybrid</span> — บัญชีในระบบ หรือบัญชี RMUTSV'
                        : '<span class="text-warning">local</span> — บัญชีในระบบเท่านั้น (ตั้ง <code>AUTH_MODE=hybrid</code> เพื่อเปิด)' ?></dd>
                    <dt class="col-5">Endpoint เข้าสู่ระบบ</dt><dd class="col-7"><code><?= e($cfg['endpoints']['auth']) ?></code></dd>
                    <dt class="col-5">นักศึกษาใหม่</dt><dd class="col-7">สร้างบัญชีอัตโนมัติเมื่อเข้าสู่ระบบครั้งแรก<?= $cfg['allowed_faccodes'] ? ' (เฉพาะคณะรหัส ' . e(implode(', ', $cfg['allowed_faccodes'])) . ')' : '' ?></dd>
                    <dt class="col-5">อาจารย์/บุคลากร</dt><dd class="col-7">ต้องมีบัญชีในระบบก่อน (ชื่อผู้ใช้หรืออีเมลตรงกับ RMUTSV)</dd>
                    <dt class="col-5">ค่า type นักศึกษา</dt><dd class="col-7"><code><?= e(implode(', ', $cfg['student_types'])) ?></code></dd>
                </dl>
            </div>
            <div class="card-footer bg-white">
                <form method="post"><?= csrf_field() ?><button name="action" value="ping" class="btn btn-outline-primary btn-sm"><i class="bi bi-activity me-1"></i>ทดสอบการเชื่อมต่อ</button></form>
                <?php if ($ping): ?>
                    <div class="alert alert-<?= $ping['ok'] ? 'success' : 'danger' ?> small mt-2 mb-0"><?= e($ping['message']) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="alert alert-light border small">
            <strong>ตั้งค่า</strong> ในไฟล์ <code>.env</code> แล้วรัน <code>docker compose up -d</code><br>
            <code>UNI_API_DRIVER=rmutsv</code> · <code>AUTH_MODE=hybrid</code><br>
            ระบบ <strong>ไม่เก็บ</strong> รหัสผ่าน, เลขประจำตัวประชาชน (<code>cid</code>) และ <code>token</code> จาก API
        </div>
    </div>

    <div class="col-lg-7">
        <form method="post" class="card mb-3" autocomplete="off">
            <?= csrf_field() ?>
            <div class="card-header bg-white fw-semibold"><i class="bi bi-person-check me-1"></i>ทดสอบเข้าสู่ระบบด้วยบัญชี RMUTSV</div>
            <div class="card-body">
                <p class="small text-muted mb-2">ดูว่า API ส่งข้อมูลอะไรกลับมา และระบบจะจับคู่เป็นนักศึกษาหรืออาจารย์ — <strong>ไม่สร้างหรือแก้บัญชีใดๆ</strong></p>
                <div class="row g-2">
                    <div class="col-md-6"><input type="text" name="test_username" class="form-control" placeholder="ชื่อผู้ใช้ RMUTSV" value="<?= e($test['username'] ?? '') ?>" required></div>
                    <div class="col-md-6"><input type="password" name="test_password" class="form-control" placeholder="รหัสผ่าน" required autocomplete="new-password"></div>
                </div>
            </div>
            <div class="card-footer bg-white text-end">
                <button name="action" value="test_login" class="btn btn-primary"><i class="bi bi-play me-1"></i>ทดสอบ</button>
            </div>
        </form>

        <?php if ($test): ?>
            <div class="card mb-3">
                <div class="card-header bg-white fw-semibold">
                    ผลการทดสอบ:
                    <?php $st = $test['raw']['status'] ?? ''; ?>
                    <span class="badge text-bg-<?= $test['profile'] ? 'success' : 'danger' ?>"><?= e($statusText[$st] ?? ($st ?: 'ไม่มีคำตอบ')) ?></span>
                </div>
                <?php if ($test['profile']):
                    $p = $test['profile'];
                    $local = $test['local']; ?>
                    <div class="card-body small">
                        <dl class="row mb-0">
                            <dt class="col-sm-4">ระบบจะถือว่าเป็น</dt>
                            <dd class="col-sm-8"><strong><?= $p['role'] === 'student' ? 'นักศึกษา' : 'อาจารย์/บุคลากร' ?></strong> <span class="text-muted">(type = "<?= e($p['raw_type']) ?>")</span>
                                <?php if ($p['role'] === 'student'): ?>
                                    <div>แผนการเรียน: <strong><?= $p['program_type'] ? program_label($p['program_type']) : 'ไม่ทราบ (ตั้งเป็น 4 ปี)' ?></strong> <span class="text-muted">(จากรหัส <?= e($p['student_code']) ?>)</span></div>
                                <?php endif; ?></dd>
                            <dt class="col-sm-4">ชื่อ-สกุล</dt><dd class="col-sm-8"><?= e(full_name($p)) ?> <span class="text-muted">(คำนำหน้า: "<?= e($p['prefix']) ?>")</span></dd>
                            <dt class="col-sm-4">คณะ / สาขา</dt><dd class="col-sm-8"><?= e($p['faculty'] ?? '-') ?> / <?= e($p['major'] ?? '-') ?></dd>
                            <dt class="col-sm-4">บัญชีในระบบนี้</dt>
                            <dd class="col-sm-8">
                                <?php if ($local): ?>
                                    พบบัญชี <code><?= e($local['username']) ?></code> (<?= role_label($local['role']) ?>) — เข้าสู่ระบบได้
                                <?php elseif ($p['role'] === 'student'): ?>
                                    ยังไม่มี — <span class="text-success">จะสร้างบัญชีนักศึกษาให้อัตโนมัติเมื่อเข้าสู่ระบบครั้งแรก</span>
                                <?php else: ?>
                                    <span class="text-danger">ยังไม่มี — ต้องสร้างบัญชีอาจารย์ชื่อผู้ใช้ <code><?= e($p['username']) ?></code> ก่อน</span>
                                    <a href="/registrar/user/edit?role=teacher" class="ms-1">สร้างบัญชี</a>
                                <?php endif; ?>
                            </dd>
                        </dl>
                    </div>
                <?php endif; ?>
                <?php if ($test['raw']): ?>
                    <details class="border-top">
                        <summary class="px-3 py-2 small">ข้อมูลดิบจาก API (ซ่อน cid และ token)</summary>
                        <table class="table table-sm small mb-0">
                            <?php foreach ($test['raw'] as $k => $v): ?>
                                <tr><td class="ps-3" style="width:35%"><code><?= e($k) ?></code></td><td><?= e(is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)) ?></td></tr>
                            <?php endforeach; ?>
                        </table>
                    </details>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($api->supportsLookup()): ?>
            <form method="post" class="card">
                <?= csrf_field() ?>
                <div class="card-header bg-white fw-semibold">ดึงข้อมูลนักศึกษาจากมหาวิทยาลัย</div>
                <div class="card-body">
                    <label class="form-label">รหัสนักศึกษา (หลายคนคั่นด้วยช่องว่าง, จุลภาค หรือขึ้นบรรทัดใหม่)</label>
                    <textarea name="codes" class="form-control font-monospace" rows="4" placeholder="166404140005&#10;166404140006"><?= e(input('codes', '')) ?></textarea>
                    <?php if ($cfg['driver'] === 'mock'): ?><div class="form-text">โหมด mock: ลอง 166404140005, 166404140006, 167404150003</div><?php endif; ?>
                </div>
                <div class="card-footer bg-white d-flex gap-2 justify-content-end">
                    <button name="action" value="lookup" class="btn btn-outline-primary">ตรวจสอบข้อมูล</button>
                    <button name="action" value="import" class="btn btn-primary">นำเข้า / อัปเดตในระบบ</button>
                </div>
            </form>
            <?php if ($result !== null): ?>
                <div class="card mt-3">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>รหัส</th><th>ผล</th><th>ชื่อ</th></tr></thead>
                        <tbody>
                        <?php foreach ($result as $r): ?>
                            <tr>
                                <td><?= e($r['code']) ?></td>
                                <td><?= $r['ok'] ? '<span class="badge text-bg-success">พบ</span>' : '<span class="badge text-bg-danger">ไม่พบ</span>' ?></td>
                                <td><?= e($r['name'] ?? '-') ?><?= !empty($r['program']) ? ' <span class="small text-muted">(' . program_label($r['program']) . ')</span>' : '' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="alert alert-light border small">
                <i class="bi bi-info-circle me-1"></i>API ของมหาวิทยาลัยตอนนี้มีเฉพาะการเข้าสู่ระบบ (ไม่มีการค้นหานักศึกษาด้วยรหัส)
                นักศึกษาจะถูกเพิ่มเข้าระบบอัตโนมัติเมื่อเข้าสู่ระบบครั้งแรก หรือนำเข้าเองจากไฟล์ <a href="/registrar/import">CSV</a>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php layout_end(); ?>
