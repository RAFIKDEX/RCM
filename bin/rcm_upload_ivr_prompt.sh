#!/usr/bin/env bash
set -euo pipefail

SRC="${1:-}"       # uploaded file path (temp)
IVRNUM="${2:-}"    # 7000
BASENAME="${3:-welcome}"  # welcome / promptname

DST_DIR="/var/lib/asterisk/sounds/en/rcm/ivr"
fail(){ echo "ERROR: $1"; exit 1; }

[[ -n "$SRC" && -f "$SRC" ]] || fail "Source file missing"
[[ "$IVRNUM" =~ ^[0-9]{2,6}$ ]] || fail "Invalid IVR number"
[[ "$BASENAME" =~ ^[a-zA-Z0-9_-]+$ ]] || fail "Invalid basename"

mkdir -p "$DST_DIR"

# final name (wav)
OUT="${DST_DIR}/ivr_${IVRNUM}_${BASENAME}.wav"

# pick converter
if command -v sox >/dev/null 2>&1; then
  sox "$SRC" -r 8000 -c 1 -b 16 -e signed-integer "$OUT"
elif command -v ffmpeg >/dev/null 2>&1; then
  ffmpeg -y -i "$SRC" -ar 8000 -ac 1 -c:a pcm_s16le "$OUT" >/dev/null 2>&1
else
  # no converter: just copy (may fail in asterisk if format unsupported)
  cp -f "$SRC" "$OUT"
fi

chmod 644 "$OUT"
echo "OK: Saved $OUT"
