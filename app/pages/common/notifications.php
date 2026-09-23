<?php
$me = current_user();

if (is_post()) {
    q('UPDATE notifications SET is_read = 1 WHERE user_id = ?', [$me['id']]);
    redirect('/notifications');
}

// คลิกแจ้งเตือน → ทำเครื่องหมายอ่านแล้ว แล้วไปยังลิงก์
if ($open = input_int('open')) {
    $n = q_one('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [$open, $me['id']]);
    if ($n) {
        q('UPDATE notifications SET is_read = 1 WHERE id = ?', [$open]);
        if ($n['link'] && str_starts_with($n['link'], '/') && !str_starts_with($n['link'], '//')) {
            redirect($n['link']);
        }
    }
    redirect('/notifications');
}

$pg = paginate((int) q_val('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$me['id']]), 30);
$rows = q_all("SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", [$me['id']]);

layout_start('การแจ้งเตือน');
page_header('การแจ้งเตือน', null, '<form method="post">' . csrf_field() . '<button class="btn btn-outline-secondary btn-sm"><i class="bi bi-check2-all me-1"></i>อ่านทั้งหมดแล้ว</button></form>');
?>
<div class="list-group">
    <?php foreach ($rows as $n): ?>
        <a href="/notifications?open=<?= $n['id'] ?>" class="list-group-item list-group-item-action <?= $n['is_read'] ? '' : 'list-group-item-primary' ?>">
            <div class="d-flex justify-content-between">
                <strong><?= e($n['title']) ?></strong>
                <small class="text-muted"><?= thai_date($n['created_at'], true) ?></small>
            </div>
            <?php if ($n['message']): ?><div class="small"><?= e($n['message']) ?></div><?php endif; ?>
        </a>
    <?php endforeach; ?>
    <?php if (!$rows): ?><div class="list-group-item text-muted text-center py-4">ไม่มีการแจ้งเตือน</div><?php endif; ?>
</div>
<?= pagination_links($pg) ?>
<?php layout_end(); ?>
