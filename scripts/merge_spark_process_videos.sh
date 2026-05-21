#!/usr/bin/env bash
# Merge 7 SPARK process clips → spark_forno_procesy_final.mp4 (crossfade 0.4s, max ~1.25×)
set -euo pipefail
OUT="/opt/cursor/artifacts"
FADE=0.4
SPEED=0.8  # ~1.14× (within 1.25× cap)

CLIPS=(
  "$OUT/spark_P1_online.webm"
  "$OUT/spark_P2_kds.webm"
  "$OUT/spark_P3_track.webm"
  "$OUT/spark_P4_courses.webm"
  "$OUT/spark_P5_driver.webm"
  "$OUT/spark_P6_ksef.webm"
  "$OUT/spark_P7_bi.webm"
)

for f in "${CLIPS[@]}"; do
  [[ -f "$f" ]] || { echo "Missing: $f"; exit 1; }
done

WORKDIR=$(mktemp -d)
trap 'rm -rf "$WORKDIR"' EXIT

i=0
for f in "${CLIPS[@]}"; do
  ffmpeg -y -i "$f" -an -vf "setpts=${SPEED}*PTS,scale=1920:1080:force_original_aspect_ratio=decrease,pad=1920:1080:(ow-iw)/2:(oh-ih)/2" \
    -c:v libx264 -preset fast -crf 23 "$WORKDIR/p${i}.mp4" 2>/dev/null
  i=$((i+1))
done

dur() { ffprobe -v error -show_entries format=duration -of csv=p=0 "$1"; }

d0=$(dur "$WORKDIR/p0.mp4")
d1=$(dur "$WORKDIR/p1.mp4")
d2=$(dur "$WORKDIR/p2.mp4")
d3=$(dur "$WORKDIR/p3.mp4")
d4=$(dur "$WORKDIR/p4.mp4")
d5=$(dur "$WORKDIR/p5.mp4")
d6=$(dur "$WORKDIR/p6.mp4")

o1=$(python3 -c "print($d0+$d1-$FADE)")
o2=$(python3 -c "print($o1+$d2-$FADE)")
o3=$(python3 -c "print($o2+$d3-$FADE)")
o4=$(python3 -c "print($o3+$d4-$FADE)")
o5=$(python3 -c "print($o4+$d5-$FADE)")
o6=$(python3 -c "print($o5+$d6-$FADE)")

ffmpeg -y \
  -i "$WORKDIR/p0.mp4" -i "$WORKDIR/p1.mp4" -i "$WORKDIR/p2.mp4" -i "$WORKDIR/p3.mp4" \
  -i "$WORKDIR/p4.mp4" -i "$WORKDIR/p5.mp4" -i "$WORKDIR/p6.mp4" \
  -filter_complex "\
[0:v][1:v]xfade=transition=fade:duration=${FADE}:offset=${d0}[v01];\
[v01][2:v]xfade=transition=fade:duration=${FADE}:offset=${o1}[v02];\
[v02][3:v]xfade=transition=fade:duration=${FADE}:offset=${o2}[v03];\
[v03][4:v]xfade=transition=fade:duration=${FADE}:offset=${o3}[v04];\
[v04][5:v]xfade=transition=fade:duration=${FADE}:offset=${o4}[v05];\
[v05][6:v]xfade=transition=fade:duration=${FADE}:offset=${o5}[vout]" \
  -map "[vout]" -c:v libx264 -preset fast -crf 22 -movflags +faststart \
  "$OUT/spark_forno_procesy_final.mp4" 2>/dev/null

echo "✅ $OUT/spark_forno_procesy_final.mp4 ($(du -h "$OUT/spark_forno_procesy_final.mp4" | cut -f1))"
ffprobe -v error -show_entries format=duration -of csv=p=0 "$OUT/spark_forno_procesy_final.mp4" | xargs -I{} echo "Duration: {}s"
