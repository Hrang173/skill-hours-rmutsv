<?php
$id = input_int('id');
$u = $id ? q_one('SELECT u.*, s.student_code, s.program_type, s.faculty, s.major, s.entry_year, s.advisor_id, s.status AS student_status FROM users u LEFT JOIN students s ON s.user_id = u.id WHERE u.id = ?', [$id]) : null;
if ($id && !$u) {
    flash('danger', 'ไม่พบผู้ใช้');
    redirect('/registrar/users');
}
$role = $u['role'] ?? (in_array(input('role'), ALL_ROLES, true) ? input('role') : 'student');
if (!in_array($role, manageable_roles(), true)) {
    flash('danger', 'คุณไม่มีสิทธิ์จัดการบัญชี' . role_label($role));
    redirect('/registrar/users');
}
$errors = [];

if (is_post()) {
    $d = [
        'prefix'       => mb_substr((string) input('prefix', ''), 0, 50),
        'first_name'   => mb_substr((string) input('first_name', ''), 0, 100),
        'last_name'    => mb_substr((string) input('last_name', ''), 0, 100),
        'email'        => (string) input('email', '') ?: null,
        'phone'        => mb_substr((string) input('phone', ''), 0, 30) ?: null,
        'username'     => (string) input('username', ''),
        'password'     => (string) ($_POST['password'] ?? ''),
        'must_change'  => input('must_change') === '1' ? 1 : 0,
        'student_code' => (string) input('student_code', ''),
        'program_type' => input('program_type') === 'transfer' ? 'transfer' : '4year',
        'faculty'      => (string) input('faculty', setting('faculty_name')),
        'major'        => (string) input('major', setting('default_major')),
        'entry_year'   => input_int('entry_year') ?: null,
        'advisor_id'   => input_int('advisor_id') ?: null,
        'student_status' => in_array(input('student_status'), ['studying', 'graduated', 'inactive'], true) ? input('student_status') : 'studying',
    ];
    if ($role === 'student') {
        $d['username'] = $d['student_code'];
        if (!preg_match('/^[0-9A-Za-z\-]{5,20}$/', $d['student_code'])) $errors[] = 'รหัสนักศึกษาไม่ถูกต้อง';
        elseif (q_val('SELECT 1 FROM students WHERE student_code = ? AND user_id <> ?', [$d['student_code'], $id])) $errors[] = 'รหัสนักศึกษานี้มีอยู่แล้ว';
    }
    if (!preg_match('/^[0-9A-Za-z._\-]{3,50}$/', $d['username'])) $errors[] = 'ชื่อผู้ใช้ต้องเป็นตัวอักษรภาษาอังกฤษ/ตัวเลข 3-50 ตัว';
    elseif (q_val('SELECT 1 FROM users WHERE username = ? AND id <> ?', [$d['username'], $id])) $errors[] = 'ชื่อผู้ใช้นี้มีอยู่แล้ว';
    if ($d['first_name'] === '' || $d['last_name'] === '') $errors[] = 'กรุณากรอกชื่อและนามสกุล';
    if ($d['email'] && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'อีเมลไม่ถูกต้อง';
    if (!$id && $d['password'] === '' && $role !== 'student') $errors[] = 'กรุณากำหนดรหัสผ่านเริ่มต้น';
    if ($d['password'] !== '' && mb_strlen($d['password']) < 6) $errors[] = 'รหัสผ่านต้องยาวอย่างน้อย 6 ตัว';

    if (!$errors) {
        db()->beginTransaction();
        // นักศึกษาใหม่ที่ไม่ได้ตั้งรหัสผ่าน → ใช้รหัสนักศึกษาเป็นรหัสผ่านเริ่มต้น และบังคับเปลี่ยน
        if (!$id && $role === 'student' && $d['password'] === '') {
            $d['password'] = $d['student_code'];
            $d['must_change'] = 1;
        }
        if ($id) {
            q('UPDATE users SET username=?, prefix=?, first_name=?, last_name=?, email=?, phone=?, must_change_password=? WHERE id=?',
                [$d['username'], $d['prefix'], $d['first_name'], $d['last_name'], $d['email'], $d['phone'], $d['must_change'], $id]);
            if ($d['password'] !== '') {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($d['password'], PASSWORD_DEFAULT), $id]);
            }
        } else {
            q('INSERT INTO users (username, password_hash, role, prefix, first_name, last_name, email, phone, must_change_password) VALUES (?,?,?,?,?,?,?,?,?)',
                [$d['username'], password_hash($d['password'], PASSWORD_DEFAULT), $role, $d['prefix'], $d['first_name'], $d['last_name'], $d['email'], $d['phone'], $d['must_change']]);
            $id = (int) db()->lastInsertId();
        }
        if ($role === 'student') {
            q('INSERT INTO students (user_id, student_code, program_type, faculty, major, entry_year, advisor_id, status) VALUES (?,?,?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE student_code=VALUES(student_code), program_type=VALUES(program_type), faculty=VALUES(faculty), major=VALUES(major),
                                       entry_year=VALUES(entry_year), advisor_id=VALUES(advisor_id), status=VALUES(status)',
                [$id, $d['student_code'], $d['program_type'], $d['faculty'], $d['major'], $d['entry_year'], $d['advisor_id'], $d['student_status']]);
        }
        db()->commit();
        audit($u ? 'user.update' : 'user.create', ['id' => $id, 'username' => $d['username']]);
        flash('success', 'บันทึกข้อมูลผู้ใช้แล้ว');
        redirect('/registrar/users', ['role' => $role]);
    }
    $u = array_merge($u ?? [], $d);
}

$teachers = q_all("SELECT id, prefix, first_name, last_name FROM users WHERE role = 'teacher' AND is_active = 1 ORDER BY first_name");
$v = fn(string $k, $default = '') => $u[$k] ?? $default;

layout_start($id ? 'แก้ไขผู้ใช้' : 'เพิ่มผู้ใช้');
page_header(($id ? 'แก้ไข' : 'เพิ่ม') . role_label($role), $id ? full_name($u) : null);
?>
<?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="role" value="<?= e($role) ?>">
    <div class="card-body">
        <div class="row g-3">
            <?php if ($role === 'student'): ?>
                <div class="col-md-4"><label class="form-label">รหัสนักศึกษา *</label><input name="student_code" class="form-control" required value="<?= e($v('student_code')) ?>"></div>
                <div class="col-md-4"><label class="form-label">แผนการเรียน *</label>
                    <select name="program_type" class="form-select">
                        <option value="4year" <?= $v('program_type') !== 'transfer' ? 'selected' : '' ?>>4 ปี (100 ชม.)</option>
                        <option value="transfer" <?= $v('program_type') === 'transfer' ? 'selected' : '' ?>>เทียบโอน (50 ชม.)</option>
                    </select>
                </div>
                <div class="col-md-4"><label class="form-label">ปีที่เข้า (พ.ศ.)</label><input type="number" name="entry_year" class="form-control" min="2500" max="2700" value="<?= e($v('entry_year')) ?>"></div>
            <?php else: ?>
                <div class="col-md-4"><label class="form-label">ชื่อผู้ใช้ *</label><input name="username" class="form-control" required value="<?= e($v('username')) ?>"></div>
            <?php endif; ?>
        </div>
        <div class="row g-3 mt-0">
            <div class="col-md-2"><label class="form-label">คำนำหน้า</label><input name="prefix" class="form-control" list="prefixes" value="<?= e($v('prefix')) ?>"></div>
            <div class="col-md-5"><label class="form-label">ชื่อ *</label><input name="first_name" class="form-control" required value="<?= e($v('first_name')) ?>"></div>
            <div class="col-md-5"><label class="form-label">นามสกุล *</label><input name="last_name" class="form-control" required value="<?= e($v('last_name')) ?>"></div>
            <div class="col-md-6"><label class="form-label">อีเมล</label><input type="email" name="email" class="form-control" value="<?= e($v('email')) ?>"></div>
            <div class="col-md-6"><label class="form-label">โทรศัพท์</label><input name="phone" class="form-control" value="<?= e($v('phone')) ?>"></div>
            <?php if ($role === 'student'): ?>
                <div class="col-md-6"><label class="form-label">คณะ</label><input name="faculty" class="form-control" value="<?= e($v('faculty', setting('faculty_name'))) ?>"></div>
                <div class="col-md-6"><label class="form-label">สาขาวิชา</label><input name="major" class="form-control" value="<?= e($v('major', setting('default_major'))) ?>"></div>
                <div class="col-md-6"><label class="form-label">อาจารย์ที่ปรึกษา</label>
                    <select name="advisor_id" class="form-select">
                        <option value="">-</option>
                        <?php foreach ($teachers as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= (int) $v('advisor_id', 0) === (int) $t['id'] ? 'selected' : '' ?>><?= e(full_name($t)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6"><label class="form-label">สถานะนักศึกษา</label>
                    <select name="student_status" class="form-select">
                        <?php foreach (['studying' => 'กำลังศึกษา', 'graduated' => 'สำเร็จการศึกษา', 'inactive' => 'พ้นสภาพ/ลาออก'] as $k => $l): ?>
                            <option value="<?= $k ?>" <?= $v('student_status', 'studying') === $k ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-md-6">
                <label class="form-label"><?= $id ? 'ตั้งรหัสผ่านใหม่ (เว้นว่างถ้าไม่เปลี่ยน)' : 'รหัสผ่านเริ่มต้น' ?></label>
                <input type="text" name="password" class="form-control" autocomplete="new-password">
                <?php if (!$id && $role === 'student'): ?><div class="form-text">เว้นว่าง = ใช้รหัสนักศึกษาเป็นรหัสผ่าน และบังคับเปลี่ยนเมื่อเข้าใช้ครั้งแรก</div><?php endif; ?>
            </div>
            <div class="col-md-6 d-flex align-items-end">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="must_change" value="1" id="mc" <?= $v('must_change_password', $v('must_change', 0)) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mc">บังคับเปลี่ยนรหัสผ่านเมื่อเข้าสู่ระบบครั้งถัดไป</label>
                </div>
            </div>
        </div>
        <datalist id="prefixes">
            <?php foreach (['นาย', 'นางสาว', 'นาง', 'อ.', 'ผศ.', 'ผศ.ดร.', 'รศ.', 'รศ.ดร.', 'ดร.'] as $p): ?><option value="<?= $p ?>"><?php endforeach; ?>
        </datalist>
    </div>
    <div class="card-footer bg-white d-flex justify-content-end gap-2">
        <a href="/registrar/users" class="btn btn-light">ยกเลิก</a>
        <button class="btn btn-primary"><i class="bi bi-save me-1"></i>บันทึก</button>
    </div>
</form>
<?php layout_end(); ?>
