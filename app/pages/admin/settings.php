<?php
$fields = [
    'required_hours_4year'    => ['ชั่วโมงที่ต้องเก็บ แผน 4 ปี', 'number'],
    'required_hours_transfer' => ['ชั่วโมงที่ต้องเก็บ แผนเทียบโอน', 'number'],
    'max_hours_per_teacher'   => ['ชั่วโมงสูงสุดที่นักศึกษา 1 คน เก็บได้จากอาจารย์ 1 ท่าน', 'number'],
    'enforce_teacher_cap'     => ['บังคับเพดานชั่วโมงต่ออาจารย์ (ส่วนที่เกินไม่นับรวม)', 'bool'],
    'university_name'         => ['ชื่อมหาวิทยาลัย/วิทยาเขต (หัวกระดาษ)', 'text'],
    'faculty_name'            => ['ชื่อคณะ (หัวกระดาษ)', 'text'],
    'default_major'           => ['สาขาวิชาเริ่มต้น', 'text'],
    'print_rows_per_page'     => ['จำนวนแถวต่อหน้าในแบบบันทึก (3-10, ฟอร์มต้นฉบับ = 4, มากกว่า 5 ตัวหนังสือจะถูกย่อ)', 'number'],
];

if (is_post()) {
    foreach ($fields as $key => [, $type]) {
        $val = $type === 'bool' ? (input($key) === '1' ? '1' : '0') : (string) input($key, '');
        if ($type === 'number' && !is_numeric($val)) {
            continue;
        }
        setting_set($key, $val);
    }
    audit('settings.update');
    flash('success', 'บันทึกการตั้งค่าแล้ว');
    redirect('/admin/settings');
}

layout_start('ตั้งค่าระบบ');
page_header('ตั้งค่าระบบ', 'เกณฑ์ชั่วโมงและข้อความบนแบบบันทึก');
?>
<form method="post" class="card" style="max-width:720px">
    <?= csrf_field() ?>
    <div class="card-body">
        <?php foreach ($fields as $key => [$label, $type]): ?>
            <div class="mb-3">
                <?php if ($type === 'bool'): ?>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="<?= $key ?>" value="1" id="<?= $key ?>" <?= setting($key) === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="<?= $key ?>"><?= e($label) ?></label>
                    </div>
                <?php else: ?>
                    <label class="form-label" for="<?= $key ?>"><?= e($label) ?></label>
                    <input type="<?= $type ?>" name="<?= $key ?>" id="<?= $key ?>" class="form-control" value="<?= e(setting($key, '')) ?>" <?= $type === 'number' ? 'step="0.5" min="0"' : '' ?>>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <div class="alert alert-light border small mb-0">
            หัวหน้าหลักสูตร (ท้ายแบบบันทึก) กำหนดได้ที่เมนู <a href="/registrar/program-heads">หัวหน้าหลักสูตร</a><br>
            ค่าการเชื่อมต่อฐานข้อมูลและ API มหาวิทยาลัย ตั้งในไฟล์ <code>.env</code> / <code>docker-compose.yml</code>
        </div>
    </div>
    <div class="card-footer bg-white text-end"><button class="btn btn-primary"><i class="bi bi-save me-1"></i>บันทึก</button></div>
</form>
<?php layout_end(); ?>
