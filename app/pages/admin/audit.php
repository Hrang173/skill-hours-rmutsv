<?php
$kw = (string) input('q', '');
$where = '1=1';
$params = [];
if ($kw !== '') {
    $where = '(l.action LIKE ? OR u.username LIKE ? OR l.detail LIKE ?)';
    $params = [like($kw), like($kw), like($kw)];
}
$pg = paginate((int) q_val("SELECT COUNT(*) FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id WHERE $where", $params), 50);
$rows = q_all(
    "SELECT l.*, u.username, u.prefix, u.first_name, u.last_name, u.role
       FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id
      WHERE $where ORDER BY l.id DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

layout_start('บันทึกการใช้งาน');
page_header('บันทึกการใช้งานระบบ', 'ตรวจสอบย้อนหลังว่าใครทำอะไร เมื่อไร');
?>
<form class="row g-2 mb-3" method="get">
    <div class="col-md-10"><input type="search" name="q" class="form-control" placeholder="ค้นหา action / ชื่อผู้ใช้ / รายละเอียด" value="<?= e($kw) ?>"></div>
    <div class="col-md-2"><button class="btn btn-primary w-100">ค้นหา</button></div>
</form>
<div class="card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 small">
            <thead class="table-light"><tr><th>เวลา</th><th>ผู้ใช้</th><th>การกระทำ</th><th>รายละเอียด</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="text-nowrap"><?= thai_date($r['created_at'], true) ?></td>
                    <td><?= $r['username'] ? e($r['username']) . ' <span class="text-muted">(' . role_label($r['role']) . ')</span>' : '-' ?></td>
                    <td><code><?= e($r['action']) ?></code></td>
                    <td class="text-break"><?= e($r['detail']) ?></td>
                    <td><?= e($r['ip_address']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?= pagination_links($pg) ?>
<?php layout_end(); ?>
