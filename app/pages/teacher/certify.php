<?php
use App\Services\ProgressService;

/**
 * หัวหน้าหลักสูตรเซ็นยืนยันนักศึกษาที่เก็บชั่วโมงครบแล้ว (ขั้นตอนสุดท้าย)
 * - เฉพาะอาจารย์ที่เป็นหัวหน้าหลักสูตร และเฉพาะนักศึกษาสาขาของตัวเอง
 * - เซ็นบนตัวอย่างท้ายแบบบันทึก (ลงชื่อ / ( ) / หัวหน้าหลักสูตร) — เซ็นตรงบรรทัด, ใช้ลายเซ็นที่บันทึกไว้, เซ็นในกรอบ หรือพิมพ์ชื่อ
 * - เลือกหลายคนแล้วเซ็นครั้งเดียวได้ · ผลจะแสดงท้ายแบบบันทึกของนักศึกษาคนนั้น ไม่ว่าใครเป็นคนพิมพ์
 */
$me = current_user();
$major = $me['head_of_major'];
if (!$major) {
    flash('warning', 'หน้านี้สำหรับหัวหน้าหลักสูตรเท่านั้น (ฝ่ายทะเบียนเป็นผู้กำหนด)');
    redirect('/teacher');
}
$from = ProgressService::fromSql();
$stageSql = ProgressService::stageSql();

if (is_post()) {
    $ids = array_map('intval', (array) ($_POST['student_ids'] ?? []));
    if (input('action') === 'revoke') {
        $sid = input_int('student_id');
        q('DELETE c FROM student_certifications c JOIN students st ON st.user_id = c.student_id WHERE c.student_id = ? AND st.major = ?', [$sid, $major]);
        audit('certify.revoke', ['student_id' => $sid]);
        flash('success', 'ยกเลิกการเซ็นยืนยันแล้ว');
        redirect('/teacher/certify', ['tab' => 'done']);
    }
    [$slots, $err] = ProgressService::slotsFromRequest();
    if (!$ids) {
        flash('danger', 'กรุณาเลือกนักศึกษาอย่างน้อย 1 คน');
    } elseif ($err) {
        flash('danger', $err);
    } else {
        // เซ็นได้เฉพาะนักศึกษาสาขาตัวเองที่ชั่วโมงครบและยังไม่ได้เซ็น
        $in = implode(',', array_fill(0, count($ids), '?'));
        $ok = q_all("SELECT st.user_id, COALESCE(h.earned, 0) AS earned $from
                      WHERE st.user_id IN ($in) AND st.major = ? AND $stageSql = 'wait_head'", [...$ids, $major]);
        foreach ($ok as $r) {
            q('INSERT INTO student_certifications (student_id, head_id, major, hours, sign_method, signature_data, signer_name, slots) VALUES (?,?,?,?,?,?,?,?)',
                [$r['user_id'], $me['id'], $major, $r['earned'],
                 $slots['sign']['mode'] === 'signature' ? 'online' : 'name',
                 $slots['sign']['mode'] === 'signature' ? $slots['sign']['value'] : null,
                 full_name($me), json_encode($slots, JSON_UNESCAPED_UNICODE)]);
            notify((int) $r['user_id'], 'หัวหน้าหลักสูตรเซ็นยืนยันการฝึกทักษะวิชาชีพของคุณแล้ว', 'พิมพ์แบบบันทึกได้ที่เมนู "พิมพ์แบบบันทึกทักษะ"', '/print/form');
        }
        audit('certify.sign', ['students' => array_column($ok, 'user_id'), 'modes' => array_map(fn($s) => $s['mode'], $slots)]);
        flash('success', 'เซ็นยืนยันแล้ว ' . count($ok) . ' คน' . (count($ok) < count($ids) ? ' (ข้ามคนที่ชั่วโมงยังไม่ครบหรือเซ็นไปแล้ว)' : ''));
    }
    redirect('/teacher/certify');
}

$tab = input('tab') === 'done' ? 'done' : 'wait';
$rows = q_all('SELECT ' . ProgressService::columns() . " $from
                WHERE st.major = ? AND st.status = 'studying' AND $stageSql IN ('wait_head', 'certified')
                ORDER BY entry_year_calc, st.student_code", [$major]);
$waiting = array_values(array_filter($rows, fn($r) => $r['stage'] === 'wait_head'));
$done = array_values(array_filter($rows, fn($r) => $r['stage'] === 'certified'));
$list = $tab === 'done' ? $done : $waiting;

layout_start('เซ็นยืนยัน (หัวหน้าหลักสูตร)');
page_header('เซ็นยืนยันนักศึกษา', 'หัวหน้าหลักสูตรสาขา' . $major . ' · นักศึกษาที่เก็บชั่วโมงครบตามเกณฑ์แล้ว');
?>
<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab === 'wait' ? 'active' : '' ?>" href="?tab=wait">รอเซ็นยืนยัน <span class="badge text-bg-info"><?= count($waiting) ?></span></a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'done' ? 'active' : '' ?>" href="?tab=done">เซ็นแล้ว <span class="badge text-bg-success"><?= count($done) ?></span></a></li>
</ul>

<?php if ($tab === 'wait'): ?>
<form method="post" class="row g-3">
    <?= csrf_field() ?>
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-white fw-semibold">1. เลือกนักศึกษา</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr>
                        <th style="width:36px"><input type="checkbox" class="form-check-input" data-check-all="cert"></th>
                        <th>รหัส</th><th>ชื่อ-สกุล</th><th>แผน</th><th class="text-end">ชั่วโมง</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($list as $s): ?>
                        <tr>
                            <td><input type="checkbox" class="form-check-input" name="student_ids[]" value="<?= $s['id'] ?>" data-check-group="cert"></td>
                            <td><code><?= e($s['student_code']) ?></code></td>
                            <td><a href="/students/view?id=<?= $s['id'] ?>"><?= e(full_name($s)) ?></a>
                                <?php if ($s['pending'] || $s['unrecorded']): ?><div class="small text-warning">มีรายการค้างที่อาจารย์ <?= (int) $s['pending'] + (int) $s['unrecorded'] ?> รายการ</div><?php endif; ?></td>
                            <td class="small"><?= program_label($s['program_type']) ?></td>
                            <td class="text-end"><?= fmt_hours($s['earned']) ?> / <?= fmt_hours($s['required']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$list): ?><tr><td colspan="5" class="text-center text-muted py-4">ไม่มีนักศึกษารอเซ็นยืนยัน</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-white fw-semibold">2. เซ็นท้ายแบบบันทึก</div>
            <div class="card-body">
                <?php head_sign_editor($me, $major); ?>
            </div>
            <div class="card-footer bg-white text-end">
                <button class="btn btn-primary" name="action" value="certify" <?= $list ? '' : 'disabled' ?>><i class="bi bi-patch-check me-1"></i>เซ็นยืนยันนักศึกษาที่เลือก</button>
            </div>
        </div>
    </div>
</form>
<?php else: ?>
<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>รหัส</th><th>ชื่อ-สกุล</th><th class="text-end">ชั่วโมง</th><th>เซ็นโดย</th><th>วันที่</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($list as $s): ?>
                <tr>
                    <td><code><?= e($s['student_code']) ?></code></td>
                    <td><a href="/students/view?id=<?= $s['id'] ?>"><?= e(full_name($s)) ?></a></td>
                    <td class="text-end"><?= fmt_hours($s['earned']) ?></td>
                    <td class="small"><?= e($s['certified_by']) ?></td>
                    <td class="small"><?= thai_date($s['certified_at']) ?></td>
                    <td class="text-end text-nowrap">
                        <a href="/print/form?student_id=<?= $s['id'] ?>" class="btn btn-sm btn-light" target="_blank"><i class="bi bi-printer"></i></a>
                        <form method="post" class="d-inline" onsubmit="return confirm('ยกเลิกการเซ็นยืนยันของนักศึกษาคนนี้?')">
                            <?= csrf_field() ?><input type="hidden" name="student_id" value="<?= $s['id'] ?>">
                            <button name="action" value="revoke" class="btn btn-sm btn-light text-danger" title="ยกเลิกการเซ็น"><i class="bi bi-x-circle"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$list): ?><tr><td colspan="6" class="text-center text-muted py-4">ยังไม่มี</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php layout_end(['js/signature.js', 'js/head-sign.js']); ?>
