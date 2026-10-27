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
            flash('success', 'บันทึกลายเซ็นเรียบร้อย พร้อมใช้งานแล้ว');
        }
    }
    redirect('/signature');
}

layout_start('ลายเซ็นของฉัน');
page_header('ลายเซ็นออนไลน์', $me['role'] === 'teacher'
    ? 'บันทึกลายเซ็นไว้ล่วงหน้า เพื่อใช้ลงช่อง "ลายมือชื่ออาจารย์ผู้ควบคุม" และช่องลงชื่อท้ายแบบบันทึกการฝึกทักษะ'
    : 'บันทึกลายเซ็นไว้ล่วงหน้า เพื่อใช้ลงชื่อท้ายแบบบันทึกการฝึกทักษะวิชาชีพ');
?>
<div class="row g-3">
    <div class="col-lg-7">
        <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#sig-draw" type="button"><i class="bi bi-pen me-1"></i>เซ็นในกรอบ</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#sig-upload" type="button"><i class="bi bi-upload me-1"></i>อัปโหลดรูป / PDF</button></li>
        </ul>
        <div class="tab-content">
            <div class="tab-pane fade show active" id="sig-draw">
                <form method="post" class="card border-top-0 rounded-top-0" data-signature-form>
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
            <div class="tab-pane fade" id="sig-upload">
                <form method="post" class="card border-top-0 rounded-top-0" data-signature-upload>
                    <?= csrf_field() ?>
                    <input type="hidden" name="signature_data" data-upload-output>
                    <div class="card-body">
                        <label class="form-label fw-semibold" for="sig-file">เลือกไฟล์ลายเซ็น (JPG, PNG หรือ PDF)</label>
                        <input type="file" id="sig-file" class="form-control" accept="image/png,image/jpeg,image/webp,application/pdf" data-upload-file>
                        <div class="form-text">แนะนำ: เซ็นบนกระดาษขาวด้วยปากกาสีเข้ม แล้วถ่ายรูปหรือสแกน · PDF ใช้หน้าที่เลือก</div>

                        <div data-upload-editor hidden>
                            <div class="d-flex flex-wrap gap-2 align-items-center mt-3 mb-2">
                                <span class="small text-muted me-auto">ลากกรอบให้ครอบเฉพาะลายเซ็น (ลากมุมเพื่อย่อ/ขยาย, ลากในกรอบเพื่อย้าย, เลื่อนล้อเมาส์เพื่อซูม)</span>
                                <label class="small" data-pdf-page-wrap hidden>หน้า PDF
                                    <select class="form-select form-select-sm d-inline-block w-auto" data-pdf-page></select>
                                </label>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-secondary" data-rotate="-90" title="หมุนซ้าย"><i class="bi bi-arrow-counterclockwise"></i></button>
                                    <button type="button" class="btn btn-outline-secondary" data-rotate="90" title="หมุนขวา"><i class="bi bi-arrow-clockwise"></i></button>
                                    <button type="button" class="btn btn-outline-secondary" data-crop-reset title="เริ่มใหม่"><i class="bi bi-arrow-repeat"></i></button>
                                </div>
                            </div>
                            <div class="sig-crop-area"><img alt="" data-upload-image></div>

                            <div class="row g-3 mt-1 align-items-center">
                                <div class="col-sm-7">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="sig-transparent" checked data-opt-transparent>
                                        <label class="form-check-label" for="sig-transparent">ลบพื้นหลังสีขาว (ให้โปร่งใส ไม่ทับเส้นในแบบฟอร์ม)</label>
                                    </div>
                                </div>
                                <div class="col-sm-5">
                                    <label class="form-label small mb-0" for="sig-threshold">ความเข้มที่ถือเป็นพื้นหลัง</label>
                                    <input type="range" class="form-range" id="sig-threshold" min="120" max="245" value="200" data-opt-threshold>
                                </div>
                            </div>

                            <div class="fw-semibold small mt-2 mb-1">ตัวอย่างบนแบบบันทึกการฝึกทักษะวิชาชีพ (ขนาดประมาณจริง)</div>
                            <div class="sig-mock">
                                <div class="sig-mock-cell">
                                    <div class="sig-mock-label">ช่อง "ลายมือชื่ออาจารย์ผู้ควบคุม"</div>
                                    <div class="sig-mock-box cell"><img alt="" data-preview></div>
                                </div>
                                <div class="sig-mock-cell">
                                    <div class="sig-mock-label">ท้ายแบบบันทึก</div>
                                    <div class="sig-mock-line">ลงชื่อ <span class="sig-mock-box line"><img alt="" data-preview></span></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                        <span class="small text-muted" data-upload-status></span>
                        <button class="btn btn-primary" data-upload-save disabled><i class="bi bi-save me-1"></i>บันทึกลายเซ็นนี้</button>
                    </div>
                </form>
            </div>
        </div>
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
            <i class="bi bi-info-circle me-1"></i>ทุกครั้งที่ลงชื่อ (ตอนบันทึกผล หรือช่องลงชื่อท้ายแบบบันทึก) เลือกได้ว่าจะใช้ <strong>ลายเซ็นออนไลน์</strong> หรือ <strong>พิมพ์ชื่อ</strong>
        </div>
    </div>
</div>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>
<?php layout_end(['js/signature.js', 'js/signature-upload.js']); ?>
