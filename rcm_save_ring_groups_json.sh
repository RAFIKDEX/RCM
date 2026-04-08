#!/bin/bash
set -euo pipefail

OUT="/etc/asterisk/rcm_ring_groups.json"

if [[ $# -ne 1 ]]; then
  echo "usage: $0 /path/to/tmp.json" >&2
  exit 1
fi

IN="$1"

if [[ ! -f "$IN" ]]; then
  echo "input file not found: $IN" >&2
  exit 1
fi

command -v jq >/dev/null 2>&1 || { echo "jq missing" >&2; exit 1; }

# validate + normalize shape
TMP="$(mktemp /tmp/rcm_ring_groups.norm.XXXXXX.json)"
trap 'rm -f "$TMP"' EXIT

jq '
  if type=="object" and (.groups|type=="array") then .
  elif type=="array" then {groups:.}
  else {groups:[]}
  end
' "$IN" > "$TMP"

install -m 0664 "$TMP" "$OUT"
chown root:www-data "$OUT"

echo "OK: wrote $OUT"
