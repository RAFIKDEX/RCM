#!/bin/bash
set -uo pipefail

JSON="/etc/asterisk/rcm_announcement_paging.json"
CRON_FILE="/etc/cron.d/rcm_announcement_paging"
RUN_SCRIPT="/usr/local/bin/rcm_run_announcement_paging.sh"

command -v jq >/dev/null 2>&1 || { echo "ERROR: jq not found"; exit 1; }

# امسح الـ cron القديم
> "$CRON_FILE"
echo "# RCM Announcement Paging - Auto-generated" >> "$CRON_FILE"
echo "SHELL=/bin/bash" >> "$CRON_FILE"
echo "PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin" >> "$CRON_FILE"
echo "" >> "$CRON_FILE"

if [[ ! -f "$JSON" ]]; then
  echo "OK: no JSON file"
  exit 0
fi

# map الأيام لأرقام cron
declare -A DAY_MAP=(
  [Sun]=0 [Mon]=1 [Tue]=2 [Wed]=3 [Thu]=4 [Fri]=5 [Sat]=6
)

jq -c '.items[]? // empty' "$JSON" | while read -r row; do
  enabled=$(echo "$row" | jq -r '.enabled // true')
  [[ "$enabled" == "true" || "$enabled" == "1" ]] || continue

  id=$(echo "$row"          | jq -r '.id // ""')
  time_val=$(echo "$row"    | jq -r '.time // "08:00"')
  play_count=$(echo "$row"  | jq -r '.play_count // 1')
  days_json=$(echo "$row"   | jq -r '.days // [] | join(",")')

  [[ -z "$id" ]] && continue
  [[ -z "$days_json" ]] && continue

  # تحويل الوقت
  hour=$(echo "$time_val" | cut -d: -f1)
  min=$(echo "$time_val"  | cut -d: -f2)

  # تحويل الأيام
  cron_days=""
  IFS=',' read -ra days_arr <<< "$days_json"
  for day in "${days_arr[@]}"; do
    num="${DAY_MAP[$day]:-}"
    if [[ -n "$num" ]]; then
      cron_days+="${num},"
    fi
  done
  cron_days="${cron_days%,}"

  [[ -z "$cron_days" ]] && continue

  echo "$min $hour * * $cron_days root /bin/bash $RUN_SCRIPT $(printf '%q' "$id") >> /var/log/rcm_announcement.log 2>&1" >> "$CRON_FILE"
done

chmod 644 "$CRON_FILE"
echo "OK: updated $CRON_FILE"
exit 0
