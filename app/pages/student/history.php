<?php
$me = current_user();
$status = (string) input('status', '');
$allowed = ['pending', 'approved', 'completed', 'rejected', 'cancelled'];

$sql = "SELECT p.*, a.title, a.hours AS activity_hours, a.id AS activity_id, sk.name AS skill_name,
               CONCAT(t.prefix, t.first_name, ' ', t.last_name) AS teacher_name,
               sem.term, sem.academic_year,
               (SELECT MIN(session_date) FROM activity_sessions s WHERE s.activity_id = a.id) AS first_date
          FROM participations p
          JOIN activities a ON a.id = p.activity_id
          JOIN skills sk ON sk.id = a.skill_id
          JOIN users t ON t.id = a.teacher_id
          LEFT JOIN semesters sem ON sem.id = a.semester_id
         WHERE p.student_id = ?";
$params = [$me['id']];
if (in_array($status, $allowed, true)) {
    $sql .= ' AND p.status = ?';
    $params[] = $status;
}
$sql .= ' ORDER BY p.created_at DESC';
$rows = q_all($sql, $params);

layout_start('ประวัติการเข้าร่วม');
page_header('ประวัติการเข้าร่วมกิจกรรม', 'รายการคำขอ กิจกรรมที่ได้รับมอบหมาย และผลการฝึกทั้งหมด');
$tabs = ['' => 'ทั้งหมด', 'pending' => 'รออนุมัติ', 'approved' => 'รอบันทึกผล', 'completed' => 'บันทึกผลแล้ว', 'rejected' => 'ไม่อนุมัติ', 'cancelled' => 'ยกเลิก'];
?>
<ul class="nav nav-pills mb-3 flex-wrap">
    <?php foreach ($tabs as $k => $label): ?>
        <li class="nav-item"><a class="nav-link <?= $status === $k ? 'active' : '' ?>" href="?status=<?= $k ?>"><?= $label ?></a></li>
    <?php endforeach; ?>
</ul>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>กิจกรรม</th><th>ทักษะ</th><th>อาจารย์</th><th>ภาค</th><th class="text-end">ชม.</th><th>สถานะ</th><th>วันที่ส่ง</th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td>
                        <a href="/student/activity?id=<?= $r['activity_id'] ?>"><?= e($r['title']) ?></a>
                        <?php if ($r['source'] === 'assigned'): ?><span class="badge text-bg-primary ms-1">มอบหมาย</span><?php endif; ?>
                        <?php if ($r['first_date']): ?><div class="small text-muted"><?= thai_date($r['first_date']) ?></div><?php endif; ?>
                    </td>
                    <td class="small"><?= e($r['skill_name']) ?></td>
                    <td class="small"><?= e($r['teacher_name']) ?></td>
                    <td class="small"><?= $r['term'] ? e($r['term'] . '/' . $r['academic_year']) : '-' ?></td>
                    <td class="text-end"><?= $r['status'] === 'completed' ? '<strong>' . fmt_hours($r['hours_awarded']) . '</strong>' : '<span class="text-muted">' . fmt_hours($r['activity_hours']) . '</span>' ?></td>
                    <td><?= participation_badge($r) ?></td>
                    <td class="small text-muted"><?= thai_date($r['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">ไม่มีรายการ</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php layout_end(); ?>
