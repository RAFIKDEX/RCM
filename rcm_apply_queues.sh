#!/usr/bin/env bash
set -euo pipefail

STATE_FILE="/etc/asterisk/rcm_queues.json"
QUEUE_CONF="/etc/asterisk/queues.conf"
DP_FILE="/etc/asterisk/extensions_gui.conf"

fail() {
  echo "ERROR: $1" >&2
  exit 1
}

[[ -f "$STATE_FILE" ]] || printf '%s\n' '{"queues":[]}' > "$STATE_FILE"
[[ -f "$QUEUE_CONF" ]] || touch "$QUEUE_CONF"
[[ -f "$DP_FILE" ]] || touch "$DP_FILE"

python3 - "$STATE_FILE" "$QUEUE_CONF" "$DP_FILE" <<'PY'
import json
import os
import re
import sys

state_file, queue_conf, dp_file = sys.argv[1], sys.argv[2], sys.argv[3]

def load_json(path):
    try:
        with open(path, "r", encoding="utf-8") as f:
            data = json.load(f)
            if isinstance(data, dict):
                return data
    except Exception:
        pass
    return {"queues": []}

def read_text(path):
    try:
        with open(path, "r", encoding="utf-8", errors="ignore") as f:
            return f.read()
    except Exception:
        return ""

def write_text(path, text):
    with open(path, "w", encoding="utf-8") as f:
        f.write(text)

def normalize_onoff(v):
    return "yes" if str(v).strip().lower() in ("on", "yes", "1", "true") else "no"

def clean_display_name(v):
    v = "" if v is None else str(v)
    v = v.strip()
    v = re.sub(r'[\r\n]+', ' ', v)
    return v

def replace_managed_block(text, begin_marker, end_marker, new_block):
    pattern = re.compile(re.escape(begin_marker) + r'.*?' + re.escape(end_marker), re.S)
    if pattern.search(text):
        return pattern.sub(new_block, text)
    if text and not text.endswith("\n"):
        text += "\n"
    return text + "\n" + new_block + "\n"

def ensure_section_with_managed_block(text, section_name, begin_marker, end_marker, payload_lines):
    lines = text.splitlines()
    section_header = f'[{section_name}]'

    found_idx = None
    for i, line in enumerate(lines):
        if line.strip() == section_header:
            found_idx = i
            break

    if found_idx is None:
        if text and not text.endswith("\n"):
            text += "\n"
        text += f"\n{section_header}\n{begin_marker}\n"
        text += "\n".join(payload_lines) + "\n"
        text += f"{end_marker}\n"
        return text

    j = found_idx + 1
    while j < len(lines) and not re.match(r'^\[[^]]+\]$', lines[j].strip()):
        j += 1

    body = lines[found_idx + 1:j]

    filtered = []
    in_managed = False
    for line in body:
        s = line.strip()
        if s == begin_marker:
            in_managed = True
            continue
        if s == end_marker:
            in_managed = False
            continue
        if not in_managed:
            filtered.append(line)

    new_body = [begin_marker] + payload_lines + [end_marker] + filtered
    new_lines = lines[:found_idx + 1] + new_body + lines[j:]
    return "\n".join(new_lines).rstrip() + "\n"

state = load_json(state_file)
queues = state.get("queues", [])
if not isinstance(queues, list):
    queues = []

queue_blocks = []
route_lines = ["[rcm-queue-routes]"]
feature_lines = [
    "[rcm-queue-features]",
    "exten => _*71XXXX,1,Answer()",
    " same => n,Set(QNUM=${EXTEN:3})",
    " same => n,Set(QUEUENAME=${GLOBAL(QUEUE_${QNUM})})",
    " same => n,GotoIf($[\"${QUEUENAME}\"=\"\"]?bad,1)",
    " same => n,Set(MEMBERIFACE=PJSIP/${CALLERID(num)})",
    " same => n,AddQueueMember(${QUEUENAME},${MEMBERIFACE})",
    " same => n,Playback(agent-loginok)",
    " same => n,SayDigits(${QNUM})",
    " same => n,Hangup()",
    "",
    "exten => bad,1,Playback(invalid)",
    " same => n,Hangup()",
    "",
    "exten => _*72XXXX,1,Answer()",
    " same => n,Set(QNUM=${EXTEN:3})",
    " same => n,Set(QUEUENAME=${GLOBAL(QUEUE_${QNUM})})",
    " same => n,GotoIf($[\"${QUEUENAME}\"=\"\"]?bad2,1)",
    " same => n,Set(MEMBERIFACE=PJSIP/${CALLERID(num)})",
    " same => n,RemoveQueueMember(${QUEUENAME},${MEMBERIFACE})",
    " same => n,Playback(agent-loggedoff)",
    " same => n,SayDigits(${QNUM})",
    " same => n,Hangup()",
    "",
    "exten => bad2,1,Playback(invalid)",
    " same => n,Hangup()",
    "",
    "exten => _*73XXXX,1,Answer()",
    " same => n,Set(QNUM=${EXTEN:3})",
    " same => n,Set(QUEUENAME=${GLOBAL(QUEUE_${QNUM})})",
    " same => n,GotoIf($[\"${QUEUENAME}\"=\"\"]?bad3,1)",
    " same => n,Set(MEMBERIFACE=PJSIP/${CALLERID(num)})",
    " same => n,PauseQueueMember(${QUEUENAME},${MEMBERIFACE},,break)",
    " same => n,Playback(agent-loginok)",
    " same => n,Hangup()",
    "",
    "exten => bad3,1,Playback(invalid)",
    " same => n,Hangup()",
    "",
    "exten => _*74XXXX,1,Answer()",
    " same => n,Set(QNUM=${EXTEN:3})",
    " same => n,Set(QUEUENAME=${GLOBAL(QUEUE_${QNUM})})",
    " same => n,GotoIf($[\"${QUEUENAME}\"=\"\"]?bad4,1)",
    " same => n,Set(MEMBERIFACE=PJSIP/${CALLERID(num)})",
    " same => n,UnpauseQueueMember(${QUEUENAME},${MEMBERIFACE},,back)",
    " same => n,Playback(agent-loginok)",
    " same => n,Hangup()",
    "",
    "exten => bad4,1,Playback(invalid)",
    " same => n,Hangup()",
]
context_lines = []
global_lines = []

for q in queues:
    queue_number = str(q.get("queue_number", "")).strip()
    if not re.fullmatch(r'\d{2,6}', queue_number):
        continue

    queue_name_for_engine = queue_number
    display_name = clean_display_name(q.get("name", "")) or queue_number

    strategy = str(q.get("strategy", "rrmemory")).strip() or "rrmemory"
    music_on_hold = str(q.get("music_on_hold", "default")).strip() or "default"
    max_queue_length = str(q.get("max_queue_length", "0")).strip() or "0"
    wrapup_time = str(q.get("wrapup_time", "0")).strip() or "0"
    retry_time = str(q.get("retry_time", "0")).strip() or "0"
    ring_time = str(q.get("ring_time", "0")).strip() or "0"
    auto_record = normalize_onoff(q.get("auto_record", "off"))

    enable_welcome_prompt = normalize_onoff(q.get("enable_welcome_prompt", "off"))
    custom_prompt = str(q.get("custom_prompt", "")).strip()
    welcome_mode = str(q.get("welcome_mode", "before")).strip() or "before"

    max_wait_time = str(q.get("max_wait_time", "0")).strip() or "0"
    destination_type = str(q.get("destination_type", "hangup")).strip() or "hangup"
    destination_value = str(q.get("destination_value", "")).strip()

    position_announcement = normalize_onoff(q.get("position_announcement", "off"))
    announcement_frequency = str(q.get("announcement_frequency", "15")).strip() or "15"
    periodic_announcement = str(q.get("periodic_announcement", "")).strip()
    periodic_announcement_frequency = str(q.get("periodic_announcement_frequency", "30")).strip() or "30"
    caller_bridge_announcement = str(q.get("caller_bridge_announcement", "")).strip()

    leave_when_empty = "yes" if str(q.get("leave_when_empty", "yes")).strip().lower() == "yes" else "no"
    dial_in_empty_queue = "yes" if str(q.get("dial_in_empty_queue", "yes")).strip().lower() == "yes" else "no"

    report_hold_time = normalize_onoff(q.get("report_hold_time", "off"))
    replace_display_name = normalize_onoff(q.get("replace_display_name", "off"))
    display_name_value = clean_display_name(q.get("display_name_value", "")) or display_name
    skip_busy_agent = normalize_onoff(q.get("skip_busy_agent", "on"))
    auto_fill = normalize_onoff(q.get("auto_fill", "off"))
    auto_pause = normalize_onoff(q.get("auto_pause", "off"))
    agent_bridge_announcement = str(q.get("agent_bridge_announcement", "")).strip()

    members = q.get("static_agents", [])
    if not isinstance(members, list):
        members = []

    queue_blocks.append(f"[{queue_name_for_engine}]")
    queue_blocks.append(f"; RCM_NAME={display_name}")
    queue_blocks.append(f"musicclass = {music_on_hold}")
    queue_blocks.append(f"strategy = {strategy}")
    queue_blocks.append(f"timeout = {ring_time}")
    queue_blocks.append(f"retry = {retry_time}")
    queue_blocks.append(f"maxlen = {max_queue_length}")
    queue_blocks.append(f"wrapuptime = {wrapup_time}")
    queue_blocks.append(f"announce-position = {position_announcement}")
    queue_blocks.append(f"announce-frequency = {announcement_frequency}")
    queue_blocks.append(f"reportholdtime = {report_hold_time}")
    queue_blocks.append(f"joinempty = {dial_in_empty_queue}")
    queue_blocks.append(f"leavewhenempty = {leave_when_empty}")
    queue_blocks.append(f"autofill = {auto_fill}")
    queue_blocks.append(f"autopause = {auto_pause}")
    queue_blocks.append(f"ringinuse = {'no' if skip_busy_agent == 'yes' else 'yes'}")
    queue_blocks.append("setqueuevar = yes")

    if auto_record == "yes":
        queue_blocks.append("monitor-type = MixMonitor")

    if periodic_announcement:
        queue_blocks.append(f"periodic-announce = {periodic_announcement}")
        queue_blocks.append(f"periodic-announce-frequency = {periodic_announcement_frequency}")

    if caller_bridge_announcement:
        queue_blocks.append(f"queue-callerannounce = {caller_bridge_announcement}")

    if agent_bridge_announcement:
        queue_blocks.append(f"announce = {agent_bridge_announcement}")

    for agent in members:
        agent = str(agent).strip()
        if re.fullmatch(r'\d{2,6}', agent):
            queue_blocks.append(f"member => PJSIP/{agent}")

    queue_blocks.append("")

    global_lines.append(f"QUEUE_{queue_number}={queue_name_for_engine}")
    route_lines.append(f"exten => {queue_number},1,Goto(queue-{queue_number},{queue_number},1)")

    context_lines.append(f"[queue-{queue_number}]")
    context_lines.append(f"exten => {queue_number},1,Answer()")

    if replace_display_name == "yes":
        context_lines.append(f" same => n,Set(CALLERID(name)={display_name_value})")

    if enable_welcome_prompt == "yes" and custom_prompt and welcome_mode == "before":
        context_lines.append(f" same => n,Playback({custom_prompt})")

    context_lines.append(f" same => n,Queue({queue_name_for_engine},tT,,,{max_wait_time})")

    if destination_type == "extension" and re.fullmatch(r'\d{2,6}', destination_value):
        context_lines.append(f" same => n,Goto(internal,{destination_value},1)")
    elif destination_type == "ivr" and re.fullmatch(r'\d{2,6}', destination_value):
        context_lines.append(f" same => n,Goto(ivr-{destination_value},{destination_value},1)")
    elif destination_type == "announcement" and re.fullmatch(r'\d{2,6}', destination_value):
        context_lines.append(f" same => n,Goto(ann-{destination_value},{destination_value},1)")
    elif destination_type == "queue" and re.fullmatch(r'\d{2,6}', destination_value):
        context_lines.append(f" same => n,Queue({destination_value})")
    else:
        context_lines.append(" same => n,Hangup()")

    context_lines.append("")

queue_block = "; BEGIN RCM QUEUE GUI\n" + "\n".join(queue_blocks).rstrip() + "\n; END RCM QUEUE GUI"

dialplan_context_block_lines = []
dialplan_context_block_lines.extend(context_lines)
dialplan_context_block_lines.extend(route_lines)
dialplan_context_block_lines.append("")
dialplan_context_block_lines.extend(feature_lines)

context_block = "; BEGIN RCM QUEUE GUI CONTEXTS\n" + "\n".join(dialplan_context_block_lines).rstrip() + "\n; END RCM QUEUE GUI CONTEXTS"

queue_text = read_text(queue_conf)
queue_text = replace_managed_block(queue_text, "; BEGIN RCM QUEUE GUI", "; END RCM QUEUE GUI", queue_block)
write_text(queue_conf, queue_text)

dp_text = read_text(dp_file)
dp_text = ensure_section_with_managed_block(
    dp_text,
    "globals",
    "; BEGIN RCM QUEUE GUI GLOBALS",
    "; END RCM QUEUE GUI GLOBALS",
    global_lines if global_lines else ["; no queue globals"]
)
dp_text = ensure_section_with_managed_block(
    dp_text,
    "internal",
    "; BEGIN RCM QUEUE GUI INTERNAL",
    "; END RCM QUEUE GUI INTERNAL",
    [
        "include => rcm-queue-routes",
        "include => rcm-queue-features",
    ]
)
dp_text = replace_managed_block(dp_text, "; BEGIN RCM QUEUE GUI CONTEXTS", "; END RCM QUEUE GUI CONTEXTS", context_block)
write_text(dp_file, dp_text)
PY

/usr/sbin/asterisk -rx "dialplan reload" >/dev/null 2>&1 || true
/usr/sbin/asterisk -rx "queue reload" >/dev/null 2>&1 || true

echo "OK: Queues applied"
