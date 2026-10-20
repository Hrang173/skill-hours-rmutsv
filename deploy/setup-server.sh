#!/usr/bin/env bash
# =====================================================================
#  ติดตั้งระบบบนเซิร์ฟเวอร์ Ubuntu 22.04 / 24.04 ด้วยคำสั่งเดียว
#
#    cd ~/skill-hours-rmutsv
#    bash deploy/setup-server.sh
#
#  สิ่งที่สคริปต์ทำ:
#   1) ติดตั้ง Docker Engine + Docker Compose (ถ้ายังไม่มี) จาก repository ทางการของ Docker
#   2) สร้างไฟล์ .env ครั้งแรก (สุ่มรหัสผ่านฐานข้อมูลใหม่ + ตั้ง APP_URL เป็น IP ของเครื่อง)
#   3) เปิดพอร์ตใน firewall (ถ้าเปิด ufw ไว้)
#   4) build และรันระบบด้วย docker compose
#  รันซ้ำได้ปลอดภัย (ใช้ตอนอัปเดตโค้ดก็ได้)
# =====================================================================
set -euo pipefail

cd "$(dirname "$0")/.."

# ต้องใช้สิทธิ์ root — ถ้าไม่ใช่ จะถามรหัสผ่าน sudo แล้วรันตัวเองใหม่
if [ "$(id -u)" -ne 0 ]; then
    exec sudo bash "$0" "$@"
fi
REAL_USER="${SUDO_USER:-root}"

rand_hex() { openssl rand -hex 16 2>/dev/null || head -c 16 /dev/urandom | od -An -tx1 | tr -d ' 
'; }
say() { printf '\n\033[1;34m==> %s\033[0m\n' "$1"; }

# ---------------------------------------------------------------- 1) Docker
if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
    say "ติดตั้ง Docker (ใช้เวลาสักครู่)"
    apt-get update
    apt-get install -y ca-certificates curl
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc
    CODENAME="$(. /etc/os-release && echo "${UBUNTU_CODENAME:-$VERSION_CODENAME}")"
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $CODENAME stable" \
        > /etc/apt/sources.list.d/docker.list
    apt-get update
    apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
    systemctl enable --now docker
    if [ "$REAL_USER" != "root" ]; then
        usermod -aG docker "$REAL_USER"   # ให้ user ใช้คำสั่ง docker ได้โดยไม่ต้อง sudo (มีผลหลัง logout/login)
    fi
else
    say "มี Docker อยู่แล้ว: $(docker --version)"
fi

# ---------------------------------------------------------------- 2) .env
IP="$(hostname -I | awk '{print $1}')"
if [ ! -f .env ]; then
    say "สร้างไฟล์ .env (สุ่มรหัสผ่านฐานข้อมูลใหม่)"
    cp .env.example .env
    sed -i "s|^APP_URL=.*|APP_URL=http://$IP:8080|" .env
    sed -i "s|^DB_PASS=.*|DB_PASS=$(rand_hex)|" .env
    sed -i "s|^DB_ROOT_PASS=.*|DB_ROOT_PASS=$(rand_hex)|" .env
    chown "$REAL_USER": .env
    chmod 600 .env
else
    say "ใช้ไฟล์ .env เดิม"
fi

WEB_PORT="$(grep -E '^WEB_PORT=' .env | cut -d= -f2 || true)"; WEB_PORT="${WEB_PORT:-8080}"
PMA_PORT="$(grep -E '^PMA_PORT=' .env | cut -d= -f2 || true)"; PMA_PORT="${PMA_PORT:-8081}"

# ---------------------------------------------------------------- 3) firewall
if command -v ufw >/dev/null 2>&1 && ufw status | grep -q "Status: active"; then
    say "เปิดพอร์ต $WEB_PORT (เว็บ) และ $PMA_PORT (phpMyAdmin) ใน ufw"
    ufw allow "$WEB_PORT/tcp"
    ufw allow "$PMA_PORT/tcp"
fi

# ---------------------------------------------------------------- 4) run
say "build และเปิดระบบ (ครั้งแรกใช้เวลา 2-5 นาที)"
docker compose up -d --build

say "รอฐานข้อมูลพร้อม..."
for _ in $(seq 1 60); do
    if curl -fs -o /dev/null "http://localhost:$WEB_PORT/login"; then
        break
    fi
    sleep 2
done
docker compose ps

cat <<EOF

=====================================================================
  ติดตั้งเสร็จแล้ว เปิดจากเครื่องอื่นในวงแลนเดียวกันได้ที่:

    เว็บระบบ     : http://$IP$([ "$WEB_PORT" = "80" ] || echo ":$WEB_PORT")
    phpMyAdmin  : http://$IP:$PMA_PORT

  บัญชีทดลองทุกบัญชีรหัสผ่าน password123 (ควรเปลี่ยนก่อนใช้งานจริง)
  อัปเดตโค้ดครั้งต่อไป:  git pull && bash deploy/setup-server.sh
=====================================================================
EOF
