<?php
/**
 * นักศึกษา/อาจารย์ที่ยังไม่ระบุสาขา (เข้าด้วย e-Passport แต่ชื่อสาขาไม่ตรงกับสาขาในระบบ)
 * ฝ่ายทะเบียนเลือกสาขาให้ทีละคน หรือติ๊กหลายคนแล้วกำหนดพร้อมกัน
 */
$majors = majors();

if (is_post()) {
    $majorId = input_int('major_id');
    $ids = array_map('intval', (array) ($_POST['user_ids'] ?? []));
    if (!isset($majors[$majorId]) || !$ids) {
        flash('danger', 'กรุณาเลือกสาขาและรายชื่ออย่างน้อย 1 คน');
        redirect('/registrar/unassigned');
    }
    $n = 0;
    foreach ($ids as $uid) {
        $role = q_val('SELECT role FROM users WHERE id = ?', [$uid]);
        if ($role === 'student') {
            set_student_major($uid, $majorId);
            $n++;
        } elseif ($role === 'teacher') {
            q('UPDATE users SET major_id = ? WHERE id = ? AND major_id IS NULL', [$majorId, $uid]);
            $n++;
        } else {
            continue;
        }
        notify($uid, 'ฝ่ายทะเบียนกำหนดสาขาให้คุณแล้ว', 'สาขา' . $majors[$majorId]['name'], '/');
    }
    audit('major.assign', ['major_id' => $majorId, 'users' => $ids]);
    flash('success', "กำหนดสาขา{$majors[$majorId]['name']} ให้ $n คนแล้ว");
    redirect('/registrar/unassigned');
}

$students = q_all("SELECT u.id, u.username, u.prefix, u.first_name, u.last_name, u.created_at, s.student_code, s.major, s.program_type
                     FROM students s JOIN users u ON u.id = s.user_id WHERE s.major_id IS NULL ORDER BY s.student_code");
$teachers = q_all("SELECT id, username, prefix, first_name, last_name, created_at FROM users
                    WHERE role = 'teacher' AND major_id IS NULL AND is_active = 1 ORDER BY first_name");

$majorSelect = '<select name="major_id" class="form-select form-select-sm w-auto" required><option value="">— เลือกสาขา —</option>'
    . implode('', array_map(fn($m) => '<option value="' . $m['id'] . '">' . e($m['name']) . '</option>', $majors)) . '</select>';

layout_start('ยังไม่ระบุสาขา');
page_header('ยังไม่ระบุสาขา', 'บัญชีที่ระบบยังไม่รู้สาขา — เข้าระบบได้แต่ยังไม่เห็นกิจกรรม/นักศึกษา จนกว่าจะกำหนดสาขา',
    '<a href="/registrar/majors" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i>เลือกสาขา</a>');
?>
<?php foreach (['student' => ['นักศึกษา', $students], 'teacher' => ['อาจารย์', $teachers]] as $type => [$label, $rows]): ?>
<form method="post" class="card mb-4">
    <?= csrf_field() ?>
    <div class="card-header bg-white d-flex flex-wrap gap-2 align-items-center">
        <span class="fw-semibold me-auto"><?= $label ?> <span class="badge text-bg-warning"><?= count($rows) ?></span></span>
        <?php if ($rows): ?>
            <span class="small">กำหนดสาขาให้รายชื่อที่เลือก</span> <?= $majorSelect ?>
            <button class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1"></i>บันทึก</button>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr>
                <th style="width:36px"><input type="checkbox" class="form-check-input" data-check-all="<?= $type ?>"></th>
                <?= $type === 'student' ? '<th>รหัส</th>' : '<th>ชื่อผู้ใช้</th>' ?><th>ชื่อ-สกุล</th>
                <?= $type === 'student' ? '<th>สาขาที่ API ส่งมา</th><th>แผน</th>' : '' ?><th>สร้างเมื่อ</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><input type="checkbox" class="form-check-input" name="user_ids[]" value="<?= $r['id'] ?>" data-check-group="<?= $type ?>"></td>
                    <td><code><?= e($type === 'student' ? $r['student_code'] : $r['username']) ?></code></td>
                    <td><?= e(full_name($r)) ?></td>
                    <?php if ($type === 'student'): ?>
                        <td class="small"><?= e($r['major'] ?: '-') ?></td>
                        <td class="small"><?= program_label($r['program_type']) ?></td>
                    <?php endif; ?>
                    <td class="small text-muted"><?= thai_date($r['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-3">ไม่มี</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</form>
<?php endforeach; ?>
<div class="alert alert-light border small">
    <i class="bi bi-lightbulb me-1"></i>ถ้าเจอชื่อสาขาเดียวกันซ้ำ ๆ ใน "สาขาที่ API ส่งมา" ให้ไปเพิ่มชื่อนั้นที่ <a href="/registrar/majors">แก้ไขสาขา</a> → ช่อง "ชื่อสาขาที่ e-Passport ส่งมา" ครั้งต่อไประบบจะจับคู่ให้เอง
</div>
<?php layout_end(); ?>
