<?php
if (is_post()) {
    $action = input('action');
    $id = input_int('id');
    if ($action === 'current') {
        q('UPDATE semesters SET is_current = (id = ?)', [$id]);
        audit('semester.current', ['id' => $id]);
        flash('success', 'ตั้งเป็นภาคการศึกษาปัจจุบันแล้ว');
    } elseif ($action === 'delete') {
        if (q_val('SELECT 1 FROM activities WHERE semester_id = ? LIMIT 1', [$id])) {
            flash('danger', 'ลบไม่ได้ เพราะมีกิจกรรมในภาคการศึกษานี้');
        } else {
            q('DELETE FROM semesters WHERE id = ?', [$id]);
            flash('success', 'ลบแล้ว');
        }
    } else {
        $term = input_int('term');
        $year = input_int('academic_year');
        if ($term < 1 || $term > 3 || $year < 2500) {
            flash('danger', 'ข้อมูลไม่ถูกต้อง');
        } elseif (q_val('SELECT 1 FROM semesters WHERE term = ? AND academic_year = ?', [$term, $year])) {
            flash('danger', 'มีภาคการศึกษานี้อยู่แล้ว');
        } else {
            q('INSERT INTO semesters (term, academic_year, start_date, end_date) VALUES (?, ?, ?, ?)', [$term, $year, input('start_date') ?: null, input('end_date') ?: null]);
            audit('semester.create', ['term' => $term, 'year' => $year]);
            flash('success', 'เพิ่มภาคการศึกษาแล้ว');
        }
    }
    redirect('/registrar/semesters');
}
$rows = q_all('SELECT s.*, (SELECT COUNT(*) FROM activities a WHERE a.semester_id = s.id) AS activity_count FROM semesters s ORDER BY academic_year DESC, term DESC');

layout_start('ภาคการศึกษา');
page_header('ภาคการศึกษา', 'ใช้จัดกลุ่มกิจกรรม และเลือกพิมพ์แบบบันทึกรายภาค');
?>
<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>ภาค/ปีการศึกษา</th><th>ช่วงวันที่</th><th class="text-center">กิจกรรม</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $s): ?>
                    <tr>
                        <td><strong><?= e(semester_label($s)) ?></strong> <?= $s['is_current'] ? '<span class="badge text-bg-success">ปัจจุบัน</span>' : '' ?></td>
                        <td class="small"><?= thai_date($s['start_date']) ?> – <?= thai_date($s['end_date']) ?></td>
                        <td class="text-center"><?= $s['activity_count'] ?></td>
                        <td class="text-end text-nowrap">
                            <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $s['id'] ?>">
                                <?php if (!$s['is_current']): ?><button name="action" value="current" class="btn btn-sm btn-outline-success">ตั้งเป็นปัจจุบัน</button><?php endif; ?>
                                <button name="action" value="delete" class="btn btn-sm btn-light text-danger" onclick="return confirm('ลบ?')"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="col-lg-4">
        <form method="post" class="card">
            <?= csrf_field() ?>
            <div class="card-header bg-white fw-semibold">เพิ่มภาคการศึกษา</div>
            <div class="card-body row g-2">
                <div class="col-4"><label class="form-label">ภาค</label><select name="term" class="form-select"><option>1</option><option>2</option><option value="3">3 (ฤดูร้อน)</option></select></div>
                <div class="col-8"><label class="form-label">ปีการศึกษา (พ.ศ.)</label><input type="number" name="academic_year" class="form-control" value="<?= date('Y') + 543 ?>" required></div>
                <div class="col-6"><label class="form-label">เริ่ม</label><input type="date" name="start_date" class="form-control"></div>
                <div class="col-6"><label class="form-label">สิ้นสุด</label><input type="date" name="end_date" class="form-control"></div>
            </div>
            <div class="card-footer bg-white text-end"><button class="btn btn-primary">เพิ่ม</button></div>
        </form>
    </div>
</div>
<?php layout_end(); ?>
