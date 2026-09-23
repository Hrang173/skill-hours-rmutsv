<?php
use App\Services\HoursService;

$required = HoursService::requiredSqlExpr('st');
$base = "FROM students st JOIN users u ON u.id = st.user_id AND u.is_active = 1
         LEFT JOIN (" . HoursService::earnedSql() . ") h ON h.student_id = st.user_id";

$byProgram = q_all(
    "SELECT st.program_type, COUNT(*) AS total,
            SUM(COALESCE(h.earned, 0) >= $required) AS completed,
            AVG(COALESCE(h.earned, 0)) AS avg_hours
     $base
     GROUP BY st.program_type"
);
$totals = ['total' => 0, 'completed' => 0];
foreach ($byProgram as $r) {
    $totals['total'] += (int) $r['total'];
    $totals['completed'] += (int) $r['completed'];
}

$byYear = q_all(
    "SELECT st.entry_year, st.program_type, COUNT(*) AS total, SUM(COALESCE(h.earned, 0) >= $required) AS completed, AVG(COALESCE(h.earned,0)) AS avg_hours
     $base
     GROUP BY st.entry_year, st.program_type
     ORDER BY st.entry_year DESC, st.program_type"
);

$bySkill = q_all(
    "SELECT sk.name, COALESCE(SUM(p.hours_awarded), 0) AS hours, COUNT(DISTINCT p.student_id) AS students
       FROM skills sk
       LEFT JOIN activities a ON a.skill_id = sk.id
       LEFT JOIN participations p ON p.activity_id = a.id AND p.status = 'completed' AND p.result = 'pass'
      GROUP BY sk.id, sk.name ORDER BY sk.sort_order"
);
$maxSkill = max(1, ...array_map(fn($r) => (float) $r['hours'], $bySkill ?: [['hours' => 1]]));

$counts = q_one(
    "SELECT (SELECT COUNT(*) FROM users WHERE role = 'teacher' AND is_active = 1) AS teachers,
            (SELECT COUNT(*) FROM activities WHERE status IN ('open','closed')) AS active_activities,
            (SELECT COUNT(*) FROM participations WHERE status = 'completed') AS records"
);

layout_start('ภาพรวมฝ่ายทะเบียน');
page_header('ภาพรวมการฝึกทักษะวิชาชีพ', 'สรุปข้อมูลนักศึกษาทั้งหมด', '<a href="/registrar/students" class="btn btn-primary"><i class="bi bi-search me-1"></i>ค้นหานักศึกษา</a>');
?>
<div class="row g-3 mb-4">
    <?php foreach ([
        ['people', 'primary', 'นักศึกษาทั้งหมด', number_format($totals['total'])],
        ['check-circle', 'success', 'เก็บชั่วโมงครบแล้ว', number_format($totals['completed'])],
        ['hourglass-split', 'warning', 'ยังไม่ครบ', number_format($totals['total'] - $totals['completed'])],
        ['calendar-event', 'info', 'กิจกรรมที่ดำเนินอยู่', number_format((int) $counts['active_activities'])],
    ] as [$icon, $color, $label, $val]): ?>
        <div class="col-6 col-lg-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="stat-icon bg-<?= $color ?>-subtle text-<?= $color ?>"><i class="bi bi-<?= $icon ?>"></i></div>
                    <div class="text-muted small"><?= $label ?></div>
                    <div class="fs-3 fw-bold"><?= $val ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">แยกตามแผนการเรียน</div>
            <div class="card-body">
                <?php foreach ($byProgram as $r):
                    $pct = $r['total'] ? round($r['completed'] / $r['total'] * 100) : 0; ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <strong>แผน <?= program_label($r['program_type']) ?> <span class="text-muted small">(เกณฑ์ <?= fmt_hours(HoursService::requiredHours($r['program_type'])) ?> ชม.)</span></strong>
                            <span><?= $r['completed'] ?>/<?= $r['total'] ?> คน (<?= $pct ?>%)</span>
                        </div>
                        <?= progress_bar((float) $r['completed'], (float) $r['total']) ?>
                        <div class="small text-muted mt-1">เฉลี่ย <?= fmt_hours(round((float) $r['avg_hours'], 1)) ?> ชม./คน ·
                            <a href="/registrar/students?program=<?= $r['program_type'] ?>&done=0">ดูคนที่ยังไม่ครบ</a></div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$byProgram): ?><p class="text-muted mb-0">ยังไม่มีข้อมูลนักศึกษา</p><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold">ชั่วโมงที่ฝึกแล้วแยกตามทักษะ</div>
            <div class="card-body">
                <?php foreach ($bySkill as $s): ?>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between small"><span class="text-truncate me-2"><?= e($s['name']) ?></span><span class="text-nowrap"><?= fmt_hours($s['hours']) ?> ชม. · <?= $s['students'] ?> คน</span></div>
                        <div class="progress" style="height:.5rem"><div class="progress-bar" style="width:<?= round($s['hours'] / $maxSkill * 100) ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-white fw-semibold">แยกตามปีที่เข้าศึกษา</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>ปีที่เข้า</th><th>แผน</th><th class="text-end">จำนวน</th><th class="text-end">ครบแล้ว</th><th class="text-end">ชม.เฉลี่ย</th><th style="width:30%"></th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($byYear as $r): ?>
                        <tr>
                            <td><?= e($r['entry_year'] ?: '-') ?></td>
                            <td><?= program_label($r['program_type']) ?></td>
                            <td class="text-end"><?= $r['total'] ?></td>
                            <td class="text-end"><?= $r['completed'] ?></td>
                            <td class="text-end"><?= fmt_hours(round((float) $r['avg_hours'], 1)) ?></td>
                            <td><?= progress_bar((float) $r['completed'], (float) $r['total']) ?></td>
                            <td class="text-end"><a href="/registrar/students?year=<?= $r['entry_year'] ?>&program=<?= $r['program_type'] ?>" class="btn btn-sm btn-light">ดูรายชื่อ</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php layout_end(); ?>
