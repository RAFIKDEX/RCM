#!/usr/bin/env bash
set -euo pipefail

SRC="${1:-}"
NUM="${2:-}"

fail(){ echo "ERROR: $1"; exit 1; }

[[ -f "$SRC" ]] || fail "Source file not found"
[[ "$NUM" =~ ^[0-9]{2,6}$ ]] || fail "Invalid number"

DEST_DIR="/var/lib/asterisk/sounds/en/rcm/ann"
DEST_WAV="${DEST_DIR}/ann_${NUM}.wav"

mkdir -p "$DEST_DIR"

# accept wav only (simple + safe)
case "${SRC,,}" in
  *.wav) ;;
  *) fail "Only .wav allowed for now" ;;
esac

cp -f "$SRC" "$DEST_WAV"
chmod 644 "$DEST_WAV"

echo "OK: Uploaded ANN prompt ${NUM} to ${DEST_WAV}"
exit 0
