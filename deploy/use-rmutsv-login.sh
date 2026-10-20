#!/usr/bin/env bash
# =====================================================================
#  เปิดเข้าสู่ระบบด้วยบัญชี RMUTSV + ปิดโหมดพัฒนา (แก้ไฟล์ .env ให้) แล้วเปิดระบบใหม่
#
#    bash deploy/use-rmutsv-login.sh              ใช้ API elogin (api.rmutsv.ac.th) — ทดสอบกับบัญชีจริงแล้ว
#    bash deploy/use-rmutsv-login.sh epassport    ใช้ API ของ e-Passport
#
#  - สำรอง .env เดิมไว้เป็น .env.bak-วันเวลา ก่อนแก้
#  - ไม่แตะรหัสผ่านฐานข้อมูลและพอร์ต
# =====================================================================
set -euo pipefail
cd "$(dirname "$0")/.."

if [ ! -f .env ]; then
    echo "ไม่พบไฟล์ .env — รัน  bash deploy/setup-server.sh  ก่อน"
    exit 1
fi

FORMAT="${1:-elogin}"
case "$FORMAT" in
    elogin)    BASE_URL="https://api.rmutsv.ac.th";       AUTH_ENDPOINT="/elogin" ;;
    epassport) BASE_URL="https://epassport.rmutsv.ac.th"; AUTH_ENDPOINT="/assets/api/logintest_user.php" ;;
    *) echo "รูปแบบไม่ถูกต้อง: $FORMAT (ใช้ elogin หรือ epassport)"; exit 1 ;;
esac

BACKUP=".env.bak-$(date +%Y%m%d-%H%M%S)"
cp -p .env "$BACKUP"

# ตั้งค่า KEY=VALUE ใน .env (มีอยู่แล้วแทนที่ ไม่มีเพิ่มต่อท้าย)
set_env() {
    local key="$1" value="$2" escaped
    escaped="$(printf '%s' "$value" | sed 's/[&|\\]/\\&/g')"
    if grep -q "^${key}=" .env; then
        sed -i "s|^${key}=.*|${key}=${escaped}|" .env
    else
        printf '%s=%s\n' "$key" "$value" >> .env
    fi
}

set_env APP_DEBUG                false
set_env AUTH_MODE                hybrid
set_env FORGOT_PASSWORD_URL      "https://epassport.rmutsv.ac.th/forgot-pass#/forgot-pass-std"
set_env UNI_API_DRIVER           rmutsv
set_env UNI_API_FORMAT           "$FORMAT"
set_env UNI_API_BASE_URL         "$BASE_URL"
set_env UNI_API_AUTH_ENDPOINT    "$AUTH_ENDPOINT"
set_env UNI_API_STUDENT_ENDPOINT ""
set_env UNI_API_STAFF_ENDPOINT   ""
set_env UNI_API_STUDENT_TYPES    "student,students,std,นักศึกษา"
set_env UNI_API_MAJOR_FIELD      secname
grep -q '^UNI_API_ALLOW_FACCODES=' .env || set_env UNI_API_ALLOW_FACCODES ""

echo
echo "แก้ .env แล้ว (สำรองไฟล์เดิมไว้ที่ $BACKUP):"
grep -E '^(APP_DEBUG|AUTH_MODE|UNI_API_DRIVER|UNI_API_FORMAT|UNI_API_BASE_URL|UNI_API_AUTH_ENDPOINT|UNI_API_MAJOR_FIELD)=' .env | sed 's/^/    /'
echo

# เปิดระบบใหม่ให้ค่าใหม่มีผล
exec bash deploy/setup-server.sh
