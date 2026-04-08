#!/usr/bin/env bash
set -euo pipefail

EXT="${1:-}"
SECRET="${2:-}"
CONTEXT_INPUT="${3:-internal}"
MAX_CONTACTS="${4:-10}"
ALLOW="${5:-alaw,ulaw}"
RECORD_MODE="${6:-noo}"
VM_MODE="${7:-Off}"
CALLERID_NAME="${8:-}"
CALLERID_NUMBER="${9:-}"

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

[[ "$EXT" =~ ^[0-9]{2,6}$ ]] || fail "Invalid extension"
[[ -n "$SECRET" ]] || fail "Secret empty"
[[ "$CONTEXT_INPUT" == "internal" ]] || fail "Context must be internal"
[[ "$MAX_CONTACTS" =~ ^[0-9]+$ ]] || fail "Invalid max_contacts"
[[ "$ALLOW" =~ ^[a-zA-Z0-9,]+$ ]] || fail "Invalid allow codecs"
[[ "$RECORD_MODE" =~ ^(in|out|noo|all)$ ]] || fail "Invalid record mode (in|out|noo|all)"
[[ "$VM_MODE" =~ ^(On|Off)$ ]] || fail "Invalid VM mode (On|Off)"
[[ -n "$CALLERID_NAME" ]] || fail "Caller ID name empty"
[[ "$CALLERID_NUMBER" =~ ^[0-9+*#]{2,20}$ ]] || fail "Invalid Caller ID number"
[[ -f "$DP_FILE" ]] || fail "Dialplan GUI file not found: $DP_FILE"

grep -q "^\[$EXT\]$" "$EP_FILE" || fail "Extension [$EXT] is not a GUI extension"

EXT_CONTEXT="from-internal-${EXT}"

update_endpoint_section() {
  python3 - "$EP_FILE" "$EXT" "$EXT_CONTEXT" "$ALLOW" "$CALLERID_NAME" "$CALLERID_NUMBER" <<'PY'
import sys, re

path, ext, context, allow, caller_name, caller_number = sys.argv[1:]
with open(path, 'r', encoding='utf-8') as f:
    lines = f.read().splitlines()

start = None
end = len(lines)
for i, line in enumerate(lines):
    if line.strip() == f'[{ext}]':
        start = i
        break

if start is None:
    print(f"ERROR: section [{ext}] not found", file=sys.stderr)
    sys.exit(1)

for j in range(start + 1, len(lines)):
    if re.match(r'^\[[^\]]+\]$', lines[j].strip()):
        end = j
        break

body = lines[start + 1:end]
preserved = []
for line in body:
    t = line.strip()
    if t.startswith("context="):
        continue
    if t.startswith("callerid="):
        continue
    if t.startswith("allow="):
        continue
    preserved.append(line)

new_body = [
    f"context={context}",
    f'callerid="{caller_name}" <{caller_number}>',
    f"allow={allow}",
]
new_body.extend(preserved)

out = lines[:start + 1] + new_body + lines[end:]
with open(path, 'w', encoding='utf-8') as f:
    f.write("\n".join(out) + "\n")
PY
}

update_auth_section() {
  python3 - "$AUTH_FILE" "$EXT" "$SECRET" <<'PY'
import sys, re

path, ext, secret = sys.argv[1:]
with open(path, 'r', encoding='utf-8') as f:
    lines = f.read().splitlines()

start = None
end = len(lines)
for i, line in enumerate(lines):
    if line.strip() == f'[{ext}]':
        start = i
        break

if start is None:
    print(f"ERROR: auth section [{ext}] not found", file=sys.stderr)
    sys.exit(1)

for j in range(start + 1, len(lines)):
    if re.match(r'^\[[^\]]+\]$', lines[j].strip()):
        end = j
        break

body = lines[start + 1:end]
preserved = []
for line in body:
    t = line.strip()
    if t.startswith("password="):
        continue
    preserved.append(line)

new_body = []
inserted = False
for line in preserved:
    new_body.append(line)
    if line.strip().startswith("username=") and not inserted:
        new_body.append(f"password={secret}")
        inserted = True

if not inserted:
    new_body.append(f"password={secret}")

out = lines[:start + 1] + new_body + lines[end:]
with open(path, 'w', encoding='utf-8') as f:
    f.write("\n".join(out) + "\n")
PY
}

update_aor_section() {
  python3 - "$AOR_FILE" "$EXT" "$MAX_CONTACTS" <<'PY'
import sys, re

path, ext, max_contacts = sys.argv[1:]
with open(path, 'r', encoding='utf-8') as f:
    lines = f.read().splitlines()

start = None
end = len(lines)
for i, line in enumerate(lines):
    if line.strip() == f'[{ext}]':
        start = i
        break

if start is None:
    print(f"ERROR: aor section [{ext}] not found", file=sys.stderr)
    sys.exit(1)

for j in range(start + 1, len(lines)):
    if re.match(r'^\[[^\]]+\]$', lines[j].strip()):
        end = j
        break

body = lines[start + 1:end]
preserved = []
for line in body:
    t = line.strip()
    if t.startswith("max_contacts="):
        continue
    if t.startswith("remove_existing="):
        continue
    if t.startswith("qualify_frequency="):
        continue
    preserved.append(line)

new_body = [
    f"max_contacts={max_contacts}",
    "remove_existing=yes",
    "qualify_frequency=60",
]
new_body.extend(preserved)

out = lines[:start + 1] + new_body + lines[end:]
with open(path, 'w', encoding='utf-8') as f:
    f.write("\n".join(out) + "\n")
PY
}

update_endpoint_section
update_auth_section
update_aor_section

sed -i "/^RECORD_${EXT}=.*/d" "$DP_FILE"
sed -i "/^VM_${EXT}=.*/d" "$DP_FILE"
sed -i "/^exten => ${EXT},/d" "$DP_FILE"

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

upsert_ctx_block

/usr/sbin/asterisk -rx "pjsip reload" >/dev/null 2>&1 || true
/usr/sbin/asterisk -rx "dialplan reload" >/dev/null 2>&1 || true

echo "OK: Edited extension $EXT with callerid \"$CALLERID_NAME\" <$CALLERID_NUMBER>"