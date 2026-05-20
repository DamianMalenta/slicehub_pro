#!/usr/bin/env bash
# Pełne środowisko pod nagranie SPARK na VM (localhost).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "═══ SliceHub — bootstrap nagrania SPARK (localhost) ═══"

# 1. MariaDB
if ! mysql -u root -e "SELECT 1" >/dev/null 2>&1; then
  echo "→ Start MariaDB..."
  mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld 2>/dev/null || true
  mysqld_safe &
  sleep 3
fi

# 2. Baza
if ! mysql -u root -e "USE slicehub_pro_v2" >/dev/null 2>&1; then
  echo "→ Tworzę bazę slicehub_pro_v2..."
  mysql -u root -e "CREATE DATABASE IF NOT EXISTS slicehub_pro_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  if [ -f database/migrations/001_init_slicehub_pro_v2.sql ]; then
    mysql -u root slicehub_pro_v2 < database/migrations/001_init_slicehub_pro_v2.sql || true
  fi
  if [ -f scripts/apply_migrations_chain.php ]; then
    php scripts/apply_migrations_chain.php || true
  fi
  php scripts/setup_database.php 2>/dev/null || true
fi

# 3. Apache + symlink
if [ ! -L /var/www/html/slicehub ]; then
  ln -sf "$ROOT" /var/www/html/slicehub 2>/dev/null || true
fi
if ! curl -sf http://localhost/slicehub/ >/dev/null 2>&1; then
  echo "→ Start Apache..."
  apachectl start 2>/dev/null || service apache2 start 2>/dev/null || true
  sleep 2
fi

# 4. Tenant + seed Forno
php scripts/bootstrap_spark_recording_tenant.php
TID=$(php -r 'echo json_decode(file_get_contents("/opt/cursor/artifacts/spark_recording_env.json"),true)["tenant_id"];')

# 5. Apache: wymuś tenant dla Online (opcjonalnie)
export SLICEHUB_TENANT_ID="$TID"

# 6. Prep KDS + kierowca
export SLICEHUB_BASE="http://localhost/slicehub"
export SLICEHUB_OWNER_USER="spark_owner"
export SLICEHUB_OWNER_PASS="password"
export SLICEHUB_DRIVER_USER="spark_driver"
export SLICEHUB_DRIVER_PASS="password"
python3 scripts/prep_spark_demo_orders.py

echo ""
echo "✅ Gotowe. Otwórz: http://localhost/slicehub/modules/hub/index.html"
echo "   Dane: /opt/cursor/artifacts/spark_recording_env.json"
