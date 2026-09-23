<?php
declare(strict_types=1);

/* ============================================================
 *  โครงหน้า (layout) และเมนูของแต่ละบทบาท
 * ============================================================ */

function nav_items(string $role): array
{
    return match ($role) {
        'student' => [
            ['/student',            'speedometer2',     'ภาพรวมชั่วโมง'],
            ['/student/activities', 'calendar-plus',    'ขอเข้าร่วมกิจกรรม'],
            ['/student/history',    'clock-history',    'ประวัติการเข้าร่วม'],
            ['/print/form',         'printer',          'พิมพ์แบบบันทึกทักษะ'],
        ],
        'teacher' => [
            ['/teacher',              'speedometer2',   'ภาพรวม'],
            ['/teacher/activities',   'calendar-event', 'กิจกรรมของฉัน'],
            ['/teacher/activity/new', 'plus-circle',    'สร้างกิจกรรม'],
            ['/teacher/assign',       'person-check',   'มอบหมายรายบุคคล'],
            ['/teacher/students',     'search',         'ค้นหานักศึกษา'],
            ['/teacher/signature',    'pen',            'ลายเซ็นของฉัน'],
        ],
        'registrar' => [
            ['/registrar',                 'speedometer2',     'ภาพรวม'],
            ['/registrar/students',        'search',           'ค้นหานักศึกษา'],
            ['/registrar/users',           'people',           'จัดการผู้ใช้'],
            ['/registrar/import',          'upload',           'นำเข้านักศึกษา (CSV)'],
            ['/registrar/skills',          'list-check',       'รายชื่อทักษะ'],
            ['/registrar/semesters',       'calendar3',        'ภาคการศึกษา'],
            ['/registrar/university-sync', 'cloud-arrow-down', 'API มหาวิทยาลัย'],
            ['/registrar/api-keys',        'key',              'API Keys'],
            ['/registrar/audit',           'journal-text',     'บันทึกการใช้งาน'],
            ['/registrar/settings',        'gear',             'ตั้งค่าระบบ'],
        ],
        default => [],
    };
}

function layout_start(string $title, array $opts = []): void
{
    $user = current_user();
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $unread = $user ? (int) q_val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$user['id']]) : 0;
    $appName = config('app.name');
    ?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title) ?> · <?= e($appName) ?></title>
    <link rel="icon" type="image/png" href="<?= asset('img/logo.png') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= asset('css/app.css') ?>" rel="stylesheet">
</head>
<body>
<?php if ($user): ?>
<nav class="navbar navbar-expand-lg navbar-dark app-navbar sticky-top">
    <div class="container-fluid">
        <button class="btn btn-link text-white d-lg-none me-2 p-0" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-label="เมนู">
            <i class="bi bi-list fs-3"></i>
        </button>
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= home_path() ?>">
            <img src="<?= asset('img/logo.png') ?>" alt="" height="34">
            <span class="d-none d-sm-inline"><?= e($appName) ?></span>
        </a>
        <div class="ms-auto d-flex align-items-center gap-2">
            <a href="/notifications" class="btn btn-link text-white position-relative" title="การแจ้งเตือน">
                <i class="bi bi-bell fs-5"></i>
                <?php if ($unread): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $unread ?></span>
                <?php endif; ?>
            </a>
            <div class="dropdown">
                <button class="btn btn-link text-white text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="bi bi-person-circle"></i>
                    <span class="d-none d-md-inline"><?= e(full_name($user)) ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><span class="dropdown-item-text small text-muted"><?= e(role_label($user['role'])) ?><?= $user['student_code'] ? ' · ' . e($user['student_code']) : '' ?></span></li>
                    <li><a class="dropdown-item" href="/profile"><i class="bi bi-person me-2"></i>ข้อมูลส่วนตัว / เปลี่ยนรหัสผ่าน</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="/logout"><i class="bi bi-box-arrow-right me-2"></i>ออกจากระบบ</a></li>
                </ul>
            </div>
        </div>
    </div>
</nav>
<div class="d-flex app-shell">
    <aside class="offcanvas-lg offcanvas-start app-sidebar" tabindex="-1" id="sidebar">
        <div class="offcanvas-header d-lg-none">
            <h5 class="offcanvas-title">เมนู</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#sidebar"></button>
        </div>
        <div class="offcanvas-body p-0">
            <nav class="nav flex-column w-100 py-3">
                <?php foreach (nav_items($user['role']) as [$href, $icon, $label]):
                    $active = $path === $href || ($href !== home_path() && str_starts_with($path, $href . '/')); ?>
                    <a class="nav-link <?= $active ? 'active' : '' ?>" href="<?= $href ?>">
                        <i class="bi bi-<?= $icon ?> me-2"></i><?= e($label) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
    </aside>
    <main class="flex-grow-1 app-main">
<?php else: ?>
<div class="app-main-guest">
<?php endif; ?>
        <?php foreach (flashes() as $f): ?>
            <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert">
                <?= e($f['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endforeach; ?>
<?php
}

function layout_end(array $scripts = []): void
{
    $user = current_user();
    ?>
<?php if ($user): ?>
    </main>
</div>
<?php else: ?>
</div>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= asset('js/app.js') ?>"></script>
<?php foreach ($scripts as $s): ?>
<script src="<?= asset($s) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
    clear_old();
}

/** หัวเรื่องของหน้า */
function page_header(string $title, ?string $subtitle = null, string $actions = ''): void
{
    ?>
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
        <div>
            <h1 class="h4 mb-0"><?= e($title) ?></h1>
            <?php if ($subtitle): ?><div class="text-muted small"><?= e($subtitle) ?></div><?php endif; ?>
        </div>
        <?php if ($actions): ?><div class="d-flex flex-wrap gap-2"><?= $actions ?></div><?php endif; ?>
    </div>
    <?php
}

/** แถบความคืบหน้าชั่วโมง */
function progress_bar(float $earned, float $required, bool $big = false): string
{
    $pct = $required > 0 ? min(100, round($earned / $required * 100, 1)) : 0;
    $cls = $pct >= 100 ? 'bg-success' : ($pct >= 50 ? 'bg-primary' : 'bg-warning');
    $h = $big ? '1.4rem' : '.6rem';
    $label = $big ? '<span class="small fw-semibold">' . fmt_hours($earned) . ' / ' . fmt_hours($required) . ' ชม.</span>' : '';
    return '<div class="progress" style="height:' . $h . '" role="progressbar" aria-valuenow="' . $pct . '" aria-valuemin="0" aria-valuemax="100">'
        . '<div class="progress-bar ' . $cls . '" style="width:' . $pct . '%">' . $label . '</div></div>';
}

function completion_badge(bool $complete): string
{
    return $complete
        ? '<span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i>ครบแล้ว</span>'
        : '<span class="badge text-bg-warning"><i class="bi bi-hourglass-split me-1"></i>ยังไม่ครบ</span>';
}

function pagination_links(array $p): string
{
    if ($p['pages'] <= 1) {
        return '';
    }
    $q = $_GET;
    $html = '<nav><ul class="pagination pagination-sm justify-content-center mt-3">';
    $start = max(1, $p['page'] - 3);
    $end = min($p['pages'], $p['page'] + 3);
    for ($i = $start; $i <= $end; $i++) {
        $q['page'] = $i;
        $html .= '<li class="page-item ' . ($i === $p['page'] ? 'active' : '') . '"><a class="page-link" href="?' . e(http_build_query($q)) . '">' . $i . '</a></li>';
    }
    return $html . '</ul></nav>';
}

/** แสดงรายการวันเวลาของกิจกรรม */
function sessions_list(array $sessions): string
{
    if (!$sessions) {
        return '<span class="text-muted">ยังไม่กำหนดวันเวลา</span>';
    }
    $html = '<ul class="list-unstyled mb-0 small">';
    foreach ($sessions as $s) {
        $html .= '<li><i class="bi bi-calendar3 me-1 text-primary"></i>' . e(session_label($s))
            . ($s['detail'] ? ' <span class="text-muted">— ' . e($s['detail']) . '</span>' : '') . '</li>';
    }
    return $html . '</ul>';
}
