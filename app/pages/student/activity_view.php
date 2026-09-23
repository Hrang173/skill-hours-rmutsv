<?php
use App\Services\ActivityService;

$me = current_user();
$act = ActivityService::find(input_int('id'));
$my = $act ? q_one('SELECT * FROM participations WHERE activity_id = ? AND student_id = ?', [$act['id'], $me['id']]) : null;

// กิจกรรมแบบมอบหมาย ดูได้เฉพาะคนที่ถูกมอบหมาย
if (!$act || ($act['visibility'] === 'assigned' && !$my)) {
    http_response_code(404);
    require APP_PATH . '/pages/errors/404.php';
    return;
}
$sessions = ActivityService::sessions((int) $act['id']);

layout_start($act['title']);
page_header($act['title'], $act['skill_name'], '<a href="javascript:history.back()" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i>ย้อนกลับ</a>');
?>
<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-3">ทักษะ</dt><dd class="col-sm-9"><?= e($act['skill_name']) ?></dd>
                    <dt class="col-sm-3">อาจารย์ผู้ควบคุม</dt><dd class="col-sm-9"><?= e($act['teacher_name']) ?></dd>
                    <dt class="col-sm-3">จำนวนชั่วโมง</dt><dd class="col-sm-9"><?= fmt_hours($act['hours']) ?> ชม.</dd>
                    <dt class="col-sm-3">สถานที่</dt><dd class="col-sm-9"><?= e($act['location'] ?: '-') ?></dd>
                    <dt class="col-sm-3">ภาคการศึกษา</dt><dd class="col-sm-9"><?= $act['term'] ? e($act['term'] . '/' . $act['academic_year']) : '-' ?></dd>
                    <dt class="col-sm-3">วันเวลา</dt><dd class="col-sm-9"><?= sessions_list($sessions) ?></dd>
                    <dt class="col-sm-3">รายละเอียด</dt><dd class="col-sm-9"><?= nl2br(e($act['description'] ?: '-')) ?></dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-white fw-semibold">สถานะของฉัน</div>
            <div class="card-body">
                <?php if ($my): ?>
                    <p><?= participation_badge($my) ?> <?= $my['source'] === 'assigned' ? '<span class="badge text-bg-primary">มอบหมาย</span>' : '' ?></p>
                    <?php if ($my['teacher_note']): ?><p class="small"><strong>ข้อความจากอาจารย์:</strong> <?= e($my['teacher_note']) ?></p><?php endif; ?>
                    <?php if ($my['status'] === 'completed'): ?>
                        <p class="mb-1">ชั่วโมงที่ได้รับ: <strong><?= fmt_hours($my['hours_awarded']) ?> ชม.</strong></p>
                        <p class="mb-1 small">บันทึกเมื่อ <?= thai_date($my['signed_at'], true) ?></p>
                        <?php if ($my['remark']): ?><p class="small">หมายเหตุ: <?= e($my['remark']) ?></p><?php endif; ?>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-muted">คุณยังไม่ได้ขอเข้าร่วมกิจกรรมนี้</p>
                    <a href="/student/activities" class="btn btn-primary btn-sm">ไปหน้าขอเข้าร่วม</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php layout_end(); ?>
