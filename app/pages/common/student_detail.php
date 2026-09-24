<?php
use App\Integrations\UniversityApi\UniversityApiFactory;
use App\Services\HoursService;
use App\Services\UniversitySync;

$me = current_user();
$id = input_int('id');
$st = q_one(
    "SELECT u.*, s.*, CONCAT(a.prefix, a.first_name, ' ', a.last_name) AS advisor_name
       FROM users u JOIN students s ON s.user_id = u.id
       LEFT JOIN users a ON a.id = s.advisor_id
      WHERE u.id = ?",
    [$id]
);
if (!$st) {
    flash('danger', 'ไม่พบนักศึกษา');
    redirect_back('/');
}

// ผู้ดูแลระบบ: ซิงก์ข้อมูลจาก API มหาวิทยาลัย
if (is_post() && input('action') === 'sync' && $me['role'] === 'admin') {
    try {
        $ok = UniversitySync::syncStudent($st['student_code']);
        flash($ok ? 'success' : 'warning', $ok ? 'ซิงก์ข้อมูลจากระบบมหาวิทยาลัยแล้ว' : 'ไม่พบข้อมูลนักศึกษาคนนี้ใน API มหาวิทยาลัย');
    } catch (Throwable $ex) {
        flash('danger', 'เชื่อมต่อ API ไม่สำเร็จ: ' . $ex->getMessage());
    }
    redirect('/students/view', ['id' => $id]);
}

$sum = HoursService::summary($id, $st['program_type']);
$all = q_all(
    "SELECT p.*, a.title, a.hours AS activity_hours, sk.name AS skill_name,
            CONCAT(t.prefix, t.first_name, ' ', t.last_name) AS teacher_name, a.teacher_id,
            sem.term, sem.academic_year,
            (SELECT MIN(session_date) FROM activity_sessions s WHERE s.activity_id = a.id) AS first_date
       FROM participations p
       JOIN activities a ON a.id = p.activity_id
       JOIN skills sk ON sk.id = a.skill_id
       JOIN users t ON t.id = a.teacher_id
       LEFT JOIN semesters sem ON sem.id = a.semester_id
      WHERE p.student_id = ?
      ORDER BY COALESCE(first_date, p.created_at) DESC",
    [$id]
);

$actions = '<a href="/print/form?student_id=' . $id . '" target="_blank" class="btn btn-primary"><i class="bi bi-printer me-1"></i>พิมพ์แบบบันทึกทักษะ</a>';
if ($me['role'] === 'teacher') {
    $actions .= ' <a href="/teacher/assign?student_id=' . $id . '" class="btn btn-outline-primary"><i class="bi bi-person-check me-1"></i>มอบหมายกิจกรรม</a>';
}
if (is_office()) {
    $actions .= ' <a href="/registrar/user/edit?id=' . $id . '" class="btn btn-outline-secondary"><i class="bi bi-pencil me-1"></i>แก้ไขข้อมูล</a>';
}

layout_start(full_name($st));
page_header(full_name($st), 'รหัสนักศึกษา ' . $st['student_code'], $actions);
?>
<div class="row g-3 mb-3">
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">ข้อมูลนักศึกษา</div>
            <div class="card-body">
                <dl class="row small mb-0">
                    <dt class="col-5">รหัสนักศึกษา</dt><dd class="col-7"><?= e($st['student_code']) ?></dd>
                    <dt class="col-5">แผนการเรียน</dt><dd class="col-7"><?= program_label($st['program_type']) ?> (<?= fmt_hours($sum['required']) ?> ชม.)</dd>
                    <dt class="col-5">คณะ</dt><dd class="col-7"><?= e($st['faculty']) ?></dd>
                    <dt class="col-5">สาขาวิชา</dt><dd class="col-7"><?= e($st['major']) ?></dd>
                    <dt class="col-5">ปีที่เข้า</dt><dd class="col-7"><?= e($st['entry_year'] ?: '-') ?></dd>
                    <dt class="col-5">อาจารย์ที่ปรึกษา</dt><dd class="col-7"><?= e($st['advisor_name'] ?: '-') ?></dd>
                    <dt class="col-5">อีเมล</dt><dd class="col-7"><?= e($st['email'] ?: '-') ?></dd>
                    <dt class="col-5">โทรศัพท์</dt><dd class="col-7"><?= e($st['phone'] ?: '-') ?></dd>
                    <?php if ($st['synced_at']): ?><dt class="col-5">ซิงก์ล่าสุด</dt><dd class="col-7"><?= thai_date($st['synced_at'], true) ?></dd><?php endif; ?>
                </dl>
                <?php if ($me['role'] === 'admin' && UniversityApiFactory::enabled()): ?>
                    <form method="post" class="mt-2">
                        <?= csrf_field() ?>
                        <button name="action" value="sync" class="btn btn-sm btn-outline-secondary"><i class="bi bi-cloud-arrow-down me-1"></i>ซิงก์จาก API มหาวิทยาลัย</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card h-100 hero-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <div class="text-muted small">ชั่วโมงสะสม (นับได้)</div>
                        <div class="display-6 fw-bold"><?= fmt_hours($sum['earned']) ?> <span class="fs-5 text-muted">/ <?= fmt_hours($sum['required']) ?> ชม.</span></div>
                    </div>
                    <div class="fs-5"><?= completion_badge($sum['complete']) ?></div>
                </div>
                <?= progress_bar($sum['earned'], $sum['required'], true) ?>
                <div class="row small mt-3 g-2">
                    <div class="col-sm-4">ยังขาด: <strong><?= fmt_hours($sum['remaining']) ?> ชม.</strong></div>
                    <div class="col-sm-4">รอบันทึกผล: <strong><?= fmt_hours($sum['pending']) ?> ชม.</strong></div>
                    <div class="col-sm-4">ฝึกจริงทั้งหมด: <strong><?= fmt_hours($sum['raw']) ?> ชม.</strong></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-7"><?php teacher_hours_card($sum); ?></div>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">ชั่วโมงแยกตามทักษะ</div>
            <ul class="list-group list-group-flush small">
                <?php foreach ($sum['skills'] as $s): ?>
                    <li class="list-group-item d-flex justify-content-between"><span><?= e($s['name']) ?></span><strong class="text-nowrap ms-2"><?= fmt_hours($s['hours']) ?> ชม.</strong></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white fw-semibold">ประวัติการเข้าร่วมกิจกรรมทั้งหมด (<?= count($all) ?>)</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>วันที่</th><th>กิจกรรม</th><th>ทักษะ</th><th>อาจารย์ผู้ควบคุม</th><th>ภาค</th><th class="text-end">ชม.</th><th>สถานะ</th><th>ลายมือชื่อ</th><?= $me['role'] === 'admin' ? '<th></th>' : '' ?></tr>
            </thead>
            <tbody>
            <?php foreach ($all as $r): ?>
                <tr>
                    <td class="small text-nowrap"><?= thai_date($r['first_date'] ?? $r['created_at']) ?></td>
                    <td>
                        <?php if ($me['role'] === 'teacher' && (int) $r['teacher_id'] === (int) $me['id']): ?>
                            <a href="/teacher/activity?id=<?= $r['activity_id'] ?>"><?= e($r['title']) ?></a>
                        <?php else: ?>
                            <?= e($r['title']) ?>
                        <?php endif; ?>
                        <?= $r['source'] === 'assigned' ? '<span class="badge text-bg-primary ms-1">มอบหมาย</span>' : '' ?>
                    </td>
                    <td class="small"><?= e($r['skill_name']) ?></td>
                    <td class="small"><?= e($r['teacher_name']) ?></td>
                    <td class="small"><?= $r['term'] ? e($r['term'] . '/' . $r['academic_year']) : '-' ?></td>
                    <td class="text-end"><?= $r['status'] === 'completed' ? '<strong>' . fmt_hours($r['hours_awarded']) . '</strong>' : '<span class="text-muted">' . fmt_hours($r['activity_hours']) . '</span>' ?></td>
                    <td><?= participation_badge($r) ?></td>
                    <td>
                        <?php if ($r['sign_method'] === 'online' && $r['signature_data']): ?>
                            <img src="<?= e($r['signature_data']) ?>" class="sig-thumb" alt="ลายเซ็น">
                        <?php elseif ($r['sign_method'] === 'name'): ?>
                            <span class="small"><?= e($r['signer_name']) ?></span>
                        <?php endif; ?>
                    </td>
                    <?php if ($me['role'] === 'admin'): ?>
                        <td><a href="/admin/record?id=<?= $r['id'] ?>" class="btn btn-sm btn-light" title="แก้ไขรายการ"><i class="bi bi-pencil"></i></a></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$all): ?><tr><td colspan="8" class="text-center text-muted py-4">ยังไม่มีประวัติ</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php layout_end(); ?>
