#!/usr/bin/env bash
set -euo pipefail

EXT="${1:-}"

EP_FILE="/etc/asterisk/pjsip.gui.endpoint.conf"
AUTH_FILE="/etc/asterisk/pjsip.gui.auth.conf"
AOR_FILE="/etc/asterisk/pjsip.gui.aor.conf"
DP_FILE="/etc/asterisk/extensions_gui.conf"
CTX_FILE="/etc/asterisk/context_exten.conf"

fail(){ echo "ERROR: $1"; exit 1; }

[[ "$EXT" =~ ^[0-9]{2,6}$ ]] || fail "Invalid extension"

delete_block() {
  local file="$1"
  [[ -f "$file" ]] || { echo "WARN: $file not found"; return 0; }

  if ! grep -q "^\[$EXT\]" "$file"; then
    echo "INFO: [$EXT] not found in $file"
    return 0
  fi

  awk -v ext="[$EXT]" '
    BEGIN{skip=0}
    $0==ext {skip=1; next}
    skip==1 && $0 ~ /^\[[^]]+\]$/ {skip=0}
    skip==0 {print}
  ' "$file" > "${file}.tmp"

  mv "${file}.tmp" "$file"
  echo "OK: Removed [$EXT] from $file"
}

delete_ctx_block(){
  local ctx="from-internal-${EXT}"
  [[ -f "$CTX_FILE" ]] || return 0
  if ! grep -q "^\[${ctx}\]$" "$CTX_FILE"; then
    echo "INFO: [$ctx] not found in $CTX_FILE"
    return 0
  fi
  awk -v sec="[$ctx]" '
    BEGIN{skip=0}
    $0==sec {skip=1; next}
    skip==1 && $0 ~ /^\[[^]]+\]$/ {skip=0}
    skip==0 {print}
  ' "$CTX_FILE" > "${CTX_FILE}.tmp" && mv "${CTX_FILE}.tmp" "$CTX_FILE"
  echo "OK: Removed [$ctx] from $CTX_FILE"
}

delete_block "$EP_FILE"
delete_block "$AUTH_FILE"
delete_block "$AOR_FILE"

if [[ -f "$DP_FILE" ]]; then
  sed -i "/^RECORD_${EXT}=.*/d" "$DP_FILE"
  sed -i "/^VM_${EXT}=.*/d" "$DP_FILE"
  sed -i "/^exten => ${EXT},/d" "$DP_FILE"
  echo "OK: Removed dialplan entries for $EXT from $DP_FILE"
fi

delete_ctx_block

/usr/sbin/asterisk -rx "pjsip reload" >/dev/null 2>&1 || true
/usr/sbin/asterisk -rx "dialplan reload" >/dev/null 2>&1 || true

echo "DONE: Deleted extension $EXT + context_exten.conf updated"
