#!/usr/bin/env bash
# =====================================================================
#  ทดสอบระบบแบบผู้ใช้จริงทุกบทบาท (tests/e2e.php) บนเซิร์ฟเวอร์
#
#    bash deploy/e2e.sh
#
#  - ใช้เวลาประมาณ 1 นาที · ระหว่างนั้นมีสาขาทดสอบ "E2E สาขา A/B" และบัญชี e2e.* ชั่วคราว แล้วลบทิ้งเอง
#  - ผลเต็ม (ภาษาไทย) เก็บที่ /tmp/e2e-result.txt · หน้าจอแสดงสรุปเป็นภาษาอังกฤษ
#  - ควรรันตอนไม่มีผู้ใช้ใช้งาน
# =====================================================================
set -uo pipefail
cd "$(dirname "$0")/.."

DOCKER="docker"
docker info >/dev/null 2>&1 || DOCKER="sudo docker"

$DOCKER compose exec -T web php < tests/e2e.php > /tmp/e2e-result.txt 2>&1
code=$?
grep "E2E RESULT" /tmp/e2e-result.txt || { echo "E2E did not finish (exit $code) - see /tmp/e2e-result.txt"; tail -5 /tmp/e2e-result.txt; }
exit $code
