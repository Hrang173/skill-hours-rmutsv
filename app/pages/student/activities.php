<?php
use App\Services\ActivityService;
use App\Services\HoursService;

$me = current_user();
$cap = HoursService::teacherCap();

// ส่งคำขอ / ยกเลิกคำขอ
if (is_post()) {
    $activityId = input_int('activity_id');
    $act = ActivityService::find($activityId);
    $action = input('action');

    if (!$act || $act['visibility'] !== 'public') {
        flash('danger', 'ไม่พบกิจกรรม');
        redirect_back('/student/activities');
    }

    $existing = q_one('SELECT * FROM participations WHERE activity_id = ? AND student_id = ?', [$activityId, $me['id']]);

    if ($action === 'request') {
        $deadlinePassed = $act['register_deadline'] && strtotime($act['register_deadline']) < time();
        $full = $act['capacity'] !== null && (int) $act['joined_count'] >= (int) $act['capacity'];
        $withTeacher = HoursService::hoursWithTeacher((int) $act['teacher_id'], [$me['id']])[$me['id']] ?? 0.0;
        if ($cap !== null && $withTeacher >= $cap) {
            flash('danger', 'คุณเก็บชั่วโมงกับ ' . $act['teacher_name'] . ' ครบ ' . fmt_hours($cap) . ' ชม. แล้ว (อาจารย์ 1 ท่าน นับได้สูงสุด ' . fmt_hours($cap) . ' ชม.) กรุณาเลือกกิจกรรมของอาจารย์ท่านอื่น');
        } elseif ($act['status'] !== 'open' || $deadlinePassed) {
            flash('danger', 'กิจกรรมนี้ปิดรับสมัครแล้ว');
        } elseif ($full) {
            flash('danger', 'กิจกรรมนี้มีผู้เข้าร่วมเต็มแล้ว');
        } elseif ($existing && !in_array($existing['status'], ['cancelled', 'rejected'], true)) {
            flash('warning', 'คุณส่งคำขอกิจกรรมนี้ไปแล้ว');
        } else {
            $note = mb_substr((string) input('note', ''), 0, 500) ?: null;
            if ($existing) {
                q("UPDATE participations SET status = 'pending', source = 'request', request_note = ?, teacher_note = NULL WHERE id = ?", [$note, $existing['id']]);
            } else {
                q("INSERT INTO participations (activity_id, student_id, source, status, request_note) VALUES (?, ?, 'request', 'pending', ?)", [$activityId, $me['id'], $note]);
            }
            notify(
                (int) $act['teacher_id'],
                'มีคำขอเข้าร่วมกิจกรรมใหม่',
                full_name($me) . ' (' . $me['student_code'] . ') ขอเข้าร่วม "' . $act['title'] . '"',
                '/teacher/activity?id=' . $activityId
            );
            audit('participation.request', ['activity_id' => $activityId]);
            flash('success', 'ส่งคำขอเข้าร่วม "' . $act['title'] . '" แล้ว กรุณารออาจารย์อนุมัติ');
        }
    } elseif ($action === 'cancel' && $existing && in_array($existing['status'], ['pending', 'approved'], true) && $existing['source'] === 'request') {
        q("UPDATE participations SET status = 'cancelled' WHERE id = ?", [$existing['id']]);
        notify((int) $act['teacher_id'], 'นักศึกษายกเลิกคำขอ', full_name($me) . ' ยกเลิกการเข้าร่วม "' . $act['title'] . '"', '/teacher/activity?id=' . $activityId);
        audit('participation.cancel', ['activity_id' => $activityId]);
        flash('success', 'ยกเลิกคำขอเรียบร้อย');
    }
    redirect_back('/student/activities');
}

// ตัวกรอง
$skillId = input_int('skill');
$kw = (string) input('q', '');
$where = ["a.visibility = 'public'", "a.status = 'open'"];
$params = [];
if ($skillId) {
    $where[] = 'a.skill_id = ?';
    $params[] = $skillId;
}
if ($kw !== '') {
    $where[] = '(a.title LIKE ? OR a.description LIKE ?)';
    $params[] = like($kw);
    $params[] = like($kw);
}

$activities = q_all(
    "SELECT a.*, sk.name AS skill_name, CONCAT(t.prefix, t.first_name, ' ', t.last_name) AS teacher_name,
            (SELECT COUNT(*) FROM participations p WHERE p.activity_id = a.id AND p.status IN ('approved','completed')) AS joined_count,
            my.status AS my_status, my.source AS my_source,
            (SELECT COALESCE(SUM(p2.hours_awarded), 0) FROM participations p2 JOIN activities a2 ON a2.id = p2.activity_id
              WHERE a2.teacher_id = a.teacher_id AND p2.student_id = ? AND p2.status = 'completed' AND p2.result = 'pass') AS my_teacher_hours
       FROM activities a
       JOIN skills sk ON sk.id = a.skill_id
       JOIN users t ON t.id = a.teacher_id
       LEFT JOIN participations my ON my.activity_id = a.id AND my.student_id = ?
      WHERE " . implode(' AND ', $where) . "
      ORDER BY (SELECT MIN(session_date) FROM activity_sessions s WHERE s.activity_id = a.id), a.id DESC",
    array_merge([$me['id'], $me['id']], $params)
);
$sessions = ActivityService::sessionsFor(array_column($activities, 'id'));
$skills = q_all('SELECT id, name FROM skills WHERE is_active = 1 ORDER BY sort_order');

layout_start('ขอเข้าร่วมกิจกรรม');
page_header('กิจกรรมที่เปิดรับ', 'เลือกกิจกรรมที่อาจารย์โพสต์ไว้แล้วกดส่งคำขอเข้าร่วม');
?>
<form class="row g-2 mb-4" method="get">
    <div class="col-md-5"><input type="search" name="q" class="form-control" placeholder="ค้นหาชื่อกิจกรรม..." value="<?= e($kw) ?>"></div>
    <div class="col-md-5">
        <select name="skill" class="form-select">
            <option value="">ทุกทักษะ</option>
            <?php foreach ($skills as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $skillId === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i>กรอง</button></div>
</form>

<div class="row g-3">
    <?php foreach ($activities as $a):
        $full = $a['capacity'] !== null && (int) $a['joined_count'] >= (int) $a['capacity'];
        $closed = $a['register_deadline'] && strtotime($a['register_deadline']) < time();
        $teacherLeft = $cap !== null ? max(0, $cap - (float) $a['my_teacher_hours']) : null;
        $capReached = $teacherLeft !== null && $teacherLeft <= 0; ?>
        <div class="col-md-6 col-xl-4">
            <div class="card h-100 activity-card">
                <div class="card-body d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="badge text-bg-light border"><?= e($a['skill_name']) ?></span>
                        <span class="badge bg-primary fs-6"><?= fmt_hours($a['hours']) ?> ชม.</span>
                    </div>
                    <h2 class="h6 fw-bold"><a href="/student/activity?id=<?= $a['id'] ?>" class="stretched-link-off"><?= e($a['title']) ?></a></h2>
                    <div class="small text-muted mb-2"><i class="bi bi-person me-1"></i><?= e($a['teacher_name']) ?>
                        <?php if ($a['location']): ?> · <i class="bi bi-geo-alt me-1"></i><?= e($a['location']) ?><?php endif; ?></div>
                    <?= sessions_list($sessions[$a['id']] ?? []) ?>
                    <div class="small mt-2">
                        ผู้เข้าร่วม <?= (int) $a['joined_count'] ?><?= $a['capacity'] !== null ? ' / ' . (int) $a['capacity'] : '' ?> คน
                        <?php if ($a['register_deadline']): ?> · ปิดรับ <?= thai_date($a['register_deadline'], true) ?><?php endif; ?>
                    </div>
                    <?php if ($teacherLeft !== null && (float) $a['my_teacher_hours'] > 0): ?>
                        <div class="small mt-1 <?= $capReached ? 'text-danger' : ($teacherLeft < (float) $a['hours'] ? 'text-warning-emphasis' : 'text-muted') ?>">
                            <i class="bi bi-info-circle me-1"></i>คุณมีชั่วโมงกับอาจารย์ท่านนี้แล้ว <?= fmt_hours($a['my_teacher_hours']) ?>/<?= fmt_hours($cap) ?> ชม.
                            <?= $capReached ? '(ครบแล้ว)' : ($teacherLeft < (float) $a['hours'] ? '— นับเพิ่มได้อีกแค่ ' . fmt_hours($teacherLeft) . ' ชม.' : '') ?>
                        </div>
                    <?php endif; ?>
                    <div class="mt-auto pt-3">
                        <?php if ($a['my_status'] && !in_array($a['my_status'], ['cancelled', 'rejected'], true)): ?>
                            <div class="d-flex align-items-center justify-content-between">
                                <?= participation_badge(['status' => $a['my_status'], 'result' => null]) ?>
                                <?php if (in_array($a['my_status'], ['pending', 'approved'], true) && $a['my_source'] === 'request'): ?>
                                    <form method="post" onsubmit="return confirm('ยืนยันยกเลิกคำขอ?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="activity_id" value="<?= $a['id'] ?>">
                                        <button name="action" value="cancel" class="btn btn-sm btn-outline-danger">ยกเลิกคำขอ</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($full || $closed || $capReached): ?>
                            <button class="btn btn-secondary w-100" disabled><?= $capReached ? 'ครบ ' . fmt_hours($cap) . ' ชม. กับอาจารย์ท่านนี้แล้ว' : ($full ? 'เต็มแล้ว' : 'ปิดรับสมัครแล้ว') ?></button>
                        <?php else: ?>
                            <button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#requestModal"
                                    data-activity-id="<?= $a['id'] ?>" data-activity-title="<?= e($a['title']) ?>">
                                <i class="bi bi-send me-1"></i>ขอเข้าร่วม
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$activities): ?>
        <div class="col-12"><div class="alert alert-light border text-center">ยังไม่มีกิจกรรมที่เปิดรับในขณะนี้</div></div>
    <?php endif; ?>
</div>

<div class="modal fade" id="requestModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="request">
            <input type="hidden" name="activity_id" id="reqActivityId">
            <div class="modal-header">
                <h5 class="modal-title">ขอเข้าร่วมกิจกรรม</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="fw-semibold" id="reqActivityTitle"></p>
                <label class="form-label">ข้อความถึงอาจารย์ (ไม่บังคับ)</label>
                <textarea name="note" class="form-control" rows="3" maxlength="500" placeholder="เช่น เหตุผลที่สนใจ หรือช่วงเวลาที่สะดวก"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">ปิด</button>
                <button class="btn btn-primary"><i class="bi bi-send me-1"></i>ส่งคำขอ</button>
            </div>
        </form>
    </div>
</div>
<script>
document.getElementById('requestModal').addEventListener('show.bs.modal', e => {
    document.getElementById('reqActivityId').value = e.relatedTarget.dataset.activityId;
    document.getElementById('reqActivityTitle').textContent = e.relatedTarget.dataset.activityTitle;
});
</script>
<?php layout_end(); ?>
