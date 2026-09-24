<?php
use App\Services\ActivityService;
use App\Services\HoursService;

$me = current_user();
$sum = HoursService::summary((int) $me['id'], $me['program_type']);

// กิจกรรมที่กำลังจะถึง (อนุมัติแล้ว)
$upcoming = q_all(
    "SELECT a.*, p.source, sk.name AS skill_name, CONCAT(t.prefix, t.first_name, ' ', t.last_name) AS teacher_name
       FROM participations p
       JOIN activities a ON a.id = p.activity_id
       JOIN skills sk ON sk.id = a.skill_id
       JOIN users t ON t.id = a.teacher_id
      WHERE p.student_id = ? AND p.status = 'approved'
      ORDER BY a.id DESC",
    [$me['id']]
);
$sessions = ActivityService::sessionsFor(array_column($upcoming, 'id'));
$pendingCount = (int) q_val("SELECT COUNT(*) FROM participations WHERE student_id = ? AND status = 'pending'", [$me['id']]);

layout_start('ภาพรวมชั่วโมง');
page_header(
    'สวัสดี ' . full_name($me),
    'รหัสนักศึกษา ' . $me['student_code'] . ' · ' . $me['major'] . ' · แผนการเรียน ' . program_label($me['program_type']),
    '<a href="/print/form" class="btn btn-outline-primary"><i class="bi bi-printer me-1"></i>พิมพ์แบบบันทึกทักษะ</a>'
);
?>
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card h-100 hero-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <div class="text-muted small">ชั่วโมงสะสม (นับได้)</div>
                        <div class="display-5 fw-bold"><?= fmt_hours($sum['earned']) ?> <span class="fs-5 text-muted">/ <?= fmt_hours($sum['required']) ?> ชม.</span></div>
                    </div>
                    <div class="fs-5"><?= completion_badge($sum['complete']) ?></div>
                </div>
                <?= progress_bar($sum['earned'], $sum['required'], true) ?>
                <div class="mt-3 small">
                    <?php if ($sum['complete']): ?>
                        <span class="text-success"><i class="bi bi-trophy me-1"></i>ยินดีด้วย! คุณเก็บชั่วโมงครบตามเกณฑ์แผน <?= program_label($me['program_type']) ?> แล้ว</span>
                    <?php else: ?>
                        ยังขาดอีก <strong><?= fmt_hours($sum['remaining']) ?> ชม.</strong> จึงจะครบตามเกณฑ์แผน <?= program_label($me['program_type']) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100 stat-card">
            <div class="card-body">
                <div class="stat-icon bg-info-subtle text-info"><i class="bi bi-hourglass-split"></i></div>
                <div class="text-muted small">ชั่วโมงรอบันทึกผล</div>
                <div class="fs-3 fw-bold"><?= fmt_hours($sum['pending']) ?></div>
                <div class="small text-muted"><?= count($upcoming) ?> กิจกรรมที่อนุมัติแล้ว</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100 stat-card">
            <div class="card-body">
                <div class="stat-icon bg-warning-subtle text-warning"><i class="bi bi-send"></i></div>
                <div class="text-muted small">คำขอรออนุมัติ</div>
                <div class="fs-3 fw-bold"><?= $pendingCount ?></div>
                <a href="/student/activities" class="small">ดูกิจกรรมที่เปิดรับ →</a>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <?php teacher_hours_card($sum); ?>
    </div>
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-list-check me-1"></i>ชั่วโมงแยกตามทักษะ</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($sum['skills'] as $s): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-start">
                        <span><?= e($s['name']) ?><div class="small text-muted"><?= e($s['owner_name'] ?? '-') ?></div></span>
                        <strong class="text-nowrap ms-2"><?= fmt_hours($s['hours']) ?> ชม.</strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="card">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-calendar-check me-1"></i>กิจกรรมที่ต้องไปฝึก</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($upcoming as $a): ?>
                    <li class="list-group-item">
                        <a href="/student/activity?id=<?= $a['id'] ?>" class="fw-semibold"><?= e($a['title']) ?></a>
                        <?php if ($a['source'] === 'assigned'): ?><span class="badge text-bg-primary ms-1">มอบหมาย</span><?php endif; ?>
                        <div class="small text-muted"><?= e($a['skill_name']) ?> · <?= e($a['teacher_name']) ?> · <?= fmt_hours($a['hours']) ?> ชม.</div>
                        <?= sessions_list($sessions[$a['id']] ?? []) ?>
                    </li>
                <?php endforeach; ?>
                <?php if (!$upcoming): ?>
                    <li class="list-group-item text-muted small">ไม่มีกิจกรรมที่รอเข้าร่วม</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>
<?php layout_end(); ?>
