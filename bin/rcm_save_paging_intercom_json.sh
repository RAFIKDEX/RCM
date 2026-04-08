#!/bin/bash
set -euo pipefail
TARGET="/etc/asterisk/rcm_paging_intercom.json"
TMP="${1:-}"
if [[ -z "$TMP" || ! -f "$TMP" ]]; then
  echo "ERROR: missing temp json file" >&2
  exit 1
fi
install -d -m 755 "$(dirname "$TARGET")"
cp -f "$TMP" "$TARGET"
chmod 640 "$TARGET"
echo "OK: wrote $TARGET"
exit 0
