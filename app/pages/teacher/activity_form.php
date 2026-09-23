<?php
use App\Services\ActivityService;

$me = current_user();
$id = input_int('id');
$act = $id ? ActivityService::find($id) : null;
if ($id && (!$act || (int) $act['teacher_id'] !== (int) $me['id'])) {
    flash('danger', 'ไม่พบกิจกรรม หรือคุณไม่ใช่เจ้าของกิจกรรมนี้');
    redirect('/teacher/activities');
}
$sessions = $act ? ActivityService::sessions($id) : [];
$errors = [];

if (is_post()) {
    $data = [
        'skill_id'          => input_int('skill_id'),
        'semester_id'       => input_int('semester_id') ?: null,
        'title'             => mb_substr((string) input('title', ''), 0, 255),
        'description'       => (string) input('description', ''),
        'location'          => mb_substr((string) input('location', ''), 0, 255),
        'hours'             => round((float) input('hours', 0), 1),
        'capacity'          => input('capacity') === '' || input('capacity') === null ? null : max(1, input_int('capacity')),
        'visibility'        => input('visibility') === 'assigned' ? 'assigned' : 'public',
        'register_deadline' => input('register_deadline') ? str_replace('T', ' ', (string) input('register_deadline')) : null,
        'status'            => in_array(input('status'), ['open', 'closed', 'completed', 'cancelled'], true) ? input('status') : 'open',
    ];
    $sessions = ActivityService::sessionsFromRequest();

    if ($data['title'] === '') $errors[] = 'กรุณากรอกชื่อกิจกรรม';
    if (!q_val('SELECT 1 FROM skills WHERE id = ?', [$data['skill_id']])) $errors[] = 'กรุณาเลือกทักษะ';
    if ($data['hours'] <= 0 || $data['hours'] > 200) $errors[] = 'จำนวนชั่วโมงต้องอยู่ระหว่าง 0.5 – 200';
    foreach ($sessions as $s) {
        if ($s['start_time'] && $s['end_time'] && $s['end_time'] <= $s['start_time']) {
            $errors[] = 'เวลาสิ้นสุดต้องมากกว่าเวลาเริ่ม (' . thai_date($s['session_date']) . ')';
        }
    }

    if (!$errors) {
        db()->beginTransaction();
        if ($act) {
            q(
                'UPDATE activities SET skill_id=?, semester_id=?, title=?, description=?, location=?, hours=?, capacity=?, visibility=?, register_deadline=?, status=? WHERE id=?',
                [$data['skill_id'], $data['semester_id'], $data['title'], $data['description'], $data['location'], $data['hours'], $data['capacity'], $data['visibility'], $data['register_deadline'], $data['status'], $id]
            );
            audit('activity.update', ['id' => $id]);
        } else {
            q(
                'INSERT INTO activities (skill_id, teacher_id, semester_id, title, description, location, hours, capacity, visibility, register_deadline, status) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$data['skill_id'], $me['id'], $data['semester_id'], $data['title'], $data['description'], $data['location'], $data['hours'], $data['capacity'], $data['visibility'], $data['register_deadline'], 'open']
            );
            $id = (int) db()->lastInsertId();
            audit('activity.create', ['id' => $id, 'title' => $data['title']]);
        }
        ActivityService::saveSessions($id, $sessions);
        db()->commit();
        flash('success', $act ? 'บันทึกการแก้ไขแล้ว' : 'สร้างกิจกรรมเรียบร้อย' . ($data['visibility'] === 'public' ? ' นักศึกษาสามารถส่งคำขอเข้าร่วมได้แล้ว' : ' เพิ่มรายชื่อนักศึกษาที่มอบหมายได้ด้านล่าง'));
        redirect('/teacher/activity', ['id' => $id]);
    }
    $act = array_merge($act ?? [], $data);
}

$skills = q_all("SELECT sk.id, sk.name, CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS owner_name, sk.owner_id
                   FROM skills sk LEFT JOIN users u ON u.id = sk.owner_id WHERE sk.is_active = 1 ORDER BY sk.sort_order");
$semesters = all_semesters();
$cur = current_semester();
$v = fn(string $k, $d = '') => $act[$k] ?? $d;

layout_start($id ? 'แก้ไขกิจกรรม' : 'สร้างกิจกรรม');
page_header($id ? 'แก้ไขกิจกรรม' : 'สร้างกิจกรรมใหม่', 'โพสต์กิจกรรมให้นักศึกษาขอเข้าร่วม หรือสร้างเป็นกิจกรรมมอบหมายเฉพาะคน');
?>
<?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="card">
    <?= csrf_field() ?>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label">ชื่อกิจกรรม <span class="text-danger">*</span></label>
                <input type="text" name="title" class="form-control" required maxlength="255" value="<?= e($v('title')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">ประเภท</label>
                <select name="visibility" class="form-select">
                    <option value="public" <?= $v('visibility', 'public') === 'public' ? 'selected' : '' ?>>เปิดให้นักศึกษาขอเข้าร่วม</option>
                    <option value="assigned" <?= $v('visibility') === 'assigned' ? 'selected' : '' ?>>มอบหมายเฉพาะคน (ไม่แสดงในรายการ)</option>
                </select>
            </div>
            <div class="col-md-8">
                <label class="form-label">ทักษะ <span class="text-danger">*</span></label>
                <select name="skill_id" class="form-select" required>
                    <option value="">-- เลือกทักษะ --</option>
                    <?php foreach ($skills as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= (int) $v('skill_id', 0) === (int) $s['id'] || (!$v('skill_id') && (int) $s['owner_id'] === (int) $me['id']) ? 'selected' : '' ?>>
                            <?= e($s['name']) ?> (<?= e($s['owner_name'] ?? '-') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">ภาคการศึกษา</label>
                <select name="semester_id" class="form-select">
                    <option value="">-</option>
                    <?php foreach ($semesters as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= (int) $v('semester_id', $cur['id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>><?= e(semester_label($s)) ?><?= $s['is_current'] ? ' (ปัจจุบัน)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">จำนวนชั่วโมง <span class="text-danger">*</span></label>
                <input type="number" name="hours" class="form-control" step="0.5" min="0.5" max="200" required value="<?= e($v('hours')) ?>" data-hours-input>
            </div>
            <div class="col-md-3">
                <label class="form-label">รับจำนวน (คน)</label>
                <input type="number" name="capacity" class="form-control" min="1" value="<?= e($v('capacity')) ?>" placeholder="ไม่จำกัด">
            </div>
            <div class="col-md-3">
                <label class="form-label">ปิดรับสมัคร</label>
                <input type="datetime-local" name="register_deadline" class="form-control" value="<?= e($v('register_deadline') ? date('Y-m-d\TH:i', strtotime($v('register_deadline'))) : '') ?>">
            </div>
            <?php if ($id): ?>
                <div class="col-md-3">
                    <label class="form-label">สถานะ</label>
                    <select name="status" class="form-select">
                        <?php foreach (['open' => 'เปิดรับ', 'closed' => 'ปิดรับสมัคร', 'completed' => 'เสร็จสิ้น', 'cancelled' => 'ยกเลิก'] as $k => $l): ?>
                            <option value="<?= $k ?>" <?= $v('status') === $k ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-12">
                <label class="form-label">สถานที่</label>
                <input type="text" name="location" class="form-control" maxlength="255" value="<?= e($v('location')) ?>">
            </div>
            <div class="col-12">
                <label class="form-label">รายละเอียด / สิ่งที่ต้องเตรียม</label>
                <textarea name="description" class="form-control" rows="4"><?= e($v('description')) ?></textarea>
            </div>
            <div class="col-12">
                <label class="form-label">วันและเวลาที่ฝึก</label>
                <?php sessions_editor($sessions); ?>
            </div>
        </div>
    </div>
    <div class="card-footer bg-white d-flex justify-content-end gap-2">
        <a href="<?= $id ? '/teacher/activity?id=' . $id : '/teacher/activities' ?>" class="btn btn-light">ยกเลิก</a>
        <button class="btn btn-primary"><i class="bi bi-save me-1"></i>บันทึก</button>
    </div>
</form>
<?php layout_end(); ?>
