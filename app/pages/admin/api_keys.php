<?php
/**
 * จัดการ API key สำหรับให้ระบบภายนอก (เช่น ระบบทะเบียนของมหาวิทยาลัย) ดึงข้อมูลชั่วโมง
 */
$newKey = null;
if (is_post()) {
    if (input('action') === 'create') {
        $name = mb_substr((string) input('name', ''), 0, 100);
        if ($name === '') {
            flash('danger', 'กรุณาตั้งชื่อ key');
            redirect('/admin/api-keys');
        }
        $newKey = 'sk_' . bin2hex(random_bytes(24));
        q('INSERT INTO api_keys (name, key_prefix, key_hash, created_by) VALUES (?, ?, ?, ?)', [$name, substr($newKey, 0, 10), hash('sha256', $newKey), user_id()]);
        audit('apikey.create', ['name' => $name]);
    } elseif (input('action') === 'revoke') {
        q('UPDATE api_keys SET is_active = 0 WHERE id = ?', [input_int('id')]);
        audit('apikey.revoke', ['id' => input_int('id')]);
        flash('success', 'ยกเลิก key แล้ว');
        redirect('/admin/api-keys');
    }
}
$keys = q_all('SELECT * FROM api_keys ORDER BY id DESC');
$base = config('app.url');

layout_start('API Keys');
page_header('API Keys', 'ให้ระบบภายนอกดึงข้อมูลชั่วโมงผ่าน REST API');
?>
<?php if ($newKey): ?>
    <div class="alert alert-success">
        <strong>สร้าง key สำเร็จ</strong> — คัดลอกเก็บไว้ตอนนี้ ระบบจะไม่แสดงอีก
        <div class="input-group mt-2"><input class="form-control font-monospace" value="<?= e($newKey) ?>" readonly id="newKey">
            <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('newKey').value)">คัดลอก</button></div>
    </div>
<?php endif; ?>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>ชื่อ</th><th>Key</th><th>ใช้ล่าสุด</th><th>สถานะ</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($keys as $k): ?>
                    <tr>
                        <td><?= e($k['name']) ?></td>
                        <td><code><?= e($k['key_prefix']) ?>…</code></td>
                        <td class="small"><?= thai_date($k['last_used_at'], true) ?></td>
                        <td><?= $k['is_active'] ? '<span class="badge text-bg-success">ใช้งาน</span>' : '<span class="badge text-bg-secondary">ยกเลิก</span>' ?></td>
                        <td class="text-end">
                            <?php if ($k['is_active']): ?>
                                <form method="post" onsubmit="return confirm('ยกเลิก key นี้?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $k['id'] ?>">
                                    <button name="action" value="revoke" class="btn btn-sm btn-outline-danger">ยกเลิก</button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$keys): ?><tr><td colspan="5" class="text-center text-muted py-3">ยังไม่มี key</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <form method="post" class="card card-body">
            <?= csrf_field() ?>
            <div class="input-group">
                <input name="name" class="form-control" placeholder="ชื่อ เช่น ระบบทะเบียน มทร.ศรีวิชัย" required>
                <button name="action" value="create" class="btn btn-primary">สร้าง API key</button>
            </div>
        </form>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header bg-white fw-semibold">วิธีใช้งาน</div>
            <div class="card-body small">
                <p>ส่ง key ใน header <code>X-API-Key</code> (หรือ <code>Authorization: Bearer ...</code>)</p>
                <pre class="bg-light p-2 rounded small">curl -H "X-API-Key: sk_..." \
  <?= e($base) ?>/api/v1/students/166404140001/summary</pre>
                <table class="table table-sm">
                    <tr><td><code>GET /api/v1/health</code></td><td>ตรวจสถานะ (ไม่ต้องใช้ key)</td></tr>
                    <tr><td><code>GET /api/v1/students</code></td><td>รายชื่อ + ชั่วโมง (?q, ?program, ?done, ?year, ?page)</td></tr>
                    <tr><td><code>GET /api/v1/students/{code}/summary</code></td><td>สรุปชั่วโมงรายคน</td></tr>
                    <tr><td><code>GET /api/v1/students/{code}/records</code></td><td>รายการที่บันทึกผลแล้ว</td></tr>
                    <tr><td><code>GET /api/v1/skills</code></td><td>รายชื่อทักษะ</td></tr>
                    <tr><td><code>POST /api/v1/students/sync</code></td><td>ระบบมหาวิทยาลัยส่งข้อมูลนักศึกษาเข้ามา (JSON)</td></tr>
                </table>
            </div>
        </div>
    </div>
</div>
<?php layout_end(); ?>
