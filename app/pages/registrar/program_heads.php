<?php
/**
 * กำหนดอาจารย์ที่เป็น "หัวหน้าหลักสูตร" ของแต่ละสาขา (เลือกได้เฉพาะอาจารย์ของสาขานั้น)
 * ชื่อหัวหน้าหลักสูตรจะแสดงในวงเล็บท้ายแบบบันทึกการฝึกทักษะวิชาชีพของนักศึกษาสาขานั้น
 */
if (is_post()) {
    $majors = array_map(fn($m) => mb_substr(trim((string) $m), 0, 150), (array) ($_POST['majors'] ?? []));
    $heads = array_map('intval', (array) ($_POST['heads'] ?? []));
    $newMajor = mb_substr((string) input('new_major', ''), 0, 150);
    if ($newMajor !== '') {
        $majors[] = $newMajor;
        $heads[] = input_int('new_head');
    }

    $chosen = array_filter($heads);
    if (count($chosen) !== count(array_unique($chosen))) {
        flash('danger', 'อาจารย์ 1 ท่านเป็นหัวหน้าหลักสูตรได้เพียง 1 สาขา');
        redirect('/registrar/program-heads');
    }

    db()->beginTransaction();
    foreach ($majors as $i => $major) {
        if ($major === '') {
            continue;
        }
        q('UPDATE users SET head_of_major = NULL WHERE head_of_major = ?', [$major]);
        if (!empty($heads[$i])) {
            q("UPDATE users SET head_of_major = ? WHERE id = ? AND role = 'teacher' AND major_id = (SELECT id FROM majors WHERE name = ?)", [$major, $heads[$i], $major]);
        }
    }
    db()->commit();
    audit('program_heads.update', array_map(fn($m, $h) => ['major' => $m, 'teacher_id' => $h], $majors, $heads));
    flash('success', 'บันทึกหัวหน้าหลักสูตรแล้ว');
    redirect('/registrar/program-heads');
}

// สาขาทั้งหมดในระบบ (เพิ่มสาขาได้ที่หน้า "เลือกสาขา / เพิ่มสาขา")
$majorRows = majors();
$majors = array_values(array_column($majorRows, 'name'));
$majorIdOf = array_column($majorRows, 'id', 'name');
$teachers = q_all("SELECT id, prefix, first_name, last_name, head_of_major, major_id, signature_data IS NOT NULL AS has_sig
                     FROM users WHERE role = 'teacher' AND is_active = 1 ORDER BY first_name");
$headOf = [];
foreach ($teachers as $t) {
    if ($t['head_of_major']) {
        $headOf[$t['head_of_major']] = $t;
    }
}
$studentCount = array_column(q_all('SELECT major, COUNT(*) AS n FROM students GROUP BY major'), 'n', 'major');

// เลือกได้เฉพาะอาจารย์ของสาขานั้น
$teacherSelect = function (string $name, ?int $selected, ?string $major = null) use ($teachers, $majorIdOf): string {
    $html = '<select name="' . $name . '" class="form-select"><option value="0">— ยังไม่กำหนด —</option>';
    foreach ($teachers as $t) {
        if ($major !== null && (int) $t['major_id'] !== (int) ($majorIdOf[$major] ?? 0)) {
            continue;
        }
        $html .= '<option value="' . $t['id'] . '"' . ((int) $t['id'] === $selected ? ' selected' : '') . '>' . e(full_name($t)) . '</option>';
    }
    return $html . '</select>';
};

layout_start('หัวหน้าหลักสูตร');
page_header('หัวหน้าหลักสูตร', 'กำหนดว่าอาจารย์ท่านใดเป็นหัวหน้าหลักสูตรของแต่ละสาขา — ชื่อจะแสดงในวงเล็บท้ายแบบบันทึกการฝึกทักษะวิชาชีพ');
?>
<form method="post" class="card" style="max-width:900px">
    <?= csrf_field() ?>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>สาขาวิชา</th><th class="text-center">นักศึกษา</th><th style="width:45%">หัวหน้าหลักสูตร</th><th>ลายเซ็นที่บันทึกไว้</th></tr></thead>
            <tbody>
            <?php foreach ($majors as $i => $major):
                $head = $headOf[$major] ?? null; ?>
                <tr>
                    <td><?= e($major) ?><input type="hidden" name="majors[<?= $i ?>]" value="<?= e($major) ?>"></td>
                    <td class="text-center"><?= (int) ($studentCount[$major] ?? 0) ?></td>
                    <td><?= $teacherSelect("heads[$i]", $head ? (int) $head['id'] : null, $major) ?></td>
                    <td class="small"><?= $head ? ($head['has_sig'] ? '<span class="text-success"><i class="bi bi-check-circle me-1"></i>มี</span>' : '<span class="text-muted">ยังไม่มี</span>') : '-' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center">
        <span class="small text-muted">อาจารย์ 1 ท่าน เป็นหัวหน้าหลักสูตรได้ 1 สาขา · หัวหน้าหลักสูตรบันทึกลายเซ็นของตัวเองได้ที่เมนู "ลายเซ็นของฉัน"</span>
        <button class="btn btn-primary"><i class="bi bi-save me-1"></i>บันทึก</button>
    </div>
</form>
<?php layout_end(); ?>
