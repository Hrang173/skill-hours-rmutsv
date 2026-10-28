<?php
/**
 * เลือกสาขาวิชาที่จะดู (ฝ่ายทะเบียน/admin) + เพิ่ม/แก้ไขสาขา
 * เลือกแล้วทุกหน้างานทะเบียนแสดงเฉพาะข้อมูลสาขานั้น (เก็บใน session)
 */
if (is_post()) {
    $action = input('action');
    if ($action === 'select') {
        $m = major(input_int('id'));
        if ($m && $m['is_active']) {
            $_SESSION['office_major_id'] = (int) $m['id'];
            $next = (string) input('next', '');
            redirect(str_starts_with($next, '/registrar') || str_starts_with($next, '/admin') ? $next : home_path());
        }
        flash('danger', 'ไม่พบสาขา');
    } elseif ($action === 'save') {
        $id = input_int('id');
        $num = fn(string $k) => ($v = trim((string) input($k, ''))) === '' ? null : max(0, round((float) $v, 1));
        $d = [
            mb_substr(trim((string) input('name', '')), 0, 150),
            trim((string) input('api_names', '')) ?: null,
            $num('required_4year'),
            $num('required_transfer'),
            $num('teacher_cap'),
            input('is_active', $id ? '0' : '1') === '1' ? 1 : 0,
        ];
        if ($d[0] === '') {
            flash('danger', 'กรุณากรอกชื่อสาขา');
        } elseif (q_val('SELECT 1 FROM majors WHERE name = ? AND id <> ?', [$d[0], $id])) {
            flash('danger', 'มีสาขานี้อยู่แล้ว');
        } elseif ($id) {
            $old = major($id);
            q('UPDATE majors SET name=?, api_names=?, required_4year=?, required_transfer=?, teacher_cap=?, is_active=? WHERE id=?', [...$d, $id]);
            if ($old && $old['name'] !== $d[0]) {
                // เปลี่ยนชื่อสาขา → ชื่อบนแบบบันทึก + หัวหน้าหลักสูตรตามด้วย
                q('UPDATE students SET major = ? WHERE major_id = ?', [$d[0], $id]);
                q('UPDATE users SET head_of_major = ? WHERE head_of_major = ?', [$d[0], $old['name']]);
            }
            audit('major.update', ['id' => $id, 'name' => $d[0]]);
            flash('success', 'บันทึกสาขาแล้ว');
        } else {
            q('INSERT INTO majors (name, api_names, required_4year, required_transfer, teacher_cap, is_active, sort_order)
               VALUES (?,?,?,?,?,?, (SELECT COALESCE(MAX(sort_order), 0) + 1 FROM majors m2))', $d);
            audit('major.create', ['name' => $d[0]]);
            flash('success', 'เพิ่มสาขา "' . $d[0] . '" แล้ว');
        }
    }
    redirect('/registrar/majors');
}

$majors = q_all(
    "SELECT m.*,
            (SELECT COUNT(*) FROM students s WHERE s.major_id = m.id AND s.status = 'studying') AS students,
            (SELECT COUNT(*) FROM users u WHERE u.major_id = m.id AND u.role = 'teacher' AND u.is_active = 1) AS teachers,
            (SELECT COUNT(*) FROM skills k WHERE k.major_id = m.id AND k.academic_year = ?) AS skills
       FROM majors m ORDER BY m.is_active DESC, m.sort_order, m.id",
    [current_academic_year()]
);
$unassigned = q_one(
    "SELECT (SELECT COUNT(*) FROM students WHERE major_id IS NULL) AS students,
            (SELECT COUNT(*) FROM users WHERE role = 'teacher' AND major_id IS NULL AND is_active = 1) AS teachers"
);
$current = office_major();
$edit = input_int('edit') ? major(input_int('edit')) : null;
$next = (string) input('next', '');
$def = ['4year' => setting('required_hours_4year', 100), 'transfer' => setting('required_hours_transfer', 50), 'cap' => setting('max_hours_per_teacher', 25)];

layout_start('เลือกสาขาวิชา');
page_header('เลือกสาขาวิชา', 'เลือกสาขาที่ต้องการดู — ทุกหน้างานทะเบียนจะแสดงเฉพาะข้อมูลของสาขานั้น (เปลี่ยนได้ตลอดจากแถบด้านบน)');
?>
<div class="row g-3 mb-4">
    <?php foreach ($majors as $m): if (!$m['is_active']) continue; ?>
        <div class="col-md-6 col-xl-4">
            <div class="card h-100 <?= $current && (int) $current['id'] === (int) $m['id'] ? 'border-primary border-2' : '' ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <h5 class="mb-1"><i class="bi bi-mortarboard me-1 text-primary"></i><?= e($m['name']) ?></h5>
                        <a href="?edit=<?= $m['id'] ?>#form" class="btn btn-sm btn-light" title="แก้ไขสาขา"><i class="bi bi-pencil"></i></a>
                    </div>
                    <div class="small text-muted mb-3">
                        นักศึกษา <?= $m['students'] ?> คน · อาจารย์ <?= $m['teachers'] ?> ท่าน · ทักษะปี <?= current_academic_year() ?> <?= $m['skills'] ?> ทักษะ<br>
                        เกณฑ์ 4 ปี <?= fmt_hours($m['required_4year'] ?? $def['4year']) ?> / เทียบโอน <?= fmt_hours($m['required_transfer'] ?? $def['transfer']) ?> ชม.
                        · อาจารย์ 1 ท่าน ≤ <?= fmt_hours($m['teacher_cap'] ?? $def['cap']) ?> ชม.
                    </div>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="select"><input type="hidden" name="id" value="<?= $m['id'] ?>">
                        <input type="hidden" name="next" value="<?= e($next) ?>">
                        <button class="btn btn-primary w-100"><?= $current && (int) $current['id'] === (int) $m['id'] ? '<i class="bi bi-check2 me-1"></i>กำลังดูสาขานี้ — ไปต่อ' : 'ดูสาขานี้' ?></button>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <div class="col-md-6 col-xl-4">
        <a href="/registrar/unassigned" class="card h-100 text-decoration-none text-body card-hover border-warning">
            <div class="card-body">
                <h5 class="mb-1"><i class="bi bi-question-circle me-1 text-warning"></i>ยังไม่ระบุสาขา</h5>
                <div class="small text-muted mb-2">บัญชีที่เข้าด้วย e-Passport แต่ชื่อสาขาไม่ตรงกับสาขาในระบบ — ยังไม่เห็นกิจกรรมจนกว่าจะกำหนดสาขา</div>
                <div>นักศึกษา <strong><?= $unassigned['students'] ?></strong> คน · อาจารย์ <strong><?= $unassigned['teachers'] ?></strong> ท่าน</div>
            </div>
        </a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5" id="form">
        <form method="post" class="card">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $edit['id'] ?? 0 ?>">
            <div class="card-header bg-white fw-semibold"><?= $edit ? 'แก้ไขสาขา' : '<i class="bi bi-plus-circle me-1"></i>เพิ่มสาขา' ?></div>
            <div class="card-body">
                <div class="mb-2"><label class="form-label">ชื่อสาขา *</label><input name="name" class="form-control" required value="<?= e($edit['name'] ?? '') ?>" placeholder="เช่น วิศวกรรมไฟฟ้า"></div>
                <div class="mb-2"><label class="form-label">ชื่อสาขาที่ e-Passport ส่งมา (secname)</label>
                    <textarea name="api_names" class="form-control" rows="2" placeholder="1 บรรทัด ต่อ 1 ชื่อ (ว่าง = ใช้ชื่อสาขา)"><?= e($edit['api_names'] ?? '') ?></textarea>
                    <div class="form-text">ใช้จับคู่สาขาให้อัตโนมัติตอนนักศึกษา/อาจารย์เข้าระบบครั้งแรก</div></div>
                <div class="row g-2">
                    <div class="col-4"><label class="form-label small">เกณฑ์ 4 ปี (ชม.)</label><input type="number" step="0.5" min="0" name="required_4year" class="form-control" value="<?= e($edit['required_4year'] ?? '') ?>" placeholder="<?= fmt_hours($def['4year']) ?>"></div>
                    <div class="col-4"><label class="form-label small">เกณฑ์เทียบโอน</label><input type="number" step="0.5" min="0" name="required_transfer" class="form-control" value="<?= e($edit['required_transfer'] ?? '') ?>" placeholder="<?= fmt_hours($def['transfer']) ?>"></div>
                    <div class="col-4"><label class="form-label small">ต่ออาจารย์ ≤</label><input type="number" step="0.5" min="0" name="teacher_cap" class="form-control" value="<?= e($edit['teacher_cap'] ?? '') ?>" placeholder="<?= fmt_hours($def['cap']) ?>"></div>
                </div>
                <div class="form-text">ช่องชั่วโมงว่าง = ใช้ค่ากลางในหน้าตั้งค่าระบบ</div>
                <?php if ($edit): ?>
                    <div class="form-check mt-2"><input type="checkbox" class="form-check-input" name="is_active" value="1" id="mact" <?= $edit['is_active'] ? 'checked' : '' ?>><label for="mact" class="form-check-label">เปิดใช้งาน</label></div>
                <?php endif; ?>
            </div>
            <div class="card-footer bg-white text-end">
                <?php if ($edit): ?><a href="/registrar/majors" class="btn btn-light">ยกเลิก</a><?php endif; ?>
                <button class="btn btn-primary"><i class="bi bi-save me-1"></i>บันทึก</button>
            </div>
        </form>
    </div>
    <?php if (array_filter($majors, fn($m) => !$m['is_active'])): ?>
        <div class="col-lg-7">
            <div class="card"><div class="card-header bg-white fw-semibold">สาขาที่ปิดใช้งาน</div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($majors as $m): if ($m['is_active']) continue; ?>
                        <li class="list-group-item d-flex justify-content-between"><?= e($m['name']) ?> <a href="?edit=<?= $m['id'] ?>#form">แก้ไข</a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php layout_end(); ?>
