#!/usr/bin/env bash

# Clean args (remove CRLF and surrounding spaces)
MODE="$(printf '%s' "$1" | tr -d '\r' | xargs)"
NAME="$(printf '%s' "$2" | tr -d '\r' | xargs)"

[ -z "$MODE" ] || [ -z "$NAME" ] && { echo '{"ok":false,"error":"usage: peer|reg-client|reg-server name"}'; exit 1; }

# Extract contact raw (WITHOUT "sip:") from "pjsip show aor"
extract_contact_from_aor_out() {
  local OUT="$1"

  # 1) from "contact : sip:...."
  local C
  C="$(echo "$OUT" | awk '
    BEGIN{IGNORECASE=1}
    /^[[:space:]]*contact[[:space:]]*:/{
      line=$0
      sub(/^[^:]*:[[:space:]]*/,"",line)     # remove "contact :"
      sub(/^[[:space:]]*sip:/,"",line)       # remove leading "sip:"
      gsub(/[[:space:]]+$/,"",line)          # rtrim
      if(line!=""){ print line; exit }
    }'
  )"
  if [ -n "$C" ]; then
    echo "$C"
    return
  fi

  # 2) fallback from table "Contact:" line
  C="$(echo "$OUT" | awk '
    BEGIN{IGNORECASE=1}
    /^[[:space:]]*Contact:[[:space:]]/{
      if(match($0, /\/sip:([^[:space:]]+)/, m)){
        print m[1]; exit
      }
    }'
  )"
  echo "$C"
}

json() {
  local ST="$1"
  local CT="$2"
  if [ -n "$CT" ]; then
    echo "{\"ok\":true,\"mode\":\"$MODE\",\"name\":\"$NAME\",\"status\":\"$ST\",\"contact\":\"$CT\"}"
  else
    echo "{\"ok\":true,\"mode\":\"$MODE\",\"name\":\"$NAME\",\"status\":\"$ST\"}"
  fi
}

peer_status() {
  OUT="$(asterisk -rx "pjsip show aor $NAME" 2>/dev/null)"

  echo "$OUT" | grep -q "Contacts: 0" && { json "Unregistered" ""; exit 0; }

  CONTACT_RAW="$(extract_contact_from_aor_out "$OUT")"

  S="$(echo "$OUT" | awk '
    BEGIN{IGNORECASE=1; avail=0; unavail=0; nonqual=0;}
    /Contact:/{
      if ($0 ~ /[[:space:]]Avail[[:space:]]/)   avail=1;
      if ($0 ~ /[[:space:]]Unavail[[:space:]]/) unavail=1;
      if ($0 ~ /[[:space:]]NonQual[[:space:]]/) nonqual=1;
    }
    END{
      if (avail)   {print "Reachable"; exit}
      if (unavail) {print "Unreachable"; exit}
      if (nonqual) {print "unqualified"; exit}
      print "Unregistered"
    }'
  )"

  json "$S" "$CONTACT_RAW"
}

reg_client_status() {
  OUT="$(asterisk -rx "pjsip show registration ${NAME}-reg" 2>/dev/null)"
  echo "$OUT" | grep -qi "No such registration" && { json "Unregistered" ""; exit 0; }

  SERVER="$(echo "$OUT" | awk '
    BEGIN{IGNORECASE=1}
    NR<=12{
      if(match($0, /sip:([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+:[0-9]+)/, m)){
        print m[1]; exit
      }
    }'
  )"

  if echo "$OUT" | grep -qi "Registered"; then
    S="Reachable"
  elif echo "$OUT" | grep -qi "Rejected"; then
    S="Rejected"
  elif echo "$OUT" | grep -qi "Unregistered"; then
    S="Unregistered"
  elif echo "$OUT" | grep -qi "Registering"; then
    S="Unregistered"
  elif echo "$OUT" | grep -qi "Failed"; then
    S="Unreachable"
  else
    S="Unregistered"
  fi

  json "$S" "$SERVER"
}

reg_server_status() {
  OUT="$(asterisk -rx "pjsip show aor $NAME" 2>/dev/null)"

  echo "$OUT" | grep -q "Contacts: 0" && { json "Unregistered" ""; exit 0; }

  CONTACT_RAW="$(extract_contact_from_aor_out "$OUT")"

  S="$(echo "$OUT" | awk '
    BEGIN{IGNORECASE=1; avail=0; unavail=0; nonqual=0;}
    /Contact:/{
      if ($0 ~ /[[:space:]]Avail[[:space:]]/)   avail=1;
      if ($0 ~ /[[:space:]]Unavail[[:space:]]/) unavail=1;
      if ($0 ~ /[[:space:]]NonQual[[:space:]]/) nonqual=1;
    }
    END{
      if (avail)   {print "Reachable"; exit}
      if (unavail) {print "Unreachable"; exit}
      if (nonqual) {print "unqualified"; exit}
      print "Unregistered"
    }'
  )"

  json "$S" "$CONTACT_RAW"
}

case "$MODE" in
  peer) peer_status;;
  reg-client) reg_client_status;;
  reg-server) reg_server_status;;
  *) echo '{"ok":false,"error":"invalid mode"}'; exit 1;;
esac
