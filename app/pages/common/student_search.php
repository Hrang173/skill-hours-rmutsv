<?php
use App\Services\StudentQuery;

/**
 * ค้นหานักศึกษา (อาจารย์ + ฝ่ายทะเบียน) ด้วยรหัสนักศึกษาหรือชื่อ-สกุล
 */
$me = current_user();
$isRegistrar = $me['role'] === 'registrar';

$f = StudentQuery::filtersFromRequest();
['q' => $kw, 'program' => $program, 'done' => $done, 'year' => $year] = $f;
['from' => $from, 'params' => $params, 'required' => $required] = StudentQuery::build($f);

$pg = paginate((int) q_val("SELECT COUNT(*) $from", $params), 25);
$rows = q_all(
    'SELECT ' . StudentQuery::columns($required) . " $from ORDER BY st.student_code LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);
$years = q_all('SELECT DISTINCT entry_year FROM students WHERE entry_year IS NOT NULL ORDER BY entry_year DESC');

$exportQuery = http_build_query(array_filter(['q' => $kw, 'program' => $program, 'done' => $done, 'year' => $year ?: null], fn($v) => $v !== '' && $v !== null));

layout_start('ค้นหานักศึกษา');
page_header(
    'ค้นหานักศึกษา',
    'ค้นหาด้วยรหัสนักศึกษาหรือชื่อ-สกุล · พบ ' . number_format($pg['total']) . ' คน',
    $isRegistrar ? '<a href="/registrar/export?' . e($exportQuery) . '" class="btn btn-outline-success"><i class="bi bi-file-earmark-spreadsheet me-1"></i>ส่งออก CSV (Excel)</a>' : ''
);
?>
<form class="card card-body mb-3" method="get">
    <div class="row g-2">
        <div class="col-lg-5">
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="search" name="q" class="form-control" placeholder="รหัสนักศึกษา หรือ ชื่อ-สกุล" value="<?= e($kw) ?>" autofocus>
            </div>
        </div>
        <div class="col-6 col-lg-2">
            <select name="program" class="form-select">
                <option value="">ทุกแผนการเรียน</option>
                <option value="4year" <?= $program === '4year' ? 'selected' : '' ?>>4 ปี (100 ชม.)</option>
                <option value="transfer" <?= $program === 'transfer' ? 'selected' : '' ?>>เทียบโอน (50 ชม.)</option>
            </select>
        </div>
        <div class="col-6 col-lg-2">
            <select name="done" class="form-select">
                <option value="">ทุกสถานะ</option>
                <option value="1" <?= $done === '1' ? 'selected' : '' ?>>ครบแล้ว</option>
                <option value="0" <?= $done === '0' ? 'selected' : '' ?>>ยังไม่ครบ</option>
            </select>
        </div>
        <div class="col-6 col-lg-2">
            <select name="year" class="form-select">
                <option value="">ทุกปีที่เข้า</option>
                <?php foreach ($years as $y): ?>
                    <option value="<?= $y['entry_year'] ?>" <?= $year === (int) $y['entry_year'] ? 'selected' : '' ?>><?= $y['entry_year'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-lg-1"><button class="btn btn-primary w-100">ค้นหา</button></div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>รหัสนักศึกษา</th><th>ชื่อ-สกุล</th><th>แผน</th><th style="min-width:14rem">ชั่วโมงสะสม</th><th>สถานะ</th><th class="text-end"></th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $complete = (float) $r['earned'] >= (float) $r['required']; ?>
                <tr>
                    <td><?= e($r['student_code']) ?></td>
                    <td>
                        <a href="/students/view?id=<?= $r['id'] ?>" class="fw-semibold"><?= e(full_name($r)) ?></a>
                        <?= $r['is_active'] ? '' : '<span class="badge text-bg-secondary">ระงับ</span>' ?>
                        <div class="small text-muted"><?= e($r['major']) ?></div>
                    </td>
                    <td><?= program_label($r['program_type']) ?></td>
                    <td>
                        <div class="small mb-1"><?= fmt_hours($r['earned']) ?> / <?= fmt_hours($r['required']) ?> ชม.</div>
                        <?= progress_bar((float) $r['earned'], (float) $r['required']) ?>
                    </td>
                    <td><?= completion_badge($complete) ?></td>
                    <td class="text-end text-nowrap">
                        <a href="/students/view?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary">รายละเอียด</a>
                        <a href="/print/form?student_id=<?= $r['id'] ?>" target="_blank" class="btn btn-sm btn-light" title="พิมพ์แบบบันทึก"><i class="bi bi-printer"></i></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">ไม่พบนักศึกษา</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= pagination_links($pg) ?>
<?php layout_end(); ?>
