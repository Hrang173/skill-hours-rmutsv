<?php
use App\Services\ProgressService;

/**
 * ติดตามขั้นตอนของนักศึกษา แยกตามปีที่เข้า (จากรหัสนักศึกษาหลักที่ 2-3)
 *  - ไม่ระบุปี: สรุปทุกปีที่เข้า + จำนวนนักศึกษาในแต่ละขั้นตอน
 *  - ?year=2566: รายชื่อนักศึกษาปีนั้น พร้อมขั้นตอน (กรองตามขั้นตอน/ค้นหาได้)
 *  - ค้นหาจากหน้าแรก: ปีที่เข้า (66 / 2566) → ไปหน้าปีนั้น · รหัสนักศึกษาเต็ม (666415850004) → หน้าปี 66 เฉพาะคนนั้น
 *    · อื่น ๆ (ชื่อ / รหัสบางส่วน) → แสดงผลค้นหาทุกปี
 */
$year = (string) input('year', '');
$stage = (string) input('stage', '');
$kw = (string) input('q', '');
$from = ProgressService::fromSql();
$yearSql = ProgressService::entryYearSql();
$stageSql = ProgressService::stageSql();

if ($year === '' && $kw !== '') {
    $k = trim($kw);
    if (preg_match('/^(\d{2}|25\d{2})$/', $k)) {
        redirect('/registrar/progress', ['year' => strlen($k) === 2 ? 2500 + (int) $k : (int) $k]);
    }
    if ($y = entry_year_from_code($k)) {
        redirect('/registrar/progress', ['year' => $y, 'q' => ltrim($k, 'sS')]);
    }
}

if ($year === '') {
    $found = $kw !== '' ? q_all('SELECT ' . ProgressService::columns() . " $from
        WHERE st.status = 'studying' AND (st.student_code LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)
        ORDER BY st.student_code LIMIT 100", [like(ltrim(trim($kw), 'sS')), like($kw), like($kw), like($kw)]) : null;
    $rows = q_all("SELECT $yearSql AS y, $stageSql AS stage, COUNT(*) AS n $from WHERE st.status = 'studying' GROUP BY y, stage");
    $years = [];
    foreach ($rows as $r) {
        $key = $r['y'] === null ? 'none' : (string) $r['y'];
        $years[$key][$r['stage']] = (int) $r['n'];
    }
    uksort($years, fn($a, $b) => $a === 'none' ? 1 : ($b === 'none' ? -1 : (int) $b - (int) $a));
} else {
    $where = ["st.status = 'studying'"];
    $params = [];
    if ($year === 'none') {
        $where[] = "$yearSql IS NULL";
    } else {
        $where[] = "$yearSql = ?";
        $params[] = (int) $year;
    }
    if ($kw !== '') {
        $where[] = "(st.student_code LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
        array_push($params, like($kw), like($kw), like($kw));
    }
    $all = q_all('SELECT ' . ProgressService::columns() . " $from WHERE " . implode(' AND ', $where) . ' ORDER BY st.student_code', $params);
    $counts = array_count_values(array_column($all, 'stage'));
    $students = $stage !== '' ? array_values(array_filter($all, fn($s) => $s['stage'] === $stage)) : $all;
}

$yearLabel = fn(string $y) => $y === 'none' ? 'ไม่ทราบปีที่เข้า' : 'รหัส ' . substr($y, 2) . ' (เข้าปี ' . $y . ')';

layout_start('ติดตามขั้นตอนนักศึกษา');
if ($year === ''): ?>
    <?php page_header('ติดตามขั้นตอนนักศึกษา', 'แยกตามปีที่เข้า (จากรหัสนักศึกษาหลักที่ 2-3 เช่น 666415850004 → เข้าปี 2566) · เฉพาะนักศึกษาที่กำลังศึกษา'); ?>
    <form class="row g-2 mb-3" method="get">
        <div class="col-md-6"><input type="search" name="q" class="form-control" value="<?= e($kw) ?>" autofocus
            placeholder="ค้นหาปีที่เข้า (66 หรือ 2566) / รหัสนักศึกษา / ชื่อ"></div>
        <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-search me-1"></i>ค้นหา</button></div>
        <?php if ($kw !== ''): ?><div class="col-md-2"><a href="/registrar/progress" class="btn btn-light w-100">ล้าง</a></div><?php endif; ?>
    </form>
    <?php if ($found !== null): ?>
        <div class="card mb-4">
            <div class="card-header bg-white fw-semibold">ผลการค้นหา "<?= e($kw) ?>" <span class="badge text-bg-light border"><?= count($found) ?></span></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>รหัส</th><th>ชื่อ-สกุล</th><th>ปีที่เข้า</th><th>ชั่วโมง</th><th>ขั้นตอน</th></tr></thead>
                    <tbody>
                    <?php foreach ($found as $s): ?>
                        <tr>
                            <td><code><?= e($s['student_code']) ?></code></td>
                            <td><a href="/students/view?id=<?= $s['id'] ?>"><?= e(full_name($s)) ?></a></td>
                            <td><?php if ($s['entry_year_calc']): ?><a href="?year=<?= (int) $s['entry_year_calc'] ?>">ปี <?= substr((string) $s['entry_year_calc'], 2) ?></a><?php else: ?>-<?php endif; ?></td>
                            <td class="small"><?= fmt_hours($s['earned']) ?> / <?= fmt_hours($s['required']) ?> ชม.</td>
                            <td><?php stage_badge($s['stage']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$found): ?><tr><td colspan="5" class="text-center text-muted py-4">ไม่พบนักศึกษา</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
    <div class="d-flex flex-wrap gap-3 small mb-3">
        <?php foreach (ProgressService::STAGES as $k => [$label, $color, $icon]): ?>
            <span><span class="badge text-bg-<?= $color ?>"><i class="bi bi-<?= $icon ?>"></i></span> <?= $label ?></span>
        <?php endforeach; ?>
    </div>
    <div class="row g-3">
        <?php foreach ($years as $y => $c): $total = array_sum($c); ?>
            <div class="col-md-6 col-xl-4">
                <a href="?year=<?= e($y) ?>" class="card h-100 text-decoration-none text-body card-hover">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h5 class="mb-0"><?= e($yearLabel($y)) ?></h5>
                            <span class="badge text-bg-light border"><?= $total ?> คน</span>
                        </div>
                        <div class="progress mb-3" style="height:.6rem">
                            <?php foreach (ProgressService::STAGES as $k => [$label, $color]): if (empty($c[$k])) continue; ?>
                                <div class="progress-bar bg-<?= $color ?>" style="width:<?= round($c[$k] / $total * 100, 1) ?>%" title="<?= $label ?> <?= $c[$k] ?> คน"></div>
                            <?php endforeach; ?>
                        </div>
                        <ul class="list-unstyled small mb-0">
                            <?php foreach (ProgressService::STAGES as $k => [$label, $color, $icon]): ?>
                                <li class="d-flex justify-content-between"><span><i class="bi bi-<?= $icon ?> text-<?= $color ?> me-1"></i><?= $label ?></span><strong><?= $c[$k] ?? 0 ?></strong></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
        <?php if (!$years): ?><div class="col-12"><div class="alert alert-light border">ยังไม่มีนักศึกษา</div></div><?php endif; ?>
    </div>
<?php else: ?>
    <?php page_header($yearLabel($year), 'ขั้นตอนของนักศึกษาแต่ละคน — คลิกชื่อเพื่อดูรายละเอียด', '<a href="/registrar/progress" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i>ทุกปี</a>'); ?>
    <form class="row g-2 mb-3" method="get">
        <input type="hidden" name="year" value="<?= e($year) ?>">
        <?php if ($stage !== ''): ?><input type="hidden" name="stage" value="<?= e($stage) ?>"><?php endif; ?>
        <div class="col-md-6"><input type="search" name="q" class="form-control" placeholder="รหัส / ชื่อ / นามสกุล" value="<?= e($kw) ?>"></div>
        <div class="col-md-2"><button class="btn btn-primary w-100">ค้นหา</button></div>
    </form>
    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="?<?= e(http_build_query(['year' => $year, 'q' => $kw])) ?>" class="btn btn-sm <?= $stage === '' ? 'btn-dark' : 'btn-outline-dark' ?>">ทั้งหมด <span class="badge text-bg-light"><?= count($all) ?></span></a>
        <?php foreach (ProgressService::STAGES as $k => [$label, $color, $icon]): ?>
            <a href="?<?= e(http_build_query(['year' => $year, 'stage' => $k, 'q' => $kw])) ?>" class="btn btn-sm <?= $stage === $k ? "btn-$color" : "btn-outline-$color" ?>">
                <i class="bi bi-<?= $icon ?>"></i> <?= $label ?> <span class="badge text-bg-light"><?= $counts[$k] ?? 0 ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>รหัส</th><th>ชื่อ-สกุล</th><th>แผน</th><th style="min-width:170px">ชั่วโมง</th><th>ขั้นตอน</th><th>รายละเอียด</th></tr></thead>
                <tbody>
                <?php foreach ($students as $s): $pct = min(100, $s['required'] > 0 ? $s['earned'] / $s['required'] * 100 : 0); ?>
                    <tr>
                        <td><code><?= e($s['student_code']) ?></code></td>
                        <td><a href="/students/view?id=<?= $s['id'] ?>"><?= e(full_name($s)) ?></a></td>
                        <td class="small"><?= program_label($s['program_type']) ?></td>
                        <td>
                            <div class="small"><?= fmt_hours($s['earned']) ?> / <?= fmt_hours($s['required']) ?> ชม.</div>
                            <div class="progress" style="height:.4rem"><div class="progress-bar <?= $pct >= 100 ? 'bg-success' : '' ?>" style="width:<?= round($pct) ?>%"></div></div>
                        </td>
                        <td><?php stage_badge($s['stage']); ?></td>
                        <td class="small text-muted">
                            <?php if ($s['pending']): ?><div>คำขอรออาจารย์อนุมัติ <?= (int) $s['pending'] ?> รายการ</div><?php endif; ?>
                            <?php if ($s['unrecorded']): ?><div>รออาจารย์บันทึกผล <?= (int) $s['unrecorded'] ?> รายการ</div><?php endif; ?>
                            <?php if ($s['stage'] === 'certified'): ?><div><?= e($s['certified_by']) ?> · <?= thai_date($s['certified_at']) ?></div><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$students): ?><tr><td colspan="6" class="text-center text-muted py-4">ไม่มีนักศึกษาในขั้นตอนนี้</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif;
layout_end();
