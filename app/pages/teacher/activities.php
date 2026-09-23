<?php
$me = current_user();
$status = (string) input('status', '');
$kw = (string) input('q', '');

$where = ['a.teacher_id = ?'];
$params = [$me['id']];
if (in_array($status, ['open', 'closed', 'completed', 'cancelled'], true)) {
    $where[] = 'a.status = ?';
    $params[] = $status;
}
if ($kw !== '') {
    $where[] = 'a.title LIKE ?';
    $params[] = like($kw);
}
$whereSql = implode(' AND ', $where);

$pg = paginate((int) q_val("SELECT COUNT(*) FROM activities a WHERE $whereSql", $params), 20);
$rows = q_all(
    "SELECT a.*, sk.name AS skill_name, sem.term, sem.academic_year,
            (SELECT COUNT(*) FROM participations p WHERE p.activity_id = a.id AND p.status IN ('approved','completed')) AS joined_count,
            (SELECT COUNT(*) FROM participations p WHERE p.activity_id = a.id AND p.status = 'pending') AS pending_count,
            (SELECT MIN(session_date) FROM activity_sessions s WHERE s.activity_id = a.id) AS first_date
       FROM activities a
       JOIN skills sk ON sk.id = a.skill_id
       LEFT JOIN semesters sem ON sem.id = a.semester_id
      WHERE $whereSql
      ORDER BY a.id DESC
      LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

layout_start('กิจกรรมของฉัน');
page_header('กิจกรรมของฉัน', 'ทั้งหมด ' . $pg['total'] . ' กิจกรรม', '<a href="/teacher/activity/new" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>สร้างกิจกรรม</a>');
?>
<form class="row g-2 mb-3" method="get">
    <div class="col-md-6"><input type="search" name="q" class="form-control" placeholder="ค้นหาชื่อกิจกรรม" value="<?= e($kw) ?>"></div>
    <div class="col-md-4">
        <select name="status" class="form-select">
            <option value="">ทุกสถานะ</option>
            <?php foreach (['open' => 'เปิดรับ', 'closed' => 'ปิดรับสมัคร', 'completed' => 'เสร็จสิ้น', 'cancelled' => 'ยกเลิก'] as $k => $v): ?>
                <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2"><button class="btn btn-primary w-100">ค้นหา</button></div>
</form>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>กิจกรรม</th><th>ทักษะ</th><th>ภาค</th><th class="text-end">ชม.</th><th class="text-center">ผู้เข้าร่วม</th><th class="text-center">รออนุมัติ</th><th>สถานะ</th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $a): ?>
                <tr>
                    <td>
                        <a href="/teacher/activity?id=<?= $a['id'] ?>" class="fw-semibold"><?= e($a['title']) ?></a>
                        <?= $a['visibility'] === 'assigned' ? '<span class="badge text-bg-primary ms-1">มอบหมาย</span>' : '' ?>
                        <div class="small text-muted"><?= $a['first_date'] ? thai_date($a['first_date']) : 'ยังไม่กำหนดวัน' ?></div>
                    </td>
                    <td class="small"><?= e($a['skill_name']) ?></td>
                    <td class="small"><?= $a['term'] ? e($a['term'] . '/' . $a['academic_year']) : '-' ?></td>
                    <td class="text-end"><?= fmt_hours($a['hours']) ?></td>
                    <td class="text-center"><?= $a['joined_count'] ?><?= $a['capacity'] !== null ? '/' . $a['capacity'] : '' ?></td>
                    <td class="text-center"><?= $a['pending_count'] ? '<span class="badge text-bg-warning">' . $a['pending_count'] . '</span>' : '-' ?></td>
                    <td><?= activity_status_badge($a['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">ไม่พบกิจกรรม</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= pagination_links($pg) ?>
<?php layout_end(); ?>
