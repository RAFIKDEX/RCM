#!/usr/bin/env bash
set -euo pipefail

EXT="${1:-}"
SECRET="${2:-}"
CONTEXT_INPUT="${3:-internal}"   # UI sends "internal" (kept for compatibility)
MAX_CONTACTS="${4:-10}"
ALLOW="${5:-alaw,ulaw}"
RECORD_MODE="${6:-noo}"          # in | out | noo | all
VM_MODE="${7:-Off}"              # On | Off

EP_FILE="/etc/asterisk/pjsip.gui.endpoint.conf"
AUTH_FILE="/etc/asterisk/pjsip.gui.auth.conf"
AOR_FILE="/etc/asterisk/pjsip.gui.aor.conf"
DP_FILE="/etc/asterisk/extensions_gui.conf"

CTX_FILE="/etc/asterisk/context_exten.conf"

fail(){ echo "ERROR: $1"; exit 1; }

ensure_ctx_file(){
  if [[ ! -f "$CTX_FILE" ]]; then
    cat > "$CTX_FILE" <<'EOF'
; Auto-generated per-extension contexts
; Do not edit manually
EOF
    chmod 664 "$CTX_FILE" || true
    chown root:www-data "$CTX_FILE" 2>/dev/null || true
  fi
}

delete_ctx_block(){
  local ctx="from-internal-${EXT}"
  [[ -f "$CTX_FILE" ]] || return 0
  awk -v sec="[$ctx]" '
    BEGIN{skip=0}
    $0==sec {skip=1; next}
    skip==1 && $0 ~ /^\[[^]]+\]$/ {skip=0}
    skip==0 {print}
  ' "$CTX_FILE" > "${CTX_FILE}.tmp" && mv "${CTX_FILE}.tmp" "$CTX_FILE"
}

upsert_ctx_block(){
  ensure_ctx_file
  delete_ctx_block
  local ctx="from-internal-${EXT}"
  {
    echo ""
    echo "[$ctx]"
    echo "include => internal"
  } >> "$CTX_FILE"
}

# --- validate ---
[[ "$EXT" =~ ^[0-9]{2,6}$ ]] || fail "Invalid extension"
[[ -n "$SECRET" ]] || fail "Secret empty"
[[ "$CONTEXT_INPUT" == "internal" ]] || fail "Context must be internal"
[[ "$MAX_CONTACTS" =~ ^[0-9]+$ ]] || fail "Invalid max_contacts"
[[ "$ALLOW" =~ ^[a-zA-Z0-9,]+$ ]] || fail "Invalid allow codecs"
[[ "$RECORD_MODE" =~ ^(in|out|noo|all)$ ]] || fail "Invalid record mode (in|out|noo|all)"
[[ "$VM_MODE" =~ ^(On|Off)$ ]] || fail "Invalid VM mode (On|Off)"
[[ -f "$DP_FILE" ]] || fail "Dialplan GUI file not found: $DP_FILE"

EXT_CONTEXT="from-internal-${EXT}"

# --- prevent duplicates (in any of the 3 GUI files) ---
if grep -q "^\[$EXT\]" "$EP_FILE" 2>/dev/null || grep -q "^\[$EXT\]" "$AUTH_FILE" 2>/dev/null || grep -q "^\[$EXT\]" "$AOR_FILE" 2>/dev/null; then
  fail "Extension [$EXT] already exists in GUI files"
fi

# --- append endpoint ---
cat >> "$EP_FILE" <<EOF

[$EXT]
type=endpoint
context=$EXT_CONTEXT
disallow=all
allow=$ALLOW
aors=$EXT
auth=$EXT
transport=transport-udp
;direct_media=no

EOF

# --- append auth ---
cat >> "$AUTH_FILE" <<EOF

[$EXT]
type=auth
auth_type=userpass
username=$EXT
password=$SECRET

EOF

# --- append aor ---
cat >> "$AOR_FILE" <<EOF

[$EXT]
type=aor
max_contacts=$MAX_CONTACTS
remove_existing=yes
qualify_frequency=60

EOF

# --- append dialplan globals + internal mapping (keep legacy flow) ---
if grep -q "^RECORD_${EXT}=" "$DP_FILE" || grep -q "^VM_${EXT}=" "$DP_FILE" || grep -q "^exten => ${EXT}," "$DP_FILE"; then
  fail "Dialplan entries for $EXT already exist in $DP_FILE"
fi

# Add globals directly after [globals]
awk -v ext="$EXT" -v rec="$RECORD_MODE" -v vm="$VM_MODE" '
  BEGIN{added=0}
  {
    print
    if (!added && $0 ~ /^\[globals\]$/) {
      print "RECORD_" ext "=" rec
      print "VM_" ext "=" vm
      print ""
      added=1
    }
  }
  END{
    if (!added) {
      print ""
      print "[globals]"
      print "RECORD_" ext "=" rec
      print "VM_" ext "=" vm
      print ""
    }
  }
' "$DP_FILE" > "${DP_FILE}.tmp" && mv "${DP_FILE}.tmp" "$DP_FILE"

# Add internal exten line (routes extension into your existing dialplan logic)
awk -v ext="$EXT" '
  BEGIN{added=0}
  {
    print
    if (!added && $0 ~ /^\[internal\]$/) {
      print "exten => " ext ",1,Goto(dexter,${EXTEN},1)"
      added=1
    }
  }
  END{
    if (!added) {
      print ""
      print "[internal]"
      print "exten => " ext ",1,Goto(dexter,${EXTEN},1)"
    }
  }
' "$DP_FILE" > "${DP_FILE}.tmp" && mv "${DP_FILE}.tmp" "$DP_FILE"

# --- create per-extension context include block ---
upsert_ctx_block

# --- reload only what we need ---
/usr/sbin/asterisk -rx "pjsip reload" >/dev/null 2>&1 || true
/usr/sbin/asterisk -rx "dialplan reload" >/dev/null 2>&1 || true

echo "OK: Added extension $EXT (context=$EXT_CONTEXT) + context_exten.conf updated"
