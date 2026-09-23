<?php
use App\Services\ActivityService;

$me = current_user();
$id = input_int('id');
$act = ActivityService::find($id);
if (!$act || (int) $act['teacher_id'] !== (int) $me['id']) {
    flash('danger', 'ไม่พบกิจกรรม หรือคุณไม่ใช่เจ้าของกิจกรรมนี้');
    redirect('/teacher/activities');
}

if (is_post()) {
    $action = (string) input('action', '');
    $single = null;
    if (str_contains($action, ':')) {
        [$action, $single] = explode(':', $action, 2);
        $single = (int) $single;
    }
    $ids = $single ? [$single] : array_map('intval', (array) ($_POST['ids'] ?? []));

    switch ($action) {
        case 'approve':
        case 'reject':
            $newStatus = $action === 'approve' ? 'approved' : 'rejected';
            $note = mb_substr((string) input('teacher_note', ''), 0, 500) ?: null;
            $n = 0;
            foreach ($ids as $pid) {
                $p = q_one("SELECT * FROM participations WHERE id = ? AND activity_id = ? AND status = 'pending'", [$pid, $id]);
                if (!$p) continue;
                if ($action === 'approve' && $act['capacity'] !== null && (int) $act['joined_count'] + $n >= (int) $act['capacity']) {
                    flash('warning', 'จำนวนผู้เข้าร่วมเต็มตามที่กำหนดแล้ว (' . $act['capacity'] . ' คน)');
                    break;
                }
                q('UPDATE participations SET status = ?, teacher_note = COALESCE(?, teacher_note) WHERE id = ?', [$newStatus, $note, $pid]);
                notify(
                    (int) $p['student_id'],
                    $action === 'approve' ? 'คำขอเข้าร่วมได้รับการอนุมัติ' : 'คำขอเข้าร่วมไม่ได้รับการอนุมัติ',
                    '"' . $act['title'] . '"' . ($note ? ' — ' . $note : ''),
                    '/student/activity?id=' . $id
                );
                $n++;
            }
            audit('participation.' . $action, ['activity_id' => $id, 'count' => $n]);
            if ($n) flash('success', ($action === 'approve' ? 'อนุมัติ ' : 'ปฏิเสธ ') . $n . ' รายการ');
            break;

        case 'record':
            if (!$ids) {
                flash('warning', 'กรุณาเลือกนักศึกษาที่ต้องการบันทึกผล');
                break;
            }
            [$sig, $err] = signature_from_request($me);
            if ($err) {
                flash('danger', $err);
                break;
            }
            $result = input('result') === 'fail' ? 'fail' : 'pass';
            $n = ActivityService::recordResults($act, $ids, $sig + [
                'result' => $result,
                'hours'  => array_map('floatval', (array) ($_POST['hours'] ?? [])),
                'remark' => mb_substr((string) input('remark', ''), 0, 255),
            ]);
            audit('participation.record', ['activity_id' => $id, 'count' => $n, 'result' => $result, 'sign_method' => $sig['sign_method']]);
            flash('success', "บันทึกผลและลงลายมือชื่อ $n รายการเรียบร้อย");
            break;

        case 'reset':
            q("UPDATE participations SET status = 'approved', result = NULL, hours_awarded = NULL, remark = NULL, sign_method = NULL, signature_data = NULL, signer_name = NULL, signed_by = NULL, signed_at = NULL WHERE id = ? AND activity_id = ?", [$single, $id]);
            audit('participation.reset', ['participation_id' => $single]);
            flash('success', 'ยกเลิกผลการบันทึกแล้ว สามารถบันทึกใหม่ได้');
            break;

        case 'remove':
            q("UPDATE participations SET status = 'cancelled' WHERE id = ? AND activity_id = ? AND status IN ('approved','pending')", [$single, $id]);
            audit('participation.remove', ['participation_id' => $single]);
            flash('success', 'นำนักศึกษาออกจากกิจกรรมแล้ว');
            break;

        case 'add_students':
            $n = ActivityService::assignStudents($act, (array) ($_POST['student_ids'] ?? []), mb_substr((string) input('assign_note', ''), 0, 500) ?: null);
            audit('activity.assign', ['activity_id' => $id, 'count' => $n]);
            flash($n ? 'success' : 'warning', $n ? "เพิ่ม/มอบหมายนักศึกษา $n คน" : 'ไม่มีนักศึกษาที่เพิ่มใหม่ (อาจอยู่ในกิจกรรมแล้ว)');
            break;

        case 'status':
            $st = (string) input('status');
            if (in_array($st, ['open', 'closed', 'completed', 'cancelled'], true)) {
                q('UPDATE activities SET status = ? WHERE id = ?', [$st, $id]);
                audit('activity.status', ['id' => $id, 'status' => $st]);
                flash('success', 'เปลี่ยนสถานะกิจกรรมแล้ว');
            }
            break;

        case 'delete':
            if ((int) q_val("SELECT COUNT(*) FROM participations WHERE activity_id = ? AND status = 'completed'", [$id]) > 0) {
                flash('danger', 'ลบไม่ได้ เพราะมีการบันทึกผลแล้ว (เปลี่ยนสถานะเป็น "ยกเลิก" แทน)');
                break;
            }
            q('DELETE FROM activities WHERE id = ?', [$id]);
            audit('activity.delete', ['id' => $id, 'title' => $act['title']]);
            flash('success', 'ลบกิจกรรมแล้ว');
            redirect('/teacher/activities');
    }
    redirect('/teacher/activity', ['id' => $id]);
}

$sessions = ActivityService::sessions($id);
$parts = q_all(
    "SELECT p.*, s.student_code, s.program_type, u.prefix, u.first_name, u.last_name
       FROM participations p
       JOIN users u ON u.id = p.student_id
       JOIN students s ON s.user_id = u.id
      WHERE p.activity_id = ?
      ORDER BY s.student_code",
    [$id]
);
$group = ['pending' => [], 'active' => [], 'other' => []];
foreach ($parts as $p) {
    $key = $p['status'] === 'pending' ? 'pending' : (in_array($p['status'], ['approved', 'completed'], true) ? 'active' : 'other');
    $group[$key][] = $p;
}

layout_start($act['title']);
page_header(
    $act['title'],
    $act['skill_name'] . ($act['term'] ? ' · ภาค ' . $act['term'] . '/' . $act['academic_year'] : ''),
    '<a href="/teacher/activity/edit?id=' . $id . '" class="btn btn-outline-primary"><i class="bi bi-pencil me-1"></i>แก้ไข</a>
     <button onclick="window.print()" class="btn btn-outline-secondary"><i class="bi bi-printer me-1"></i>พิมพ์รายชื่อ</button>'
);
?>
<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-3">สถานะ</dt><dd class="col-sm-9"><?= activity_status_badge($act['status']) ?>
                        <?= $act['visibility'] === 'assigned' ? '<span class="badge text-bg-primary">มอบหมายเฉพาะคน</span>' : '<span class="badge text-bg-light border">เปิดให้ขอเข้าร่วม</span>' ?></dd>
                    <dt class="col-sm-3">จำนวนชั่วโมง</dt><dd class="col-sm-9"><?= fmt_hours($act['hours']) ?> ชม.</dd>
                    <dt class="col-sm-3">ผู้เข้าร่วม</dt><dd class="col-sm-9"><?= $act['joined_count'] ?><?= $act['capacity'] !== null ? ' / ' . $act['capacity'] : '' ?> คน</dd>
                    <dt class="col-sm-3">สถานที่</dt><dd class="col-sm-9"><?= e($act['location'] ?: '-') ?></dd>
                    <dt class="col-sm-3">วันเวลา</dt><dd class="col-sm-9"><?= sessions_list($sessions) ?></dd>
                    <?php if ($act['register_deadline']): ?><dt class="col-sm-3">ปิดรับสมัคร</dt><dd class="col-sm-9"><?= thai_date($act['register_deadline'], true) ?></dd><?php endif; ?>
                    <dt class="col-sm-3">รายละเอียด</dt><dd class="col-sm-9"><?= nl2br(e($act['description'] ?: '-')) ?></dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-lg-4 d-print-none">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">จัดการกิจกรรม</div>
            <div class="card-body">
                <form method="post" class="d-grid gap-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="status">
                    <?php if ($act['status'] === 'open'): ?>
                        <button name="status" value="closed" class="btn btn-outline-warning"><i class="bi bi-lock me-1"></i>ปิดรับสมัคร</button>
                    <?php else: ?>
                        <button name="status" value="open" class="btn btn-outline-success"><i class="bi bi-unlock me-1"></i>เปิดรับสมัครอีกครั้ง</button>
                    <?php endif; ?>
                    <?php if ($act['status'] !== 'completed'): ?>
                        <button name="status" value="completed" class="btn btn-outline-primary"><i class="bi bi-flag me-1"></i>ทำเครื่องหมายว่าเสร็จสิ้น</button>
                    <?php endif; ?>
                    <?php if ($act['status'] !== 'cancelled'): ?>
                        <button name="status" value="cancelled" class="btn btn-outline-secondary" onclick="return confirm('ยืนยันยกเลิกกิจกรรม?')"><i class="bi bi-x-circle me-1"></i>ยกเลิกกิจกรรม</button>
                    <?php endif; ?>
                </form>
                <form method="post" class="d-grid mt-2" onsubmit="return confirm('ลบกิจกรรมนี้ถาวร?')">
                    <?= csrf_field() ?>
                    <button name="action" value="delete" class="btn btn-link text-danger btn-sm">ลบกิจกรรม</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($group['pending']): ?>
<form method="post" class="card mb-3 border-warning d-print-none">
    <?= csrf_field() ?>
    <div class="card-header bg-warning-subtle fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-inbox me-1"></i>คำขอรออนุมัติ (<?= count($group['pending']) ?>)</span>
        <div class="d-flex gap-2">
            <button name="action" value="approve" class="btn btn-sm btn-success">อนุมัติที่เลือก</button>
            <button name="action" value="reject" class="btn btn-sm btn-outline-danger">ปฏิเสธที่เลือก</button>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th style="width:2rem"><input type="checkbox" class="form-check-input" data-check-all="pending"></th><th>รหัส</th><th>ชื่อ-สกุล</th><th>แผน</th><th>ข้อความ</th><th>วันที่ขอ</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($group['pending'] as $p): ?>
                <tr>
                    <td><input type="checkbox" class="form-check-input" name="ids[]" value="<?= $p['id'] ?>" data-check-group="pending"></td>
                    <td><?= e($p['student_code']) ?></td>
                    <td><a href="/students/view?id=<?= $p['student_id'] ?>"><?= e(full_name($p)) ?></a></td>
                    <td><?= program_label($p['program_type']) ?></td>
                    <td class="small fst-italic"><?= e($p['request_note'] ?? '') ?></td>
                    <td class="small"><?= thai_date($p['created_at'], true) ?></td>
                    <td class="text-nowrap">
                        <button name="action" value="approve:<?= $p['id'] ?>" class="btn btn-sm btn-success" title="อนุมัติ"><i class="bi bi-check-lg"></i></button>
                        <button name="action" value="reject:<?= $p['id'] ?>" class="btn btn-sm btn-outline-danger" title="ปฏิเสธ"><i class="bi bi-x-lg"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white">
        <input type="text" name="teacher_note" class="form-control form-control-sm" maxlength="500" placeholder="ข้อความถึงนักศึกษา (ไม่บังคับ) เช่น ให้เตรียมโน้ตบุ๊กมาด้วย">
    </div>
</form>
<?php endif; ?>

<form method="post" class="card mb-3" id="participantsForm">
    <?= csrf_field() ?>
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-people me-1"></i>ผู้เข้าร่วม (<?= count($group['active']) ?>)</span>
        <button type="button" class="btn btn-sm btn-primary d-print-none" data-bs-toggle="modal" data-bs-target="#recordModal" <?= $group['active'] ? '' : 'disabled' ?>>
            <i class="bi bi-pencil-square me-1"></i>บันทึกผล + ลงลายมือชื่อ (ที่เลือก)
        </button>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:2rem" class="d-print-none"><input type="checkbox" class="form-check-input" data-check-all="active"></th>
                    <th>รหัส</th><th>ชื่อ-สกุล</th><th style="width:7rem">ชั่วโมง</th><th>ผล</th><th>ลายมือชื่อ</th><th class="d-print-none"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($group['active'] as $p): ?>
                <tr>
                    <td class="d-print-none"><input type="checkbox" class="form-check-input" name="ids[]" value="<?= $p['id'] ?>" data-check-group="active" <?= $p['status'] === 'approved' ? 'data-unrecorded' : '' ?>></td>
                    <td><?= e($p['student_code']) ?></td>
                    <td>
                        <a href="/students/view?id=<?= $p['student_id'] ?>"><?= e(full_name($p)) ?></a>
                        <?= $p['source'] === 'assigned' ? '<span class="badge text-bg-primary ms-1">มอบหมาย</span>' : '' ?>
                    </td>
                    <td><input type="number" name="hours[<?= $p['id'] ?>]" class="form-control form-control-sm" step="0.5" min="0" max="999" value="<?= e(fmt_hours($p['hours_awarded'] ?? $act['hours'])) ?>"></td>
                    <td><?= participation_badge($p) ?><?php if ($p['remark']): ?><div class="small text-muted"><?= e($p['remark']) ?></div><?php endif; ?></td>
                    <td>
                        <?php if ($p['sign_method'] === 'online' && $p['signature_data']): ?>
                            <img src="<?= e($p['signature_data']) ?>" alt="ลายเซ็น" class="sig-thumb">
                        <?php elseif ($p['sign_method'] === 'name'): ?>
                            <span class="small"><?= e($p['signer_name']) ?></span>
                        <?php else: ?>
                            <span class="text-muted small">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap d-print-none">
                        <a href="/print/form?student_id=<?= $p['student_id'] ?>" class="btn btn-sm btn-light" title="พิมพ์แบบบันทึก" target="_blank"><i class="bi bi-printer"></i></a>
                        <?php if ($p['status'] === 'completed'): ?>
                            <button name="action" value="reset:<?= $p['id'] ?>" class="btn btn-sm btn-light" title="ยกเลิกผลเพื่อบันทึกใหม่" onclick="return confirm('ยกเลิกผลที่บันทึกไว้?')"><i class="bi bi-arrow-counterclockwise"></i></button>
                        <?php else: ?>
                            <button name="action" value="remove:<?= $p['id'] ?>" class="btn btn-sm btn-light text-danger" title="นำออก" onclick="return confirm('นำนักศึกษาออกจากกิจกรรม?')"><i class="bi bi-person-dash"></i></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$group['active']): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">ยังไม่มีผู้เข้าร่วม</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Modal บันทึกผล -->
    <div class="modal fade" id="recordModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">บันทึกผลการฝึกทักษะ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info small py-2" data-selected-count></div>
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">ผลการฝึกทักษะ</label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="result" id="r_pass" value="pass" checked>
                                <label class="btn btn-outline-success" for="r_pass"><i class="bi bi-check-circle me-1"></i>ผ่าน</label>
                                <input type="radio" class="btn-check" name="result" id="r_fail" value="fail">
                                <label class="btn btn-outline-danger" for="r_fail"><i class="bi bi-x-circle me-1"></i>ไม่ผ่าน</label>
                            </div>
                            <label class="form-label fw-semibold mt-3">หมายเหตุ (แสดงในแบบบันทึก)</label>
                            <input type="text" name="remark" class="form-control" maxlength="255">
                            <div class="form-text">จำนวนชั่วโมงแก้ได้รายคนในตาราง</div>
                        </div>
                        <div class="col-md-7">
                            <?php signature_chooser($me); ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">ปิด</button>
                    <button name="action" value="record" class="btn btn-primary"><i class="bi bi-save me-1"></i>บันทึกผล</button>
                </div>
            </div>
        </div>
    </div>
</form>

<form method="post" class="card mb-3 d-print-none">
    <?= csrf_field() ?>
    <div class="card-header bg-white fw-semibold"><i class="bi bi-person-plus me-1"></i>เพิ่ม / มอบหมายนักศึกษาเข้ากิจกรรมนี้</div>
    <div class="card-body">
        <?php student_picker(); ?>
        <input type="text" name="assign_note" class="form-control mt-2" maxlength="500" placeholder="ข้อความถึงนักศึกษา (ไม่บังคับ)">
    </div>
    <div class="card-footer bg-white text-end">
        <button name="action" value="add_students" class="btn btn-primary"><i class="bi bi-person-check me-1"></i>มอบหมาย</button>
    </div>
</form>

<?php if ($group['other']): ?>
<details class="card d-print-none">
    <summary class="card-header bg-white">ไม่อนุมัติ / ยกเลิก (<?= count($group['other']) ?>)</summary>
    <ul class="list-group list-group-flush">
        <?php foreach ($group['other'] as $p): ?>
            <li class="list-group-item small d-flex justify-content-between">
                <span><?= e($p['student_code'] . ' ' . full_name($p)) ?></span><?= participation_badge($p) ?>
            </li>
        <?php endforeach; ?>
    </ul>
</details>
<?php endif; ?>
<?php layout_end(['js/signature.js']); ?>
