<?php
$role = (string) input('role', '');
$kw = (string) input('q', '');
$roles = manageable_roles();
$rolesIn = implode(',', array_map(fn($r) => "'$r'", $roles));

if (is_post()) {
    $uid = input_int('id');
    $action = input('action');
    if ($uid === user_id()) {
        flash('danger', 'ไม่สามารถเปลี่ยนสถานะบัญชีของตัวเองได้');
    } elseif (!in_array(q_val('SELECT role FROM users WHERE id = ?', [$uid]), $roles, true)) {
        flash('danger', 'คุณไม่มีสิทธิ์จัดการบัญชีนี้');
    } elseif ($action === 'toggle') {
        q('UPDATE users SET is_active = 1 - is_active WHERE id = ?', [$uid]);
        audit('user.toggle', ['id' => $uid]);
        flash('success', 'เปลี่ยนสถานะบัญชีแล้ว');
    }
    redirect_back('/registrar/users');
}

$where = ["u.role IN ($rolesIn)"];
$params = [];
if (in_array($role, $roles, true)) {
    $where[] = 'u.role = ?';
    $params[] = $role;
}
if ($kw !== '') {
    $where[] = "(u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR s.student_code LIKE ?)";
    array_push($params, like($kw), like($kw), like($kw), like($kw));
}
$from = 'FROM users u LEFT JOIN students s ON s.user_id = u.id WHERE ' . implode(' AND ', $where);
$pg = paginate((int) q_val("SELECT COUNT(*) $from", $params), 30);
$rows = q_all("SELECT u.*, s.student_code, s.program_type $from ORDER BY u.role, u.username LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $params);

layout_start('จัดการผู้ใช้');
page_header('จัดการผู้ใช้', implode(' · ', array_map('role_label', $roles)),
    '<div class="dropdown"><button class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-person-plus me-1"></i>เพิ่มผู้ใช้</button>
     <ul class="dropdown-menu dropdown-menu-end">'
    . implode('', array_map(fn($r) => '<li><a class="dropdown-item" href="/registrar/user/edit?role=' . $r . '">' . role_label($r) . '</a></li>', $roles))
    . '</ul></div>
     <a href="/registrar/import" class="btn btn-outline-primary"><i class="bi bi-upload me-1"></i>นำเข้า CSV</a>');
?>
<form class="row g-2 mb-3" method="get">
    <div class="col-md-6"><input type="search" name="q" class="form-control" placeholder="ชื่อผู้ใช้ / ชื่อ / รหัสนักศึกษา" value="<?= e($kw) ?>"></div>
    <div class="col-md-4">
        <select name="role" class="form-select">
            <option value="">ทุกบทบาท</option>
            <?php foreach ($roles as $r): ?>
                <option value="<?= $r ?>" <?= $role === $r ? 'selected' : '' ?>><?= role_label($r) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2"><button class="btn btn-primary w-100">ค้นหา</button></div>
</form>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>ชื่อผู้ใช้</th><th>ชื่อ-สกุล</th><th>บทบาท</th><th>ที่มา</th><th>เข้าใช้ล่าสุด</th><th>สถานะ</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $u): ?>
                <tr>
                    <td><code><?= e($u['username']) ?></code></td>
                    <td><?= e(full_name($u)) ?><?= $u['program_type'] ? ' <span class="small text-muted">(' . program_label($u['program_type']) . ')</span>' : '' ?></td>
                    <td><?= role_label($u['role']) ?><?= $u['head_of_major'] ? ' <span class="badge text-bg-warning" title="' . e($u['head_of_major']) . '">หัวหน้าหลักสูตร</span>' : '' ?></td>
                    <td class="small"><?= $u['auth_source'] === 'university' ? '<span class="badge text-bg-info">API มหาวิทยาลัย</span>' : 'ในระบบ' ?></td>
                    <td class="small"><?= thai_date($u['last_login_at'], true) ?></td>
                    <td><?= $u['is_active'] ? '<span class="badge text-bg-success">ใช้งาน</span>' : '<span class="badge text-bg-secondary">ระงับ</span>' ?></td>
                    <td class="text-end text-nowrap">
                        <a href="/registrar/user/edit?id=<?= $u['id'] ?>" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i></a>
                        <?php if ($u['role'] === 'student'): ?><a href="/students/view?id=<?= $u['id'] ?>" class="btn btn-sm btn-light"><i class="bi bi-eye"></i></a><?php endif; ?>
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                            <button name="action" value="toggle" class="btn btn-sm btn-light" title="<?= $u['is_active'] ? 'ระงับ' : 'เปิดใช้งาน' ?>"><i class="bi bi-<?= $u['is_active'] ? 'pause-circle' : 'play-circle' ?>"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?= pagination_links($pg) ?>
<?php layout_end(); ?>
