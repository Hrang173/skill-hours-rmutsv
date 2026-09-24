<?php
/**
 * ผู้ดูแลระบบ: แก้ไข / ลบ รายการเข้าร่วมกิจกรรม (ใช้แก้ข้อมูลที่บันทึกผิด)
 */
$id = input_int('id');
$p = q_one(
    "SELECT p.*, a.title, a.hours AS activity_hours, a.teacher_id, sk.name AS skill_name,
            CONCAT(t.prefix, t.first_name, ' ', t.last_name) AS teacher_name,
            s.student_code, u.prefix, u.first_name, u.last_name
       FROM participations p
       JOIN activities a ON a.id = p.activity_id
       JOIN skills sk ON sk.id = a.skill_id
       JOIN users t ON t.id = a.teacher_id
       JOIN users u ON u.id = p.student_id
       JOIN students s ON s.user_id = u.id
      WHERE p.id = ?",
    [$id]
);
if (!$p) {
    flash('danger', 'ไม่พบรายการ');
    redirect('/admin');
}
$back = '/students/view?id=' . $p['student_id'];

if (is_post()) {
    if (input('action') === 'delete') {
        q('DELETE FROM participations WHERE id = ?', [$id]);
        audit('admin.record_delete', ['id' => $id, 'student' => $p['student_code'], 'activity' => $p['title']]);
        flash('success', 'ลบรายการแล้ว');
        redirect($back);
    }
    $status = in_array(input('status'), ['pending', 'approved', 'rejected', 'cancelled', 'completed'], true) ? input('status') : $p['status'];
    $completed = $status === 'completed';
    $signMethod = in_array(input('sign_method'), ['online', 'name'], true) ? input('sign_method') : null;
    $data = [
        'status'        => $status,
        'hours_awarded' => $completed ? max(0, round((float) input('hours_awarded', 0), 1)) : null,
        'result'        => $completed ? (input('result') === 'fail' ? 'fail' : 'pass') : null,
        'remark'        => mb_substr((string) input('remark', ''), 0, 255) ?: null,
        'sign_method'   => $completed ? $signMethod : null,
        'signer_name'   => $completed ? (mb_substr((string) input('signer_name', ''), 0, 200) ?: null) : null,
    ];
    // ถ้าเปลี่ยนเป็นพิมพ์ชื่อ หรือยกเลิกผล ให้ล้างลายเซ็นเดิม
    $keepSig = $completed && $signMethod === 'online' && $p['signature_data'];
    q(
        'UPDATE participations SET status = ?, hours_awarded = ?, result = ?, remark = ?, sign_method = ?, signer_name = ?,
                signature_data = ' . ($keepSig ? 'signature_data' : 'NULL') . ',
                signed_at = ' . ($completed ? 'COALESCE(signed_at, NOW())' : 'NULL') . '
          WHERE id = ?',
        [...array_values($data), $id]
    );
    audit('admin.record_update', ['id' => $id, 'before' => array_intersect_key($p, $data), 'after' => $data]);
    flash('success', 'บันทึกการแก้ไขแล้ว');
    redirect($back);
}

layout_start('แก้ไขรายการ');
page_header('แก้ไขรายการเข้าร่วมกิจกรรม', $p['student_code'] . ' ' . full_name($p), '<a href="' . e($back) . '" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i>กลับ</a>');
?>
<div class="alert alert-warning small"><i class="bi bi-shield-exclamation me-1"></i>หน้านี้สำหรับผู้ดูแลระบบใช้แก้ไขข้อมูลที่บันทึกผิดพลาด ทุกการแก้ไขจะถูกเก็บในบันทึกการใช้งาน</div>
<form method="post" class="card" style="max-width:760px">
    <?= csrf_field() ?>
    <div class="card-body">
        <dl class="row small mb-3">
            <dt class="col-sm-3">กิจกรรม</dt><dd class="col-sm-9"><?= e($p['title']) ?> (<?= fmt_hours($p['activity_hours']) ?> ชม.)</dd>
            <dt class="col-sm-3">ทักษะ</dt><dd class="col-sm-9"><?= e($p['skill_name']) ?></dd>
            <dt class="col-sm-3">อาจารย์ผู้ควบคุม</dt><dd class="col-sm-9"><?= e($p['teacher_name']) ?></dd>
        </dl>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">สถานะ</label>
                <select name="status" class="form-select">
                    <?php foreach (['pending' => 'รออนุมัติ', 'approved' => 'อนุมัติแล้ว/รอบันทึกผล', 'completed' => 'บันทึกผลแล้ว', 'rejected' => 'ไม่อนุมัติ', 'cancelled' => 'ยกเลิก'] as $k => $l): ?>
                        <option value="<?= $k ?>" <?= $p['status'] === $k ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">ชั่วโมงที่ได้</label>
                <input type="number" name="hours_awarded" step="0.5" min="0" class="form-control" value="<?= e(fmt_hours($p['hours_awarded'] ?? $p['activity_hours'])) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">ผล</label>
                <select name="result" class="form-select">
                    <option value="pass" <?= $p['result'] !== 'fail' ? 'selected' : '' ?>>ผ่าน</option>
                    <option value="fail" <?= $p['result'] === 'fail' ? 'selected' : '' ?>>ไม่ผ่าน</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">วิธีลงลายมือชื่อ</label>
                <select name="sign_method" class="form-select">
                    <option value="online" <?= $p['sign_method'] === 'online' ? 'selected' : '' ?> <?= $p['signature_data'] ? '' : 'disabled' ?>>ลายเซ็นออนไลน์ (เดิม)</option>
                    <option value="name" <?= $p['sign_method'] !== 'online' ? 'selected' : '' ?>>พิมพ์ชื่อ</option>
                </select>
            </div>
            <div class="col-md-8">
                <label class="form-label">ชื่ออาจารย์ผู้ควบคุม</label>
                <input type="text" name="signer_name" class="form-control" maxlength="200" value="<?= e($p['signer_name'] ?? $p['teacher_name']) ?>">
            </div>
            <div class="col-12">
                <label class="form-label">หมายเหตุ</label>
                <input type="text" name="remark" class="form-control" maxlength="255" value="<?= e($p['remark']) ?>">
            </div>
            <?php if ($p['signature_data']): ?>
                <div class="col-12"><div class="signature-preview" style="max-width:260px"><img src="<?= e($p['signature_data']) ?>" alt="ลายเซ็น"></div></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-footer bg-white d-flex justify-content-between">
        <button name="action" value="delete" class="btn btn-outline-danger" formnovalidate onclick="return confirm('ลบรายการนี้ถาวร?')"><i class="bi bi-trash me-1"></i>ลบรายการ</button>
        <button name="action" value="save" class="btn btn-primary"><i class="bi bi-save me-1"></i>บันทึก</button>
    </div>
</form>
<?php layout_end(); ?>
