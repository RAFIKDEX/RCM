#!/bin/bash
set -uo pipefail

JSON="/etc/asterisk/rcm_paging_intercom.json"
GUI="/etc/asterisk/extensions_gui.conf"
MEDIA_DB="/etc/asterisk/rcm_media_center.json"

command -v jq >/dev/null 2>&1 || { echo "ERROR: jq not found"; exit 1; }
command -v python3 >/dev/null 2>&1 || { echo "ERROR: python3 not found"; exit 1; }
[[ -f "$GUI" ]] || touch "$GUI"

# امسح كل حاجة متعلقة بالـ paging/intercom من الفايل
python3 - "$GUI" << 'PYEOF'
import sys, re
with open(sys.argv[1], 'r') as f:
    content = f.read()
content = re.sub(r'\[rcm-paging-intercom\].*?(?=\n\[|\Z)', '', content, flags=re.DOTALL)
content = re.sub(r'^exten => \d+,1,Goto\(rcm-paging-intercom.*\n', '', content, flags=re.MULTILINE)
with open(sys.argv[1], 'w') as f:
    f.write(content)
PYEOF

# ابدأ بناء الكونتكست
BLOCK=$'\n[rcm-paging-intercom]\n\n'

if [[ -f "$JSON" ]]; then
  while read -r row; do

    # تجاهل العناصر المعطلة
    enabled=$(echo "$row" | jq -r '.enabled // true')
    [[ "$enabled" == "true" || "$enabled" == "1" ]] || continue

    id=$(echo "$row"      | jq -r '.id // ""')
    name=$(echo "$row"    | jq -r '.name // ""')
    type=$(echo "$row"    | jq -r '.type // "paging"')
    prompt=$(echo "$row"  | jq -r '.welcome_prompt // ""')
    members=$(echo "$row" | jq -r '.members // [] | map(tostring) | join("&PJSIP/")')
    allowed=$(echo "$row" | jq -r '.allowed_callers // [] | map(tostring) | join(",")')

    [[ -z "$id" || -z "$members" ]] && continue

    targets="PJSIP/$members"

    # ابحث عن مسار الـ prompt
    prompt_path=""
    if [[ -n "$prompt" && -f "$MEDIA_DB" ]]; then
      full_path=$(jq -r --arg pid "$prompt" \
        '.prompts[] | select(.id == $pid) | .path // ""' \
        "$MEDIA_DB" 2>/dev/null || echo "")
      if [[ -n "$full_path" ]]; then
        no_ext="${full_path%.*}"
        prompt_path="${no_ext##*/sounds/en/}"
      fi
    fi

    # اكتب الـ extension
    BLOCK+="; $name ($type)"$'\n'
    BLOCK+="exten => $id,1,NoOp(RCM $type for $name)"$'\n'

    # allowed callers check
    if [[ -n "$allowed" ]]; then
      IFS=',' read -ra callers <<< "$allowed"
      for caller in "${callers[@]}"; do
        caller=$(echo "$caller" | tr -d ' ')
        BLOCK+=" same => n,GotoIf(\$[\"$caller\" = \"\${CALLERID(num)}\"]?allow)"$'\n'
      done
      BLOCK+=" same => n,Hangup()"$'\n'
      BLOCK+=" same => n(allow),NoOp(Caller allowed)"$'\n'
    else
      BLOCK+=" same => n(allow),NoOp(Any caller allowed)"$'\n'
    fi

    # Page مع أو بدون prompt على الـ members
    if [[ "$type" == "intercom" ]]; then
      if [[ -n "$prompt_path" ]]; then
        BLOCK+=" same => n,Page($targets,dA($prompt_path))"$'\n'
      else
        BLOCK+=" same => n,Page($targets,d)"$'\n'
      fi
    else
      if [[ -n "$prompt_path" ]]; then
        BLOCK+=" same => n,Page($targets,A($prompt_path))"$'\n'
      else
        BLOCK+=" same => n,Page($targets)"$'\n'
      fi
    fi

    BLOCK+=" same => n,Hangup()"$'\n'
    BLOCK+=$'\n'

  done < <(jq -c '.items[]? // empty' "$JSON")
fi

# احقن الكونتكست في المكان الصح
if grep -q "; END RCM QUEUE GUI CONTEXTS" "$GUI"; then
  perl -i -0pe "s|; END RCM QUEUE GUI CONTEXTS|\Q$BLOCK\E\n; END RCM QUEUE GUI CONTEXTS|" "$GUI"
else
  printf '%s\n' "$BLOCK" >> "$GUI"
fi

# اكتب Goto في [internal] لكل entry
if [[ -f "$JSON" ]]; then
  while read -r row; do
    enabled=$(echo "$row" | jq -r '.enabled // true')
    [[ "$enabled" == "true" || "$enabled" == "1" ]] || continue
    id=$(echo "$row" | jq -r '.id // ""')
    [[ -z "$id" ]] && continue
    sed -i "/^\[internal\]/a exten => $id,1,Goto(rcm-paging-intercom,\${EXTEN},1)" "$GUI"
  done < <(jq -c '.items[]? // empty' "$JSON")
fi

echo "OK: updated $GUI"
exit 0
