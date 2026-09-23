<?php
/**
 * นำเข้ารายชื่อนักศึกษาจากไฟล์ CSV (บันทึกจาก Excel เป็น "CSV UTF-8")
 * มีรหัสนักศึกษาอยู่แล้ว = อัปเดตข้อมูล, ยังไม่มี = สร้างบัญชีใหม่ (รหัสผ่านเริ่มต้น = รหัสนักศึกษา)
 */
const IMPORT_COLUMNS = ['student_code', 'prefix', 'first_name', 'last_name', 'program_type', 'entry_year', 'email', 'major'];

if (input('template')) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="students-template.csv"');
    echo "\xEF\xBB\xBF" . implode(',', IMPORT_COLUMNS) . "\n";
    echo "166404149999,นาย,ตัวอย่าง,นามสกุลตัวอย่าง,4year,2566,,วิศวกรรมคอมพิวเตอร์และการสื่อสาร\n";
    echo "167404159999,นางสาว,ตัวอย่าง,เทียบโอน,transfer,2567,,\n";
    exit;
}

$report = null;
if (is_post()) {
    $file = $_FILES['csv'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        flash('danger', 'กรุณาเลือกไฟล์ CSV');
        redirect('/registrar/import');
    }
    $fh = fopen($file['tmp_name'], 'r');
    $header = fgetcsv($fh) ?: [];
    $header = array_map(fn($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header);
    $missing = array_diff(['student_code', 'first_name', 'last_name'], $header);
    if ($missing) {
        flash('danger', 'ไฟล์ขาดคอลัมน์: ' . implode(', ', $missing));
        redirect('/registrar/import');
    }

    $report = ['created' => 0, 'updated' => 0, 'errors' => []];
    $line = 1;
    db()->beginTransaction();
    while (($row = fgetcsv($fh)) !== false) {
        $line++;
        if (count(array_filter($row, fn($c) => trim((string) $c) !== '')) === 0) continue;
        $r = [];
        foreach ($header as $i => $h) {
            $r[$h] = trim((string) ($row[$i] ?? ''));
        }
        $code = $r['student_code'];
        if (!preg_match('/^[0-9A-Za-z\-]{5,20}$/', $code) || $r['first_name'] === '') {
            $report['errors'][] = "บรรทัด $line: ข้อมูลไม่ครบหรือรหัสนักศึกษาไม่ถูกต้อง ($code)";
            continue;
        }
        $type = in_array(mb_strtolower($r['program_type'] ?? ''), ['transfer', 'เทียบโอน', 't'], true) ? 'transfer' : '4year';
        $year = (int) ($r['entry_year'] ?? 0) ?: null;
        $email = filter_var($r['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: null;
        $major = ($r['major'] ?? '') ?: setting('default_major');

        $existing = q_one('SELECT user_id FROM students WHERE student_code = ?', [$code]);
        if ($existing) {
            q('UPDATE users SET prefix = ?, first_name = ?, last_name = ?, email = COALESCE(?, email) WHERE id = ?',
                [$r['prefix'] ?? '', $r['first_name'], $r['last_name'], $email, $existing['user_id']]);
            q('UPDATE students SET program_type = ?, entry_year = COALESCE(?, entry_year), major = ? WHERE user_id = ?',
                [$type, $year, $major, $existing['user_id']]);
            $report['updated']++;
        } else {
            if (q_val('SELECT 1 FROM users WHERE username = ?', [$code])) {
                $report['errors'][] = "บรรทัด $line: ชื่อผู้ใช้ $code ถูกใช้แล้ว";
                continue;
            }
            q("INSERT INTO users (username, password_hash, role, prefix, first_name, last_name, email, must_change_password) VALUES (?, ?, 'student', ?, ?, ?, ?, 1)",
                [$code, password_hash($code, PASSWORD_DEFAULT), $r['prefix'] ?? '', $r['first_name'], $r['last_name'], $email]);
            q('INSERT INTO students (user_id, student_code, program_type, entry_year, major, faculty) VALUES (?, ?, ?, ?, ?, ?)',
                [db()->lastInsertId(), $code, $type, $year, $major, setting('faculty_name')]);
            $report['created']++;
        }
    }
    db()->commit();
    fclose($fh);
    audit('import.students', ['created' => $report['created'], 'updated' => $report['updated'], 'errors' => count($report['errors'])]);
}

layout_start('นำเข้านักศึกษา');
page_header('นำเข้ารายชื่อนักศึกษา (CSV)', 'เพิ่มหรืออัปเดตนักศึกษาทีละหลายคน');
?>
<?php if ($report): ?>
    <div class="alert alert-<?= $report['errors'] ? 'warning' : 'success' ?>">
        เพิ่มใหม่ <strong><?= $report['created'] ?></strong> คน · อัปเดต <strong><?= $report['updated'] ?></strong> คน
        <?php if ($report['errors']): ?> · ผิดพลาด <strong><?= count($report['errors']) ?></strong> บรรทัด
            <ul class="small mb-0 mt-2"><?php foreach (array_slice($report['errors'], 0, 50) as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </div>
<?php endif; ?>
<div class="row g-3">
    <div class="col-lg-6">
        <form method="post" enctype="multipart/form-data" class="card">
            <?= csrf_field() ?>
            <div class="card-body">
                <label class="form-label">ไฟล์ CSV (UTF-8)</label>
                <input type="file" name="csv" accept=".csv,text/csv" class="form-control" required>
            </div>
            <div class="card-footer bg-white d-flex justify-content-between">
                <a href="/registrar/import?template=1" class="btn btn-link"><i class="bi bi-download me-1"></i>ดาวน์โหลดไฟล์ตัวอย่าง</a>
                <button class="btn btn-primary"><i class="bi bi-upload me-1"></i>นำเข้า</button>
            </div>
        </form>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header bg-white fw-semibold">รูปแบบไฟล์</div>
            <div class="card-body small">
                <p>แถวแรกเป็นหัวคอลัมน์ (ภาษาอังกฤษ) ดังนี้:</p>
                <table class="table table-sm">
                    <tr><td><code>student_code</code></td><td>รหัสนักศึกษา (จำเป็น)</td></tr>
                    <tr><td><code>prefix</code></td><td>คำนำหน้า เช่น นาย, นางสาว</td></tr>
                    <tr><td><code>first_name</code> / <code>last_name</code></td><td>ชื่อ / นามสกุล (จำเป็น)</td></tr>
                    <tr><td><code>program_type</code></td><td><code>4year</code> หรือ <code>transfer</code> (เทียบโอน)</td></tr>
                    <tr><td><code>entry_year</code></td><td>ปีที่เข้า (พ.ศ.)</td></tr>
                    <tr><td><code>email</code>, <code>major</code></td><td>ไม่บังคับ</td></tr>
                </table>
                <p class="mb-0 text-muted">นักศึกษาใหม่จะได้รหัสผ่านเริ่มต้นเป็นรหัสนักศึกษา และถูกบังคับให้เปลี่ยนเมื่อเข้าสู่ระบบครั้งแรก</p>
            </div>
        </div>
    </div>
</div>
<?php layout_end(); ?>
