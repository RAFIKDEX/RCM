#!/usr/bin/env bash
set -euo pipefail

NUM="${1:-}"
NAME="${2:-}"
DEST_TYPE="${3:-}"   # hangup | extension | ivr | queue
DEST_VAL="${4:-}"    # ext number OR ivr number OR queue name OR "-" for hangup

DP_FILE="/etc/asterisk/extensions_gui.conf"

fail(){ echo "ERROR: $1"; exit 1; }

[[ "$NUM" =~ ^[0-9]{2,6}$ ]] || fail "Invalid number"
[[ "$NAME" =~ ^[a-zA-Z0-9_-]{2,30}$ ]] || fail "Invalid name (2-30, letters/numbers/_-)"
[[ -f "$DP_FILE" ]] || fail "Dialplan file not found: $DP_FILE"

case "$DEST_TYPE" in
  hangup) DEST_VAL="-" ;;
  extension) [[ "$DEST_VAL" =~ ^[0-9]{2,6}$ ]] || fail "Invalid extension";;
  ivr) [[ "$DEST_VAL" =~ ^[0-9]{2,6}$ ]] || fail "Invalid ivr number";;
  queue) [[ "$DEST_VAL" =~ ^[a-zA-Z0-9_-]{2,40}$ ]] || fail "Invalid queue name";;
  *) fail "Invalid dest_type";;
esac

# --- remove old ann section if exists ---
tmp="$(mktemp)"
awk -v sec="[ann-${NUM}]" '
  BEGIN{skip=0}
  $0==sec {skip=1; next}
  skip==1 && $0 ~ /^\[[^]]+\]$/ {skip=0}
  skip==0 {print}
' "$DP_FILE" > "$tmp"
cat "$tmp" > "$DP_FILE"
rm -f "$tmp"

# --- ensure internal route line ---
# remove any existing internal route for NUM
sed -i "/^exten => ${NUM},1,Goto(ann-${NUM},${NUM},1)$/d" "$DP_FILE" 2>/dev/null || true

if grep -q "^\[internal\]$" "$DP_FILE"; then
  awk -v n="$NUM" '
    BEGIN{in_internal=0; inserted=0}
    /^\[internal\]$/ {in_internal=1; print; next}
    in_internal && /^\[/ {
      if(!inserted){
        print "exten => " n ",1,Goto(ann-" n "," n ",1)"
        inserted=1
      }
      in_internal=0
    }
    {print}
    END{
      if(in_internal && !inserted){
        print "exten => " n ",1,Goto(ann-" n "," n ",1)"
      }
    }
  ' "$DP_FILE" > "${DP_FILE}.tmp" && mv "${DP_FILE}.tmp" "$DP_FILE"
else
  {
    echo ""
    echo "[internal]"
    echo "exten => ${NUM},1,Goto(ann-${NUM},${NUM},1)"
  } >> "$DP_FILE"
fi

# --- build destination dialplan line ---
DEST_LINE="same => n,Hangup()"
if [[ "$DEST_TYPE" == "extension" ]]; then
  DEST_LINE="same => n,Goto(internal,${DEST_VAL},1)"
elif [[ "$DEST_TYPE" == "ivr" ]]; then
  DEST_LINE="same => n,Goto(ivr-${DEST_VAL},${DEST_VAL},1)"
elif [[ "$DEST_TYPE" == "queue" ]]; then
  DEST_LINE="same => n,Queue(${DEST_VAL})"
fi

# --- append new section ---
cat >> "$DP_FILE" <<EOF

; --- ANN GUI: ${NAME} ---
[ann-${NUM}]
exten => ${NUM},1,Answer()
 same => n,Playback(rcm/ann/ann_${NUM})
 ${DEST_LINE}
EOF

/usr/sbin/asterisk -rx "dialplan reload" >/dev/null 2>&1 || true
echo "OK: Added/Updated ANN ${NUM} in ${DP_FILE}"
