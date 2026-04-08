#!/usr/bin/env bash
set -euo pipefail

IVRNUM="${1:-}"      # 7000
IVRNAME="${2:-}"     # cuc-main (for comment only)
LOOPS="${3:-3}"      # 3
FAILMODE="${4:-goto}"  # goto|hangup
FAILEXT="${5:-2222}"   # if goto
MAPFILE="${6:-}"       # temp file contains mappings: "key dest" per line

DP_FILE="/etc/asterisk/extensions_gui.conf"

fail(){ echo "ERROR: $1"; exit 1; }

[[ "$IVRNUM" =~ ^[0-9]{2,6}$ ]] || fail "Invalid IVR number"
[[ "$IVRNAME" =~ ^[a-zA-Z0-9_-]{2,30}$ ]] || fail "Invalid IVR name"
[[ "$LOOPS" =~ ^[0-9]+$ ]] || fail "Invalid loops"
(( LOOPS >= 1 && LOOPS <= 9 )) || fail "Loops must be 1..9"
[[ "$FAILMODE" == "goto" || "$FAILMODE" == "hangup" ]] || fail "Invalid fail mode"
if [[ "$FAILMODE" == "goto" ]]; then
  [[ "$FAILEXT" =~ ^[0-9]{2,6}$ ]] || fail "Invalid fail extension"
fi
[[ -n "$MAPFILE" && -f "$MAPFILE" ]] || fail "Mapping file missing"

# Build fail action
if [[ "$FAILMODE" == "hangup" ]]; then
  FAIL_DIAL=$'Playback(goodbye)\n same => n,Hangup()'
else
  FAIL_DIAL="Goto(internal,${FAILEXT},1)"
fi

LIMIT=$((LOOPS + 1))   # to match your "<4" style with loops=3

# Build mapping lines from file
MAP_LINES=""
while read -r k d; do
  [[ -z "${k:-}" || -z "${d:-}" ]] && continue

  # Key: 1-9 * # OR Asterisk pattern _X. etc
  if [[ ! "$k" =~ ^[1-9]$ && "$k" != "*" && "$k" != "#" && ! "$k" =~ ^_[0-9XZN\.\!\#\*]+$ ]]; then
    fail "Invalid key: $k"
  fi
  [[ "$d" =~ ^[0-9]{2,6}$ ]] || fail "Invalid destination: $d"

  MAP_LINES+=$'exten => '"$k"$',1,Goto(internal,'"$d"$',1)\n'
done < "$MAPFILE"

[[ -n "$MAP_LINES" ]] || fail "No valid mappings provided"

# Create IVR block
BLOCK=$'\n\n; --- IVR GUI: '"$IVRNAME"$' ---\n'
BLOCK+=$'[ivr-'"$IVRNUM"$']\n'
BLOCK+=$'exten => '"$IVRNUM"$',1,Answer()\n'
BLOCK+=$' same => n,Mset(test=1,cuc=1)\n'
BLOCK+=$' same => n(start),Background(rcm/ivr/ivr_'"$IVRNUM"$'_welcome)\n'
BLOCK+=$' same => n,WaitExten(10)\n'
BLOCK+=$'\n'
BLOCK+="$MAP_LINES"
BLOCK+=$'\n'
BLOCK+=$'exten => i,1,Set(test=$[${test} + 1])\n'
BLOCK+=$' same => n,GotoIf($[${test} < '"$LIMIT"$']?'"$IVRNUM"$',start)\n'
BLOCK+=$' same => n,'"$FAIL_DIAL"$'\n'
BLOCK+=$'exten => t,1,Set(cuc=$[${cuc} + 1])\n'
BLOCK+=$' same => n,GotoIf($[${cuc} < '"$LIMIT"$']?'"$IVRNUM"$',start)\n'
BLOCK+=$' same => n,'"$FAIL_DIAL"$'\n'

# Remove old [ivr-IVRNUM] block if exists (section delete)
TMP="$(mktemp)"
awk -v sec="[ivr-${IVRNUM}]" '
  BEGIN{skip=0}
  $0==sec {skip=1; next}
  skip==1 && $0 ~ /^\[[^]]+\]$/ {skip=0}
  skip==0 {print}
' "$DP_FILE" > "$TMP"

printf "%s\n" "$(cat "$TMP")" > "$DP_FILE"
rm -f "$TMP"

# Append new block
printf "%b" "$BLOCK" >> "$DP_FILE"


# --- ensure internal routes IVRNUM to its context ---
# remove any old internal line for this IVRNUM (if exists)
sed -i "/^exten => ${IVRNUM},1,Goto(ivr-\\?${IVRNUM}.*,${IVRNUM},1)$/d" "$DP_FILE" 2>/dev/null || true

# insert under [internal] if not already there
if grep -q "^\[internal\]$" "$DP_FILE"; then
  if ! grep -q "^exten => ${IVRNUM},1,Goto(ivr-${IVRNUM},${IVRNUM},1)$" "$DP_FILE"; then
    awk -v n="$IVRNUM" '
      BEGIN{in_internal=0; inserted=0}
      /^\[internal\]$/ {in_internal=1; print; next}
      in_internal && /^\[/ { 
        if(!inserted){
          print "exten => " n ",1,Goto(ivr-" n "," n ",1)"
          inserted=1
        }
        in_internal=0
      }
      {print}
      END{
        if(in_internal && !inserted){
          print "exten => " n ",1,Goto(ivr-" n "," n ",1)"
        }
      }
    ' "$DP_FILE" > "${DP_FILE}.tmp" && mv "${DP_FILE}.tmp" "$DP_FILE"
  fi
else
  # if [internal] missing, append it
  {
    echo ""
    echo "[internal]"
    echo "exten => ${IVRNUM},1,Goto(ivr-${IVRNUM},${IVRNUM},1)"
  } >> "$DP_FILE"
fi



# Reload dialplan
/usr/sbin/asterisk -rx "dialplan reload" >/dev/null 2>&1 || true

echo "OK: Added/Updated IVR $IVRNUM in $DP_FILE"
