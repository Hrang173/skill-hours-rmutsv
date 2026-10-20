#!/usr/bin/env bash
# =====================================================================
#  สร้าง/รีเซ็ตบัญชีทดสอบ 3 บัญชี (ไม่แสดงในหน้าเว็บ — ใช้ส่งให้อาจารย์ทดลองระบบ)
#
#    bash deploy/create-test-accounts.sh  รหัสนักศึกษา  รหัสอาจารย์  รหัสฝ่ายทะเบียน
#
#  บัญชีที่ได้:  teststudent (นักศึกษา 4 ปี) · testteacher (อาจารย์) · testregistrar (ฝ่ายทะเบียน)
#  รันซ้ำได้ — ถ้ามีบัญชีอยู่แล้วจะตั้งรหัสผ่านใหม่และเปิดใช้งานให้
#  รหัสผ่านไม่ถูกเก็บในไฟล์ใดๆ ของโปรเจกต์ (เก็บเฉพาะค่า hash ในฐานข้อมูล)
# =====================================================================
set -euo pipefail

if [ "$#" -ne 3 ]; then
    echo "ใช้:  bash deploy/create-test-accounts.sh  รหัสนักศึกษา  รหัสอาจารย์  รหัสฝ่ายทะเบียน"
    exit 1
fi
for pw in "$@"; do
    if [ "${#pw}" -lt 8 ]; then
        echo "รหัสผ่านต้องยาวอย่างน้อย 8 ตัวอักษร"
        exit 1
    fi
done

# ใช้ sudo เฉพาะเมื่อเรียก docker ตรงๆ ไม่ได้
if ! docker info >/dev/null 2>&1 && [ "$(id -u)" -ne 0 ]; then
    exec sudo bash "$0" "$@"
fi

docker exec -i \
    -e PW_STUDENT="$1" -e PW_TEACHER="$2" -e PW_REGISTRAR="$3" \
    skillhours_web php <<'PHP'
<?php
require '/var/www/html/app/bootstrap.php';

$accounts = [
    // username,       role,        prefix,  ชื่อ,          สกุล,    env รหัสผ่าน
    ['teststudent',   'student',   'นาย',   'นักศึกษา',     'ทดสอบ', 'PW_STUDENT'],
    ['testteacher',   'teacher',   'อ.',    'อาจารย์',      'ทดสอบ', 'PW_TEACHER'],
    ['testregistrar', 'registrar', 'นาง',   'เจ้าหน้าที่ทะเบียน', 'ทดสอบ', 'PW_REGISTRAR'],
];

foreach ($accounts as [$username, $role, $prefix, $first, $last, $envKey]) {
    $hash = password_hash((string) getenv($envKey), PASSWORD_DEFAULT);
    $user = q_one('SELECT id, role FROM users WHERE username = ?', [$username]);
    if ($user && $user['role'] !== $role) {
        fwrite(STDERR, "ข้าม $username: มีบัญชีชื่อนี้อยู่แล้วแต่เป็นบทบาท {$user['role']}\n");
        continue;
    }
    if ($user) {
        q('UPDATE users SET password_hash = ?, is_active = 1, must_change_password = 0 WHERE id = ?', [$hash, $user['id']]);
        $id = (int) $user['id'];
        $what = 'รีเซ็ตรหัสผ่าน';
    } else {
        q(
            "INSERT INTO users (username, password_hash, role, prefix, first_name, last_name, auth_source, must_change_password)
             VALUES (?, ?, ?, ?, ?, ?, 'local', 0)",
            [$username, $hash, $role, $prefix, $first, $last]
        );
        $id = (int) db()->lastInsertId();
        $what = 'สร้างใหม่';
    }
    if ($role === 'student' && !q_val('SELECT 1 FROM students WHERE user_id = ?', [$id])) {
        q(
            "INSERT INTO students (user_id, student_code, program_type, faculty, major, entry_year)
             VALUES (?, 'TEST00000001', '4year', ?, ?, ?)",
            [$id, setting('faculty_name'), setting('default_major'), (int) date('Y') + 543]
        );
    }
    audit('test_account.' . ($what === 'สร้างใหม่' ? 'create' : 'reset'), ['username' => $username, 'role' => $role]);
    printf("  %-14s %-12s %s\n", $username, role_label($role), $what);
}
PHP

echo
echo "เสร็จแล้ว — เข้าสู่ระบบด้วยชื่อผู้ใช้ข้างบน และรหัสผ่านที่ใส่ไว้ในคำสั่ง"
