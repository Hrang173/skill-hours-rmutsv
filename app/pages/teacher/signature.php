<?php
$me = current_user();

if (is_post()) {
    if (input('action') === 'delete') {
        q('UPDATE users SET signature_data = NULL WHERE id = ?', [$me['id']]);
        audit('signature.delete');
        flash('success', 'ลบลายเซ็นที่บันทึกไว้แล้ว');
    } else {
        $sig = $_POST['signature_data'] ?? '';
        if (!valid_signature($sig)) {
            flash('danger', 'กรุณาเซ็นชื่อในช่องก่อนบันทึก');
        } else {
            q('UPDATE users SET signature_data = ? WHERE id = ?', [$sig, $me['id']]);
            audit('signature.save');
            flash('success', 'บันทึกลายเซ็นเรียบร้อย ใช้ได้ทันทีตอนบันทึกผลการฝึก');
        }
    }
    redirect('/teacher/signature');
}

layout_start('ลายเซ็นของฉัน');
page_header('ลายเซ็นออนไลน์', 'บันทึกลายเซ็นไว้ล่วงหน้า เพื่อใช้ลงช่อง "ลายมือชื่ออาจารย์ผู้ควบคุม" ในแบบบันทึกการฝึกทักษะ');
?>
<div class="row g-3">
    <div class="col-lg-7">
        <form method="post" class="card" data-signature-form>
            <?= csrf_field() ?>
            <div class="card-header bg-white fw-semibold">เซ็นชื่อในกรอบด้านล่าง (ใช้เมาส์ นิ้ว หรือปากกา)</div>
            <div class="card-body">
                <div class="signature-pad-wrap">
                    <canvas data-signature-pad width="600" height="220"></canvas>
                </div>
                <input type="hidden" name="signature_data" data-signature-input>
            </div>
            <div class="card-footer bg-white d-flex justify-content-between">
                <button type="button" class="btn btn-light" data-signature-clear><i class="bi bi-eraser me-1"></i>ล้าง</button>
                <button class="btn btn-primary"><i class="bi bi-save me-1"></i>บันทึกลายเซ็น</button>
            </div>
        </form>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header bg-white fw-semibold">ลายเซ็นปัจจุบัน</div>
            <div class="card-body text-center">
                <?php if ($me['signature_data']): ?>
                    <div class="signature-preview mb-2"><img src="<?= e($me['signature_data']) ?>" alt="ลายเซ็น"></div>
                    <div class="small text-muted mb-3">(<?= e(full_name($me)) ?>)</div>
                    <form method="post" onsubmit="return confirm('ลบลายเซ็นที่บันทึกไว้?')">
                        <?= csrf_field() ?>
                        <button name="action" value="delete" class="btn btn-sm btn-outline-danger">ลบลายเซ็น</button>
                    </form>
                <?php else: ?>
                    <p class="text-muted mb-0">ยังไม่มีลายเซ็นที่บันทึกไว้</p>
                <?php endif; ?>
            </div>
        </div>
        <div class="alert alert-light border small mt-3">
            <i class="bi bi-info-circle me-1"></i>ตอนบันทึกผลการฝึก สามารถเลือกได้ว่าจะใช้ <strong>ลายเซ็นออนไลน์</strong> หรือ <strong>กรอกชื่อ</strong> ลงในแบบบันทึก
        </div>
    </div>
</div>
<?php layout_end(['js/signature.js']); ?>
