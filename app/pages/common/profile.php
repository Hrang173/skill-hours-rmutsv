<?php
$me = current_user();

if (is_post()) {
    $action = input('action');
    if ($action === 'profile') {
        $email = (string) input('email', '');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('danger', 'รูปแบบอีเมลไม่ถูกต้อง');
        } else {
            q('UPDATE users SET email = ?, phone = ? WHERE id = ?', [$email ?: null, mb_substr((string) input('phone', ''), 0, 30) ?: null, $me['id']]);
            audit('profile.update');
            flash('success', 'บันทึกข้อมูลแล้ว');
        }
    } elseif ($action === 'password') {
        $cur = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        if ($me['password_hash'] && !password_verify($cur, $me['password_hash'])) {
            flash('danger', 'รหัสผ่านปัจจุบันไม่ถูกต้อง');
        } elseif (mb_strlen($new) < 8) {
            flash('danger', 'รหัสผ่านใหม่ต้องยาวอย่างน้อย 8 ตัวอักษร');
        } elseif ($new !== $confirm) {
            flash('danger', 'ยืนยันรหัสผ่านใหม่ไม่ตรงกัน');
        } else {
            q('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            audit('profile.password');
            flash('success', 'เปลี่ยนรหัสผ่านเรียบร้อย');
            redirect(home_path());
        }
    }
    redirect('/profile');
}

layout_start('ข้อมูลส่วนตัว');
page_header('ข้อมูลส่วนตัว', role_label($me['role']));
?>
<div class="row g-3">
    <div class="col-lg-6">
        <form method="post" class="card h-100">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="profile">
            <div class="card-header bg-white fw-semibold">ข้อมูลทั่วไป</div>
            <div class="card-body">
                <div class="mb-3"><label class="form-label">ชื่อ-สกุล</label><input class="form-control" value="<?= e(full_name($me)) ?>" disabled></div>
                <div class="mb-3"><label class="form-label">ชื่อผู้ใช้</label><input class="form-control" value="<?= e($me['username']) ?>" disabled></div>
                <?php if ($me['role'] === 'student'): ?>
                    <div class="mb-3"><label class="form-label">แผนการเรียน / สาขา</label><input class="form-control" value="<?= e(program_label($me['program_type']) . ' · ' . $me['major']) ?>" disabled></div>
                <?php endif; ?>
                <div class="mb-3"><label class="form-label">อีเมล</label><input type="email" name="email" class="form-control" value="<?= e($me['email']) ?>"></div>
                <div class="mb-3"><label class="form-label">โทรศัพท์</label><input type="text" name="phone" class="form-control" maxlength="30" value="<?= e($me['phone']) ?>"></div>
                <div class="form-text">หากชื่อ-สกุลหรือแผนการเรียนไม่ถูกต้อง กรุณาติดต่อฝ่ายทะเบียน</div>
            </div>
            <div class="card-footer bg-white text-end"><button class="btn btn-primary">บันทึก</button></div>
        </form>
    </div>
    <div class="col-lg-6">
        <form method="post" class="card h-100">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="password">
            <div class="card-header bg-white fw-semibold">เปลี่ยนรหัสผ่าน</div>
            <div class="card-body">
                <?php if ($me['must_change_password']): ?>
                    <div class="alert alert-warning small">กรุณาตั้งรหัสผ่านใหม่ก่อนเริ่มใช้งาน</div>
                <?php endif; ?>
                <?php if ($me['password_hash']): ?>
                    <div class="mb-3"><label class="form-label">รหัสผ่านปัจจุบัน</label><input type="password" name="current_password" class="form-control" required></div>
                <?php else: ?>
                    <div class="alert alert-info small">บัญชีนี้เข้าสู่ระบบผ่านระบบมหาวิทยาลัย สามารถตั้งรหัสผ่านสำหรับระบบนี้เพิ่มได้</div>
                <?php endif; ?>
                <div class="mb-3"><label class="form-label">รหัสผ่านใหม่ (อย่างน้อย 8 ตัว)</label><input type="password" name="new_password" class="form-control" minlength="8" required></div>
                <div class="mb-3"><label class="form-label">ยืนยันรหัสผ่านใหม่</label><input type="password" name="confirm_password" class="form-control" minlength="8" required></div>
            </div>
            <div class="card-footer bg-white text-end"><button class="btn btn-warning">เปลี่ยนรหัสผ่าน</button></div>
        </form>
    </div>
</div>
<?php layout_end(); ?>
