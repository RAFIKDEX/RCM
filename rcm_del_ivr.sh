#!/usr/bin/env bash
set -euo pipefail

IVRNUM="${1:-}"
DP_FILE="/etc/asterisk/extensions_gui.conf"
SOUND="/var/lib/asterisk/sounds/en/rcm/ivr/ivr_${IVRNUM}_welcome.wav"

fail(){ echo "ERROR: $1"; exit 1; }
[[ "$IVRNUM" =~ ^[0-9]{2,6}$ ]] || fail "Invalid IVR number"

# remove [ivr-IVRNUM] section
TMP="$(mktemp)"
awk -v sec="[ivr-${IVRNUM}]" '
  BEGIN{skip=0}
  $0==sec {skip=1; next}
  skip==1 && $0 ~ /^\[[^]]+\]$/ {skip=0}
  skip==0 {print}
' "$DP_FILE" > "$TMP"
cat "$TMP" > "$DP_FILE"
rm -f "$TMP"

# remove internal route line for IVR
# exact line we add:
# exten => 7000,1,Goto(ivr-7000,7000,1)
sed -i "/^exten => ${IVRNUM},1,Goto(ivr-${IVRNUM},${IVRNUM},1)$/d" "$DP_FILE" 2>/dev/null || true

# remove prompt file (optional)
if [[ -f "$SOUND" ]]; then
  rm -f "$SOUND" || true
fi

/usr/sbin/asterisk -rx "dialplan reload" >/dev/null 2>&1 || true
echo "OK: Deleted IVR $IVRNUM"
