#!/usr/bin/env bash
set -euo pipefail

NUM="${1:-}"
DP_FILE="/etc/asterisk/extensions_gui.conf"
SOUND="/var/lib/asterisk/sounds/en/rcm/ann/ann_${NUM}.wav"

fail(){ echo "ERROR: $1"; exit 1; }
[[ "$NUM" =~ ^[0-9]{2,6}$ ]] || fail "Invalid number"
[[ -f "$DP_FILE" ]] || fail "Dialplan file not found"

# remove section
tmp="$(mktemp)"
awk -v sec="[ann-${NUM}]" '
  BEGIN{skip=0}
  $0==sec {skip=1; next}
  skip==1 && $0 ~ /^\[[^]]+\]$/ {skip=0}
  skip==0 {print}
' "$DP_FILE" > "$tmp"
cat "$tmp" > "$DP_FILE"
rm -f "$tmp"

# remove internal route line
sed -i "/^exten => ${NUM},1,Goto(ann-${NUM},${NUM},1)$/d" "$DP_FILE" 2>/dev/null || true

# remove audio
[[ -f "$SOUND" ]] && rm -f "$SOUND" || true

/usr/sbin/asterisk -rx "dialplan reload" >/dev/null 2>&1 || true
echo "OK: Deleted ANN $NUM"
