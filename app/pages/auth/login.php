<?php
if (current_user()) {
    redirect(home_path());
}

$error = null;
if (is_post()) {
    $username = (string) input('username', '');
    $password = (string) ($_POST['password'] ?? '');

    // จำกัดการเดารหัสผ่าน: ผิดเกิน 5 ครั้ง ต้องรอ 5 นาที
    $att = $_SESSION['_login_attempts'] ?? ['count' => 0, 'until' => 0];
    if ($att['until'] > time()) {
        $error = 'พยายามเข้าสู่ระบบผิดหลายครั้ง กรุณารอ ' . ceil(($att['until'] - time()) / 60) . ' นาที';
    } elseif ($username === '' || $password === '') {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } else {
        [$user, $error] = attempt_login($username, $password);
        if ($user) {
            unset($_SESSION['_login_attempts']);
            login_user($user);
            $intended = $_SESSION['_intended'] ?? null;
            unset($_SESSION['_intended']);
            if ($intended && str_starts_with($intended, '/') && !str_starts_with($intended, '//')) {
                redirect($intended);
            }
            redirect(home_path($user['role']));
        }
        $att['count']++;
        if ($att['count'] >= 5) {
            $att = ['count' => 0, 'until' => time() + 300];
        }
        $_SESSION['_login_attempts'] = $att;
        audit('login.failed', ['username' => $username]);
    }
}

layout_start('เข้าสู่ระบบ');
?>
<div class="login-wrap">
    <div class="card shadow-sm login-card">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <img src="<?= asset('img/logo.png') ?>" alt="RMUTSV" height="90" class="mb-3">
                <h1 class="h5 fw-bold mb-1"><?= e(config('app.name')) ?></h1>
                <div class="text-muted small"><?= e(setting('faculty_name')) ?><br><?= e(setting('university_name')) ?></div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-1"></i><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="on">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label">ชื่อผู้ใช้ / รหัสนักศึกษา</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" name="username" class="form-control" value="<?= e(input('username')) ?>" required autofocus>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label">รหัสผ่าน</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                </div>
                <button class="btn btn-primary w-100 py-2"><i class="bi bi-box-arrow-in-right me-1"></i>เข้าสู่ระบบ</button>
            </form>

            <?php if (config('app.debug')): ?>
                <details class="mt-4 small text-muted">
                    <summary>บัญชีทดลอง (แสดงเฉพาะโหมดพัฒนา)</summary>
                    <table class="table table-sm small mt-2 mb-0">
                        <tr><td>ผู้ดูแลระบบ</td><td><code>admin</code></td></tr>
                        <tr><td>ฝ่ายทะเบียน</td><td><code>registrar</code></td></tr>
                        <tr><td>อาจารย์</td><td><code>naret</code>, <code>sayamon</code>, <code>chaiwat</code> (หัวหน้าหลักสูตร)</td></tr>
                        <tr><td>นักศึกษา 4 ปี</td><td><code>166404140001</code></td></tr>
                        <tr><td>นักศึกษาเทียบโอน</td><td><code>167404150001</code></td></tr>
                        <tr><td>รหัสผ่าน</td><td><code>password123</code></td></tr>
                    </table>
                </details>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php layout_end(); ?>
