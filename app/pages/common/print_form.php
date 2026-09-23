<?php
use App\Services\HoursService;

/**
 * พิมพ์ "แบบบันทึกการฝึกทักษะวิชาชีพ" (A4 แนวนอน) ตามแบบฟอร์มของคณะ
 * - นักศึกษา: พิมพ์ได้เฉพาะของตัวเอง
 * - อาจารย์ / ฝ่ายทะเบียน: ?student_id=... หรือ ?code=รหัสนักศึกษา
 */
$me = current_user();
if ($me['role'] === 'student') {
    $sid = (int) $me['id'];
} else {
    $sid = input_int('student_id');
    if (!$sid && input('code')) {
        $sid = (int) q_val('SELECT user_id FROM students WHERE student_code = ?', [input('code')]);
    }
}
$st = $sid ? q_one('SELECT u.*, s.* FROM users u JOIN students s ON s.user_id = u.id WHERE u.id = ?', [$sid]) : null;
if (!$st || !can_view_student($sid)) {
    flash('danger', 'ไม่พบข้อมูลนักศึกษา');
    redirect(home_path());
}

$semesterId = input_int('semester') ?: null;
$skillType = (string) input('type', '');           // '1' | '2' | ''
$showSummary = input('summary', '1') === '1';
$headName = (string) input('head', setting('program_head_name', ''));

$records = HoursService::records($sid, $semesterId);
$sum = HoursService::summary($sid, $st['program_type']);
$semesters = all_semesters();
$semMap = array_column($semesters, null, 'id');

// ข้อความ "ประจำภาคการศึกษาที่"
if ($semesterId && isset($semMap[$semesterId])) {
    $semText = semester_label($semMap[$semesterId]);
} else {
    // ภาคการศึกษาที่มีในรายการ เรียงจากเก่าไปใหม่
    $used = array_flip(array_filter(array_column($records, 'semester_id')));
    $labels = [];
    foreach (array_reverse($semesters) as $s) {
        if (isset($used[$s['id']])) {
            $labels[] = semester_label($s);
        }
    }
    $semText = implode(', ', $labels);
}

$perPage = max(3, min(10, (int) setting('print_rows_per_page', 5)));
$pages = array_chunk($records, $perPage) ?: [[]];
$pageCount = count($pages);
$periodHours = array_sum(array_map(fn($r) => $r['result'] === 'pass' ? (float) $r['hours_awarded'] : 0, $records));

audit('print.form', ['student_id' => $sid, 'semester_id' => $semesterId]);
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>แบบบันทึกการฝึกทักษะวิชาชีพ - <?= e($st['student_code']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= asset('css/print.css') ?>" rel="stylesheet">
</head>
<body>

<form class="print-toolbar" method="get">
    <?php if ($me['role'] !== 'student'): ?><input type="hidden" name="student_id" value="<?= $sid ?>"><?php endif; ?>
    <strong><i class="bi bi-printer"></i> พิมพ์แบบบันทึก: <?= e($st['student_code'] . ' ' . full_name($st)) ?></strong>
    <label>ภาคการศึกษา
        <select name="semester" onchange="this.form.submit()">
            <option value="">ทุกภาค (ทั้งหมด)</option>
            <?php foreach ($semesters as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $semesterId === (int) $s['id'] ? 'selected' : '' ?>><?= e(semester_label($s)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>ประเภท
        <select name="type" onchange="this.form.submit()">
            <option value="">ไม่ระบุ</option>
            <option value="1" <?= $skillType === '1' ? 'selected' : '' ?>>ทักษะวิชาชีพ 1</option>
            <option value="2" <?= $skillType === '2' ? 'selected' : '' ?>>ทักษะวิชาชีพ 2</option>
        </select>
    </label>
    <label><input type="checkbox" name="summary" value="1" <?= $showSummary ? 'checked' : '' ?>> แสดงสรุปชั่วโมง</label>
    <input type="hidden" name="summary" value="0" <?= $showSummary ? 'disabled' : '' ?>>
    <label>หัวหน้าหลักสูตร <input type="text" name="head" value="<?= e($headName) ?>" placeholder="ชื่อหัวหน้าหลักสูตร" onchange="this.form.submit()"></label>
    <span class="spacer"></span>
    <button type="button" onclick="window.print()" class="btn-print"><i class="bi bi-printer"></i> พิมพ์ / บันทึกเป็น PDF</button>
    <a href="javascript:history.back()" class="btn-back">กลับ</a>
</form>

<?php foreach ($pages as $pi => $rows): ?>
<section class="sheet">
    <header class="sheet-head">
        <img src="<?= asset('img/logo.png') ?>" alt="" class="logo">
        <div class="org">
            <div><?= e(setting('faculty_name', 'คณะวิศวกรรมศาสตร์และเทคโนโลยี')) ?></div>
            <div><?= e(setting('university_name', 'มหาวิทยาลัยเทคโนโลยีราชมงคลศรีวิชัย วิทยาเขตตรัง')) ?></div>
        </div>
    </header>

    <h1 class="title">แบบบันทึกการฝึกทักษะวิชาชีพ</h1>

    <div class="line line-1">
        <div class="semester">ประจำภาคการศึกษาที่ <span class="fill w-sem"><?= e($semText) ?></span></div>
        <div class="checks">
            <span class="box"><?= $skillType === '1' ? '✓' : '' ?></span> ทักษะวิชาชีพ 1
            <span class="box ms"><?= $skillType === '2' ? '✓' : '' ?></span> ทักษะวิชาชีพ 2
        </div>
    </div>

    <div class="line line-2">
        ชื่อ<span class="fill w-name"><?= e($st['prefix'] . $st['first_name']) ?></span>
        สกุล<span class="fill w-last"><?= e($st['last_name']) ?></span>
        รหัสนักศึกษา<span class="fill w-code"><?= e($st['student_code']) ?></span>
        สาขาวิชา<span class="fill w-major"><?= e($st['major']) ?></span>
    </div>

    <table class="record">
        <colgroup>
            <col style="width:6%"><col style="width:24.5%"><col style="width:15%"><col style="width:9%">
            <col style="width:6.5%"><col style="width:6.5%"><col style="width:15%"><col style="width:11%">
        </colgroup>
        <thead>
            <tr>
                <th rowspan="2">ลำดับที่</th>
                <th rowspan="2">ชื่อทักษะ</th>
                <th rowspan="2">ผู้รับผิดชอบทักษะ</th>
                <th rowspan="2">จำนวนชั่วโมง</th>
                <th colspan="2">ผลการฝึกทักษะ</th>
                <th rowspan="2">ลายมือชื่อ<br>อาจารย์ผู้ควบคุม</th>
                <th rowspan="2">หมายเหตุ</th>
            </tr>
            <tr><th>ผ่าน</th><th>ไม่ผ่าน</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $i => $r): ?>
            <tr>
                <td class="c"><?= $pi * $perPage + $i + 1 ?></td>
                <td>
                    <div class="skill"><?= e($r['skill_name']) ?></div>
                    <div class="sub"><?= e($r['activity_title']) ?></div>
                    <?php if ($r['first_date']): ?>
                        <div class="sub"><?= thai_date($r['first_date']) ?><?= $r['last_date'] && $r['last_date'] !== $r['first_date'] ? ' – ' . thai_date($r['last_date']) : '' ?></div>
                    <?php endif; ?>
                </td>
                <td class="c"><?= e($r['owner_name'] ?? '') ?></td>
                <td class="c big"><?= fmt_hours($r['hours_awarded']) ?></td>
                <td class="c mark"><?= $r['result'] === 'pass' ? '✓' : '' ?></td>
                <td class="c mark"><?= $r['result'] === 'fail' ? '✓' : '' ?></td>
                <td class="c sig">
                    <?php if ($r['sign_method'] === 'online' && $r['signature_data']): ?>
                        <img src="<?= e($r['signature_data']) ?>" alt="ลายเซ็น">
                        <div class="sub">(<?= e($r['signer_name'] ?: $r['teacher_name']) ?>)</div>
                    <?php elseif ($r['sign_method'] === 'name'): ?>
                        <div class="typed"><?= e($r['signer_name']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="small"><?= e($r['remark'] ?? '') ?><?php if ($r['signed_at']): ?><div class="sub">บันทึก <?= thai_date($r['signed_at']) ?></div><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php for ($k = count($rows); $k < $perPage; $k++): ?>
            <tr class="empty"><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
        <?php endfor; ?>
        </tbody>
    </table>

    <div class="sheet-foot">
        <div class="summary">
            <?php if ($showSummary && $pi === $pageCount - 1): ?>
                <?php if ($semesterId): ?>
                    ชั่วโมงที่ผ่านในภาคนี้ <b><?= fmt_hours($periodHours) ?></b> ชม. ·
                <?php endif; ?>
                ชั่วโมงสะสมทั้งหมด (นับได้) <b><?= fmt_hours($sum['earned']) ?></b> / <?= fmt_hours($sum['required']) ?> ชม.
                (แผน <?= program_label($st['program_type']) ?>)
                — <b><?= $sum['complete'] ? 'ครบตามเกณฑ์' : 'ยังไม่ครบ ขาดอีก ' . fmt_hours($sum['remaining']) . ' ชม.' ?></b>
            <?php endif; ?>
        </div>
        <div class="head-sign">
            <div>ลงชื่อ <span class="fill w-sign"></span></div>
            <div class="paren">( <span class="fill w-headname"><?= e($headName) ?></span> )</div>
            <div>หัวหน้าหลักสูตร<span class="fill w-headpos"><?= e(setting('default_major', '')) ?></span></div>
        </div>
    </div>
    <div class="meta">พิมพ์จาก<?= e(config('app.name')) ?> เมื่อ <?= thai_date(date('Y-m-d H:i:s'), true) ?> · หน้า <?= $pi + 1 ?>/<?= $pageCount ?></div>
</section>
<?php endforeach; ?>

<script>
// checkbox "แสดงสรุป": ถ้าไม่ติ๊ก ให้ส่งค่า 0
document.querySelector('input[name=summary][type=checkbox]').addEventListener('change', function () {
    document.querySelector('input[name=summary][type=hidden]').disabled = this.checked;
    this.form.submit();
});
</script>
</body>
</html>
