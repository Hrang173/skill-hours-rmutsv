<?php
use App\Services\ActivityService;

/**
 * มอบหมายกิจกรรมให้นักศึกษาเฉพาะคน
 * เช่น มอบหมายนักศึกษา A ทำงานออกแบบ PCB 21 ชม. ในวันที่ 3, 4, 10 ต.ค.
 */
$me = current_user();
$errors = [];
$preselected = [];

if (is_post()) {
    $studentIds = array_map('intval', (array) ($_POST['student_ids'] ?? []));
    $data = [
        'skill_id'    => input_int('skill_id'),
        'semester_id' => input_int('semester_id') ?: null,
        'title'       => mb_substr((string) input('title', ''), 0, 255),
        'description' => (string) input('description', ''),
        'location'    => mb_substr((string) input('location', ''), 0, 255),
        'hours'       => round((float) input('hours', 0), 1),
    ];
    $sessions = ActivityService::sessionsFromRequest();

    if (!$studentIds) $errors[] = 'กรุณาเลือกนักศึกษาอย่างน้อย 1 คน';
    if ($data['title'] === '') $errors[] = 'กรุณากรอกชื่องาน/กิจกรรม';
    if (!q_val('SELECT 1 FROM skills WHERE id = ?', [$data['skill_id']])) $errors[] = 'กรุณาเลือกทักษะ';
    if ($data['hours'] <= 0 || $data['hours'] > 200) $errors[] = 'จำนวนชั่วโมงต้องอยู่ระหว่าง 0.5 – 200';

    if (!$errors) {
        db()->beginTransaction();
        q(
            "INSERT INTO activities (skill_id, teacher_id, semester_id, title, description, location, hours, capacity, visibility, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, 'assigned', 'open')",
            [$data['skill_id'], $me['id'], $data['semester_id'], $data['title'], $data['description'], $data['location'], $data['hours']]
        );
        $activityId = (int) db()->lastInsertId();
        ActivityService::saveSessions($activityId, $sessions);
        $act = ActivityService::find($activityId);
        $n = ActivityService::assignStudents($act, $studentIds, mb_substr((string) input('note', ''), 0, 500) ?: null);
        db()->commit();
        audit('activity.assign_create', ['activity_id' => $activityId, 'students' => $n]);
        flash('success', "มอบหมาย \"{$data['title']}\" ให้นักศึกษา $n คนเรียบร้อย");
        redirect('/teacher/activity', ['id' => $activityId]);
    }
    keep_old();
    if ($studentIds) {
        $in = implode(',', array_fill(0, count($studentIds), '?'));
        $preselected = q_all("SELECT u.id, u.prefix, u.first_name, u.last_name, s.student_code FROM users u JOIN students s ON s.user_id = u.id WHERE u.id IN ($in)", $studentIds);
    }
} elseif ($sid = input_int('student_id')) {
    // มาจากปุ่ม "มอบหมายกิจกรรม" ในหน้ารายละเอียดนักศึกษา
    $preselected = q_all('SELECT u.id, u.prefix, u.first_name, u.last_name, s.student_code FROM users u JOIN students s ON s.user_id = u.id WHERE u.id = ?', [$sid]);
}

$skills = q_all("SELECT sk.id, sk.name, sk.owner_id FROM skills sk WHERE sk.is_active = 1 ORDER BY sk.sort_order");
$semesters = all_semesters();
$cur = current_semester();

layout_start('มอบหมายกิจกรรมรายบุคคล');
page_header('มอบหมายกิจกรรมรายบุคคล', 'กำหนดงาน จำนวนชั่วโมง และวันเวลาให้นักศึกษาเฉพาะคน (ไม่แสดงในรายการกิจกรรมสาธารณะ)');
?>
<?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post">
    <?= csrf_field() ?>
    <div class="card mb-3">
        <div class="card-header bg-white fw-semibold">1. เลือกนักศึกษา (ค้นหาด้วยรหัสหรือชื่อ)</div>
        <div class="card-body"><?php student_picker($preselected); ?></div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-white fw-semibold">2. รายละเอียดงานที่มอบหมาย</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">ชื่องาน/กิจกรรม <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" required maxlength="255" value="<?= e(old('title')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">จำนวนชั่วโมงที่จะได้ <span class="text-danger">*</span></label>
                    <input type="number" name="hours" class="form-control" step="0.5" min="0.5" max="200" required value="<?= e(old('hours')) ?>" data-hours-input>
                </div>
                <div class="col-md-8">
                    <label class="form-label">ทักษะ <span class="text-danger">*</span></label>
                    <select name="skill_id" class="form-select" required>
                        <option value="">-- เลือกทักษะ --</option>
                        <?php foreach ($skills as $s):
                            $sel = old('skill_id') ? (int) old('skill_id') === (int) $s['id'] : (int) $s['owner_id'] === (int) $me['id']; ?>
                            <option value="<?= $s['id'] ?>" <?= $sel ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">ภาคการศึกษา</label>
                    <select name="semester_id" class="form-select">
                        <?php foreach ($semesters as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= (int) old('semester_id', $cur['id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>><?= e(semester_label($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">สถานที่</label>
                    <input type="text" name="location" class="form-control" maxlength="255" value="<?= e(old('location')) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">รายละเอียดงาน / วิธีการ</label>
                    <textarea name="description" class="form-control" rows="3"><?= e(old('description')) ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label">ข้อความถึงนักศึกษา</label>
                    <input type="text" name="note" class="form-control" maxlength="500" value="<?= e(old('note')) ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-white fw-semibold">3. กำหนดวันและเวลา</div>
        <div class="card-body"><?php sessions_editor(is_post() ? ActivityService::sessionsFromRequest() : []); ?></div>
    </div>

    <div class="text-end">
        <button class="btn btn-primary btn-lg"><i class="bi bi-send-check me-1"></i>มอบหมายกิจกรรม</button>
    </div>
</form>
<?php layout_end(); ?>
