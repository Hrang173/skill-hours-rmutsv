#!/usr/bin/env bash
# =====================================================================
#  เปลี่ยนพอร์ตของเว็บ แล้วเปิดระบบใหม่
#
#    bash deploy/set-web-port.sh 80      เข้าเว็บด้วย http://IP ได้เลย (ไม่ต้องพิมพ์ :8080)
#    bash deploy/set-web-port.sh 8080    กลับไปใช้พอร์ตเดิม
#
#  แก้ WEB_PORT และ APP_URL ใน .env (สำรองไฟล์เดิมไว้ก่อน)
# =====================================================================
set -euo pipefail
cd "$(dirname "$0")/.."

PORT="${1:-80}"
if ! [[ "$PORT" =~ ^[0-9]+$ ]] || [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
    echo "พอร์ตไม่ถูกต้อง: $PORT"
    exit 1
fi
if [ ! -f .env ]; then
    echo "ไม่พบไฟล์ .env — รัน  bash deploy/setup-server.sh  ก่อน"
    exit 1
fi

# ถ้ามีโปรแกรมอื่น (เช่น apache2/nginx ที่ติดตั้งในเครื่อง) ใช้พอร์ตนี้อยู่ ต้องปิดก่อน
if command -v ss >/dev/null 2>&1 && ss -ltnH "sport = :$PORT" 2>/dev/null | grep -q . \
   && ! docker ps --format '{{.Ports}}' 2>/dev/null | grep -q ":$PORT->"; then
    echo "พอร์ต $PORT ถูกโปรแกรมอื่นใช้อยู่:"
    sudo ss -ltnp "sport = :$PORT" || true
    echo "ปิดโปรแกรมนั้นก่อน เช่น  sudo systemctl disable --now apache2"
    exit 1
fi

cp -p .env ".env.bak-$(date +%Y%m%d-%H%M%S)"
IP="$(hostname -I | awk '{print $1}')"
URL="http://$IP"
[ "$PORT" != "80" ] && URL="$URL:$PORT"

set_env() {
    if grep -q "^$1=" .env; then sed -i "s|^$1=.*|$1=$2|" .env; else printf '%s=%s\n' "$1" "$2" >> .env; fi
}
set_env WEB_PORT "$PORT"
set_env APP_URL "$URL"
echo "ตั้งพอร์ตเว็บเป็น $PORT  →  $URL"

exec bash deploy/setup-server.sh
