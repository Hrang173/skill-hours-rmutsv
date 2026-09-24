<?php
use App\Integrations\UniversityApi\UniversityApiFactory;
use App\Services\UniversitySync;

$api = UniversityApiFactory::make();
$ping = null;
$result = null;

if (is_post()) {
    $action = input('action');
    try {
        if ($action === 'ping') {
            $ping = $api->ping();
        } elseif ($action === 'lookup' || $action === 'import') {
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

layout_start('API มหาวิทยาลัย');
page_header('เชื่อมต่อ API มหาวิทยาลัย', 'มหาวิทยาลัยเทคโนโลยีราชมงคลศรีวิชัย (เตรียมไว้รองรับการเชื่อมต่อในอนาคต)');
?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold">สถานะการตั้งค่า</div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-5">Driver</dt><dd class="col-7"><?= e($api->name()) ?></dd>
                    <dt class="col-5">Base URL</dt><dd class="col-7 text-break"><?= e($cfg['base_url'] ?: '-') ?></dd>
                    <dt class="col-5">API Key</dt><dd class="col-7"><?= $cfg['api_key'] ? 'ตั้งค่าแล้ว' : '-' ?></dd>
                    <dt class="col-5">เข้าสู่ระบบ</dt><dd class="col-7"><?= config('auth.mode') === 'hybrid' ? 'hybrid (ในระบบ + มหาวิทยาลัย)' : 'local (ในระบบเท่านั้น)' ?></dd>
                    <dt class="col-5">Endpoints</dt><dd class="col-7"><?php foreach ($cfg['endpoints'] as $k => $ep): ?><div><code><?= e($k) ?></code>: <?= e($ep) ?></div><?php endforeach; ?></dd>
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
            <strong>ตั้งค่า</strong>: แก้ไฟล์ <code>.env</code> แล้วรัน <code>docker compose up -d</code><br>
            <code>UNI_API_DRIVER=rmutsv</code>, <code>UNI_API_BASE_URL</code>, <code>UNI_API_KEY</code>, <code>AUTH_MODE=hybrid</code><br>
            การจับคู่ชื่อฟิลด์ JSON แก้ได้ที่ <code>app/config.php</code> → <code>field_map</code>
        </div>
    </div>
    <div class="col-lg-7">
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
    </div>
</div>
<?php layout_end(); ?>
