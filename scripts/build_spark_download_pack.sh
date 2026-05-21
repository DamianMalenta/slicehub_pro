#!/usr/bin/env bash
# Buduje SPARK_Forno_Pakiet.zip — jedna paczka do pobrania.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/_docs/SPARK_materialy"
OUT="$SRC/SPARK_Forno_Pakiet.zip"
ART="$ROOT/_docs/SPARK_materialy"

cd "$SRC"
rm -f "$OUT"
zip -r "$OUT" \
  START.html \
  INSTRUKCJA_POBIERANIA.md \
  README.md \
  OPISY_DLA_WNIOSKU.md \
  spark_recording_env.example.json \
  landing.html onepager.html pitchdeck.html \
  hero_*.png \
  wideo/ \
  -x "*.DS_Store" 2>/dev/null

cp -f "$OUT" /opt/cursor/artifacts/SPARK_Forno_Pakiet.zip 2>/dev/null || true

echo "✅ Paczka: $OUT"
du -h "$OUT"
echo "   Kopia: /opt/cursor/artifacts/SPARK_Forno_Pakiet.zip (jeśli istnieje katalog artifacts)"
