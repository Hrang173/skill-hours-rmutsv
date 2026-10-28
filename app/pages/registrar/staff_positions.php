<?php
/**
 * กำหนดตำแหน่งของบุคลากร: อาจารย์ / หัวหน้าหลักสูตร / ฝ่ายทะเบียน
 * ใช้ได้ทั้งฝ่ายทะเบียนและ admin — บัญชีที่ API สร้างให้อัตโนมัติจะเป็น "อาจารย์" ทั้งหมด จึงต้องมาตั้งตำแหน่งที่หน้านี้
 */
const POSITIONS = [
    'teacher'   => 'อาจารย์',
    'head'      => 'หัวหน้าหลักสูตร',
    'registrar' => 'ฝ่ายทะเบียน',
];

$position = fn(array $u): string => $u['role'] === 'registrar' ? 'registrar' : ($u['head_of_major'] ? 'head' : 'teacher');

$officeMajor = require_office_major();

if (is_post()) {
    $uid = input_int('id');
    $new = (string) input('position', '');
    // หัวหน้าหลักสูตรของสาขาที่เลือกดูอยู่
    $major = $officeMajor['name'];
    $u = q_one("SELECT * FROM users WHERE id = ? AND role IN ('teacher', 'registrar')", [$uid]);

    if (!$u) {
        flash('danger', 'ไม่พบบัญชีบุคลากร');
    } elseif ($uid === user_id()) {
        flash('danger', 'ไม่สามารถเปลี่ยนตำแหน่งของตัวเองได้');
    } elseif (!isset(POSITIONS[$new])) {
        flash('danger', 'ตำแหน่งไม่ถูกต้อง');
    } elseif ($new === 'head' && (int) $u['major_id'] !== (int) $officeMajor['id']) {
        flash('danger', 'หัวหน้าหลักสูตรสาขา' . $major . ' ต้องเป็นอาจารย์ของสาขานี้');
    } else {
        $old = $position($u);
        db()->beginTransaction();
        $replaced = null;
        if ($new === 'head') {
            // 1 สาขามีหัวหน้าหลักสูตรได้ 1 ท่าน → ถอดคนเดิมออก (ยังเป็นอาจารย์)
            $replaced = q_one('SELECT * FROM users WHERE head_of_major = ? AND id <> ?', [$major, $uid]);
            q('UPDATE users SET head_of_major = NULL WHERE head_of_major = ? AND id <> ?', [$major, $uid]);
            q("UPDATE users SET role = 'teacher', head_of_major = ? WHERE id = ?", [$major, $uid]);
        } else {
            // ฝ่ายทะเบียน → อาจารย์: เข้าสาขาที่เลือกดูอยู่ (ถ้ายังไม่มีสาขา)
            q("UPDATE users SET role = ?, head_of_major = NULL, major_id = IF(? = 'teacher', COALESCE(major_id, ?), major_id) WHERE id = ?",
                [$new, $new, $officeMajor['id'], $uid]);
        }
        db()->commit();

        if ($old !== $new || ($new === 'head' && $major !== $u['head_of_major'])) {
            $label = POSITIONS[$new] . ($new === 'head' ? ' สาขา' . $major : '');
            audit('staff.position', ['id' => $uid, 'username' => $u['username'], 'from' => $old, 'to' => $new, 'major' => $new === 'head' ? $major : null]);
            notify($uid, 'ตำแหน่งของคุณถูกเปลี่ยนเป็น ' . $label, 'เปลี่ยนโดย ' . full_name(current_user()), '/');
            flash('success', 'ตั้ง ' . full_name($u) . ' เป็น' . $label . ' แล้ว'
                . ($replaced ? ' (' . full_name($replaced) . ' กลับเป็นอาจารย์)' : ''));
        } else {
            flash('info', 'ตำแหน่งไม่ได้เปลี่ยน');
        }
    }
    redirect_back('/registrar/staff-positions');
}

$kw = (string) input('q', '');
// อาจารย์ของสาขาที่เลือก + ฝ่ายทะเบียนทั้งหมด
$where = "(u.role = 'registrar' OR (u.role = 'teacher' AND u.major_id = ?))";
$params = [(int) $officeMajor['id']];
if ($kw !== '') {
    $where .= ' AND (u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
    array_push($params, like($kw), like($kw), like($kw));
}
$staff = q_all(
    "SELECT u.*, (SELECT COUNT(*) FROM activities a WHERE a.teacher_id = u.id) AS activity_count
       FROM users u WHERE $where ORDER BY u.is_active DESC, FIELD(u.role, 'registrar', 'teacher'), u.head_of_major IS NULL, u.first_name",
    $params
);

$badge = ['teacher' => 'secondary', 'head' => 'success', 'registrar' => 'primary'];

layout_start('ตำแหน่งบุคลากร');
page_header('ตำแหน่งบุคลากร', 'สาขา' . $officeMajor['name'] . ' · กำหนดว่าบุคลากรแต่ละท่านเป็นอาจารย์ หัวหน้าหลักสูตร หรือฝ่ายทะเบียน — อาจารย์ที่ยังไม่ระบุสาขาอยู่ที่หน้า "ยังไม่ระบุสาขา"');
?>
<form class="row g-2 mb-3" method="get">
    <div class="col-md-8"><input type="search" name="q" class="form-control" placeholder="ชื่อผู้ใช้ / ชื่อ / นามสกุล" value="<?= e($kw) ?>"></div>
    <div class="col-md-2"><button class="btn btn-primary w-100">ค้นหา</button></div>
</form>
<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>ชื่อ-สกุล</th><th>ชื่อผู้ใช้</th><th>ตำแหน่งปัจจุบัน</th><th style="min-width:420px">เปลี่ยนตำแหน่ง</th></tr></thead>
            <tbody>
            <?php foreach ($staff as $s):
                $pos = $position($s); ?>
                <tr class="<?= $s['is_active'] ? '' : 'text-muted' ?>">
                    <td>
                        <?= e(full_name($s)) ?>
                        <?php if (!$s['is_active']): ?><span class="badge text-bg-light border ms-1">ระงับ</span><?php endif; ?>
                        <?php if ($s['activity_count']): ?><div class="small text-muted">กิจกรรมที่สร้าง <?= (int) $s['activity_count'] ?> รายการ</div><?php endif; ?>
                    </td>
                    <td><code><?= e($s['username']) ?></code><?= $s['auth_source'] === 'university' ? ' <span class="badge text-bg-light border">e-Passport</span>' : '' ?></td>
                    <td>
                        <span class="badge text-bg-<?= $badge[$pos] ?>"><?= POSITIONS[$pos] ?></span>
                        <?php if ($pos === 'head'): ?><div class="small text-muted"><?= e($s['head_of_major']) ?></div><?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int) $s['id'] === user_id()): ?>
                            <span class="small text-muted">บัญชีของคุณ (เปลี่ยนเองไม่ได้)</span>
                        <?php else: ?>
                            <form method="post" class="d-flex gap-2 staff-position"
                                  data-confirm-registrar="<?= $s['activity_count'] ? 'ท่านนี้มีกิจกรรมที่สร้างไว้ ' . (int) $s['activity_count'] . ' รายการ เมื่อเป็นฝ่ายทะเบียนจะจัดการกิจกรรมเหล่านั้นในฐานะอาจารย์ไม่ได้ (ข้อมูลชั่วโมงของนักศึกษายังอยู่) ยืนยัน?' : '' ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                <select name="position" class="form-select form-select-sm" style="max-width:160px">
                                    <?php foreach (POSITIONS as $k => $l): ?>
                                        <option value="<?= $k ?>" <?= $pos === $k ? 'selected' : '' ?>><?= $l ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="small text-muted align-self-center text-nowrap" data-major-label <?= $pos === 'head' ? '' : 'hidden' ?>>สาขา<?= e($officeMajor['name']) ?></span>
                                <button class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-save me-1"></i>บันทึก</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$staff): ?><tr><td colspan="4" class="text-center text-muted py-4">ไม่พบบุคลากร</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white small text-muted">
        หัวหน้าหลักสูตรมีสิทธิ์เหมือนอาจารย์ และชื่อจะแสดงในวงเล็บท้ายแบบบันทึกของนักศึกษาสาขานั้น (1 สาขา มีหัวหน้าได้ 1 ท่าน) ·
        ฝ่ายทะเบียนใช้เมนูงานทะเบียนแทนเมนูอาจารย์ · บัญชีผู้ดูแลระบบ (admin) ไม่แสดงในหน้านี้
    </div>
</div>
<script>
document.querySelectorAll('.staff-position').forEach(f => {
    const sel = f.querySelector('[name=position]'), major = f.querySelector('[data-major-label]');
    sel.addEventListener('change', () => { major.hidden = sel.value !== 'head'; });
    f.addEventListener('submit', e => {
        if (sel.value === 'registrar' && f.dataset.confirmRegistrar && !confirm(f.dataset.confirmRegistrar)) e.preventDefault();
    });
});
</script>
<?php layout_end(); ?>
