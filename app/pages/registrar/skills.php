<?php
/**
 * รายชื่อทักษะวิชาชีพ แยกตามปีการศึกษา + สาขา (สาขาที่ฝ่ายทะเบียนเลือกดูอยู่) — ฝ่ายทะเบียนเพิ่มทักษะและผู้รับผิดชอบใหม่ทุกปี
 * อาจารย์ 1 ท่าน รับผิดชอบได้ 1 ทักษะต่อปี และโพสต์กิจกรรมได้เฉพาะทักษะของตัวเอง
 * (คัดลอกรายชื่อจากปีก่อนมาแก้ต่อได้)
 */
$major = require_office_major();
$mid = (int) $major['id'];
$years = array_map('intval', array_column(q_all(
    'SELECT academic_year FROM semesters UNION SELECT academic_year FROM skills ORDER BY academic_year DESC'
), 'academic_year'));
$year = input_int('year') ?: current_academic_year();
if (!in_array($year, $years, true)) {
    $years[] = $year;
    rsort($years);
}

if (is_post()) {
    $id = input_int('id');
    $action = input('action');
    if ($action === 'delete') {
        if (q_val('SELECT 1 FROM activities WHERE skill_id = ? LIMIT 1', [$id])) {
            flash('danger', 'ลบไม่ได้ เพราะมีกิจกรรมใช้ทักษะนี้อยู่ (ปิดการใช้งานแทนได้)');
        } else {
            q('DELETE FROM skills WHERE id = ? AND major_id = ?', [$id, $mid]);
            audit('skill.delete', ['id' => $id]);
            flash('success', 'ลบทักษะแล้ว');
        }
    } elseif ($action === 'copy') {
        // คัดลอกทักษะจากปีอื่น (ข้ามรหัสที่ปีนี้มีแล้ว)
        $from = input_int('from_year');
        $withOwner = input('with_owner') === '1';
        $n = q(
            "INSERT INTO skills (academic_year, major_id, code, name, description, owner_id, max_hours, sort_order, is_active)
             SELECT ?, f.major_id, f.code, f.name, f.description,
                    IF(? AND u.is_active = 1 AND u.role = 'teacher' AND u.major_id = f.major_id
                         AND NOT EXISTS (SELECT 1 FROM skills o WHERE o.academic_year = ? AND o.owner_id = f.owner_id), f.owner_id, NULL),
                    f.max_hours, f.sort_order, 1
               FROM skills f LEFT JOIN users u ON u.id = f.owner_id
              WHERE f.academic_year = ? AND f.major_id = ? AND f.is_active = 1
                AND NOT EXISTS (SELECT 1 FROM skills t WHERE t.academic_year = ? AND t.major_id = f.major_id AND t.code = f.code)",
            [$year, $withOwner ? 1 : 0, $year, $from, $mid, $year]
        )->rowCount();
        audit('skill.copy_year', ['from' => $from, 'to' => $year, 'count' => $n, 'with_owner' => $withOwner]);
        flash($n ? 'success' : 'info', $n ? "คัดลอก $n ทักษะจากปี $from แล้ว" . ($withOwner ? '' : ' — กรุณากำหนดผู้รับผิดชอบ') : 'ไม่มีทักษะใหม่ให้คัดลอก (ปีนี้มีครบแล้ว)');
    } else {
        $d = [
            mb_substr(trim((string) input('code', '')), 0, 20),
            mb_substr(trim((string) input('name', '')), 0, 255),
            (string) input('description', '') ?: null,
            input_int('owner_id') ?: null,
            input_int('sort_order'),
            input('is_active') === '1' ? 1 : 0,
        ];
        if ($d[0] === '' || $d[1] === '') {
            flash('danger', 'กรุณากรอกรหัสและชื่อทักษะ');
        } elseif ($id && !q_val('SELECT 1 FROM skills WHERE id = ? AND major_id = ?', [$id, $mid])) {
            flash('danger', 'ไม่พบทักษะในสาขานี้');
        } elseif ($d[3] && !q_val("SELECT 1 FROM users WHERE id = ? AND role = 'teacher' AND major_id = ?", [$d[3], $mid])) {
            flash('danger', 'ผู้รับผิดชอบต้องเป็นอาจารย์ของสาขา' . $major['name']);
        } elseif (q_val('SELECT 1 FROM skills WHERE academic_year = ? AND major_id = ? AND code = ? AND id <> ?', [$year, $mid, $d[0], $id])) {
            flash('danger', "รหัสทักษะ {$d[0]} มีอยู่แล้วในปีการศึกษา $year");
        } elseif ($d[3] && ($taken = q_one('SELECT code, name FROM skills WHERE academic_year = ? AND owner_id = ? AND id <> ?', [$year, $d[3], $id]))) {
            flash('danger', 'อาจารย์ท่านนี้รับผิดชอบทักษะ ' . $taken['code'] . ' ' . $taken['name'] . " ในปี $year อยู่แล้ว (1 ท่าน รับผิดชอบได้ 1 ทักษะ)");
        } elseif ($id) {
            q('UPDATE skills SET code=?, name=?, description=?, owner_id=?, sort_order=?, is_active=? WHERE id=?', [...$d, $id]);
            audit('skill.update', ['id' => $id]);
            flash('success', 'บันทึกแล้ว');
        } else {
            q('INSERT INTO skills (code, name, description, owner_id, sort_order, is_active, academic_year, major_id) VALUES (?,?,?,?,?,?,?,?)', [...$d, $year, $mid]);
            audit('skill.create', ['code' => $d[0], 'year' => $year]);
            flash('success', 'เพิ่มทักษะแล้ว');
        }
    }
    redirect('/registrar/skills', ['year' => $year]);
}

$skills = q_all("SELECT sk.*, CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS owner_name, u.username AS owner_username, u.username AS owner_username,
                        (SELECT COUNT(*) FROM activities a WHERE a.skill_id = sk.id) AS activity_count
                   FROM skills sk LEFT JOIN users u ON u.id = sk.owner_id
                  WHERE sk.academic_year = ? AND sk.major_id = ? ORDER BY sk.sort_order, sk.id", [$year, $mid]);
$teachers = q_all("SELECT id, prefix, first_name, last_name, username FROM users WHERE role = 'teacher' AND is_active = 1 AND major_id = ? ORDER BY first_name", [$mid]);
$ownerOf = array_column($skills, 'code', 'owner_id'); // อาจารย์ → รหัสทักษะที่รับผิดชอบแล้วในปีนี้
$edit = input_int('edit') ? q_one('SELECT * FROM skills WHERE id = ? AND academic_year = ? AND major_id = ?', [input_int('edit'), $year, $mid]) : null;
// ปีที่คัดลอกมาได้ (ปีอื่นที่มีทักษะ) — ค่าเริ่มต้น = ปีก่อนหน้าที่ใกล้ที่สุด
$copyYears = array_map('intval', array_column(q_all('SELECT DISTINCT academic_year FROM skills WHERE academic_year <> ? AND major_id = ? ORDER BY academic_year DESC', [$year, $mid]), 'academic_year'));
$copyDefault = current(array_filter($copyYears, fn($y) => $y < $year)) ?: ($copyYears[0] ?? null);
$curYear = current_academic_year();

layout_start('รายชื่อทักษะวิชาชีพ');
page_header('รายชื่อทักษะวิชาชีพ', 'สาขา' . $major['name'] . ' · ทักษะและผู้รับผิดชอบของแต่ละปีการศึกษา (อาจารย์ 1 ท่าน นับได้สูงสุด ' . fmt_hours(App\Services\HoursService::teacherCap($mid) ?? 0) . ' ชม./นักศึกษา)');
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <span class="fw-semibold">ปีการศึกษา</span>
    <div class="btn-group flex-wrap">
        <?php foreach ($years as $y): ?>
            <a href="?year=<?= $y ?>" class="btn btn-sm <?= $y === $year ? 'btn-primary' : 'btn-outline-primary' ?>"><?= $y ?><?= $y === $curYear ? ' (ปัจจุบัน)' : '' ?></a>
        <?php endforeach; ?>
    </div>
    <form class="d-flex gap-1 ms-auto" method="get">
        <input type="number" name="year" class="form-control form-control-sm" style="width:110px" min="2500" max="2700" placeholder="ปีอื่น เช่น <?= $curYear + 1 ?>">
        <button class="btn btn-sm btn-outline-secondary">ไป</button>
    </form>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <?php if (!$skills && $copyDefault): ?>
            <form method="post" class="alert alert-info d-flex flex-wrap align-items-center gap-2">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="copy">
                <i class="bi bi-info-circle"></i> ปีการศึกษา <?= $year ?> ยังไม่มีทักษะ — คัดลอกจากปี
                <select name="from_year" class="form-select form-select-sm w-auto">
                    <?php foreach ($copyYears as $y): ?><option value="<?= $y ?>" <?= $y === $copyDefault ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?>
                </select>
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" name="with_owner" value="1" id="wo" checked>
                    <label class="form-check-label" for="wo">คัดลอกผู้รับผิดชอบด้วย</label>
                </div>
                <button class="btn btn-sm btn-primary"><i class="bi bi-copy me-1"></i>คัดลอก</button>
                <span class="small text-muted w-100">หรือเพิ่มทักษะเองทีละรายการทางขวา · คัดลอกแล้วแก้ไขชื่อ/ผู้รับผิดชอบต่อได้</span>
            </form>
        <?php endif; ?>
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">ทักษะปีการศึกษา <?= $year ?> <span class="badge text-bg-light border"><?= count($skills) ?></span></span>
                <?php if ($skills && $copyDefault): ?>
                    <form method="post" class="d-flex align-items-center gap-1 small">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="copy"><input type="hidden" name="with_owner" value="1">
                        เพิ่มที่ขาดจากปี
                        <select name="from_year" class="form-select form-select-sm w-auto">
                            <?php foreach ($copyYears as $y): ?><option value="<?= $y ?>" <?= $y === $copyDefault ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?>
                        </select>
                        <button class="btn btn-sm btn-outline-primary" title="คัดลอกทักษะที่ปีนี้ยังไม่มี (เทียบจากรหัส)"><i class="bi bi-copy"></i></button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>ลำดับ</th><th>ชื่อทักษะ</th><th>ผู้รับผิดชอบ</th><th class="text-center">กิจกรรม</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($skills as $s): ?>
                        <tr class="<?= $s['is_active'] ? '' : 'text-muted' ?>">
                            <td><?= $s['sort_order'] ?></td>
                            <td><code class="small"><?= e($s['code']) ?></code> <?= e($s['name']) ?><?= $s['is_active'] ? '' : ' <span class="badge text-bg-secondary">ปิด</span>' ?></td>
                            <td class="small"><?= $s['owner_name'] ? e($s['owner_name']) : '<span class="text-danger">ยังไม่กำหนด</span>' ?>
                                <?php if (str_starts_with((string) $s['owner_username'], 'wait.')): ?><div><span class="badge text-bg-light border" title="บัญชีสร้างรอไว้ จะผูกกับ e-Passport เมื่ออาจารย์เข้าระบบครั้งแรก">รอเข้าระบบครั้งแรก</span></div><?php endif; ?></td>
                            <td class="text-center"><?= $s['activity_count'] ?></td>
                            <td class="text-end text-nowrap">
                                <a href="?year=<?= $year ?>&edit=<?= $s['id'] ?>" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i></a>
                                <form method="post" class="d-inline" onsubmit="return confirm('ลบทักษะนี้?')">
                                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $s['id'] ?>">
                                    <button name="action" value="delete" class="btn btn-sm btn-light text-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$skills): ?><tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มีทักษะในปีการศึกษานี้</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <form method="post" class="card">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $edit['id'] ?? 0 ?>">
            <div class="card-header bg-white fw-semibold"><?= $edit ? 'แก้ไขทักษะ' : 'เพิ่มทักษะ' ?> · ปีการศึกษา <?= $year ?></div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-6"><label class="form-label">รหัส</label><input name="code" class="form-control" required value="<?= e($edit['code'] ?? '') ?>"></div>
                    <div class="col-6"><label class="form-label">ลำดับ</label><input type="number" name="sort_order" class="form-control" value="<?= e($edit['sort_order'] ?? count($skills) + 1) ?>"></div>
                    <div class="col-12"><label class="form-label">ชื่อทักษะ</label><input name="name" class="form-control" required value="<?= e($edit['name'] ?? '') ?>"></div>
                    <div class="col-12"><label class="form-label">ผู้รับผิดชอบทักษะ</label>
                        <select name="owner_id" class="form-select">
                            <option value="">-</option>
                            <?php foreach ($teachers as $t): ?>
                                <?php $taken = isset($ownerOf[$t['id']]) && (int) ($edit['owner_id'] ?? 0) !== (int) $t['id']; ?>
                                <option value="<?= $t['id'] ?>" <?= (int) ($edit['owner_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?> <?= $taken ? 'disabled' : '' ?>><?= e(full_name($t)) ?><?= $taken ? ' — รับผิดชอบ ' . e($ownerOf[$t['id']]) . ' แล้ว' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12"><div class="form-check"><input type="checkbox" class="form-check-input" name="is_active" value="1" id="ia" <?= ($edit['is_active'] ?? 1) ? 'checked' : '' ?>><label for="ia" class="form-check-label">เปิดใช้งาน</label></div></div>
                    <div class="col-12"><label class="form-label">คำอธิบาย</label><textarea name="description" class="form-control" rows="2"><?= e($edit['description'] ?? '') ?></textarea></div>
                </div>
            </div>
            <div class="card-footer bg-white text-end">
                <?php if ($edit): ?><a href="?year=<?= $year ?>" class="btn btn-light">ยกเลิก</a><?php endif; ?>
                <button class="btn btn-primary">บันทึก</button>
            </div>
        </form>
        <div class="alert alert-light border small mt-3">
            <i class="bi bi-info-circle me-1"></i>อาจารย์ 1 ท่าน รับผิดชอบได้ 1 ทักษะต่อปี · กิจกรรมที่อาจารย์โพสต์จะเป็นทักษะของตัวเองโดยอัตโนมัติ (เลือกทักษะของคนอื่นไม่ได้) ·
            ปีการศึกษาต้องมีภาคการศึกษาที่เมนู <a href="/registrar/semesters">ภาคการศึกษา</a> ด้วย
        </div>
    </div>
</div>
<?php layout_end(); ?>
