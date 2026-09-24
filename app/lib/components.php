<?php
declare(strict_types=1);

/* ============================================================
 *  ชิ้นส่วน UI ที่ใช้ซ้ำหลายหน้า
 * ============================================================ */

/** ตารางแก้ไขวัน/เวลาของกิจกรรม (เพิ่ม/ลบแถวได้ด้วย JS ใน app.js) */
function sessions_editor(array $sessions = []): void
{
    if (!$sessions) {
        $sessions = [['session_date' => '', 'start_time' => '09:00', 'end_time' => '16:00', 'detail' => '']];
    }
    ?>
    <div class="sessions-editor">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-2">
                <thead class="table-light">
                    <tr><th style="min-width:10rem">วันที่</th><th style="min-width:7rem">เวลาเริ่ม</th><th style="min-width:7rem">เวลาสิ้นสุด</th><th>รายละเอียดวันนั้น</th><th></th></tr>
                </thead>
                <tbody data-sessions-body>
                <?php foreach ($sessions as $s): ?>
                    <tr>
                        <td><input type="date" name="sessions[date][]" class="form-control form-control-sm" value="<?= e($s['session_date']) ?>"></td>
                        <td><input type="time" name="sessions[start][]" class="form-control form-control-sm" value="<?= e(time_short($s['start_time'])) ?>"></td>
                        <td><input type="time" name="sessions[end][]" class="form-control form-control-sm" value="<?= e(time_short($s['end_time'])) ?>"></td>
                        <td><input type="text" name="sessions[detail][]" class="form-control form-control-sm" maxlength="255" value="<?= e($s['detail']) ?>" placeholder="เช่น ภาคทฤษฎี / ลงพื้นที่"></td>
                        <td><button type="button" class="btn btn-sm btn-outline-danger" data-remove-session title="ลบ"><i class="bi bi-x-lg"></i></button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary" data-add-session><i class="bi bi-plus-lg me-1"></i>เพิ่มวัน</button>
        <span class="small text-muted ms-2" data-sessions-hours></span>
    </div>
    <?php
}

/**
 * ส่วนเลือกวิธีลงลายมือชื่ออาจารย์ผู้ควบคุม: ลายเซ็นออนไลน์ หรือ กรอกชื่อ
 * ค่าที่ส่งไป: sign_method, signature_data, signer_name, save_signature
 */
function signature_chooser(array $teacher): void
{
    $saved = $teacher['signature_data'] ?? null;
    $name = full_name($teacher);
    ?>
    <div class="signature-chooser" data-signature-chooser>
        <label class="form-label fw-semibold">ลายมือชื่ออาจารย์ผู้ควบคุม</label>
        <div class="btn-group w-100 mb-3" role="group">
            <input type="radio" class="btn-check" name="sign_method" id="sm_online" value="online" checked>
            <label class="btn btn-outline-primary" for="sm_online"><i class="bi bi-pen me-1"></i>ลายเซ็นออนไลน์</label>
            <input type="radio" class="btn-check" name="sign_method" id="sm_name" value="name">
            <label class="btn btn-outline-primary" for="sm_name"><i class="bi bi-fonts me-1"></i>กรอกชื่อ</label>
        </div>

        <div data-sign-online>
            <?php if ($saved): ?>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="radio" name="sig_source" id="sig_saved" value="saved" checked>
                    <label class="form-check-label" for="sig_saved">ใช้ลายเซ็นที่บันทึกไว้</label>
                </div>
                <div class="signature-preview mb-2" data-sig-saved-preview><img src="<?= e($saved) ?>" alt="ลายเซ็น"></div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="radio" name="sig_source" id="sig_draw" value="draw">
                    <label class="form-check-label" for="sig_draw">เซ็นใหม่</label>
                </div>
            <?php else: ?>
                <input type="hidden" name="sig_source" value="draw">
            <?php endif; ?>
            <div data-sig-draw <?= $saved ? 'hidden' : '' ?>>
                <div class="signature-pad-wrap">
                    <canvas data-signature-pad width="500" height="180"></canvas>
                </div>
                <div class="d-flex justify-content-between mt-1">
                    <button type="button" class="btn btn-sm btn-light" data-signature-clear><i class="bi bi-eraser me-1"></i>ล้าง</button>
                    <div class="form-check small">
                        <input class="form-check-input" type="checkbox" name="save_signature" value="1" id="save_sig">
                        <label class="form-check-label" for="save_sig">บันทึกเป็นลายเซ็นประจำตัว</label>
                    </div>
                </div>
            </div>
            <input type="hidden" name="signature_data" data-signature-input value="<?= e($saved) ?>">
            <input type="hidden" data-saved-signature value="<?= e($saved) ?>">
        </div>

        <div data-sign-name hidden>
            <input type="text" name="signer_name" class="form-control" value="<?= e($name) ?>" maxlength="200" placeholder="ชื่อ-สกุล อาจารย์ผู้ควบคุม">
            <div class="form-text">ชื่อนี้จะแสดงในช่อง "ลายมือชื่ออาจารย์ผู้ควบคุม" ของแบบบันทึก</div>
        </div>
    </div>
    <?php
}

/**
 * อ่านค่าลายเซ็นจากฟอร์ม signature_chooser แล้วตรวจสอบ
 * @return array{0: ?array, 1: ?string} [data, error]
 */
function signature_from_request(array $teacher): array
{
    $method = input('sign_method') === 'name' ? 'name' : 'online';
    if ($method === 'name') {
        $name = mb_substr((string) input('signer_name', ''), 0, 200);
        if ($name === '') {
            return [null, 'กรุณากรอกชื่ออาจารย์ผู้ควบคุม'];
        }
        return [['sign_method' => 'name', 'signature_data' => null, 'signer_name' => $name], null];
    }

    $sig = input('sig_source') === 'saved' ? ($teacher['signature_data'] ?? null) : ($_POST['signature_data'] ?? null);
    if (!valid_signature($sig)) {
        return [null, 'กรุณาเซ็นชื่อในช่องลายเซ็น'];
    }
    if (input('save_signature') === '1' && input('sig_source') !== 'saved') {
        q('UPDATE users SET signature_data = ? WHERE id = ?', [$sig, $teacher['id']]);
    }
    return [['sign_method' => 'online', 'signature_data' => $sig, 'signer_name' => full_name($teacher)], null];
}

/**
 * ตารางชั่วโมงแยกตามอาจารย์ผู้ควบคุม (อาจารย์ 1 ท่าน นับได้สูงสุด 25 ชม.)
 * @param array $sum ผลจาก HoursService::summary()
 */
function teacher_hours_card(array $sum): void
{
    $cap = $sum['cap'];
    ?>
    <div class="card h-100">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-person-badge me-1"></i>ชั่วโมงแยกตามอาจารย์ผู้ควบคุม</div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr><th>อาจารย์</th><th class="text-end">ผ่านแล้ว</th><th style="width:40%">นับได้<?= $cap !== null ? ' (สูงสุด ' . fmt_hours($cap) . ' ชม./ท่าน)' : '' ?></th></tr>
                </thead>
                <tbody>
                <?php foreach ($sum['teachers'] as $t): ?>
                    <tr>
                        <td><?= e($t['name']) ?> <div class="small text-muted"><?= $t['activity_count'] ?> กิจกรรม</div></td>
                        <td class="text-end"><?= fmt_hours($t['hours']) ?></td>
                        <td>
                            <div class="d-flex justify-content-between small">
                                <strong><?= fmt_hours($t['counted']) ?><?= $cap !== null ? ' / ' . fmt_hours($cap) : '' ?> ชม.</strong>
                                <?php if ($t['over']): ?><span class="text-danger" title="ส่วนที่เกินไม่นับรวม">เกิน <?= fmt_hours($t['hours'] - $cap) ?> ชม. (ไม่นับ)</span>
                                <?php elseif ($cap !== null && $t['counted'] >= $cap): ?><span class="text-success">ครบเพดาน</span><?php endif; ?>
                            </div>
                            <?php if ($cap !== null): ?><?= progress_bar((float) $t['counted'], $cap) ?><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$sum['teachers']): ?>
                    <tr><td colspan="3" class="text-muted small text-center py-3">ยังไม่มีชั่วโมงที่บันทึกผล</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($cap !== null): ?>
            <div class="card-footer bg-white small text-muted">* นักศึกษา 1 คน เก็บชั่วโมงจากอาจารย์ 1 ท่านได้สูงสุด <?= fmt_hours($cap) ?> ชม. ส่วนที่เกินจะไม่นับรวม</div>
        <?php endif; ?>
    </div>
    <?php
}

/** ช่องค้นหา + เลือกนักศึกษาหลายคน (ใช้ /ajax/students) */
function student_picker(array $preselected = []): void
{
    ?>
    <div class="student-picker" data-student-picker>
        <div class="input-group mb-2">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" class="form-control" placeholder="พิมพ์รหัสนักศึกษา หรือ ชื่อ-สกุล แล้วเลือกจากรายการ" data-picker-input autocomplete="off">
        </div>
        <div class="list-group picker-results mb-2" data-picker-results></div>
        <div data-picker-selected class="d-flex flex-wrap gap-2">
            <?php foreach ($preselected as $s): ?>
                <span class="badge text-bg-primary picker-chip" data-id="<?= (int) $s['id'] ?>">
                    <?= e($s['student_code'] . ' ' . full_name($s)) ?>
                    <input type="hidden" name="student_ids[]" value="<?= (int) $s['id'] ?>">
                    <button type="button" class="btn-close btn-close-white ms-1" data-picker-remove></button>
                </span>
            <?php endforeach; ?>
        </div>
        <div class="small text-muted mt-1" data-picker-empty <?= $preselected ? 'hidden' : '' ?>>ยังไม่ได้เลือกนักศึกษา</div>
    </div>
    <?php
}
