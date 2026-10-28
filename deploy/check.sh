#!/usr/bin/env bash
# =====================================================================
#  ตรวจสุขภาพระบบบนเซิร์ฟเวอร์ (อ่านอย่างเดียว ไม่แก้อะไร)
#
#    bash deploy/check.sh
#
#  - syntax PHP ทุกไฟล์ (ใน container จริง PHP 8.3)
#  - สถานะ container และหน้าเว็บตอบหรือไม่
#  - migration ที่รันแล้ว + จำนวนนักศึกษาแต่ละแผน
#  - กติกาแยก 4 ปี / เทียบโอน จากรหัสนักศึกษา
# =====================================================================
set -uo pipefail
cd "$(dirname "$0")/.."

DOCKER="docker"
docker info >/dev/null 2>&1 || DOCKER="sudo docker"

echo "== PHP syntax"
$DOCKER compose exec -T web sh -c 'find /var/www/html/app /var/www/html/public -name "*.php" -print0 | xargs -0 -n1 php -l' \
    | grep -v "No syntax errors" || echo "   OK ทุกไฟล์"

echo "== containers"
$DOCKER compose ps --format '   {{.Service}}: {{.Status}}'

PORT="$(grep -E '^WEB_PORT=' .env 2>/dev/null | cut -d= -f2 || true)"
PORT="${PORT:-8080}"
echo "== เว็บ (port $PORT)"
curl -s -o /dev/null -w "   หน้าเข้าสู่ระบบ HTTP %{http_code}\n" "http://localhost:$PORT/login"

echo "== migration + แผนการเรียน"
$DOCKER compose exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" skillhours -e "
    SELECT version FROM schema_migrations ORDER BY version;
    SELECT program_type, COUNT(*) AS students FROM students GROUP BY program_type;
    SELECT COALESCE(m.name, CONCAT(CHAR(40), 0x2D, CHAR(41))) AS major, COUNT(s.user_id) AS students FROM students s LEFT JOIN majors m ON m.id = s.major_id GROUP BY m.name;"'

echo "== กติการหัสนักศึกษา (หลักที่ 4: 4 = 4 ปี, 2 = เทียบโอน)"
$DOCKER compose exec -T web php -r '
    require "/var/www/html/app/lib/helpers.php";
    foreach (["s666415850035" => "4year", "s666215850032" => "transfer", "TEST00000001" => null] as $code => $want) {
        $got = program_type_from_code($code);
        printf("   %-14s -> %-8s %s\n", $code, var_export($got, true), $got === $want ? "OK" : "ผิด!");
    }'

echo "== สร้างบัญชีอาจารย์อัตโนมัติ (ทดลองแล้วยกเลิก ไม่บันทึกจริง)"
$DOCKER compose exec -T web php -r '
    require "/var/www/html/app/bootstrap.php";
    db()->beginTransaction();
    $p = ["role" => "teacher", "username" => "zz.selftest", "prefix" => "อ.", "first_name" => "ทดสอบ", "last_name" => "ตรวจระบบ",
          "email" => null, "external_id" => "zz.selftest", "raw_type" => "staff"];
    [$u1, $err] = App\Services\UniversitySync::loginFromProfile($p);
    [$u2] = App\Services\UniversitySync::loginFromProfile($p);
    [$u3, $err3] = App\Services\UniversitySync::loginFromProfile(["raw_type" => "other", "username" => "zz.other"] + $p);
    db()->rollBack();
    echo "   ตั้งค่า auto_create_staff = ", setting("auto_create_staff", "1"), "\n";
    echo "   staff เข้าครั้งแรก  -> ", $u1 ? "สร้าง {$u1["role"]} {$u1["prefix"]}{$u1["first_name"]} {$u1["last_name"]}  OK" : "ไม่สร้าง: $err", "\n";
    echo "   เข้าครั้งที่สอง     -> ", $u1 && $u2 && $u1["id"] === $u2["id"] ? "บัญชีเดิม  OK" : "ผิด!", "\n";
    echo "   type อื่น           -> ", $u3 ? "สร้าง  ผิด!" : "ไม่สร้าง  OK", "\n";'
