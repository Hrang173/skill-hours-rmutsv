<?php
use App\Services\ActivityService;

$me = current_user();

$stats = q_one(
    "SELECT
        (SELECT COUNT(*) FROM activities WHERE teacher_id = :t1 AND status IN ('open','closed')) AS active_count,
        (SELECT COUNT(*) FROM participations p JOIN activities a ON a.id = p.activity_id WHERE a.teacher_id = :t2 AND p.status = 'pending') AS pending_count,
        (SELECT COUNT(*) FROM participations p JOIN activities a ON a.id = p.activity_id WHERE a.teacher_id = :t3 AND p.status = 'approved') AS to_record,
        (SELECT COALESCE(SUM(p.hours_awarded),0) FROM participations p JOIN activities a ON a.id = p.activity_id WHERE a.teacher_id = :t4 AND p.status = 'completed' AND p.result = 'pass') AS hours_given",
    ['t1' => $me['id'], 't2' => $me['id'], 't3' => $me['id'], 't4' => $me['id']]
);

$pending = q_all(
    "SELECT p.id, p.request_note, p.created_at, a.id AS activity_id, a.title, s.student_code, u.prefix, u.first_name, u.last_name
       FROM participations p
       JOIN activities a ON a.id = p.activity_id
       JOIN users u ON u.id = p.student_id
       JOIN students s ON s.user_id = u.id
      WHERE a.teacher_id = ? AND p.status = 'pending'
      ORDER BY p.created_at
      LIMIT 10",
    [$me['id']]
);

$activities = q_all(
    "SELECT a.*, sk.name AS skill_name,
            (SELECT COUNT(*) FROM participations p WHERE p.activity_id = a.id AND p.status IN ('approved','completed')) AS joined_count
       FROM activities a JOIN skills sk ON sk.id = a.skill_id
      WHERE a.teacher_id = ? AND a.status IN ('open','closed')
      ORDER BY a.id DESC LIMIT 8",
    [$me['id']]
);
$sessions = ActivityService::sessionsFor(array_column($activities, 'id'));

layout_start('ภาพรวม');
page_header(
    'สวัสดี ' . full_name($me),
    'อาจารย์ผู้ควบคุมการฝึกทักษะวิชาชีพ',
    '<a href="/teacher/activity/new" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>สร้างกิจกรรม</a>
     <a href="/teacher/assign" class="btn btn-outline-primary"><i class="bi bi-person-check me-1"></i>มอบหมายรายบุคคล</a>'
);
?>
<div class="row g-3 mb-4">
    <?php foreach ([
        ['calendar-event', 'primary', 'กิจกรรมที่ดำเนินอยู่', $stats['active_count']],
        ['inbox', 'warning', 'คำขอรออนุมัติ', $stats['pending_count']],
        ['pencil-square', 'info', 'รอบันทึกผล (คน)', $stats['to_record']],
        ['clock', 'success', 'ชั่วโมงที่ให้ไปแล้ว', fmt_hours($stats['hours_given'])],
    ] as [$icon, $color, $label, $val]): ?>
        <div class="col-6 col-lg-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="stat-icon bg-<?= $color ?>-subtle text-<?= $color ?>"><i class="bi bi-<?= $icon ?>"></i></div>
                    <div class="text-muted small"><?= $label ?></div>
                    <div class="fs-3 fw-bold"><?= $val ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-inbox me-1"></i>คำขอเข้าร่วมล่าสุด</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($pending as $p): ?>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between">
                            <div>
                                <strong><?= e(full_name($p)) ?></strong> <span class="text-muted small"><?= e($p['student_code']) ?></span>
                                <div class="small">→ <a href="/teacher/activity?id=<?= $p['activity_id'] ?>"><?= e($p['title']) ?></a></div>
                                <?php if ($p['request_note']): ?><div class="small text-muted fst-italic">"<?= e($p['request_note']) ?>"</div><?php endif; ?>
                            </div>
                            <span class="small text-muted"><?= thai_date($p['created_at']) ?></span>
                        </div>
                    </li>
                <?php endforeach; ?>
                <?php if (!$pending): ?><li class="list-group-item text-muted small">ไม่มีคำขอที่รออนุมัติ</li><?php endif; ?>
            </ul>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span><i class="bi bi-calendar-event me-1"></i>กิจกรรมที่ดำเนินอยู่</span>
                <a href="/teacher/activities" class="small">ดูทั้งหมด</a>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach ($activities as $a): ?>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between">
                            <a href="/teacher/activity?id=<?= $a['id'] ?>" class="fw-semibold"><?= e($a['title']) ?></a>
                            <span><?= activity_status_badge($a['status']) ?></span>
                        </div>
                        <div class="small text-muted"><?= e($a['skill_name']) ?> · <?= fmt_hours($a['hours']) ?> ชม. · ผู้เข้าร่วม <?= $a['joined_count'] ?> คน
                            <?= $a['visibility'] === 'assigned' ? '<span class="badge text-bg-primary">มอบหมาย</span>' : '' ?></div>
                        <?= sessions_list($sessions[$a['id']] ?? []) ?>
                    </li>
                <?php endforeach; ?>
                <?php if (!$activities): ?><li class="list-group-item text-muted small">ยังไม่มีกิจกรรม</li><?php endif; ?>
            </ul>
        </div>
    </div>
</div>
<?php layout_end(); ?>
