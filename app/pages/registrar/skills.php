<?php
if (is_post()) {
    $id = input_int('id');
    $action = input('action');
    if ($action === 'delete') {
        if (q_val('SELECT 1 FROM activities WHERE skill_id = ? LIMIT 1', [$id])) {
            flash('danger', 'ลบไม่ได้ เพราะมีกิจกรรมใช้ทักษะนี้อยู่ (ปิดการใช้งานแทนได้)');
        } else {
            q('DELETE FROM skills WHERE id = ?', [$id]);
            audit('skill.delete', ['id' => $id]);
            flash('success', 'ลบทักษะแล้ว');
        }
    } else {
        $d = [
            mb_substr((string) input('code', ''), 0, 20),
            mb_substr((string) input('name', ''), 0, 255),
            (string) input('description', '') ?: null,
            input_int('owner_id') ?: null,
            max(1, (float) input('max_hours', 25)),
            input_int('sort_order'),
            input('is_active') === '1' ? 1 : 0,
        ];
        if ($d[0] === '' || $d[1] === '') {
            flash('danger', 'กรุณากรอกรหัสและชื่อทักษะ');
        } elseif (q_val('SELECT 1 FROM skills WHERE code = ? AND id <> ?', [$d[0], $id])) {
            flash('danger', 'รหัสทักษะซ้ำ');
        } elseif ($id) {
            q('UPDATE skills SET code=?, name=?, description=?, owner_id=?, max_hours=?, sort_order=?, is_active=? WHERE id=?', [...$d, $id]);
            audit('skill.update', ['id' => $id]);
            flash('success', 'บันทึกแล้ว');
        } else {
            q('INSERT INTO skills (code, name, description, owner_id, max_hours, sort_order, is_active) VALUES (?,?,?,?,?,?,?)', $d);
            audit('skill.create', ['code' => $d[0]]);
            flash('success', 'เพิ่มทักษะแล้ว');
        }
    }
    redirect('/registrar/skills');
}

$skills = q_all("SELECT sk.*, CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS owner_name,
                        (SELECT COUNT(*) FROM activities a WHERE a.skill_id = sk.id) AS activity_count
                   FROM skills sk LEFT JOIN users u ON u.id = sk.owner_id ORDER BY sk.sort_order, sk.id");
$teachers = q_all("SELECT id, prefix, first_name, last_name FROM users WHERE role = 'teacher' AND is_active = 1 ORDER BY first_name");
$edit = input_int('edit') ? q_one('SELECT * FROM skills WHERE id = ?', [input_int('edit')]) : null;

layout_start('รายชื่อทักษะวิชาชีพ');
page_header('รายชื่อทักษะวิชาชีพ', 'ทักษะที่นักศึกษาต้องฝึก พร้อมอาจารย์ผู้ควบคุมและชั่วโมงสูงสุดที่นับได้');
?>
<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>ลำดับ</th><th>ชื่อทักษะ</th><th>ผู้ควบคุม</th><th class="text-end">ชม.สูงสุด</th><th class="text-center">กิจกรรม</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($skills as $s): ?>
                        <tr class="<?= $s['is_active'] ? '' : 'text-muted' ?>">
                            <td><?= $s['sort_order'] ?></td>
                            <td><code class="small"><?= e($s['code']) ?></code> <?= e($s['name']) ?><?= $s['is_active'] ? '' : ' <span class="badge text-bg-secondary">ปิด</span>' ?></td>
                            <td class="small"><?= e($s['owner_name'] ?? '-') ?></td>
                            <td class="text-end"><?= fmt_hours($s['max_hours']) ?></td>
                            <td class="text-center"><?= $s['activity_count'] ?></td>
                            <td class="text-end text-nowrap">
                                <a href="?edit=<?= $s['id'] ?>" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i></a>
                                <form method="post" class="d-inline" onsubmit="return confirm('ลบทักษะนี้?')">
                                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $s['id'] ?>">
                                    <button name="action" value="delete" class="btn btn-sm btn-light text-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <form method="post" class="card">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $edit['id'] ?? 0 ?>">
            <div class="card-header bg-white fw-semibold"><?= $edit ? 'แก้ไขทักษะ' : 'เพิ่มทักษะ' ?></div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-6"><label class="form-label">รหัส</label><input name="code" class="form-control" required value="<?= e($edit['code'] ?? '') ?>"></div>
                    <div class="col-6"><label class="form-label">ลำดับ</label><input type="number" name="sort_order" class="form-control" value="<?= e($edit['sort_order'] ?? count($skills) + 1) ?>"></div>
                    <div class="col-12"><label class="form-label">ชื่อทักษะ</label><input name="name" class="form-control" required value="<?= e($edit['name'] ?? '') ?>"></div>
                    <div class="col-12"><label class="form-label">ผู้ควบคุม/ผู้รับผิดชอบ</label>
                        <select name="owner_id" class="form-select">
                            <option value="">-</option>
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?= $t['id'] ?>" <?= (int) ($edit['owner_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= e(full_name($t)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6"><label class="form-label">ชม.สูงสุด</label><input type="number" step="0.5" name="max_hours" class="form-control" value="<?= e($edit['max_hours'] ?? 25) ?>"></div>
                    <div class="col-6 d-flex align-items-end"><div class="form-check"><input type="checkbox" class="form-check-input" name="is_active" value="1" id="ia" <?= ($edit['is_active'] ?? 1) ? 'checked' : '' ?>><label for="ia" class="form-check-label">เปิดใช้งาน</label></div></div>
                    <div class="col-12"><label class="form-label">คำอธิบาย</label><textarea name="description" class="form-control" rows="2"><?= e($edit['description'] ?? '') ?></textarea></div>
                </div>
            </div>
            <div class="card-footer bg-white text-end">
                <?php if ($edit): ?><a href="/registrar/skills" class="btn btn-light">ยกเลิก</a><?php endif; ?>
                <button class="btn btn-primary">บันทึก</button>
            </div>
        </form>
    </div>
</div>
<?php layout_end(); ?>
