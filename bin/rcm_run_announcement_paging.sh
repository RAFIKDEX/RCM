#!/bin/bash
set -uo pipefail

JSON="/etc/asterisk/rcm_announcement_paging.json"
MEDIA_DB="/etc/asterisk/rcm_media_center.json"
ASTERISK="/usr/sbin/asterisk"

ANN_ID="${1:-}"
[[ -z "$ANN_ID" ]] && { echo "ERROR: no announcement id"; exit 1; }

command -v jq >/dev/null 2>&1 || { echo "ERROR: jq not found"; exit 1; }
[[ -f "$JSON" ]] || { echo "ERROR: JSON not found"; exit 1; }

# ابحث عن الـ entry
row=$(jq -c --arg id "$ANN_ID" '.items[] | select(.id == $id)' "$JSON" 2>/dev/null)
[[ -z "$row" ]] && { echo "ERROR: id $ANN_ID not found"; exit 1; }

enabled=$(echo "$row" | jq -r '.enabled // true')
[[ "$enabled" == "true" || "$enabled" == "1" ]] || { echo "SKIP: disabled"; exit 0; }

prompt_path=$(echo "$row" | jq -r '.prompt_path // ""')
play_count=$(echo "$row"  | jq -r '.play_count // 1')
members=$(echo "$row"     | jq -r '.members // [] | map(tostring) | .[]')

[[ -z "$prompt_path" ]] && { echo "ERROR: no prompt"; exit 1; }
[[ -z "$members" ]]     && { echo "ERROR: no members"; exit 1; }

echo "[$(date)] Running announcement: $ANN_ID"

# شغّل الإعلان على كل member عدد play_count مرات
while read -r ext; do
  [[ -z "$ext" ]] && continue
  for ((i=1; i<=play_count; i++)); do
    echo "  -> Playing on $ext (attempt $i/$play_count)"
    $ASTERISK -rx "channel originate PJSIP/$ext application Playback $prompt_path" 2>&1
    sleep 2
  done
done <<< "$members"

echo "[$(date)] Done: $ANN_ID"
exit 0
