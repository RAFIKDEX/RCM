#!/usr/bin/env bash
asterisk -rx "pjsip reload" >/dev/null 2>&1 || true
